<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_insights_search\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Provides the "Principal InsightsSearch" block.
 *
 * @Block(
 *   id = "pds_insights_search_block",
 *   admin_label = @Translation("PDS InsightsSearch"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsInsightsSearchBlock extends BlockBase {

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

    // Read saved entries from config (supports legacy key "insights_search").
    $stored = $cfg['fondos'] ?? ($cfg['insights_search'] ?? []);
    $stored = is_array($stored) ? $stored : [];

    $link_text = (string) $this->t('Get our perspective');

    // Map saved config → frontend items (node-backed preferred).
    [$items, $cache_tags] = $this->buildItemsFromSavedFondos($stored, $link_text);

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? '');
    $component_id = Html::getUniqueId('pds-insights-search');
    $attributes = new Attribute(['data-pds-insights-search-id' => $component_id]);

    // Frontend search endpoint for live results.
    $search_url = Url::fromRoute('pds_recipe_insights_search.api.search')->toString();

    //1.- Build the render array ensuring featured items flow to Twig and JS consumers.
    return [
      '#theme' => 'pds_insights_search',
      '#title' => $title,
      '#items' => $items,
      '#initial_items' => $items,
      '#featured_items' => $items,
      '#total' => count($items),
      '#component_id' => $component_id,
      '#attributes' => $attributes,
      '#icons_path' => $this->buildAssetUrl('assets/images/icons'),
      '#attached' => [
        'library' => ['pds_recipe_insights_search/insights_search'],
        'drupalSettings' => [
          'pdsInsightsSearch' => [
            $component_id => [
              //2.- Provide both legacy and explicit featured datasets for the frontend behavior.
              'initialItems' => $items,
              'featuredItems' => $items,
              'searchUrl' => $search_url,
              'linkText' => $link_text,
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => array_values(array_unique(array_merge($cache_tags, ['config:block_list']))),
      ],
    ];
  }

  /**
   * Turn saved fondos[] into renderable items. Returns [items, cache_tags].
   *
   * Each item matches Twig:
   *  - theme_id, theme_label, title, summary, author, read_time, url, link_text
   *
   * Accepts rows with or without source_nid. Preserves saved order.
   */
  private function buildItemsFromSavedFondos(array $fondos_cfg, string $link_text): array {
    $items = [];
    $cache_tags = [];

    // 1) Collect referenced node IDs in the order they first appear.
    $nids = [];
    foreach ($fondos_cfg as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (isset($row['source_nid'])) {
        $nid = is_numeric($row['source_nid']) ? (int) $row['source_nid'] : 0;
        if ($nid > 0 && !in_array($nid, $nids, true)) {
          $nids[] = $nid;
        }
      }
    }

    // 2) Load nodes once. Optional: translate to current language.
    /** @var \Drupal\node\NodeInterface[] $loaded */
    $loaded = $nids
      ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids)
      : [];
    $current_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    foreach ($loaded as $nid => $node) {
      if ($node->hasTranslation($current_lang)) {
        $loaded[$nid] = $node->getTranslation($current_lang);
      }
    }

    // 3) Map each saved row to a card, preferring live node data.
    foreach ($fondos_cfg as $row) {
      if (!is_array($row)) {
        continue;
      }

      $source_nid = isset($row['source_nid']) && is_numeric($row['source_nid'])
        ? (int) $row['source_nid']
        : 0;

      if ($source_nid > 0 && isset($loaded[$source_nid])) {
        $node = $loaded[$source_nid];
        $items[] = $this->itemFromNode($node, $link_text);
        $cache_tags[] = "node:{$node->id()}";
        continue;
      }

      // Fallback to saved snapshot when node missing or not set.
      $title = $this->cleanText($row['title'] ?? ($row['name'] ?? ''));
      $summary = $this->cleanText($row['description'] ?? ($row['desc'] ?? ''));
      $url = $this->sanitizeUrl($row['url'] ?? '');

      if ($title === '' && $summary === '' && $url === '') {
        continue;
      }

      $items[] = [
        'id'         => 'saved-' . (count($items) + 1),
        'theme_id'   => 'saved',
        'theme_label'=> '',
        'title'      => $title,
        'summary'    => $summary,
        'author'     => '',
        'read_time'  => '',
        'url'        => $url !== '' ? $url : '#',
        'link_text'  => $link_text,
      ];
    }

    return [$items, array_values(array_unique($cache_tags))];
  }


  /**
   * Build a card item from a Node.
   */
  private function itemFromNode(NodeInterface $node, string $link_text): array {
    $title = $node->label() ?? '';

    // Summary: prefer dedicated summary fields, fallback to body summary/value.
    $summary = '';
    foreach (['field_summary','field_resumen','field_short_description'] as $f) {
      if ($node->hasField($f) && !$node->get($f)->isEmpty()) {
        $summary = trim((string) $node->get($f)->value);
        break;
      }
    }
    if ($summary === '' && $node->hasField('body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      $summary = $item ? trim((string) (($item->summary ?? '') ?: ($item->value ?? ''))) : '';
    }

    // Theme taxonomy if present.
    $theme_id = '';
    $theme_label = '';
    $theme_term = NULL;
    foreach (['field_theme','field_tema'] as $f) {
      if ($node->hasField($f) && !$node->get($f)->isEmpty() && ($term = $node->get($f)->entity) instanceof TermInterface) {
        $theme_term = $term;
        $theme_id = $this->normalizeThemeIdentifier($term) ?: (string) $term->id();
        $theme_label = $term->label();
        break;
      }
    }

    // Read time if present.
    $read_time = '';
    foreach (['field_read_time','field_tiempo_de_lectura', 'field_mins_of_read'] as $f) {
      if ($node->hasField($f) && !$node->get($f)->isEmpty()) {
        $read_time = trim((string) $node->get($f)->value);
        break;
      }
    }

    $category = 'Sin categoría';
    foreach (['field_category','field_categoria', 'field_category_insights'] as $f) {
      if ($node->hasField($f) && !$node->get($f)->isEmpty() && ($term = $node->get($f)->entity)) {
        $category = $term->label();
        break;
      }
    }

    return [
      'id' => (int) $node->id(),
      'theme_id' => $theme_id ?: 'node',
      'theme_label' => $theme_label,
      'title' => $title,
      'summary' => $summary,
      'author' => $node->getOwner()?->getDisplayName() ?? '',
      'read_time' => $read_time,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'link_text' => $link_text,
      'category' => $category,
      'theme' => [
        'id' => $theme_term instanceof TermInterface ? (int) $theme_term->id() : NULL,
        'label' => $theme_label,
        'machine_name' => $theme_id,
      ],
    ];
  }

  private function normalizeThemeIdentifier(TermInterface $term): string {
    //1.- Prefer an explicit machine-name field when present to keep parity with the search endpoint.
    if ($term->hasField('field_machine_name') && !$term->get('field_machine_name')->isEmpty()) {
      $value = trim((string) $term->get('field_machine_name')->value ?? '');
      if ($value !== '') {
        return mb_strtolower($value);
      }
    }

    //2.- Fallback to a sanitized label-derived slug so filters can match consistently.
    return Html::cleanCssIdentifier(mb_strtolower($term->label() ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $working = self::getWorkingInsightsSearch($form_state, $cfg['fondos'] ?? ($cfg['insights_search'] ?? []));
    $editing_index = self::getEditingIndex($form_state);

    // Track active tab.
    $input = $form_state->getUserInput();
    $submitted_tab = is_array($input) && isset($input['insights_search_ui_active_tab'])
      ? trim((string) $input['insights_search_ui_active_tab'])
      : '';
    $active_tab = $submitted_tab !== ''
      ? $submitted_tab
      : ($form_state->get('pds_recipe_insights_search_active_tab') ?? '');
    if ($active_tab === '' && $editing_index !== NULL) {
      $active_tab = 'edit';
    }
    if ($active_tab === '') {
      $active_tab = 'general';
    }
    $form_state->set('pds_recipe_insights_search_active_tab', $active_tab);

    if (!$form_state->has('working_fondos')) {
      $form_state->set('working_fondos', $working);
    }
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent_state = $form_state->getCompleteFormState();
      if ($parent_state instanceof FormStateInterface && !$parent_state->has('working_fondos')) {
        $parent_state->set('working_fondos', $working);
      }
    }

    $form['#attached']['library'][] = 'pds_recipe_insights_search/admin.vertical_tabs';
    $form['#attached']['library'][] = 'pds_recipe_insights_search/admin.search';

    $search_route = Url::fromRoute('pds_recipe_insights_search.api.search')->setAbsolute();
    $form['#attached']['drupalSettings']['pdsRecipeInsightsSearchAdmin'] = [
      'searchUrl' => $search_route->toString(),
      'strings' => [
        'resultsEmpty' => (string) $this->t('No insights found. Try a different search.'),
        'error' => (string) $this->t('An unexpected error occurred. Please try again.'),
        'loading' => (string) $this->t('Searching…'),
        'add' => (string) $this->t('Select'),
        'selected' => (string) $this->t('Selected insight: @title'),
        'selectionEmpty' => (string) $this->t('No insight selected.'),
        'minimum' => (string) $this->t('Enter at least three characters to search.'),
      ],
    ];

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
        'label' => (string) $this->t('Featured Insights'),
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
      $form_state->set('pds_recipe_insights_search_active_tab', $active_tab);
    }

    // Vertical tabs shell.
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

    $form['insights_search_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-insights_search-form',
        'class' => ['pds-vertical-tabs'],
        'data-pds-vertical-tabs' => 'true',
      ],
    ];

    $form['insights_search_ui']['active_tab'] = [
      '#type' => 'hidden',
      '#value' => $active_tab,
      '#parents' => ['insights_search_ui_active_tab'],
      '#attributes' => ['data-pds-vertical-tabs-active' => 'true'],
    ];

    $form['insights_search_ui']['menu'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($menu_markup),
    ];

    $form['insights_search_ui']['panes'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pds-vertical-tabs__panes']],
    ];

    // General pane.
    $form['insights_search_ui']['panes']['general'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('general', 'tab-general', $active_tab),
    ];
    $form['insights_search_ui']['panes']['general']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('General'),
    ];
    $form['insights_search_ui']['panes']['general']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the section heading shown above the insights list.'),
    ];
    $form['insights_search_ui']['panes']['general']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
      '#parents' => ['title'],
    ];

    // Add pane.
    $form['insights_search_ui']['panes']['add_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('add', 'tab-add', $active_tab),
    ];
    $form['insights_search_ui']['panes']['add_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add New'),
    ];
    $form['insights_search_ui']['panes']['add_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Search for existing Insights content and add it to the featured list.'),
    ];

    $search_wrapper_id = Html::getUniqueId('pds-insights-search-admin');
    $form['insights_search_ui']['panes']['add_person']['search_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $search_wrapper_id,
        'class' => ['pds-recipe-insights-search-admin'],
        'data-pds-insights-search-admin' => 'wrapper',
      ],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['search_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pds-recipe-insights-search-admin__controls']],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['search_controls']['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search by title'),
      '#attributes' => [
        'data-pds-insights-search-admin' => 'input',
        'placeholder' => $this->t('Type at least 3 characters'),
        'autocomplete' => 'off',
      ],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['search_controls']['button'] = [
      '#type' => 'button',
      '#value' => $this->t('Search'),
      '#attributes' => [
        'class' => ['button'],
        'data-pds-insights-search-admin' => 'button',
      ],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['results'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-recipe-insights-search-admin__results'],
        'data-pds-insights-search-admin' => 'results',
      ],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['selected'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-recipe-insights-search-admin__selection'],
        'data-pds-insights-search-admin' => 'selection',
      ],
      '#markup' => $this->t('No insight selected.'),
    ];

    // Hidden fields filled by the admin JS when selecting a result.
    $add_base_key = \pds_recipe_insights_search_base_key($form, $form_state, ['insights_search_ui', 'panes', 'add_person']);
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['fields'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pds-recipe-insights-search-admin__fields']],
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['fields']['fondo_name'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-pds-insights-search-admin-selected' => 'title'],
      '#parents' => array_merge($add_base_key, ['fondo_name']),
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['fields']['fondo_desc'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-pds-insights-search-admin-selected' => 'summary'],
      '#parents' => array_merge($add_base_key, ['fondo_desc']),
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['fields']['fondo_url'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-pds-insights-search-admin-selected' => 'url'],
      '#parents' => array_merge($add_base_key, ['fondo_url']),
    ];
    $form['insights_search_ui']['panes']['add_person']['search_wrapper']['fields']['fondo_source'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-pds-insights-search-admin-selected' => 'source'],
      '#parents' => array_merge($add_base_key, ['fondo_source']),
    ];

    $form['insights_search_ui']['panes']['add_person']['actions'] = ['#type' => 'actions'];
    $form['insights_search_ui']['panes']['add_person']['actions']['add_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add selected insight'),
      '#name' => 'pds_recipe_insights_search_add_person',
      '#validate' => ['pds_recipe_insights_search_add_person_validate'],
      '#submit' => ['pds_recipe_insights_search_add_person_submit'],
      '#limit_validation_errors' => [
        ['insights_search_ui', 'panes', 'add_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_insights_search_ajax_events',
        'wrapper' => 'pds-insights_search-form',
      ],
    ];

    // List pane.
    $form['insights_search_ui']['panes']['people_list'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('people', 'tab-people', $active_tab),
    ];
    $form['insights_search_ui']['panes']['people_list']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Featured Insights'),
    ];
    $form['insights_search_ui']['panes']['people_list']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage the list of featured insights shown in the frontend.'),
    ];

    $form['insights_search_ui']['panes']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Title'),
        $this->t('Description'),
        $this->t('URL'),
        $this->t('Edit'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No insights yet. Add one using the Add New tab.'),
    ];

    foreach ($working as $index => $fondo) {
      if (!is_array($fondo)) {
        continue;
      }
      $name = trim((string) ($fondo['title'] ?? ($fondo['name'] ?? '')));
      $desc_value = trim((string) ($fondo['description'] ?? ($fondo['desc'] ?? '')));
      $url_value = trim((string) ($fondo['url'] ?? ''));

      $form['insights_search_ui']['panes']['people_list']['people'][$index]['name'] = [
        '#plain_text' => $name === '' ? $this->t('Untitled @number', ['@number' => $index + 1]) : $name,
      ];
      $form['insights_search_ui']['panes']['people_list']['people'][$index]['desc'] = [
        '#plain_text' => $desc_value,
      ];
      $form['insights_search_ui']['panes']['people_list']['people'][$index]['url'] = [
        '#plain_text' => $url_value,
      ];
      $form['insights_search_ui']['panes']['people_list']['people'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_insights_search_edit_person_' . $index,
        '#submit' => ['pds_recipe_insights_search_edit_person_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_insights_search_ajax_events',
          'wrapper' => 'pds-insights_search-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-insights-search-edit-person']],
        '#pds_recipe_insights_search_edit_index' => $index,
      ];
      $form['insights_search_ui']['panes']['people_list']['people'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
      ];
    }

    $form['insights_search_ui']['panes']['people_list']['actions'] = ['#type' => 'actions'];
    $form['insights_search_ui']['panes']['people_list']['actions']['remove_people'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_insights_search_remove_people',
      '#submit' => ['pds_recipe_insights_search_remove_people_submit'],
      '#limit_validation_errors' => [
        ['insights_search_ui', 'panes', 'people_list', 'people'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_insights_search_ajax_events',
        'wrapper' => 'pds-insights_search-form',
      ],
    ];

    // Edit pane.
    $form['insights_search_ui']['panes']['edit_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('edit', 'tab-edit', $active_tab),
    ];
    $form['insights_search_ui']['panes']['edit_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Edit'),
    ];
    $form['insights_search_ui']['panes']['edit_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Update the selected fondo card.'),
    ];

    $editing_index = self::getEditingIndex($form_state);
    $editing_fondo = $editing_index !== NULL && isset($working[$editing_index]) && is_array($working[$editing_index])
      ? $working[$editing_index]
      : NULL;

    $form['insights_search_ui']['panes']['edit_person']['fondo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['title'] ?? ($editing_fondo['name'] ?? '')) : '',
    ];
    $form['insights_search_ui']['panes']['edit_person']['fondo_desc'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#rows' => 3,
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['description'] ?? ($editing_fondo['desc'] ?? '')) : '',
    ];
    $form['insights_search_ui']['panes']['edit_person']['fondo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Destination URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#default_value' => is_array($editing_fondo) ? (string) ($editing_fondo['url'] ?? '') : '',
    ];

    $form['insights_search_ui']['panes']['edit_person']['actions'] = ['#type' => 'actions'];
    $form['insights_search_ui']['panes']['edit_person']['actions']['save_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_insights_search_save_person',
      '#validate' => ['pds_recipe_insights_search_edit_person_validate'],
      '#submit' => ['pds_recipe_insights_search_edit_person_submit'],
      '#limit_validation_errors' => [
        ['insights_search_ui', 'panes', 'edit_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_insights_search_ajax_events',
        'wrapper' => 'pds-insights_search-form',
      ],
    ];
    $form['insights_search_ui']['panes']['edit_person']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_insights_search_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_insights_search_edit_person_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_insights_search_ajax_events',
        'wrapper' => 'pds-insights_search-form',
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

    $insights_search = self::getWorkingInsightsSearch($form_state, $cfg['fondos'] ?? []);
    $clean = [];

    foreach ($insights_search as $fondo) {
      if (!is_array($fondo)) {
        continue;
      }
      $clean_fondo = $this->cleanFondoConfig($fondo);
      if ($clean_fondo !== NULL) {
        $clean[] = $clean_fondo;
      }
    }

    $this->configuration['fondos'] = array_values($clean);
    unset($this->configuration['insights_search']);

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

  private static function getWorkingInsightsSearch(FormStateInterface $form_state, array $cfg_fondos): array {
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

    if ($form_state->has('pds_recipe_insights_search_editing_index')) {
      $index = $form_state->get('pds_recipe_insights_search_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_insights_search_editing_index')) {
        $index = $parent->get('pds_recipe_insights_search_editing_index');
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
    $source = isset($fondo['source_nid']) ? (int) $fondo['source_nid'] : 0;

    if ($name === '' && $desc === '' && $url === '' && $source <= 0) {
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
    if ($source > 0) {
      $clean['source_nid'] = $source;
    }

    return $clean === [] ? NULL : $clean;
  }

  private function buildAssetUrl(string $relative_path): string {
    static $module_path = NULL;
    if ($module_path === NULL) {
      $module_path = \Drupal::service('extension.path.resolver')->getPath('module', 'pds_recipe_insights_search');
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
