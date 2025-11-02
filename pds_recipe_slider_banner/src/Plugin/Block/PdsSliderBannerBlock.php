<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_slider_banner\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides the "Principal SliderBanner" block.
 *
 * @Block(
 *   id = "pds_slider_banner_block",
 *   admin_label = @Translation("PDS SliderBanner"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsSliderBannerBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'title' => '',
      'section_id' => 'principal-slider_banner',
      'slides' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $raw_slides = [];
    if (is_array($cfg['slides'] ?? NULL)) {
      $raw_slides = $cfg['slides'];
    }
    elseif (is_array($cfg['slider_banner'] ?? NULL)) {
      $raw_slides = $cfg['slider_banner'];
    }

    $slides = [];

    //1.- Sanitize every configured slide before passing the data to Twig.
    foreach ($raw_slides as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $desktop_src = $this->sanitizeUrl($item['desktop_src'] ?? '');
      $mobile_src = $this->sanitizeUrl($item['mobile_src'] ?? '');
      $alt = trim((string) ($item['alt'] ?? ''));
      $title_html_raw = (string) ($item['title_html'] ?? '');
      $title_html = $this->sanitizeCv($title_html_raw);
      $intro = trim((string) ($item['intro'] ?? ''));
      $cta_label = trim((string) ($item['cta_label'] ?? ''));
      $cta_url = $this->sanitizeUrl($item['cta_url'] ?? '');

      if ($desktop_src === '' && $mobile_src === '' && $alt === '' && $title_html === '' && $intro === '' && $cta_label === '' && $cta_url === '') {
        continue;
      }

      $slides[] = [
        'desktop_src' => $desktop_src,
        'mobile_src' => $mobile_src !== '' ? $mobile_src : $desktop_src,
        'alt' => $alt,
        'title_html' => $title_html,
        'intro' => $intro,
        'cta_label' => $cta_label,
        'cta_url' => $cta_url,
      ];
    }

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? '');
    $section_id = trim((string) ($cfg['section_id'] ?? '')) ?: 'principal-slider_banner';

    return [
      '#theme' => 'pds_slider_banner',
      '#title' => $title,
      '#section_id' => $section_id,
      '#slides' => $slides,
      '#attached' => [
        'library' => [
          'pds_recipe_slider_banner/slider_banner',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $slides_cfg = [];
    if (is_array($cfg['slides'] ?? NULL)) {
      $slides_cfg = $cfg['slides'];
    }
    elseif (is_array($cfg['slider_banner'] ?? NULL)) {
      $slides_cfg = $cfg['slider_banner'];
    }
    $working = self::getWorkingSlides($form_state, $slides_cfg);
    $editing_index = self::getEditingIndex($form_state);

    //1.- Track which tab is active so AJAX rebuilds preserve the author context.
    $input = $form_state->getUserInput();
    $submitted_tab = is_array($input) && isset($input['slider_banner_ui_active_tab'])
      ? trim((string) $input['slider_banner_ui_active_tab'])
      : '';
    $active_tab = $submitted_tab !== ''
      ? $submitted_tab
      : ($form_state->get('pds_recipe_slider_banner_active_tab') ?? '');
    if ($active_tab === '' && $editing_index !== NULL) {
      $active_tab = 'edit';
    }
    if ($active_tab === '') {
      $active_tab = 'general';
    }
    $form_state->set('pds_recipe_slider_banner_active_tab', $active_tab);

    if (!$form_state->has('working_slides')) {
      $form_state->set('working_slides', $working);
    }

    $form['#attached']['library'][] = 'pds_recipe_slider_banner/admin.vertical_tabs';

    $tabs = [
      'general' => [
        'label' => (string) $this->t('General'),
        'pane_key' => 'general',
        'tab_id' => 'tab-general',
        'pane_id' => 'pane-general',
        'access' => TRUE,
      ],
      'add' => [
        'label' => (string) $this->t('Add New'),
        'pane_key' => 'add',
        'tab_id' => 'tab-add',
        'pane_id' => 'pane-add',
        'access' => TRUE,
      ],
      'slides' => [
        'label' => (string) $this->t('Slides'),
        'pane_key' => 'slides',
        'tab_id' => 'tab-slides',
        'pane_id' => 'pane-slides',
        'access' => TRUE,
      ],
      'edit' => [
        'label' => (string) $this->t('Edit'),
        'pane_key' => 'edit',
        'tab_id' => 'tab-edit',
        'pane_id' => 'pane-edit',
        'access' => $editing_index !== NULL,
      ],
    ];

    $available_tabs = array_filter($tabs, static fn(array $tab) => !empty($tab['access']));
    if (!isset($available_tabs[$active_tab])) {
      $active_tab = array_key_first($available_tabs) ?: 'general';
      $form_state->set('pds_recipe_slider_banner_active_tab', $active_tab);
    }

    //2.- Render the Claro-inspired vertical tabs navigation used by other recipes.
    $menu_markup = '<ul class="pds-vertical-tabs__menu" role="tablist" aria-orientation="vertical" data-pds-vertical-tabs-menu="true">';
    foreach ($available_tabs as $machine_name => $tab) {
      $is_selected = $machine_name === $active_tab;
      $li_classes = ['pds-vertical-tabs__menu-item'];
      if ($is_selected) {
        $li_classes[] = 'is-selected';
      }
      $menu_markup .= '<li class="' . implode(' ', $li_classes) . '">';
      $menu_markup .= '<a class="pds-vertical-tabs__menu-link" href="#' . Html::escape($tab['pane_id']) . '" role="tab" id="' . Html::escape($tab['tab_id']) . '" aria-controls="' . Html::escape($tab['pane_id']) . '" aria-selected="' . ($is_selected ? 'true' : 'false') . '" data-pds-vertical-tab="' . Html::escape($tab['pane_key']) . '"';
      if (!$is_selected) {
        $menu_markup .= ' tabindex="-1"';
      }
      $menu_markup .= '>' . Html::escape($tab['label']) . '</a>';
      $menu_markup .= '</li>';
    }
    $menu_markup .= '</ul>';

    $form['slider_banner_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-slider_banner-form',
        'class' => ['pds-vertical-tabs'],
        'data-pds-vertical-tabs' => 'true',
      ],
    ];

    $form['slider_banner_ui']['active_tab'] = [
      '#type' => 'hidden',
      '#value' => $active_tab,
      '#parents' => ['slider_banner_ui_active_tab'],
      '#attributes' => [
        'data-pds-vertical-tabs-active' => 'true',
      ],
    ];

    $form['slider_banner_ui']['menu'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($menu_markup),
    ];

    $form['slider_banner_ui']['panes'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-vertical-tabs__panes'],
      ],
    ];

    //3.- General pane exposes overall section settings.
    $form['slider_banner_ui']['panes']['general'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('general', 'tab-general', $active_tab),
    ];
    $form['slider_banner_ui']['panes']['general']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('General'),
    ];
    $form['slider_banner_ui']['panes']['general']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the section heading and optional HTML id.'),
    ];
    $form['slider_banner_ui']['panes']['general']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
      '#parents' => ['title'],
    ];
    $form['slider_banner_ui']['panes']['general']['section_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section ID'),
      '#default_value' => $cfg['section_id'] ?? 'principal-slider_banner',
      '#description' => $this->t('DOM id attribute. Must be unique on the page.'),
      '#parents' => ['section_id'],
    ];

    //4.- Add pane lets authors create a new slide entry.
    $form['slider_banner_ui']['panes']['add_slide'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('add', 'tab-add', $active_tab),
    ];
    $form['slider_banner_ui']['panes']['add_slide']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add New'),
    ];
    $form['slider_banner_ui']['panes']['add_slide']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Provide the public information for a new hero slide.'),
    ];

    $form['slider_banner_ui']['panes']['add_slide']['slide_desktop_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Desktop image URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_desktop_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Desktop image upload'),
      '#description' => $this->t('Upload an image instead of entering a URL.'),
      '#upload_location' => 'public://pds-slider_banner',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_mobile_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mobile image URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_mobile_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Mobile image upload'),
      '#description' => $this->t('Upload an image instead of entering a URL.'),
      '#upload_location' => 'public://pds-slider_banner',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternative text'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_title_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title (HTML)'),
      '#description' => $this->t('Allowed tags: @tags', ['@tags' => implode(', ', $this->allowedCvTags())]),
      '#rows' => 4,
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Intro'),
      '#rows' => 3,
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_cta_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA label'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['slider_banner_ui']['panes']['add_slide']['slide_cta_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];

    $form['slider_banner_ui']['panes']['add_slide']['actions'] = ['#type' => 'actions'];
    $form['slider_banner_ui']['panes']['add_slide']['actions']['add_slide'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add slide'),
      '#name' => 'pds_recipe_slider_banner_add_slide',
      '#validate' => ['pds_recipe_slider_banner_add_slide_validate'],
      '#submit' => ['pds_recipe_slider_banner_add_slide_submit'],
      '#limit_validation_errors' => [
        ['slider_banner_ui', 'panes', 'add_slide'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_slider_banner_ajax_events',
        'wrapper' => 'pds-slider_banner-form',
      ],
    ];

    //5.- Slides pane lists existing entries and exposes edit/remove actions.
    $form['slider_banner_ui']['panes']['slides_list'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('slides', 'tab-slides', $active_tab),
    ];
    $form['slider_banner_ui']['panes']['slides_list']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Slides'),
    ];
    $form['slider_banner_ui']['panes']['slides_list']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Review, edit or remove existing slides.'),
    ];

    $form['slider_banner_ui']['panes']['slides_list']['slides'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Title'),
        $this->t('CTA label'),
        $this->t('CTA URL'),
        $this->t('Edit'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No slides yet. Add one using the Add New tab.'),
    ];

    foreach ($working as $index => $slide) {
      if (!is_array($slide)) {
        continue;
      }
      $title_value = trim(strip_tags((string) ($slide['title_html'] ?? '')));
      $cta_label_value = trim((string) ($slide['cta_label'] ?? ''));
      $cta_url_value = trim((string) ($slide['cta_url'] ?? ''));

      $form['slider_banner_ui']['panes']['slides_list']['slides'][$index]['title'] = [
        '#plain_text' => $title_value === '' ? $this->t('Untitled @number', ['@number' => $index + 1]) : $title_value,
      ];
      $form['slider_banner_ui']['panes']['slides_list']['slides'][$index]['cta_label'] = [
        '#plain_text' => $cta_label_value,
      ];
      $form['slider_banner_ui']['panes']['slides_list']['slides'][$index]['cta_url'] = [
        '#plain_text' => $cta_url_value,
      ];
      $form['slider_banner_ui']['panes']['slides_list']['slides'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_slider_banner_edit_slide_' . $index,
        '#submit' => ['pds_recipe_slider_banner_edit_slide_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_slider_banner_ajax_events',
          'wrapper' => 'pds-slider_banner-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-slider-banner-edit-slide']],
        '#pds_recipe_slider_banner_edit_index' => $index,
      ];
      $form['slider_banner_ui']['panes']['slides_list']['slides'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
      ];
    }

    $form['slider_banner_ui']['panes']['slides_list']['actions'] = ['#type' => 'actions'];
    $form['slider_banner_ui']['panes']['slides_list']['actions']['remove_slides'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_slider_banner_remove_slides',
      '#submit' => ['pds_recipe_slider_banner_remove_slides_submit'],
      '#limit_validation_errors' => [
        ['slider_banner_ui', 'panes', 'slides_list', 'slides'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_slider_banner_ajax_events',
        'wrapper' => 'pds-slider_banner-form',
      ],
    ];

    //6.- Edit pane mirrors the Add pane but pre-fills the selected slide.
    $form['slider_banner_ui']['panes']['edit_slide'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('edit', 'tab-edit', $active_tab),
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Edit'),
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Update the selected slide.'),
    ];

    $editing_slide = $editing_index !== NULL && isset($working[$editing_index]) && is_array($working[$editing_index])
      ? $working[$editing_index]
      : NULL;

    $form['slider_banner_ui']['panes']['edit_slide']['slide_desktop_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Desktop image URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['desktop_src'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_desktop_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Desktop image upload'),
      '#description' => $this->t('Upload a new image to replace the current one.'),
      '#upload_location' => 'public://pds-slider_banner',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_mobile_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mobile image URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['mobile_src'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_mobile_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Mobile image upload'),
      '#description' => $this->t('Upload a new image to replace the current one.'),
      '#upload_location' => 'public://pds-slider_banner',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternative text'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['alt'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_title_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title (HTML)'),
      '#description' => $this->t('Allowed tags: @tags', ['@tags' => implode(', ', $this->allowedCvTags())]),
      '#rows' => 4,
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['title_html'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Intro'),
      '#rows' => 3,
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['intro'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_cta_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA label'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['cta_label'] ?? '') : '',
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['slide_cta_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CTA URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_slide) ? (string) ($editing_slide['cta_url'] ?? '') : '',
    ];

    $form['slider_banner_ui']['panes']['edit_slide']['actions'] = ['#type' => 'actions'];
    $form['slider_banner_ui']['panes']['edit_slide']['actions']['save_slide'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_slider_banner_save_slide',
      '#validate' => ['pds_recipe_slider_banner_edit_slide_validate'],
      '#submit' => ['pds_recipe_slider_banner_edit_slide_submit'],
      '#limit_validation_errors' => [
        ['slider_banner_ui', 'panes', 'edit_slide'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_slider_banner_ajax_events',
        'wrapper' => 'pds-slider_banner-form',
      ],
    ];
    $form['slider_banner_ui']['panes']['edit_slide']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_slider_banner_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_slider_banner_edit_slide_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_slider_banner_ajax_events',
        'wrapper' => 'pds-slider_banner-form',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();

    $submitted_title = $this->extractSubmittedString($form_state, 'title');
    $submitted_section_id = $this->extractSubmittedString($form_state, 'section_id');

    //1.- Persist the sanitized section heading while allowing empty titles to
    //    fall back to the default block label.
    $this->configuration['title'] = $submitted_title;

    //2.- Guarantee a predictable DOM id even when the form omits the field.
    $this->configuration['section_id'] = $submitted_section_id !== ''
      ? $submitted_section_id
      : 'principal-slider_banner';

    $slides_cfg = [];
    if (is_array($cfg['slides'] ?? NULL)) {
      $slides_cfg = $cfg['slides'];
    }
    elseif (is_array($cfg['slider_banner'] ?? NULL)) {
      $slides_cfg = $cfg['slider_banner'];
    }
    $slides = self::getWorkingSlides($form_state, $slides_cfg);
    $clean = [];

    //1.- Persist sanitized slide definitions.
    foreach ($slides as $slide) {
      if (!is_array($slide)) {
        continue;
      }
      $clean_slide = $this->cleanSlideConfig($slide);
      if ($clean_slide !== NULL) {
        $clean[] = $clean_slide;
      }
    }

    $this->configuration['slides'] = array_values($clean);
    unset($this->configuration['slider_banner']);

    $form_state->set('working_slides', $this->configuration['slides']);
  }

  /**
   * Build a consistent attribute set for a tab pane.
   */
  private function buildPaneAttributes(string $pane_key, string $tab_id, string $active_tab): array {
    //1.- Seed default accessibility attributes shared by every pane.
    $attributes = [
      'id' => 'pane-' . $pane_key,
      'class' => ['pds-vertical-tabs__pane'],
      'role' => 'tabpanel',
      'aria-labelledby' => $tab_id,
      'data-pds-vertical-pane' => $pane_key,
    ];

    //2.- Hide panes that are not active so CSS can mimic Drupal Claro behavior.
    if ($pane_key !== $active_tab) {
      $attributes['hidden'] = 'hidden';
      $attributes['aria-hidden'] = 'true';
    }
    else {
      $attributes['aria-hidden'] = 'false';
    }

    return $attributes;
  }

  /**
   * Resolve the current list of slides during form interaction.
   */
  private static function getWorkingSlides(FormStateInterface $form_state, array $cfg_slides): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('working_slides')) {
      $tmp = $form_state->get('working_slides');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('working_slides')) {
        $tmp = $parent->get('working_slides');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    return array_values(is_array($cfg_slides) ? $cfg_slides : []);
  }

  /**
   * Extract a scalar string value from the form state across nesting levels.
   */
  private function extractSubmittedString(FormStateInterface $form_state, string $key): string {
    //1.- Compile every plausible location for the requested value, accounting
    //    for Layout Builder subforms that wrap configuration under settings.
    $candidates = [];
    $direct_value = $form_state->getValue($key);
    if ($direct_value !== NULL) {
      $candidates[] = $direct_value;
    }
    $settings_value = $form_state->getValue(['settings', $key]);
    if ($settings_value !== NULL) {
      $candidates[] = $settings_value;
    }
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent_state = $form_state->getCompleteFormState();
      if ($parent_state instanceof FormStateInterface) {
        $parent_direct = $parent_state->getValue($key);
        if ($parent_direct !== NULL) {
          $candidates[] = $parent_direct;
        }
        $parent_settings = $parent_state->getValue(['settings', $key]);
        if ($parent_settings !== NULL) {
          $candidates[] = $parent_settings;
        }
      }
    }

    //2.- Return the first string candidate after trimming whitespace.
    foreach ($candidates as $candidate) {
      if (is_string($candidate)) {
        return trim($candidate);
      }
    }

    return '';
  }

  /**
   * Determine which slide is currently being edited, if any.
   */
  private static function getEditingIndex(FormStateInterface $form_state): ?int {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('pds_recipe_slider_banner_editing_index')) {
      $index = $form_state->get('pds_recipe_slider_banner_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_slider_banner_editing_index')) {
        $index = $parent->get('pds_recipe_slider_banner_editing_index');
        if (is_numeric($index)) {
          return (int) $index;
        }
      }
    }

    return NULL;
  }

  /**
   * Clean up a slide array before saving it in configuration.
   */
  private function cleanSlideConfig(array $slide): ?array {
    $desktop = $this->sanitizeUrl($slide['desktop_src'] ?? '');
    $mobile = $this->sanitizeUrl($slide['mobile_src'] ?? '');
    $alt = trim((string) ($slide['alt'] ?? ''));
    $title_html = $this->sanitizeCv((string) ($slide['title_html'] ?? ''));
    $intro = trim((string) ($slide['intro'] ?? ''));
    $cta_label = trim((string) ($slide['cta_label'] ?? ''));
    $cta_url = $this->sanitizeUrl($slide['cta_url'] ?? '');

    if ($desktop === '' && $mobile === '' && $alt === '' && $title_html === '' && $intro === '' && $cta_label === '' && $cta_url === '') {
      return NULL;
    }

    $clean = [];
    if ($desktop !== '') {
      $clean['desktop_src'] = $desktop;
    }
    if ($mobile !== '') {
      $clean['mobile_src'] = $mobile;
    }
    if ($alt !== '') {
      $clean['alt'] = $alt;
    }
    if ($title_html !== '') {
      $clean['title_html'] = $title_html;
    }
    if ($intro !== '') {
      $clean['intro'] = $intro;
    }
    if ($cta_label !== '') {
      $clean['cta_label'] = $cta_label;
    }
    if ($cta_url !== '') {
      $clean['cta_url'] = $cta_url;
    }

    if (isset($slide['desktop_file_fid']) && is_numeric($slide['desktop_file_fid'])) {
      $clean['desktop_file_fid'] = (int) $slide['desktop_file_fid'];
    }
    if (isset($slide['mobile_file_fid']) && is_numeric($slide['mobile_file_fid'])) {
      $clean['mobile_file_fid'] = (int) $slide['mobile_file_fid'];
    }

    return $clean === [] ? NULL : $clean;
  }

  /**
   * Sanitize and normalize CV markup.
   */
  private function sanitizeCv(string $cv_html): string {
    $cv_html = trim($cv_html);
    if ($cv_html === '') {
      return '';
    }
    $filtered = Xss::filter($cv_html, $this->allowedCvTags());
    return $filtered !== '' ? $filtered : Html::escape($cv_html);
  }

  /**
   * Restrict links to safe URL schemes.
   */
  private function sanitizeUrl($value): string {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
      return '';
    }

    $filtered = UrlHelper::filterBadProtocol($value);
    if (UrlHelper::isValid($filtered, TRUE) || UrlHelper::isValid($filtered, FALSE)) {
      return $filtered;
    }

    if (strpos($filtered, '/') === 0) {
      return $filtered;
    }

    return '';
  }

  /**
   * Allowed tags for the CV field.
   */
  private function allowedCvTags(): array {
    return [
      'a', 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'span', 'div', 'h3', 'h4', 'h5', 'h6', 'b', 'i', 'u'
    ];
  }

}
