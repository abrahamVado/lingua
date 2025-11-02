<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_fondos_mutuos\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides the "Principal FondosMutuos" block.
 *
 * @Block(
 *   id = "pds_fondos_mutuos_block",
 *   admin_label = @Translation("PDS FondosMutuos"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsFondosMutuosBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array { 
    return [
      'title' => '',
      'subtitle' => '',
      'fondos' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $raw_fondos = $cfg['fondos'] ?? ($cfg['fondos_mutuos'] ?? []);
    $fondos_cfg = is_array($raw_fondos) ? $raw_fondos : [];

    $fondos = [];

    //1.- Sanitize every configured fondo before passing the data to Twig.
    foreach ($fondos_cfg as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $name = $this->cleanText($item['name'] ?? '');
      $desc = $this->cleanText($item['desc'] ?? '');
      $url = $this->sanitizeUrl($item['url'] ?? '');
      $icon_src = $this->sanitizeUrl($item['icon_src'] ?? '');
      $icon_alt = $this->cleanText($item['icon_alt'] ?? '');
      $arrow_src = $this->sanitizeUrl($item['arrow_src'] ?? '');
      $arrow_alt = $this->cleanText($item['arrow_alt'] ?? '');

      if ($name === '' && $desc === '' && $icon_src === '' && $url === '' && $arrow_src === '') {
        continue;
      }

      $fondo = [
        'name' => $name,
        'desc' => $desc,
      ];
      if ($url !== '') {
        $fondo['url'] = $url;
      }
      if ($icon_src !== '') {
        $fondo['icon_src'] = $icon_src;
      }
      if ($icon_alt !== '') {
        $fondo['icon_alt'] = $icon_alt;
      }
      if ($arrow_src !== '') {
        $fondo['arrow_src'] = $arrow_src;
      }
      if ($arrow_alt !== '') {
        $fondo['arrow_alt'] = $arrow_alt;
      }

      $fondos[] = $fondo;
    }

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? '');
    $subtitle = trim((string) ($cfg['subtitle'] ?? ''));

    return [
      '#theme' => 'pds_fondos_mutuos',
      '#title' => $title,
      '#subtitle' => $subtitle,
      '#fondos' => $fondos,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $working = self::getWorkingFondosMutuos($form_state, $cfg['fondos'] ?? ($cfg['fondos_mutuos'] ?? []));
    $editing_index = self::getEditingIndex($form_state);

    //1.- Track which tab is active so AJAX rebuilds preserve the author context.
    $input = $form_state->getUserInput();
    $submitted_tab = is_array($input) && isset($input['fondos_mutuos_ui_active_tab'])
      ? trim((string) $input['fondos_mutuos_ui_active_tab'])
      : '';
    $active_tab = $submitted_tab !== ''
      ? $submitted_tab
      : ($form_state->get('pds_recipe_fondos_mutuos_active_tab') ?? '');
    if ($active_tab === '' && $editing_index !== NULL) {
      $active_tab = 'edit';
    }
    if ($active_tab === '') {
      $active_tab = 'general';
    }
    $form_state->set('pds_recipe_fondos_mutuos_active_tab', $active_tab);

    if (!$form_state->has('working_fondos')) {
      $form_state->set('working_fondos', $working);
    }
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent_state = $form_state->getCompleteFormState();
      if ($parent_state instanceof FormStateInterface && !$parent_state->has('working_fondos')) {
        $parent_state->set('working_fondos', $working);
      }
    }

    $form['#attached']['library'][] = 'pds_recipe_fondos_mutuos/admin.vertical_tabs';

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
        'label' => (string) $this->t('FondosMutuos'),
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
      $form_state->set('pds_recipe_fondos_mutuos_active_tab', $active_tab);
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

    $form['fondos_mutuos_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-fondos_mutuos-form',
        'class' => ['pds-vertical-tabs'],
        'data-pds-vertical-tabs' => 'true',
      ],
    ];

    $form['fondos_mutuos_ui']['active_tab'] = [
      '#type' => 'hidden',
      '#value' => $active_tab,
      '#parents' => ['fondos_mutuos_ui_active_tab'],
      '#attributes' => [
        'data-pds-vertical-tabs-active' => 'true',
      ],
    ];

    $form['fondos_mutuos_ui']['menu'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($menu_markup),
    ];

    $form['fondos_mutuos_ui']['panes'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-vertical-tabs__panes'],
      ],
    ];

    //3.- General pane exposes overall section settings.
    $form['fondos_mutuos_ui']['panes']['general'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('general', 'tab-general', $active_tab),
    ];
    $form['fondos_mutuos_ui']['panes']['general']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('General'),
    ];
    $form['fondos_mutuos_ui']['panes']['general']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the section heading and optional subtitle.'),
    ];
    $form['fondos_mutuos_ui']['panes']['general']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
      '#parents' => ['title'],
    ];
    $form['fondos_mutuos_ui']['panes']['general']['subtitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subtitle'),
      '#default_value' => $cfg['subtitle'] ?? '',
      '#parents' => ['subtitle'],
    ];

    //4.- Add pane lets authors create a new executive entry.
    $form['fondos_mutuos_ui']['panes']['add_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('add', 'tab-add', $active_tab),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add New'),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Provide the public information for a new fondo card.'),
    ];

    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_desc'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#rows' => 3,
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_icon_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon image URL'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#description' => $this->t('Provide an absolute or theme-relative URL for the icon.'),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_icon_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon alt text'),
      '#description' => $this->t('Optional accessible description for the icon.'),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_arrow_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arrow image URL'),
      '#description' => $this->t('Optional absolute or theme-relative URL for the arrow graphic.'),
    ];
    $form['fondos_mutuos_ui']['panes']['add_person']['fondo_arrow_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arrow alt text'),
      '#description' => $this->t('Optional accessible description for the arrow graphic.'),
    ];

    $form['fondos_mutuos_ui']['panes']['add_person']['actions'] = ['#type' => 'actions'];
    $form['fondos_mutuos_ui']['panes']['add_person']['actions']['add_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add fondo'),
      '#name' => 'pds_recipe_fondos_mutuos_add_person',
      '#validate' => ['pds_recipe_fondos_mutuos_add_person_validate'],
      '#submit' => ['pds_recipe_fondos_mutuos_add_person_submit'],
      '#limit_validation_errors' => [
        ['fondos_mutuos_ui', 'panes', 'add_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_fondos_mutuos_ajax_events',
        'wrapper' => 'pds-fondos_mutuos-form',
      ],
    ];

    //5.- People pane lists existing fondos cards and exposes edit/remove actions.
    $form['fondos_mutuos_ui']['panes']['people_list'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('people', 'tab-people', $active_tab),
    ];
    $form['fondos_mutuos_ui']['panes']['people_list']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('FondosMutuos'),
    ];
    $form['fondos_mutuos_ui']['panes']['people_list']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Review, edit or remove existing cards.'),
    ];

    $form['fondos_mutuos_ui']['panes']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Name'),
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
      $name = trim((string) ($fondo['name'] ?? ''));
      $desc_value = trim((string) ($fondo['desc'] ?? ''));
      $url_value = trim((string) ($fondo['url'] ?? ''));

      $form['fondos_mutuos_ui']['panes']['people_list']['people'][$index]['name'] = [
        '#plain_text' => $name === '' ? $this->t('Unnamed @number', ['@number' => $index + 1]) : $name,
      ];
      $form['fondos_mutuos_ui']['panes']['people_list']['people'][$index]['desc'] = [
        '#plain_text' => $desc_value,
      ];
      $form['fondos_mutuos_ui']['panes']['people_list']['people'][$index]['url'] = [
        '#plain_text' => $url_value,
      ];
      $form['fondos_mutuos_ui']['panes']['people_list']['people'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_fondos_mutuos_edit_person_' . $index,
        '#submit' => ['pds_recipe_fondos_mutuos_edit_person_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_fondos_mutuos_ajax_events',
          'wrapper' => 'pds-fondos_mutuos-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-fondos-mutuos-edit-person']],
        '#pds_recipe_fondos_mutuos_edit_index' => $index,
      ];
      $form['fondos_mutuos_ui']['panes']['people_list']['people'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
      ];
    }

    $form['fondos_mutuos_ui']['panes']['people_list']['actions'] = ['#type' => 'actions'];
    $form['fondos_mutuos_ui']['panes']['people_list']['actions']['remove_people'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_fondos_mutuos_remove_people',
      '#submit' => ['pds_recipe_fondos_mutuos_remove_people_submit'],
      '#limit_validation_errors' => [
        ['fondos_mutuos_ui', 'panes', 'people_list', 'people'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_fondos_mutuos_ajax_events',
        'wrapper' => 'pds-fondos_mutuos-form',
      ],
    ];

    //6.- Edit pane mirrors the Add pane but pre-fills the selected executive.
    $form['fondos_mutuos_ui']['panes']['edit_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('edit', 'tab-edit', $active_tab),
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Edit'),
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Update the selected fondo card.'),
    ];

    $editing_fondo = $editing_index !== NULL && isset($working[$editing_index]) && is_array($working[$editing_index])
      ? $working[$editing_index]
      : NULL;

    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['name'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_desc'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#rows' => 3,
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['desc'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['url'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_icon_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon image URL'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#description' => $this->t('Provide an absolute or theme-relative URL for the icon.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['icon_src'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_icon_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon alt text'),
      '#description' => $this->t('Optional accessible description for the icon.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['icon_alt'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_arrow_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arrow image URL'),
      '#description' => $this->t('Optional absolute or theme-relative URL for the arrow graphic.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['arrow_src'] ?? '') : '',
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['fondo_arrow_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arrow alt text'),
      '#description' => $this->t('Optional accessible description for the arrow graphic.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['arrow_alt'] ?? '') : '',
    ];

    $form['fondos_mutuos_ui']['panes']['edit_person']['actions'] = ['#type' => 'actions'];
    $form['fondos_mutuos_ui']['panes']['edit_person']['actions']['save_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_fondos_mutuos_save_person',
      '#validate' => ['pds_recipe_fondos_mutuos_edit_person_validate'],
      '#submit' => ['pds_recipe_fondos_mutuos_edit_person_submit'],
      '#limit_validation_errors' => [
        ['fondos_mutuos_ui', 'panes', 'edit_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_fondos_mutuos_ajax_events',
        'wrapper' => 'pds-fondos_mutuos-form',
      ],
    ];
    $form['fondos_mutuos_ui']['panes']['edit_person']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_fondos_mutuos_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_fondos_mutuos_edit_person_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_fondos_mutuos_ajax_events',
        'wrapper' => 'pds-fondos_mutuos-form',
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
    $submitted_subtitle = $this->extractSubmittedString($form_state, 'subtitle');

    //1.- Persist the sanitized section heading while allowing empty titles to
    //    fall back to the default block label.
    $this->configuration['title'] = $submitted_title;

    //2.- Store the optional subtitle exactly as entered.
    $this->configuration['subtitle'] = $submitted_subtitle;

    $fondos_mutuos = self::getWorkingFondosMutuos($form_state, $cfg['fondos'] ?? []);
    $clean = [];

    //1.- Persist sanitized fondo definitions.
    foreach ($fondos_mutuos as $fondo) {
      if (!is_array($fondo)) {
        continue;
      }
      $clean_fondo = $this->cleanFondoConfig($fondo);
      if ($clean_fondo !== NULL) {
        $clean[] = $clean_fondo;
      }
    }

    $this->configuration['fondos'] = array_values($clean);
    unset($this->configuration['fondos_mutuos']);

    $form_state->set('working_fondos', $this->configuration['fondos']);
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
   * Resolve the current list of fondos during form interaction.
   */
  private static function getWorkingFondosMutuos(FormStateInterface $form_state, array $cfg_fondos): array {
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
   * Determine which executive is currently being edited, if any.
   */
  private static function getEditingIndex(FormStateInterface $form_state): ?int {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('pds_recipe_fondos_mutuos_editing_index')) {
      $index = $form_state->get('pds_recipe_fondos_mutuos_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_fondos_mutuos_editing_index')) {
        $index = $parent->get('pds_recipe_fondos_mutuos_editing_index');
        if (is_numeric($index)) {
          return (int) $index;
        }
      }
    }

    return NULL;
  }

  /**
   * Clean up a fondo array before saving it in configuration.
   */
  private function cleanFondoConfig(array $fondo): ?array {
    $name = $this->cleanText($fondo['name'] ?? '');
    $desc = $this->cleanText($fondo['desc'] ?? '');
    $url = $this->sanitizeUrl($fondo['url'] ?? '');
    $icon_src = $this->sanitizeUrl($fondo['icon_src'] ?? '');
    $icon_alt = $this->cleanText($fondo['icon_alt'] ?? '');
    $arrow_src = $this->sanitizeUrl($fondo['arrow_src'] ?? '');
    $arrow_alt = $this->cleanText($fondo['arrow_alt'] ?? '');

    if ($name === '' && $desc === '' && $icon_src === '' && $url === '' && $arrow_src === '') {
      return NULL;
    }

    $clean = [];
    if ($name !== '') {
      $clean['name'] = $name;
    }
    if ($desc !== '') {
      $clean['desc'] = $desc;
    }
    if ($url !== '') {
      $clean['url'] = $url;
    }
    if ($icon_src !== '') {
      $clean['icon_src'] = $icon_src;
    }
    if ($icon_alt !== '') {
      $clean['icon_alt'] = $icon_alt;
    }
    if ($arrow_src !== '') {
      $clean['arrow_src'] = $arrow_src;
    }
    if ($arrow_alt !== '') {
      $clean['arrow_alt'] = $arrow_alt;
    }

    return $clean === [] ? NULL : $clean;
  }

  /**
   * Normalize author-entered text values.
   */
  private function cleanText($value): string {
    return trim(is_string($value) ? $value : '');
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

}
