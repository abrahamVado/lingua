<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_executives\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides the "Principal Executives" block.
 *
 * @Block(
 *   id = "pds_executives_block",
 *   admin_label = @Translation("PDS Executives"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsExecutivesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'title' => '',
      'section_id' => 'principal-executives',
      'executives' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $executives_cfg = is_array($cfg['executives'] ?? NULL) ? $cfg['executives'] : [];

    $executives = [];

    //1.- Sanitize every configured executive before passing the data to Twig.
    foreach ($executives_cfg as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $name = trim((string) ($item['name'] ?? ''));
      $title = trim((string) ($item['title'] ?? ''));
      $photo = $this->sanitizeUrl($item['photo'] ?? '');
      $linkedin = $this->sanitizeUrl($item['linkedin'] ?? '');
      $cv_raw = (string) ($item['cv_html'] ?? '');
      $cv_html = $this->sanitizeCv($cv_raw);

      if ($name === '' && $title === '' && $photo === '' && $linkedin === '' && $cv_html === '') {
        continue;
      }

      $executives[] = [
        'name' => $name,
        'title' => $title,
        'photo' => $photo,
        'linkedin' => $linkedin,
        'cv_html' => $cv_html,
      ];
    }

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? '');
    $section_id = trim((string) ($cfg['section_id'] ?? '')) ?: 'principal-executives';

    return [
      '#theme' => 'pds_executives',
      '#title' => $title,
      '#section_id' => $section_id,
      '#executives' => $executives,
      '#attached' => [
        'library' => [
          'pds_recipe_executives/executives',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $working = self::getWorkingExecutives($form_state, $cfg['executives'] ?? []);
    $editing_index = self::getEditingIndex($form_state);

    //1.- Track which tab is active so AJAX rebuilds preserve the author context.
    $input = $form_state->getUserInput();
    $submitted_tab = is_array($input) && isset($input['executives_ui_active_tab'])
      ? trim((string) $input['executives_ui_active_tab'])
      : '';
    $active_tab = $submitted_tab !== ''
      ? $submitted_tab
      : ($form_state->get('pds_recipe_executives_active_tab') ?? '');
    if ($active_tab === '' && $editing_index !== NULL) {
      $active_tab = 'edit';
    }
    if ($active_tab === '') {
      $active_tab = 'general';
    }
    $form_state->set('pds_recipe_executives_active_tab', $active_tab);

    if (!$form_state->has('working_people')) {
      $form_state->set('working_people', $working);
    }

    $form['#attached']['library'][] = 'pds_recipe_executives/admin.vertical_tabs';

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
        'label' => (string) $this->t('Executives'),
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
      $form_state->set('pds_recipe_executives_active_tab', $active_tab);
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

    $form['executives_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-executives-form',
        'class' => ['pds-vertical-tabs'],
        'data-pds-vertical-tabs' => 'true',
      ],
    ];

    $form['executives_ui']['active_tab'] = [
      '#type' => 'hidden',
      '#value' => $active_tab,
      '#parents' => ['executives_ui_active_tab'],
      '#attributes' => [
        'data-pds-vertical-tabs-active' => 'true',
      ],
    ];

    $form['executives_ui']['menu'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($menu_markup),
    ];

    $form['executives_ui']['panes'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-vertical-tabs__panes'],
      ],
    ];

    //3.- General pane exposes overall section settings.
    $form['executives_ui']['panes']['general'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('general', 'tab-general', $active_tab),
    ];
    $form['executives_ui']['panes']['general']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('General'),
    ];
    $form['executives_ui']['panes']['general']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the section heading and optional HTML id.'),
    ];
    $form['executives_ui']['panes']['general']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
      '#parents' => ['title'],
    ];
    $form['executives_ui']['panes']['general']['section_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section ID'),
      '#default_value' => $cfg['section_id'] ?? 'principal-executives',
      '#description' => $this->t('DOM id attribute. Must be unique on the page.'),
      '#parents' => ['section_id'],
    ];

    //4.- Add pane lets authors create a new executive entry.
    $form['executives_ui']['panes']['add_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('add', 'tab-add', $active_tab),
    ];
    $form['executives_ui']['panes']['add_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Add New'),
    ];
    $form['executives_ui']['panes']['add_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Provide the public information for a new executive card.'),
    ];

    $form['executives_ui']['panes']['add_person']['person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['executives_ui']['panes']['add_person']['person_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
    ];
    $form['executives_ui']['panes']['add_person']['person_photo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Photo URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
    ];
    $form['executives_ui']['panes']['add_person']['person_photo_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photo upload'),
      '#description' => $this->t('Upload an image instead of entering a URL.'),
      '#upload_location' => 'public://pds-executives',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['executives_ui']['panes']['add_person']['person_linkedin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LinkedIn URL'),
      '#description' => $this->t('Optional link for the modal action button.'),
    ];
    $form['executives_ui']['panes']['add_person']['person_cv'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Curriculum (HTML)'),
      '#description' => $this->t('Allowed tags: @tags', ['@tags' => implode(', ', $this->allowedCvTags())]),
      '#rows' => 6,
    ];

    $form['executives_ui']['panes']['add_person']['actions'] = ['#type' => 'actions'];
    $form['executives_ui']['panes']['add_person']['actions']['add_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add executive'),
      '#name' => 'pds_recipe_executives_add_person',
      '#validate' => ['pds_recipe_executives_add_person_validate'],
      '#submit' => ['pds_recipe_executives_add_person_submit'],
      '#limit_validation_errors' => [
        ['executives_ui', 'panes', 'add_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_events',
        'wrapper' => 'pds-executives-form',
      ],
    ];

    //5.- People pane lists existing executives and exposes edit/remove actions.
    $form['executives_ui']['panes']['people_list'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('people', 'tab-people', $active_tab),
    ];
    $form['executives_ui']['panes']['people_list']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Executives'),
    ];
    $form['executives_ui']['panes']['people_list']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Review, edit or remove existing cards.'),
    ];

    $form['executives_ui']['panes']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Name'),
        $this->t('Title'),
        $this->t('LinkedIn'),
        $this->t('Edit'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No executives yet. Add one using the Add New tab.'),
    ];

    foreach ($working as $index => $person) {
      if (!is_array($person)) {
        continue;
      }
      $name = trim((string) ($person['name'] ?? ''));
      $title_value = trim((string) ($person['title'] ?? ''));
      $linkedin_value = trim((string) ($person['linkedin'] ?? ''));

      $form['executives_ui']['panes']['people_list']['people'][$index]['name'] = [
        '#plain_text' => $name === '' ? $this->t('Unnamed @number', ['@number' => $index + 1]) : $name,
      ];
      $form['executives_ui']['panes']['people_list']['people'][$index]['title'] = [
        '#plain_text' => $title_value,
      ];
      $form['executives_ui']['panes']['people_list']['people'][$index]['linkedin'] = [
        '#plain_text' => $linkedin_value,
      ];
      $form['executives_ui']['panes']['people_list']['people'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_executives_edit_person_' . $index,
        '#submit' => ['pds_recipe_executives_edit_person_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_executives_ajax_events',
          'wrapper' => 'pds-executives-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-executives-edit-person']],
        '#pds_recipe_executives_edit_index' => $index,
      ];
      $form['executives_ui']['panes']['people_list']['people'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
      ];
    }

    $form['executives_ui']['panes']['people_list']['actions'] = ['#type' => 'actions'];
    $form['executives_ui']['panes']['people_list']['actions']['remove_people'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_executives_remove_people',
      '#submit' => ['pds_recipe_executives_remove_people_submit'],
      '#limit_validation_errors' => [
        ['executives_ui', 'panes', 'people_list', 'people'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_events',
        'wrapper' => 'pds-executives-form',
      ],
    ];

    //6.- Edit pane mirrors the Add pane but pre-fills the selected executive.
    $form['executives_ui']['panes']['edit_person'] = [
      '#type' => 'container',
      '#attributes' => $this->buildPaneAttributes('edit', 'tab-edit', $active_tab),
    ];
    $form['executives_ui']['panes']['edit_person']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Edit'),
    ];
    $form['executives_ui']['panes']['edit_person']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Update the selected executive card.'),
    ];

    $editing_person = $editing_index !== NULL && isset($working[$editing_index]) && is_array($working[$editing_index])
      ? $working[$editing_index]
      : NULL;

    $form['executives_ui']['panes']['edit_person']['person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['name'] ?? '') : '',
    ];
    $form['executives_ui']['panes']['edit_person']['person_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#title_attributes' => ['class' => ['js-form-required', 'form-required']],
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['title'] ?? '') : '',
    ];
    $form['executives_ui']['panes']['edit_person']['person_photo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Photo URL'),
      '#description' => $this->t('Provide an absolute or theme-relative URL.'),
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['photo'] ?? '') : '',
    ];
    $form['executives_ui']['panes']['edit_person']['person_photo_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photo upload'),
      '#description' => $this->t('Upload a new image to replace the current photo.'),
      '#upload_location' => 'public://pds-executives',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif webp svg'],
      ],
    ];
    $form['executives_ui']['panes']['edit_person']['person_linkedin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LinkedIn URL'),
      '#description' => $this->t('Optional link for the modal action button.'),
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['linkedin'] ?? '') : '',
    ];
    $form['executives_ui']['panes']['edit_person']['person_cv'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Curriculum (HTML)'),
      '#description' => $this->t('Allowed tags: @tags', ['@tags' => implode(', ', $this->allowedCvTags())]),
      '#rows' => 6,
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['cv_html'] ?? '') : '',
    ];

    $form['executives_ui']['panes']['edit_person']['actions'] = ['#type' => 'actions'];
    $form['executives_ui']['panes']['edit_person']['actions']['save_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_executives_save_person',
      '#validate' => ['pds_recipe_executives_edit_person_validate'],
      '#submit' => ['pds_recipe_executives_edit_person_submit'],
      '#limit_validation_errors' => [
        ['executives_ui', 'panes', 'edit_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_events',
        'wrapper' => 'pds-executives-form',
      ],
    ];
    $form['executives_ui']['panes']['edit_person']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_executives_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_executives_edit_person_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_events',
        'wrapper' => 'pds-executives-form',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();

    $this->configuration['title'] = trim((string) $form_state->getValue('title') ?? '');
    $this->configuration['section_id'] = trim((string) $form_state->getValue('section_id') ?? 'principal-executives');

    $executives = self::getWorkingExecutives($form_state, $cfg['executives'] ?? []);
    $clean = [];

    //1.- Persist sanitized executive definitions.
    foreach ($executives as $exec) {
      if (!is_array($exec)) {
        continue;
      }
      $clean_exec = $this->cleanExecutiveConfig($exec);
      if ($clean_exec !== NULL) {
        $clean[] = $clean_exec;
      }
    }

    $this->configuration['executives'] = array_values($clean);

    $form_state->set('working_people', $this->configuration['executives']);
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
   * Resolve the current list of executives during form interaction.
   */
  private static function getWorkingExecutives(FormStateInterface $form_state, array $cfg_people): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('working_people')) {
      $tmp = $form_state->get('working_people');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('working_people')) {
        $tmp = $parent->get('working_people');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    return array_values(is_array($cfg_people) ? $cfg_people : []);
  }

  /**
   * Determine which executive is currently being edited, if any.
   */
  private static function getEditingIndex(FormStateInterface $form_state): ?int {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('pds_recipe_executives_editing_index')) {
      $index = $form_state->get('pds_recipe_executives_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_executives_editing_index')) {
        $index = $parent->get('pds_recipe_executives_editing_index');
        if (is_numeric($index)) {
          return (int) $index;
        }
      }
    }

    return NULL;
  }

  /**
   * Clean up an executive array before saving it in configuration.
   */
  private function cleanExecutiveConfig(array $exec): ?array {
    $name = trim((string) ($exec['name'] ?? ''));
    $title = trim((string) ($exec['title'] ?? ''));
    $photo = $this->sanitizeUrl($exec['photo'] ?? '');
    $linkedin = $this->sanitizeUrl($exec['linkedin'] ?? '');
    $cv = trim((string) ($exec['cv_html'] ?? ''));

    if ($name === '' && $title === '' && $photo === '' && $linkedin === '' && $cv === '') {
      return NULL;
    }

    $clean = [];
    if ($name !== '') {
      $clean['name'] = $name;
    }
    if ($title !== '') {
      $clean['title'] = $title;
    }
    if ($photo !== '') {
      $clean['photo'] = $photo;
    }
    if ($linkedin !== '') {
      $clean['linkedin'] = $linkedin;
    }
    if ($cv !== '') {
      $clean['cv_html'] = $cv;
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
