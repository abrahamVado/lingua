<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_latamvideo\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * @Block(
 *   id = "pds_latamvideo_block",
 *   admin_label = @Translation("PDS Latam Video Block"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsLatamvideoBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'title_html'      => 'Educação financeira para Ensino Fundamental e Médio.',
      'body_text'       => 'Desde 2016, mais de 12 mil alunos já participaram das aulas ministradas por nossos colaboradores e parceiros voluntários.',
      'link_text'       => 'Conheça o programa',
      'link_url'        => '',
      'video_provider'  => 'auto',
      'video_url'       => '',
      'thumbnail_fid'   => NULL,
      'thumbnail_alt'   => '',
      'autoplay'        => FALSE,
      'muted'           => FALSE,
      'controls'        => TRUE,
      'start_sec'       => 0,
    ];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $cfg = $this->getConfiguration();

    $form['title_html'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#default_value' => $cfg['title_html'] ?? '',
      '#required' => TRUE,
    ];

    $form['body_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto'),
      '#default_value' => $cfg['body_text'] ?? '',
      '#rows' => 3,
      '#required' => TRUE,
    ];

    //1.- Creamos el selector del proveedor para que el editor defina la fuente.
    $form['video_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Proveedor del video'),
      '#options' => $this->getProviderOptions(),
      '#default_value' => $cfg['video_provider'] ?? 'auto',
      '#required' => TRUE,
      '#description' => $this->t('Selecciona el origen del video para construir el embed adecuado.'),
    ];

    //2.- Registramos la URL del video que será analizada para obtener el ID.
    $form['video_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL del video'),
      '#default_value' => $cfg['video_url'] ?? '',
      '#description' => $this->t('Pega la URL de YouTube, Vimeo, Dailymotion, Streamable, VideoPress, Wistia, Vidyard o Kaltura.'),
      '#placeholder' => 'https://youtu.be/XXXXXXXXXXX',
      '#required' => TRUE,
    ];

    //3.- Permitimos subir la miniatura que acompaña al video en el frontend.
    $form['thumbnail'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Miniatura'),
      '#default_value' => !empty($cfg['thumbnail_fid']) ? [$cfg['thumbnail_fid']] : [],
      '#upload_location' => 'public://latamvideo_thumbnails/',
      '#description' => $this->t('Sube una imagen en formato PNG, JPG o WebP para usarla como portada.'),
      '#upload_validators' => [
        'file_validate_is_image' => [],
        'file_validate_extensions' => ['png jpg jpeg webp'],
      ],
      '#required' => TRUE,
    ];

    //4.- Capturamos un texto alternativo para la accesibilidad de la miniatura.
    $form['thumbnail_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto alternativo de la miniatura'),
      '#default_value' => $cfg['thumbnail_alt'] ?? '',
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Opciones del reproductor'),
      '#open' => FALSE,
    ];
    $form['options']['autoplay'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autoplay'),
      '#default_value' => (bool) ($cfg['autoplay'] ?? FALSE),
      '#description' => $this->t('Algunos navegadores requieren que el video esté silenciado.'),
    ];
    $form['options']['muted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Silenciado'),
      '#default_value' => (bool) ($cfg['muted'] ?? FALSE),
    ];
    $form['options']['controls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mostrar controles'),
      '#default_value' => (bool) ($cfg['controls'] ?? TRUE),
    ];
    $form['options']['start_sec'] = [
      '#type' => 'number',
      '#title' => $this->t('Inicio en segundos'),
      '#default_value' => (int) ($cfg['start_sec'] ?? 0),
      '#min' => 0,
      '#step' => 1,
    ];

    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto del botón'),
      '#default_value' => $cfg['link_text'] ?? '',
      '#required' => TRUE,
    ];

    $form['link_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL del botón'),
      '#default_value' => $cfg['link_url'] ?? '',
      '#description' => $this->t('Dejar vacío para desactivar el botón.'),
    ];

    return $form;
  }

  public function blockValidate($form, FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue('video_url'));
    $provider = (string) $form_state->getValue('video_provider');

    //1.- Validamos que exista una URL y que sea válida en formato absoluto.
    if ($url === '') {
      $form_state->setErrorByName('video_url', $this->t('Debes ingresar la URL del video.'));
      return;
    }
    if (!UrlHelper::isValid($url, TRUE)) {
      $form_state->setErrorByName('video_url', $this->t('La URL no es válida.'));
      return;
    }

    //2.- Determinamos si el proveedor seleccionado coincide con la URL ingresada.
    $match = $provider === 'auto'
      ? $this->detectProvider($url)
      : $this->matchSpecificProvider($provider, $url);
    if (!$match) {
      $form_state->setErrorByName('video_url', $this->t('Proveedor no reconocido para la URL proporcionada.'));
    }

    $form_state->setValue('video_url', $url);
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $cfg = $this->getConfiguration();
    $thumbnail_fid = !empty($v['thumbnail'][0]) ? (int) $v['thumbnail'][0] : NULL;
    $old_thumbnail_fid = $cfg['thumbnail_fid'] ?? NULL;

    if ($old_thumbnail_fid && $old_thumbnail_fid !== $thumbnail_fid) {
      $old_file = \Drupal::entityTypeManager()->getStorage('file')->load($old_thumbnail_fid);
      if ($old_file) {
        //3.- Eliminamos la relación previa para permitir limpiar archivos antiguos.
        \Drupal::service('file.usage')->delete($old_file, 'pds_recipe_latamvideo', 'block', $this->getPluginId());
      }
    }

    if ($thumbnail_fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($thumbnail_fid);
      if ($file) {
        //1.- Aseguramos que el archivo quede permanente para evitar su eliminación.
        $file->setPermanent();
        $file->save();
        //2.- Registramos el uso del archivo para mantener la referencia desde el bloque.
        \Drupal::service('file.usage')->add($file, 'pds_recipe_latamvideo', 'block', $this->getPluginId());
      }
    }

    $this->setConfiguration([
      'title_html'     => (string) $v['title_html'],
      'body_text'      => (string) $v['body_text'],
      'link_text'      => (string) $v['link_text'],
      'link_url'       => (string) ($v['link_url'] ?? ''),
      'video_provider' => (string) $v['video_provider'],
      'video_url'      => trim((string) ($v['video_url'] ?? '')),
      'thumbnail_fid'  => $thumbnail_fid,
      'thumbnail_alt'  => (string) ($v['thumbnail_alt'] ?? ''),
      'autoplay'       => (bool) ($v['options']['autoplay'] ?? FALSE),
      'muted'          => (bool) ($v['options']['muted'] ?? FALSE),
      'controls'       => (bool) ($v['options']['controls'] ?? TRUE),
      'start_sec'      => max(0, (int) ($v['options']['start_sec'] ?? 0)),
    ]);
  }

  private function resolveModuleUri(string $uri): string {
    $scheme = 'module://';
    if (!str_starts_with($uri, $scheme)) {
      return $uri;
    }
    $rest = substr($uri, strlen($scheme));
    [$mod, $rel] = explode('/', $rest, 2) + [null, null];
    if (!$mod || !$rel) {
      return $uri;
    }
    $path = \Drupal::service('extension.list.module')->getPath($mod);
    return base_path() . $path . '/' . ltrim($rel, '/');
  }

  public function build(): array {
    $logo_url = $this->resolveModuleUri('module://pds_recipe_latamvideo/images/logo.png');
    $cfg = $this->getConfiguration();

    $link_url = '';
    if (!empty($cfg['link_url'])) {
      $link_url = str_starts_with($cfg['link_url'], 'http')
        ? $cfg['link_url']
        : Url::fromUri('base:' . ltrim($cfg['link_url'], '/'))->toString();
    }

    //1.- Resolvemos la URL de la miniatura cargada por el editor.
    $thumbnail_src = '';
    if (!empty($cfg['thumbnail_fid'])) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($cfg['thumbnail_fid']);
      if ($file) {
        $thumbnail_src = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
      }
    }

    //2.- Construimos el embed del video usando la fuente seleccionada.
    $embed_html = '';
    if (!empty($cfg['video_url'])) {
      $embed = $this->buildEmbedFromUrl(
        (string) $cfg['video_url'],
        (string) ($cfg['video_provider'] ?? 'auto'),
        (bool) ($cfg['autoplay'] ?? FALSE),
        (bool) ($cfg['muted'] ?? FALSE),
        (bool) ($cfg['controls'] ?? TRUE),
        (int) ($cfg['start_sec'] ?? 0),
      );
      if ($embed !== '') {
        //3.- Marcamos el HTML como seguro porque controlamos los atributos generados.
        $embed_html = Markup::create($embed);
      }
    }

    return [
      '#theme' => 'pds_latamvideo',
      '#attributes' => ['class' => ['pds-educacion', 'pds-embedCallout']],
      '#heading' => Markup::create($cfg['title_html'] ?? ''),
      '#subheading' => $cfg['body_text'] ?? '',
      '#logo_url' => $logo_url,
      '#link_text' => $cfg['link_text'] ?? '',
      '#link_url' => $link_url,
      '#thumbnail_src' => $thumbnail_src,
      '#thumbnail_alt' => $cfg['thumbnail_alt'] ?? '',
      //4.- Entregamos el HTML del video listo para que el frontend lo inserte en el modal.
      '#embed_html' => $embed_html,
      '#attached' => ['library' => ['pds_recipe_latamvideo/educacion']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Return provider key and regex match or null.
   */
  private function detectProvider(string $url): ?array {
    $map = $this->getProviderRegexMap();
    foreach ($map as $key => $rx) {
      if (preg_match($rx, $url, $m)) {
        return [$key, $m];
      }
    }
    return null;
  }

  private function matchSpecificProvider(string $provider, string $url): ?array {
    $map = $this->getProviderRegexMap();
    if (!isset($map[$provider])) {
      return null;
    }
    if (preg_match($map[$provider], $url, $m)) {
      return [$provider, $m];
    }
    return null;
  }

  private function getProviderOptions(): array {
    $labels = [
      'youtube' => $this->t('YouTube'),
      'vimeo' => $this->t('Vimeo'),
      'dailymotion' => $this->t('Dailymotion'),
      'streamable' => $this->t('Streamable'),
      'videopress' => $this->t('VideoPress'),
      'wistia' => $this->t('Wistia'),
      'vidyard' => $this->t('Vidyard'),
      'kaltura' => $this->t('Kaltura'),
    ];

    $options = ['auto' => $this->t('Detectar automáticamente')];
    foreach (array_keys($this->getProviderRegexMap()) as $provider) {
      $options[$provider] = $labels[$provider] ?? ucfirst($provider);
    }
    return $options;
  }

  private function getProviderRegexMap(): array {
    return [
      'youtube' => '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/i',
      'vimeo' => '/vimeo\.com\/(?:video\/)?(\d+)/i',
      'dailymotion' => '/dailymotion\.com\/video\/([a-z0-9]+)/i',
      'streamable' => '/streamable\.com\/([a-z0-9]+)(?:$|[?#])/i',
      'videopress' => '/videopress\.com\/v\/([a-z0-9]+)/i',
      'wistia' => '/(?:wistia\.com|wi\.st)\/.*(?:medias|embed)\/([a-z0-9]+)$/i',
      'vidyard' => '/play\.vidyard\.com\/([A-Za-z0-9_-]+)/i',
      'kaltura' => '/kaltura\.com\/.*entry_id\/([A-Za-z0-9_]+)/i',
    ];
  }

  /**
   * Build responsive iframe HTML for known providers.
   * Returns empty string when unknown.
   */
  private function buildEmbedFromUrl(string $url, string $provider, bool $autoplay, bool $muted, bool $controls, int $start): string {
    //1.- Resolvemos el proveedor ya sea automático o específico.
    $det = $provider === 'auto'
      ? $this->detectProvider($url)
      : $this->matchSpecificProvider($provider, $url);
    if (!$det) {
      return '';
    }
    [$key, $m] = $det;
    $id = $m[1];

    $params = [
      'autoplay' => $autoplay ? '1' : '0',
      'mute'     => $muted ? '1' : '0',
      'controls' => $controls ? '1' : '0',
    ];
    $src = '';

    switch ($key) {
      case 'youtube':
        $params['rel'] = '0';
        $params['modestbranding'] = '1';
        if ($start > 0) {
          $params['start'] = (string) $start;
        }
        $src = 'https://www.youtube-nocookie.com/embed/' . $id . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        break;

      case 'vimeo':
        // Vimeo uses hash for start time.
        $hash = $start > 0 ? '#t=' . max(0, $start) . 's' : '';
        $src = 'https://player.vimeo.com/video/' . $id . $hash;
        break;

      case 'dailymotion':
        $src = 'https://www.dailymotion.com/embed/video/' . $id;
        break;

      case 'streamable':
        $src = 'https://streamable.com/o/' . $id;
        break;

      case 'videopress':
        $src = 'https://videopress.com/embed/' . $id;
        break;

      case 'wistia':
        $src = 'https://fast.wistia.net/embed/iframe/' . $id;
        break;

      case 'vidyard':
        $src = 'https://play.vidyard.com/' . $id . '.html';
        break;

      case 'kaltura':
        // Requires partner and uiconf to be fully correct. Basic entry-only fallback.
        // Replace <partnerId> and <uiconfId> via site config if you use Kaltura.
        $pid = '<partnerId>';
        $uiconf = '<uiconfId>';
        $src = "https://cdnapisec.kaltura.com/p/{$pid}/sp/{$pid}00/embedIframeJs/uiconf_id/{$uiconf}/partner_id/{$pid}?iframeembed=true&entry_id={$id}";
        break;
    }

    if ($src === '') {
      return '';
    }

    // Final HTML. Attributes are escaped. No user HTML is injected.
    $esc = Html::escape($src);
    $allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
    return <<<HTML
<div class="video-embed" style="position:relative;padding-top:56.25%;height:0;overflow:hidden;">
  <iframe src="{$esc}" loading="lazy" allow="{$allow}" allowfullscreen
          style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"></iframe>
</div>
HTML;
  }

}
