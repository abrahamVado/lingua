<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_slider_banner\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Banner Slider block with multi-slide config.
 *
 * @Block(
 *   id = "pds_slider_banner_block",
 *   admin_label = @Translation("PDS Slider Banner Hero"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsSliderBannerBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * slides is an associative array keyed by slide_id.
   * Each value stores desktop_fid, mobile_fid, text, etc.
   */
  public function defaultConfiguration(): array {
    return [
      'slides' => [],
    ];
  }

  /**
   * Return merged config + defaults.
   */
  public function getConfiguration(): array {
    return $this->configuration + $this->defaultConfiguration();
  }

  /**
   * Create a stable unique ID for a new slide.
   */
  private static function generateSlideId(): string {
    // uniqid is fine. Keep it short for readability.
    return 'slide_' . substr(uniqid('', true), 0, 8);
  }

  /**
   * Build the "Nuevo slide" tab form.
   *
   * This does NOT edit existing slides. It only prepares one new slide.
   */
  private function buildTabNewSlide(array &$form, array $cfg): void {
    $form['tab_new_slide'] = [
      '#type' => 'details',
      '#title' => $this->t('Nuevo slide'),
      '#group' => 'pds_slider_tabs',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    // We namespace fields under 'new_slide' so we can read them cleanly.
    $form['tab_new_slide']['new_slide'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $form['tab_new_slide']['new_slide']['image_desktop_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagen Desktop (>=768px)'),
      '#upload_location' => 'public://pds_slider_banner/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg webp avif'],
      ],
      '#description' => $this->t('Imagen para pantallas grandes.'),
    ];

    $form['tab_new_slide']['new_slide']['image_mobile_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagen Mobile'),
      '#upload_location' => 'public://pds_slider_banner/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg webp avif'],
      ],
      '#description' => $this->t('Imagen mobile o fallback <img>.'),
    ];

    $form['tab_new_slide']['new_slide']['alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alt'),
      '#maxlength' => 255,
    ];

    $form['tab_new_slide']['new_slide']['title_html'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título (HTML permitido)'),
      '#maxlength' => 512,
    ];

    $form['tab_new_slide']['new_slide']['intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Intro'),
      '#rows' => 2,
    ];

    $form['tab_new_slide']['new_slide']['cta_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA label'),
      '#maxlength' => 255,
    ];

    $form['tab_new_slide']['new_slide']['cta_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA URL'),
      '#maxlength' => 1024,
    ];

    $form['tab_new_slide']['help'] = [
      '#markup' => $this->t('Rellena los campos y haz clic en Guardar/Actualizar bloque para añadir este slide a la lista.'),
    ];
  }

  /**
   * Build the "Slides actuales" tab form.
   *
   * Shows all saved slides. Allows marking rows for removal.
   */
  private function buildTabSlidesList(array &$form, array $cfg): void {
    $slides = $cfg['slides'] ?? [];
    if (!is_array($slides)) {
      $slides = [];
    }

    $form['tab_slides_list'] = [
      '#type' => 'details',
      '#title' => $this->t('Slides actuales'),
      '#group' => 'pds_slider_tabs',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['tab_slides_list']['slides_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Eliminar'),
        $this->t('ID'),
        $this->t('Desktop fid'),
        $this->t('Mobile fid'),
        $this->t('Título / Intro / CTA'),
        $this->t('CTA URL'),
      ],
      '#empty' => $this->t('No hay slides guardados.'),
      '#tree' => TRUE,
    ];

    foreach ($slides as $slide_id => $row) {
      // Normalize.
      $desktop_fid = $row['image_desktop_fid'] ?? NULL;
      $mobile_fid  = $row['image_mobile_fid'] ?? NULL;
      $title_html  = $row['title_html'] ?? '';
      $intro       = $row['intro'] ?? '';
      $cta_label   = $row['cta_label'] ?? '';
      $cta_url     = $row['cta_url'] ?? '';

      // One table row per slide.
      $form['tab_slides_list']['slides_table'][$slide_id]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Eliminar'),
        '#title_display' => 'invisible',
      ];

      // Show the ID in a read-only way and keep it in a hidden for submit.
      $form['tab_slides_list']['slides_table'][$slide_id]['slide_id_view'] = [
        '#markup' => '<code>' . $slide_id . '</code>',
      ];
      $form['tab_slides_list']['slides_table'][$slide_id]['slide_id'] = [
        '#type' => 'hidden',
        '#value' => $slide_id,
      ];

      $form['tab_slides_list']['slides_table'][$slide_id]['desktopsrc'] = [
        '#markup' => $desktop_fid ? (int) $desktop_fid : '-',
      ];

      $form['tab_slides_list']['slides_table'][$slide_id]['mobilesrc'] = [
        '#markup' => $mobile_fid ? (int) $mobile_fid : '-',
      ];

      $title_preview = $title_html !== '' ? $title_html : '[sin título]';
      $intro_preview = $intro !== '' ? $intro : '';
      $cta_preview   = $cta_label !== '' ? $cta_label : '';

      $form['tab_slides_list']['slides_table'][$slide_id]['text_preview'] = [
        '#markup' => '<div>'
          . '<div><strong>' . $this->escapeAdmin($title_preview) . '</strong></div>'
          . '<div>' . $this->escapeAdmin($intro_preview) . '</div>'
          . '<div>' . $this->t('CTA:') . ' ' . $this->escapeAdmin($cta_preview) . '</div>'
          . '</div>',
      ];

      $form['tab_slides_list']['slides_table'][$slide_id]['cta_url'] = [
        '#markup' => $cta_url !== '' ? $this->escapeAdmin($cta_url) : '-',
      ];
    }

    $form['tab_slides_list']['help'] = [
      '#markup' => $this->t('Marca "Eliminar" y luego guarda el bloque para borrar esos slides.'),
    ];
  }

  /**
   * Minimal HTML escape for admin preview.
   */
  private function escapeAdmin(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * {@inheritdoc}
   *
   * The admin UI. Adds two tabs:
   *   - new slide
   *   - list of existing slides
   *
   * Uses core vertical_tabs group.
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $cfg = $this->getConfiguration();

    // Container for vertical tabs.
    $form['pds_slider_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Configuración del slider'),
    ];

    // Tab "Nuevo slide".
    $this->buildTabNewSlide($form, $cfg);

    // Tab "Slides actuales".
    $this->buildTabSlidesList($form, $cfg);

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * On save:
   * 1. Read new_slide data. If present (has any content) create new slide_id and add to config.
   * 2. Read slides_table remove checkboxes. Unset those slide_ids.
   * 3. Mark any new files permanent.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();

    if (!isset($cfg['slides']) || !is_array($cfg['slides'])) {
      $cfg['slides'] = [];
    }

    // 1. Add new slide if there is meaningful content.
    $new_slide = $form_state->getValue(['tab_new_slide', 'new_slide']);
    if (is_array($new_slide)) {
      $desktop_fid = NULL;
      if (!empty($new_slide['image_desktop_fid'][0])) {
        $desktop_fid = (int) $new_slide['image_desktop_fid'][0];
        $this->markFilePermanent($desktop_fid);
      }

      $mobile_fid = NULL;
      if (!empty($new_slide['image_mobile_fid'][0])) {
        $mobile_fid = (int) $new_slide['image_mobile_fid'][0];
        $this->markFilePermanent($mobile_fid);
      }

      $alt        = trim($new_slide['alt'] ?? '');
      $title_html = trim($new_slide['title_html'] ?? '');
      $intro      = trim($new_slide['intro'] ?? '');
      $cta_label  = trim($new_slide['cta_label'] ?? '');
      $cta_url    = trim($new_slide['cta_url'] ?? '');

      $has_any =
        $desktop_fid ||
        $mobile_fid ||
        $alt !== '' ||
        $title_html !== '' ||
        $intro !== '' ||
        $cta_label !== '' ||
        $cta_url !== '';

      if ($has_any) {
        $new_id = self::generateSlideId();
        $cfg['slides'][$new_id] = [
          'image_desktop_fid' => $desktop_fid,
          'image_mobile_fid'  => $mobile_fid,
          'alt'               => $alt,
          'title_html'        => $title_html,
          'intro'             => $intro,
          'cta_label'         => $cta_label,
          'cta_url'           => $cta_url,
        ];
      }
    }

    // 2. Remove slides that were checked.
    $slides_table = $form_state->getValue(['tab_slides_list', 'slides_table']);
    if (is_array($slides_table) && $slides_table) {
      foreach ($slides_table as $slide_id => $row) {
        if (!empty($row['remove'])) {
          unset($cfg['slides'][$slide_id]);
        }
      }
    }

    // 3. Save cleaned config back to this block instance.
    $this->configuration['slides'] = $cfg['slides'];
  }

  /**
   * Promote uploaded file to permanent and register usage
   * so files do not get reaped as temporary.
   */
  private function markFilePermanent(?int $fid): void {
    if (!$fid) {
      return;
    }
    $file = File::load($fid);
    if ($file) {
      if ($file->isTemporary()) {
        $file->setPermanent();
        $file->save();
      }
      \Drupal::service('file.usage')->add(
        $file,
        'pds_recipe_slider_banner',
        'block',
        $this->getPluginId()
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * Frontend render for the actual block on the site.
   * Converts stored slide info to URLs and passes to theme.
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $slides_conf = $cfg['slides'] ?? [];

    $file_url_generator = \Drupal::service('file_url_generator');

    $render_slides = [];
    foreach ($slides_conf as $slide_id => $slide) {
      $desktop_url = '';
      if (!empty($slide['image_desktop_fid'])) {
        $file_desktop = File::load($slide['image_desktop_fid']);
        if ($file_desktop) {
          $desktop_url = $file_url_generator->generateAbsoluteString($file_desktop->getFileUri());
        }
      }

      $mobile_url = '';
      if (!empty($slide['image_mobile_fid'])) {
        $file_mobile = File::load($slide['image_mobile_fid']);
        if ($file_mobile) {
          $mobile_url = $file_url_generator->generateAbsoluteString($file_mobile->getFileUri());
        }
      }

      $alt        = $slide['alt'] ?? '';
      $title_html = $slide['title_html'] ?? '';
      $intro      = $slide['intro'] ?? '';
      $cta_label  = $slide['cta_label'] ?? '';
      $cta_url    = $slide['cta_url'] ?? '';

      $has_any =
        $desktop_url !== '' ||
        $mobile_url !== '' ||
        $title_html !== '' ||
        $intro !== '' ||
        $cta_label !== '' ||
        $cta_url !== '';

      if (!$has_any) {
        continue;
      }

      $render_slides[] = [
        'image_desktop' => $desktop_url,
        'image_mobile'  => $mobile_url,
        'alt'           => $alt,
        'title_html'    => $title_html,
        'intro'         => $intro,
        'cta_label'     => $cta_label,
        'cta_url'       => $cta_url,
      ];
    }

    // Global Slick config from module config.
    $cfg_mod = \Drupal::config('pds_recipe_slider_banner.settings');
    $autoplay       = $cfg_mod->get('slick_autoplay');
    $autoplay_speed = $cfg_mod->get('slick_autoplay_speed');
    $pause_on_hover = $cfg_mod->get('slick_pause_on_hover');

    $autoplay       = is_null($autoplay)       ? TRUE  : (bool) $autoplay;
    $autoplay_speed = is_null($autoplay_speed) ? 6000  : (int)  $autoplay_speed;
    $pause_on_hover = is_null($pause_on_hover) ? TRUE  : (bool) $pause_on_hover;

    return [
      '#theme' => 'pds_slider_banner',
      '#slides' => $render_slides,
      '#attached' => [
        'library' => [
          'pds_recipe_slider_banner/pds_slider_banner',
        ],
        'drupalSettings' => [
          'pdsSliderBanner' => [
            'autoplay'       => $autoplay,
            'autoplaySpeed'  => $autoplay_speed,
            'pauseOnHover'   => $pause_on_hover,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
