<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;

/**
 * Provides the "Principal Timeline" block.
 *
 * @Block(
 *   id = "pds_timeline_block",
 *   admin_label = @Translation("PDS Timeline"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTimelineBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * Stored per event:
   * [
   *   'year'      => string (e.g. "1991", "91", "2025", "25"),
   *   'headline'  => string,
   *   'summary'   => string (HTML),
   *   'cta_label' => string,
   *   'cta_url'   => string,
   * ]
   */
  public function defaultConfiguration(): array {
    return [
      'title' => '',
      'timeline_id' => 'principal-timeline',
      'events' => [],
      'people' => [],
    ];
  }

  /**
   * Build exactly what the Twig expects.
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $people_cfg = is_array($cfg['people'] ?? NULL) ? $cfg['people'] : [];
    if ($people_cfg === [] && is_array($cfg['events'] ?? NULL) && $cfg['events'] !== []) {
      //1.- Backwards compatibility: convert legacy events into a person per event.
      foreach ($cfg['events'] as $legacy_event) {
        if (!is_array($legacy_event)) {
          continue;
        }

        $legacy_year = trim((string) ($legacy_event['year'] ?? ''));
        $legacy_summary = trim((string) ($legacy_event['summary'] ?? ''));
        $legacy_cta_label = trim((string) ($legacy_event['cta_label'] ?? ''));
        $legacy_cta_url = trim((string) ($legacy_event['cta_url'] ?? ''));
        $legacy_info = $legacy_summary;

        if ($legacy_cta_label !== '' && $legacy_cta_url !== '') {
          $legacy_info .= ($legacy_info === '' ? '' : ' ') . $legacy_cta_label . ' (' . $legacy_cta_url . ')';
        }

        $people_cfg[] = [
          'name' => trim((string) ($legacy_event['headline'] ?? '')),
          'role' => '',
          'milestones' => [
            [
              'year' => $legacy_year,
              'text' => $legacy_info,
            ],
          ],
        ];
      }
    }

    $rows = [];
    $years = [];

    //1.- Iterate over every configured person to prepare timeline rows.
    foreach ($people_cfg as $person_index => $person_cfg) {
      if (!is_array($person_cfg)) {
        continue;
      }

      $person_name = trim((string) ($person_cfg['name'] ?? ''));
      $person_role = trim((string) ($person_cfg['role'] ?? ''));
      $raw_milestones = [];

      if (is_array($person_cfg['milestones'] ?? NULL)) {
        foreach ($person_cfg['milestones'] as $milestone_cfg) {
          if (is_array($milestone_cfg)) {
            $raw_milestones[] = $milestone_cfg;
          }
        }
      }

      if ($raw_milestones === []) {
        continue;
      }

      $segments = [];
      $has_custom_width = FALSE;

      //2.- Transform milestones into visual timeline segments for Twig.
      foreach ($raw_milestones as $milestone_index => $milestone_cfg) {
        $segment = $this->buildSegment($milestone_cfg, $milestone_index);
        if ($segment === NULL) {
          continue;
        }

        if ($segment['year_norm'] !== '') {
          $years[$segment['year_norm']] = TRUE;
        }

        if ($segment['width'] !== NULL) {
          $has_custom_width = TRUE;
        }

        $segments[] = $segment;
      }

      if ($segments === []) {
        continue;
      }

      $segment_count = count($segments);
      $fallback_width = $segment_count > 0 ? (100 / $segment_count) : 100;

      foreach ($segments as &$segment) {
        $width_value = $segment['width'];
        if (!$has_custom_width || $width_value === NULL || $width_value <= 0) {
          $width_value = $fallback_width;
        }

        $segment['width'] = $this->formatWidth($width_value);
        unset($segment['year_norm']);
      }
      unset($segment);

      $rows[] = [
        'person' => [
          'name' => $person_name !== '' ? $person_name : (string) $this->t('Person @number', ['@number' => $person_index + 1]),
          'role' => $person_role,
        ],
        'segments' => $segments,
      ];
    }

    $year_list = array_keys($years);
    usort($year_list, static fn($a, $b) => ((int) $a) <=> ((int) $b));

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? 'Timeline');
    $timeline_id = trim((string) ($cfg['timeline_id'] ?? '')) ?: 'principal-timeline';

    return [
      '#theme' => 'pds_timeline',
      '#title' => $title,
      '#years' => $year_list,
      '#rows' => $rows,
      '#timeline_id' => $timeline_id,
      '#attached' => [
        'library' => [
          'pds_recipe_timeline/pds_timeline.public',
        ],
      ],
    ];
  }

  /**
   * Admin form: title/id + events table with AJAX add/remove.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();
    $people_working = self::getWorkingPeople($form_state, $cfg['people'] ?? []);

    if (!$form_state->has('working_people')) {
      $form_state->set('working_people', $people_working);
    }

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $cfg['title'] ?? '',
    ];

    $form['timeline_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timeline ID'),
      '#default_value' => $cfg['timeline_id'] ?? 'principal-timeline',
      '#description' => $this->t('DOM id attribute. Must be unique on the page.'),
    ];

    $form['timeline_ui'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'pds-timeline-form'],
    ];

    $form['timeline_ui']['tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Timeline management'),
    ];

    $form['timeline_ui']['add_person'] = [
      '#type' => 'details',
      '#title' => $this->t('New People'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $form['timeline_ui']['add_person']['person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Header'),
      '#required' => FALSE,
    ];

    $form['timeline_ui']['add_person']['person_role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subheader'),
      '#required' => FALSE,
    ];

    $form['timeline_ui']['add_person']['milestones_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Milestones JSON'),
      '#description' => $this->t('Provide milestones in JSON format, for example {"1990":"Started program","1995":"Promoted"}.'),
      '#rows' => 5,
    ];

    $form['timeline_ui']['add_person']['actions'] = ['#type' => 'actions'];
    $form['timeline_ui']['add_person']['actions']['add_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add person'),
      '#name' => 'pds_recipe_timeline_add_person',
      '#validate' => ['pds_recipe_timeline_add_person_validate'],
      '#submit' => ['pds_recipe_timeline_add_person_submit'],
      '#limit_validation_errors' => [
        ['timeline_ui', 'add_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_timeline_ajax_events',
        'wrapper' => 'pds-timeline-form',
      ],
    ];

    $form['timeline_ui']['people_list'] = [
      '#type' => 'details',
      '#title' => $this->t('People'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $editing_index = self::getEditingIndex($form_state);

    $form['timeline_ui']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Header'),
        $this->t('Subheader'),
        $this->t('Milestones'),
        $this->t('Edit'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No people yet. Add a person using the New People tab.'),
    ];

    foreach ($people_working as $index => $person) {
      $milestone_items = [];
      if (is_array($person['milestones'] ?? NULL)) {
        foreach ($person['milestones'] as $milestone) {
          if (!is_array($milestone)) {
            continue;
          }
          $year_label = trim((string) ($milestone['year'] ?? ''));
          $text_label = trim((string) ($milestone['text'] ?? ''));
          $info_label = trim((string) ($milestone['info'] ?? ''));
          $info_html_label = trim((string) ($milestone['info_html'] ?? ''));
          $img_label = trim((string) ($milestone['img_src'] ?? $milestone['image'] ?? ''));
          $width_value = $milestone['width'] ?? NULL;
          $principal_flag = !empty($milestone['principal']);
          $first_flag = !empty($milestone['first']);

          $parts = [];

          if ($year_label !== '') {
            $parts[] = $year_label;
          }

          $primary_text = $text_label !== ''
            ? $text_label
            : ($info_label !== '' ? $info_label : ($info_html_label !== '' ? Html::decodeEntities(strip_tags($info_html_label)) : ''));
          if ($primary_text !== '') {
            $parts[] = $primary_text;
          }

          if ($width_value !== NULL && $width_value !== '') {
            $parts[] = $this->t('@width%', ['@width' => $this->formatWidth((float) $width_value)]);
          }

          if ($principal_flag) {
            $parts[] = (string) $this->t('Principal segment');
          }

          if ($first_flag) {
            $parts[] = (string) $this->t('First segment');
          }

          if ($img_label !== '') {
            $parts[] = $img_label;
          }

          if ($parts === []) {
            continue;
          }

          $milestone_items[] = implode(' • ', $parts);
        }
      }

      $form['timeline_ui']['people_list']['people'][$index]['name'] = [
        '#type' => 'item',
        '#plain_text' => (string) ($person['name'] ?? ''),
      ];
      $form['timeline_ui']['people_list']['people'][$index]['role'] = [
        '#type' => 'item',
        '#plain_text' => (string) ($person['role'] ?? ''),
      ];
      $form['timeline_ui']['people_list']['people'][$index]['milestones'] = $milestone_items === []
        ? [
          '#type' => 'item',
          '#plain_text' => (string) $this->t('No milestones provided'),
        ]
        : [
          '#theme' => 'item_list',
          '#items' => $milestone_items,
        ];
      $form['timeline_ui']['people_list']['people'][$index]['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'pds_recipe_timeline_edit_person_' . $index,
        '#submit' => ['pds_recipe_timeline_edit_person_prepare_submit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => 'pds_recipe_timeline_ajax_events',
          'wrapper' => 'pds-timeline-form',
        ],
        '#attributes' => ['class' => ['pds-recipe-timeline-edit-person']],
        '#pds_recipe_timeline_edit_index' => $index,
      ];
      $form['timeline_ui']['people_list']['people'][$index]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#title_display' => 'invisible',
      ];
    }

    $form['timeline_ui']['people_list']['actions'] = ['#type' => 'actions'];
    $form['timeline_ui']['people_list']['actions']['remove_people'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_timeline_remove_people',
      '#submit' => ['pds_recipe_timeline_remove_people_submit'],
      '#limit_validation_errors' => [
        ['timeline_ui', 'people_list', 'people'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_timeline_ajax_events',
        'wrapper' => 'pds-timeline-form',
      ],
    ];

    $form['timeline_ui']['edit_person'] = [
      '#type' => 'details',
      '#title' => $this->t('Edit person'),
      '#group' => 'tabs',
      '#open' => $editing_index !== NULL,
      '#access' => $editing_index !== NULL,
    ];

    $editing_person = ($editing_index !== NULL && isset($people_working[$editing_index])) ? $people_working[$editing_index] : NULL;

    $form['timeline_ui']['edit_person']['person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Header'),
      '#required' => FALSE,
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['name'] ?? '') : '',
    ];

    $form['timeline_ui']['edit_person']['person_role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subheader'),
      '#required' => FALSE,
      '#default_value' => is_array($editing_person) ? (string) ($editing_person['role'] ?? '') : '',
    ];

    $edit_milestones = NULL;
    if (is_array($editing_person)) {
      $encoded = json_encode($editing_person['milestones'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $edit_milestones = is_string($encoded) ? $encoded : '[]';
    }

    $form['timeline_ui']['edit_person']['milestones_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Milestones JSON'),
      '#description' => $this->t('Provide milestones in JSON format, for example {"1990":"Started program","1995":"Promoted"}.'),
      '#rows' => 5,
      '#default_value' => $edit_milestones ?? '',
    ];

    $form['timeline_ui']['edit_person']['actions'] = ['#type' => 'actions'];
    $form['timeline_ui']['edit_person']['actions']['save_person'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'pds_recipe_timeline_save_person',
      '#validate' => ['pds_recipe_timeline_edit_person_validate'],
      '#submit' => ['pds_recipe_timeline_edit_person_submit'],
      '#limit_validation_errors' => [
        ['timeline_ui', 'edit_person'],
      ],
      '#ajax' => [
        'callback' => 'pds_recipe_timeline_ajax_events',
        'wrapper' => 'pds-timeline-form',
      ],
    ];

    $form['timeline_ui']['edit_person']['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'pds_recipe_timeline_cancel_edit',
      '#limit_validation_errors' => [],
      '#submit' => ['pds_recipe_timeline_edit_person_cancel_submit'],
      '#ajax' => [
        'callback' => 'pds_recipe_timeline_ajax_events',
        'wrapper' => 'pds-timeline-form',
      ],
    ];

    return $form;
  }

  /**
   * Save configuration.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();
    $this->configuration['title'] = trim((string) $form_state->getValue('title') ?? '');
    $this->configuration['timeline_id'] = trim((string) $form_state->getValue('timeline_id') ?? 'principal-timeline');
    $this->configuration['events'] = [];

    $people = self::getWorkingPeople($form_state, $cfg['people'] ?? []);
    $clean_people = [];

    foreach ($people as $person) {
      if (!is_array($person)) {
        continue;
      }

      $name = trim((string) ($person['name'] ?? ''));
      $role = trim((string) ($person['role'] ?? ''));
      $milestones_clean = [];

      if (is_array($person['milestones'] ?? NULL)) {
        foreach ($person['milestones'] as $milestone) {
          if (!is_array($milestone)) {
            continue;
          }
          $clean_milestone = $this->cleanMilestoneConfig($milestone);
          if ($clean_milestone !== NULL) {
            $milestones_clean[] = $clean_milestone;
          }
        }
      }

      if ($name === '' && $role === '' && $milestones_clean === []) {
        continue;
      }

      $clean_people[] = [
        'name' => $name,
        'role' => $role,
        'milestones' => $milestones_clean,
      ];
    }

    $this->configuration['people'] = array_values($clean_people);

    $form_state->set('working_people', $this->configuration['people']);
  }

  /**
   * Normalize a milestone array before storing it in configuration.
   */
  private function cleanMilestoneConfig(array $milestone): ?array {
    $year = trim((string) ($milestone['year'] ?? ''));
    $text = trim((string) ($milestone['text'] ?? ''));
    $info = trim((string) ($milestone['info'] ?? ''));
    $info_html = trim((string) ($milestone['info_html'] ?? ''));
    $width = $this->parseNumeric($milestone['width'] ?? ($milestone['width_percent'] ?? $milestone['width_pct'] ?? NULL));
    $principal = array_key_exists('principal', $milestone) ? $this->toBool($milestone['principal']) : FALSE;
    $first = array_key_exists('first', $milestone) ? $this->toBool($milestone['first']) : FALSE;
    $img_src = trim((string) ($milestone['img_src'] ?? $milestone['image'] ?? ''));
    $img_alt = trim((string) ($milestone['img_alt'] ?? $milestone['image_alt'] ?? ''));

    if ($year === '' && $text === '' && $info === '' && $info_html === '' && $img_src === '' && $img_alt === '' && !$principal && !$first && ($width === NULL || $width <= 0)) {
      return NULL;
    }

    $clean = [];
    if ($year !== '') {
      $clean['year'] = $year;
    }
    if ($text !== '') {
      $clean['text'] = $text;
    }
    if ($info !== '') {
      $clean['info'] = $info;
    }
    if ($info_html !== '') {
      $clean['info_html'] = $info_html;
    }
    if ($width !== NULL && $width > 0) {
      $clean['width'] = $width;
    }
    if ($principal) {
      $clean['principal'] = TRUE;
    }
    if ($first) {
      $clean['first'] = TRUE;
    }
    if ($img_src !== '') {
      $clean['img_src'] = $img_src;
    }
    if ($img_alt !== '') {
      $clean['img_alt'] = $img_alt;
    }

    return $clean === [] ? NULL : $clean;
  }

  /**
   * Resolve the current list of people during form interaction.
   */
  private static function getWorkingPeople(FormStateInterface $form_state, array $cfg_people): array {
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

    if (!$is_sub) {
      $submitted = $form_state->getValue(['timeline_ui', 'people_list', 'people']);
      if (is_array($submitted)) {
        $snapshot = $form_state->get('working_people');
        if (is_array($snapshot)) {
          return array_values($snapshot);
        }
      }
    }

    return array_values(is_array($cfg_people) ? $cfg_people : []);
  }

  /**
   * Determine which person is currently being edited, if any.
   */
  private static function getEditingIndex(FormStateInterface $form_state): ?int {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('pds_recipe_timeline_editing_index')) {
      $index = $form_state->get('pds_recipe_timeline_editing_index');
      if (is_numeric($index)) {
        return (int) $index;
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('pds_recipe_timeline_editing_index')) {
        $index = $parent->get('pds_recipe_timeline_editing_index');
        if (is_numeric($index)) {
          return (int) $index;
        }
      }
    }

    return NULL;
  }

  /**
   * Normalize year to two-digit string matching the Twig header.
   */
  private function normalizeYear(string $y): string {
    $y = trim($y);
    if ($y === '') { return ''; }
    if (preg_match('/^\d{4}$/', $y)) {
      $y2 = ((int) $y) % 100;
      return str_pad((string) $y2, 2, '0', STR_PAD_LEFT);
    }
    if (preg_match('/^\d{2}$/', $y)) {
      return $y;
    }
    if (preg_match('/^\d{1,2}$/', $y)) {
      return str_pad($y, 2, '0', STR_PAD_LEFT);
    }
    return '';
  }

  /**
   * Convert a milestone array into a renderable segment.
   */
  private function buildSegment(array $milestone_cfg, int $index): ?array {
    $year_raw = trim((string) ($milestone_cfg['year'] ?? ''));
    $year_norm = $this->normalizeYear($year_raw);
    $text = trim((string) ($milestone_cfg['text'] ?? ''));
    $info_text = trim((string) ($milestone_cfg['info'] ?? ''));
    $info_html = trim((string) ($milestone_cfg['info_html'] ?? ''));
    $img_src = trim((string) ($milestone_cfg['img_src'] ?? $milestone_cfg['image'] ?? ''));
    $img_alt = trim((string) ($milestone_cfg['img_alt'] ?? $milestone_cfg['image_alt'] ?? ''));
    $width = $this->parseNumeric($milestone_cfg['width'] ?? ($milestone_cfg['width_percent'] ?? $milestone_cfg['width_pct'] ?? NULL));

    $principal_value = $milestone_cfg['principal'] ?? ($milestone_cfg['is_principal'] ?? ($milestone_cfg['type'] ?? NULL));
    $principal = $this->toBool($principal_value);
    if (!$principal) {
      $principal = stripos($text, 'principal asset management') !== FALSE
        || stripos($info_text, 'principal asset management') !== FALSE
        || stripos($info_html, 'principal asset management') !== FALSE;
    }

    $first_value = $milestone_cfg['first'] ?? ($milestone_cfg['is_first'] ?? NULL);
    $first = $this->toBool($first_value, $index === 0);
    if ($first_value === NULL) {
      $first = $index === 0;
    }

    $info_markup = '';
    if ($info_html !== '') {
      $info_markup = Xss::filter($info_html, ['br', 'strong', 'em', 'span', 'b', 'i', 'u']);
    }
    elseif ($info_text !== '') {
      $info_markup = Html::escape($info_text);
    }
    else {
      if ($year_raw !== '') {
        $info_markup .= '<strong>' . Html::escape($year_raw) . '</strong>';
      }
      if ($text !== '') {
        $info_markup .= ($info_markup === '' ? '' : ': ') . Html::escape($text);
      }
    }

    if ($info_markup === '' && $text !== '') {
      $info_markup = Html::escape($text);
    }

    if ($info_markup === '' && $img_src === '' && $width === NULL && $year_raw === '' && $text === '') {
      return NULL;
    }

    return [
      'year_norm' => $year_norm,
      'width' => $width,
      'first' => $first,
      'principal' => $principal,
      'info' => $info_markup,
      'img_src' => $img_src !== '' ? $img_src : NULL,
      'img_alt' => $img_alt !== '' ? $img_alt : NULL,
    ];
  }

  /**
   * Turn various numeric formats into float percentages.
   */
  private function parseNumeric($value): ?float {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_numeric($value)) {
      return (float) $value;
    }
    if (is_string($value)) {
      $normalized = str_replace(',', '.', trim($value));
      if ($normalized === '') {
        return NULL;
      }
      if (is_numeric($normalized)) {
        return (float) $normalized;
      }
    }
    return NULL;
  }

  /**
   * Format widths to a compact percentage string.
   */
  private function formatWidth(float $width): string {
    $width = max(0, $width);
    $formatted = sprintf('%.6F', $width);
    return rtrim(rtrim($formatted, '0'), '.');
  }

  /**
   * Interpret booleans coming from mixed user input.
   */
  private function toBool($value, bool $default = FALSE): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_numeric($value)) {
      return ((int) $value) !== 0;
    }
    if (is_string($value)) {
      $value = strtolower(trim($value));
      if ($value === '') {
        return $default;
      }
      if (in_array($value, ['1', 'true', 'yes', 'y', 'on', 'principal'], TRUE)) {
        return TRUE;
      }
      if (in_array($value, ['0', 'false', 'no', 'n', 'off'], TRUE)) {
        return FALSE;
      }
    }
    return $default;
  }
}
