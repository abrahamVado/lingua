<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_latamvideo\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
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
      'title_html' => 'Educação financeira para Ensino Fundamental e Médio.',
      'body_text'  => 'Desde 2016, mais de 12 mil alunos já participaram das aulas ministradas por nossos colaboradores e parceiros voluntários.',
      'link_text'  => 'Conheça o programa',
      'link_url'   => '',
      // New: raw URL and simple options.
      'video_url'  => '',
      'autoplay'   => FALSE,
      'muted'      => FALSE,
      'controls'   => TRUE,
      'start_sec'  => 0,
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

    // New: URL input.
    $form['video_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL del video'),
      '#default_value' => $cfg['video_url'] ?? '',
      '#description' => $this->t('Pega la URL de YouTube, Vimeo, Dailymotion, Streamable, VideoPress, Wistia, Vidyard o Kaltura.'),
      '#placeholder' => 'https://youtu.be/XXXXXXXXXXX',
      '#required' => FALSE,
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
    if ($url !== '' && !UrlHelper::isValid($url, TRUE)) {
      $form_state->setErrorByName('video_url', $this->t('La URL no es válida.'));
      return;
    }
    if ($url !== '' && !$this->detectProvider($url)) {
      $form_state->setErrorByName('video_url', $this->t('Proveedor no reconocido. Usa YouTube, Vimeo, Dailymotion, Streamable, VideoPress, Wistia, Vidyard o Kaltura.'));
    }
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $this->setConfiguration([
      'title_html' => (string) $v['title_html'],
      'body_text'  => (string) $v['body_text'],
      'link_text'  => (string) $v['link_text'],
      'link_url'   => (string) ($v['link_url'] ?? ''),
      'video_url'  => trim((string) ($v['video_url'] ?? '')),
      'autoplay'   => (bool) ($v['options']['autoplay'] ?? FALSE),
      'muted'      => (bool) ($v['options']['muted'] ?? FALSE),
      'controls'   => (bool) ($v['options']['controls'] ?? TRUE),
      'start_sec'  => max(0, (int) ($v['options']['start_sec'] ?? 0)),
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

    // Build embed HTML or empty string.
    $embed_html = '';
    if (!empty($cfg['video_url'])) {
      $embed = $this->buildEmbedFromUrl(
        (string) $cfg['video_url'],
        (bool) ($cfg['autoplay'] ?? FALSE),
        (bool) ($cfg['muted'] ?? FALSE),
        (bool) ($cfg['controls'] ?? TRUE),
        (int) ($cfg['start_sec'] ?? 0),
      );
      if ($embed !== '') {
        // Mark as safe. We control attributes and host allowlist.
        $embed_html = Markup::create($embed);
      }
    }

    return [
      '#theme' => 'pds_latamvideo',
      '#attributes' => ['class' => ['pds-educacion']],
      '#heading' => ['#markup' => $cfg['title_html'] ?? ''],
      '#subheading' => $cfg['body_text'] ?? '',
      '#logo_url' => $logo_url,
      '#link_text' => $cfg['link_text'] ?? '',
      '#link_url' => $link_url,
      // Pass raw HTML to Twig slot. Keep it isolated in a container.
      '#embed_html' => $embed_html,
      '#attached' => ['library' => ['pds_recipe_latamvideo/educacion']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Return provider key and regex match or null.
   */
  private function detectProvider(string $url): ?array {
    $map = [
      'youtube'     => '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/i',
      'vimeo'       => '/vimeo\.com\/(?:video\/)?(\d+)/i',
      'dailymotion' => '/dailymotion\.com\/video\/([a-z0-9]+)/i',
      'streamable'  => '/streamable\.com\/([a-z0-9]+)(?:$|[?#])/i',
      'videopress'  => '/videopress\.com\/v\/([a-z0-9]+)/i',
      'wistia'      => '/(?:wistia\.com|wi\.st)\/.*(?:medias|embed)\/([a-z0-9]+)$/i',
      'vidyard'     => '/play\.vidyard\.com\/([A-Za-z0-9_-]+)/i',
      'kaltura'     => '/kaltura\.com\/.*entry_id\/([A-Za-z0-9_]+)/i',
    ];
    foreach ($map as $key => $rx) {
      if (preg_match($rx, $url, $m)) {
        return [$key, $m];
      }
    }
    return null;
  }

  /**
   * Build responsive iframe HTML for known providers.
   * Returns empty string when unknown.
   */
  private function buildEmbedFromUrl(string $url, bool $autoplay, bool $muted, bool $controls, int $start): string {
    $det = $this->detectProvider($url);
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
