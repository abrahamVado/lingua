<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

use Drupal\Component\Utility\Html;
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
      $milestones = [];

      if (is_array($person_cfg['milestones'] ?? NULL)) {
        foreach ($person_cfg['milestones'] as $milestone_cfg) {
          if (!is_array($milestone_cfg)) {
            continue;
          }

          $raw_year = trim((string) ($milestone_cfg['year'] ?? ''));
          $normalized_year = $this->normalizeYear($raw_year);
          $milestone_text = trim((string) ($milestone_cfg['text'] ?? ''));

          if ($normalized_year !== '') {
            $years[$normalized_year] = TRUE;
          }

          if ($raw_year === '' && $milestone_text === '') {
            continue;
          }

          $milestones[] = [
            'year_raw' => $raw_year !== '' ? $raw_year : $normalized_year,
            'year_norm' => $normalized_year,
            'text' => $milestone_text,
          ];
        }
      }

      if ($milestones === []) {
        continue;
      }

      $segment_count = count($milestones);
      $segment_width = $segment_count > 0 ? 100 / $segment_count : 100;
      $segments = [];

      //2.- Transform milestones into visual timeline segments for Twig.
      foreach ($milestones as $milestone_index => $milestone) {
        $segment_info = '';
        if ($milestone['year_raw'] !== '') {
          $segment_info .= '<strong>' . Html::escape($milestone['year_raw']) . '</strong>';
        }
        if ($milestone['text'] !== '') {
          $segment_info .= ($segment_info === '' ? '' : ': ') . Html::escape($milestone['text']);
        }

        $segments[] = [
          'width' => $segment_width,
          'first' => $milestone_index === 0,
          'principal' => $milestone_index === 0,
          'info' => $segment_info,
          'img_src' => NULL,
          'img_alt' => NULL,
        ];
      }

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

    $form['timeline_ui']['people_list']['people'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Header'),
        $this->t('Subheader'),
        $this->t('Milestones'),
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
          if ($year_label === '' && $text_label === '') {
            continue;
          }
          $milestone_items[] = $year_label === ''
            ? $text_label
            : $this->t('@year: @text', ['@year' => $year_label, '@text' => $text_label]);
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
          $year = trim((string) ($milestone['year'] ?? ''));
          $text = trim((string) ($milestone['text'] ?? ''));

          if ($year === '' && $text === '') {
            continue;
          }

          $milestones_clean[] = [
            'year' => $year,
            'text' => $text,
          ];
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
}
