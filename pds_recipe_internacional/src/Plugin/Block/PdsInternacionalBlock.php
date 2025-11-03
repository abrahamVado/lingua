<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_internacional\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides the "Principal Internacional" block.
 *
 * @Block(
 *   id = "pds_internacional_block",
 *   admin_label = @Translation("PDS Internacional"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsInternacionalBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'title' => '',
      'fondos' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $raw_fondos = $cfg['fondos'] ?? ($cfg['internacional'] ?? []);
    $fondos_cfg = is_array($raw_fondos) ? $raw_fondos : [];

    //1.- Prepare sanitized card information before exposing it to Twig.
    $nations = [];
    $fallback_icon = $this->buildAssetUrl('assets/images/icon.png');

    //2.- Sanitize each fondo and convert allowed inline HTML to safe Markup.
    foreach ($fondos_cfg as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $name_raw = (string) ($item['title'] ?? ($item['name'] ?? ''));
      $desc_raw = (string) ($item['description'] ?? ($item['desc'] ?? ''));
      $url = $this->sanitizeUrl($item['url'] ?? '');

      $name = $this->cleanText($name_raw);
      $desc = $this->cleanText($desc_raw);

      if ($name === '' && $desc === '' && $url === '') {
        continue;
      }

      //3.- Map the sanitized fondo to the structure expected by the Twig template.
      $nation = [
        'name' => $name,
        'img_url' => $url !== '' ? $url : $fallback_icon,
      ];
      if ($desc !== '') {
        $nation['info_html'] = $this->safeInlineHtml($desc);
      }
      if ($url !== '') {
        $nation['link_url'] = $url;
      }

      $nations[] = $nation;
    }

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? '');

    return [
      '#theme' => 'pds_internacional',
      '#title' => $title,
      '#nations' => $nations,
      '#attached' => [
        'library' => ['pds_recipe_internacional/internacional'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $working = self::getWorkingInternacional($form_state, $cfg['fondos'] ?? ($cfg['internacional'] ?? []));
    $editing_index = self::getEditingIndex($form_state);

    // Active tab tracking.
    $input = $form_state->getUserInput();
    $submitted_tab = is_array($input) && isset($input['internacional_ui_active_tab'])
      ? trim((string) $input['internacional_ui_active_tab'])
      : '';
    $active_tab = $submitted_tab !== ''
      ? $submitted_tab
      : ($form_state->get('pds_recipe_internacional_active_tab') ?? '');
    if ($active_tab === '' && $editing_index !== NULL) {
      $active_tab = 'edit';
    }
    if ($active_tab === '') {
      $active_tab = 'general';
    }
    $form_state->set('pds_recipe_internacional_active_tab', $active_tab);

    if (!$form_state->has('working_fondos')) {
      $form_state->set('working_fondos', $working);
    }
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent_state = $form_state->getCompleteFormState();
      if ($parent_state instanceof FormStateInterface && !$parent_state->has('working_fondos')) {
        $parent_state->set('working_fondos', $working);
      }
    }

    $form['#attached']['library'][] = 'pds_recipe_internacional/admin.vertical_tabs';

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
      'people' => [
        'label' => (string) $this->t('Internacional'),
        'pane_key' => 'people',
        'tab_id' => 'tab-people',
        'pane_id' => 'pane-people',
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
      $form_state->set('pds_recipe_internacional_active_tab', $active_tab);
    }

    // Vertical tabs nav.
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

    $form['internacional_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-internacional-form',
        'class' => ['pds-vertical-tabs'],
        'data-pds-vertical-tabs' => 'true',
      ],
    ];

    $form['internacional_ui']['active_tab'] = [
      '#type' => 'hidden',
      '#value' => $active_tab,
      '#parents' => ['internacional_ui_active_tab'],
      '#attributes' => ['data-pds-vertical-tabs-active' => 'true'],
    ];

    $form['internacional_ui']['menu'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($menu_markup),
    ];

    $form['internacional_ui']['panes'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pds-vertical-tabs__panes']],
    ];

    // General pane.
    $form['internacional_ui']['panes']['general'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('general', 'tab-general', $active_tab),
    ];
    $form['internacional_ui']['panes']['general']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('General'),
    ];
    $form['internacional_ui']['panes']['general']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the section heading shown above the internacional list.'),
    ];
    $form['internacional_ui']['panes']['general']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
      '#parents' => ['title'],
    ];

    // Add pane.
    $form['internacional_ui']['panes']['add_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('add', 'tab-add', $active_tab),
    ];
    $form['internacional_ui']['panes']['add_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add New'),
    ];
    $form['internacional_ui']['panes']['add_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Provide the public information for a new fondo card.'),
    ];

    $form['internacional_ui']['panes']['add_person']['fondo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['internacional_ui']['panes']['add_person']['fondo_desc'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#rows' => 3,
    ];
    $form['internacional_ui']['panes']['add_person']['fondo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
    ];

    $form['internacional_ui']['panes']['add_person']['actions'] = ['#type' => 'actions'];
    $form['internacional_ui']['panes']['add_person']['actions']['add_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add fondo'),
      '#name' => 'pds_recipe_internacional_add_person',
      '#validate' => ['pds_recipe_internacional_add_person_validate'],
      '#submit' => ['pds_recipe_internacional_add_person_submit'],
      '#limit_validation_errors' => [
        ['internacional_ui', 'panes', 'add_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_internacional_ajax_events',
        'wrapper' => 'pds-internacional-form',
      ],
    ];

    // List pane.
    $form['internacional_ui']['panes']['people_list'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('people', 'tab-people', $active_tab),
    ];
    $form['internacional_ui']['panes']['people_list']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Internacional'),
    ];
    $form['internacional_ui']['panes']['people_list']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Review, edit or remove existing cards.'),
    ];

    $form['internacional_ui']['panes']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Title'),
        $this->t('Description'),
        $this->t('URL'),
        $this->t('Edit'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No fondos yet. Add one using the Add New tab.'),
    ];

    foreach ($working as $index => $fondo) {
      if (!is_array($fondo)) {
        continue;
      }
      $name = trim((string) ($fondo['title'] ?? ($fondo['name'] ?? '')));
      $desc_value = trim((string) ($fondo['description'] ?? ($fondo['desc'] ?? '')));
      $url_value = trim((string) ($fondo['url'] ?? ''));

      $form['internacional_ui']['panes']['people_list']['people'][$index]['name'] = [
        '#plain_text' => $name === '' ? $this->t('Untitled @number', ['@number' => $index + 1]) : $name,
      ];
      $form['internacional_ui']['panes']['people_list']['people'][$index]['desc'] = [
        '#plain_text' => $desc_value,
      ];
      $form['internacional_ui']['panes']['people_list']['people'][$index]['url'] = [
        '#plain_text' => $url_value,
      ];
      $form['internacional_ui']['panes']['people_list']['people'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_internacional_edit_person_' . $index,
        '#submit' => ['pds_recipe_internacional_edit_person_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_internacional_ajax_events',
          'wrapper' => 'pds-internacional-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-internacional-edit-person']],
        '#pds_recipe_internacional_edit_index' => $index,
      ];
      $form['internacional_ui']['panes']['people_list']['people'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
      ];
    }

    $form['internacional_ui']['panes']['people_list']['actions'] = ['#type' => 'actions'];
    $form['internacional_ui']['panes']['people_list']['actions']['remove_people'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_internacional_remove_people',
      '#submit' => ['pds_recipe_internacional_remove_people_submit'],
      '#limit_validation_errors' => [
        ['internacional_ui', 'panes', 'people_list', 'people'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_internacional_ajax_events',
        'wrapper' => 'pds-internacional-form',
      ],
    ];

    // Edit pane.
    $form['internacional_ui']['panes']['edit_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('edit', 'tab-edit', $active_tab),
    ];
    $form['internacional_ui']['panes']['edit_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Edit'),
    ];
    $form['internacional_ui']['panes']['edit_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Update the selected fondo card.'),
    ];

    $editing_fondo = $editing_index !== NULL && isset($working[$editing_index]) && is_array($working[$editing_index])
      ? $working[$editing_index]
      : NULL;

    $form['internacional_ui']['panes']['edit_person']['fondo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['title'] ?? ($editing_fondo['name'] ?? '')) : '',
    ];
    $form['internacional_ui']['panes']['edit_person']['fondo_desc'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#rows' => 3,
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['description'] ?? ($editing_fondo['desc'] ?? '')) : '',
    ];
    $form['internacional_ui']['panes']['edit_person']['fondo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['url'] ?? '') : '',
    ];

    $form['internacional_ui']['panes']['edit_person']['actions'] = ['#type' => 'actions'];
    $form['internacional_ui']['panes']['edit_person']['actions']['save_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_internacional_save_person',
      '#validate' => ['pds_recipe_internacional_edit_person_validate'],
      '#submit' => ['pds_recipe_internacional_edit_person_submit'],
      '#limit_validation_errors' => [
        ['internacional_ui', 'panes', 'edit_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_internacional_ajax_events',
        'wrapper' => 'pds-internacional-form',
      ],
    ];
    $form['internacional_ui']['panes']['edit_person']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_internacional_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_internacional_edit_person_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_internacional_ajax_events',
        'wrapper' => 'pds-internacional-form',
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
    $this->configuration['title'] = $submitted_title;

    $internacional = self::getWorkingInternacional($form_state, $cfg['fondos'] ?? []);
    $clean = [];

    foreach ($internacional as $fondo) {
      if (!is_array($fondo)) {
        continue;
      }
      $clean_fondo = $this->cleanFondoConfig($fondo);
      if ($clean_fondo !== NULL) {
        $clean[] = $clean_fondo;
      }
    }

    $this->configuration['fondos'] = array_values($clean);
    unset($this->configuration['internacional']);

    $form_state->set('working_fondos', $this->configuration['fondos']);
  }

  private function buildPaneAttributes(string $pane_key, string $tab_id, string $active_tab): array {
    $attributes = [
      'id' => 'pane-' . $pane_key,
      'class' => ['pds-vertical-tabs__pane'],
      'role' => 'tabpanel',
      'aria-labelledby' => $tab_id,
      'data-pds-vertical-pane' => $pane_key,
    ];
    if ($pane_key !== $active_tab) {
      $attributes['hidden'] = 'hidden';
      $attributes['aria-hidden'] = 'true';
    }
    else {
      $attributes['aria-hidden'] = 'false';
    }
    return $attributes;
  }

  private static function getWorkingInternacional(FormStateInterface $form_state, array $cfg_fondos): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('working_fondos')) {
      $tmp = $form_state->get('working_fondos');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('working_fondos')) {
        $tmp = $parent->get('working_fondos');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    return array_values(is_array($cfg_fondos) ? $cfg_fondos : []);
  }

  private function extractSubmittedString(FormStateInterface $form_state, string $key): string {
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
    foreach ($candidates as $candidate) {
      if (is_string($candidate)) {
        return trim($candidate);
      }
    }
    return '';
  }

  private static function getEditingIndex(FormStateInterface $form_state): ?int {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('pds_recipe_internacional_editing_index')) {
      $index = $form_state->get('pds_recipe_internacional_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_internacional_editing_index')) {
        $index = $parent->get('pds_recipe_internacional_editing_index');
        if (is_numeric($index)) {
          return (int) $index;
        }
      }
    }

    return NULL;
  }

  private function cleanFondoConfig(array $fondo): ?array {
    $name = $this->cleanText($fondo['title'] ?? ($fondo['name'] ?? ''));
    $desc = $this->cleanText($fondo['description'] ?? ($fondo['desc'] ?? ''));
    $url = $this->sanitizeUrl($fondo['url'] ?? '');

    if ($name === '' && $desc === '' && $url === '') {
      return NULL;
    }

    $clean = [];
    if ($name !== '') {
      $clean['title'] = $name;
    }
    if ($desc !== '') {
      $clean['description'] = $desc;
    }
    if ($url !== '') {
      $clean['url'] = $url;
    }

    return $clean === [] ? NULL : $clean;
  }

  private function buildAssetUrl(string $relative_path): string {
    static $module_path = NULL;
    if ($module_path === NULL) {
      $module_path = \Drupal::service('extension.path.resolver')->getPath('module', 'pds_recipe_internacional');
    }
    $base_path = base_path();
    $relative = ltrim($relative_path, '/');
    return $base_path . trim($module_path, '/') . '/' . $relative;
  }

  private function cleanText($value): string {
    return trim(is_string($value) ? $value : '');
  }

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
   * Allow limited inline HTML and return safe Markup.
   */
  private function safeInlineHtml(string $value, array $allowed = ['strong','em','b','i','u','br','span','a','sup','sub']): Markup {
    $filtered = Xss::filter($value, $allowed);
    return Markup::create($filtered);
  }

}
