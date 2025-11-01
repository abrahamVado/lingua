<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

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
    ];
  }

  /**
   * Build exactly what the Twig expects.
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $events = is_array($cfg['events'] ?? NULL) ? $cfg['events'] : [];

    $norm = [];
    $year_set = [];
    foreach ($events as $row) {
      if (!is_array($row)) { continue; }

      $year = $this->normalizeYear((string) ($row['year'] ?? ''));
      if ($year === '') { continue; }

      $headline = trim((string) ($row['headline'] ?? ''));
      $summary  = trim((string) ($row['summary'] ?? ''));
      $cta_lbl  = trim((string) ($row['cta_label'] ?? ''));
      $cta_url  = trim((string) ($row['cta_url'] ?? ''));

      $info = $summary;
      if ($cta_lbl !== '' && $cta_url !== '') {
        $info .= ($info === '' ? '' : '<br>') .
          '<a href="' . htmlspecialchars($cta_url, ENT_QUOTES) . '">' .
          htmlspecialchars($cta_lbl, ENT_QUOTES) . '</a>';
      }

      $norm[] = [
        'year_raw' => $year,
        'headline' => $headline,
        'info' => $info,
      ];
      $year_set[$year] = TRUE;
    }

    $years = array_keys($year_set);
    usort($years, static fn($a, $b) => ((int) $a) <=> ((int) $b));

    $rows = [];
    foreach ($norm as $ev) {
      $rows[] = [
        'person' => [
          'name' => $ev['year_raw'],
          'role' => $ev['headline'],
        ],
        'segments' => [
          [
            'width' => 100,
            'first' => TRUE,
            'principal' => FALSE,
            'info' => $ev['info'],
            'img_src' => NULL,
            'img_alt' => NULL,
          ],
        ],
      ];
    }

    $title = trim((string) ($cfg['title'] ?? '')) ?: ($this->label() ?? 'Timeline');
    $timeline_id = trim((string) ($cfg['timeline_id'] ?? '')) ?: 'principal-timeline';

    return [
      '#theme' => 'pds_timeline',
      '#title' => $title,
      '#years' => $years,
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
    $events_working = self::getWorkingEvents($form_state, $cfg['events'] ?? []);

    if (!$form_state->has('working_events')) {
      $form_state->set('working_events', $events_working);
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

    $form['timeline_ui']['events'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Year'),
        $this->t('Headline'),
        $this->t('Summary (HTML allowed)'),
        $this->t('Link label'),
        $this->t('Link URL'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No timeline events yet. Click "Add event".'),
    ];

    foreach ($events_working as $i => $row) {
      $form['timeline_ui']['events'][$i]['year'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Year'),
        '#title_display' => 'invisible',
        '#default_value' => $row['year'] ?? '',
        '#size' => 10,
        '#maxlength' => 32,
      ];
      $form['timeline_ui']['events'][$i]['headline'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Headline'),
        '#title_display' => 'invisible',
        '#default_value' => $row['headline'] ?? '',
        '#size' => 64,
        '#maxlength' => 255,
      ];
      $form['timeline_ui']['events'][$i]['summary'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Summary (HTML allowed)'),
        '#title_display' => 'invisible',
        '#default_value' => $row['summary'] ?? '',
        '#rows' => 4,
        '#description' => $this->t('Simple HTML like <p> or <strong> is allowed.'),
      ];
      $form['timeline_ui']['events'][$i]['cta_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Link label'),
        '#title_display' => 'invisible',
        '#default_value' => $row['cta_label'] ?? '',
        '#size' => 32,
        '#maxlength' => 128,
      ];
      $form['timeline_ui']['events'][$i]['cta_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Link URL'),
        '#title_display' => 'invisible',
        '#default_value' => $row['cta_url'] ?? '',
        '#maxlength' => 512,
      ];
      $form['timeline_ui']['events'][$i]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#title_display' => 'invisible',
      ];
    }

    $form['timeline_ui']['actions'] = ['#type' => 'actions'];

    $form['timeline_ui']['actions']['add_event'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add event'),
      '#name' => 'pds_recipe_timeline_add_event',
      '#submit' => ['pds_recipe_timeline_add_event_submit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => 'pds_recipe_timeline_ajax_events',
        'wrapper' => 'pds-timeline-form',
      ],
    ];

    $form['timeline_ui']['actions']['remove_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name' => 'pds_recipe_timeline_remove_selected',
      '#submit' => ['pds_recipe_timeline_remove_selected_submit'],
      '#limit_validation_errors' => [],
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
    $submitted_events = $form_state->getValue(['timeline_ui', 'events']);
    if (!is_array($submitted_events)) {
      $submitted_events = $form_state->getValue(['settings', 'timeline_ui', 'events']);
    }
    if (!is_array($submitted_events)) {
      $submitted_events = [];
    }

    $clean = [];
    foreach ($submitted_events as $row) {
      if (!is_array($row) || !empty($row['remove'])) { continue; }

      $year = trim((string) ($row['year'] ?? ''));
      $headline = trim((string) ($row['headline'] ?? ''));
      $summary = (string) ($row['summary'] ?? '');
      $cta_label = trim((string) ($row['cta_label'] ?? ''));
      $cta_url = trim((string) ($row['cta_url'] ?? ''));

      if ($year === '' && $headline === '' && $summary === '') { continue; }

      $clean[] = [
        'year' => $year,
        'headline' => $headline,
        'summary' => $summary,
        'cta_label' => $cta_label,
        'cta_url' => $cta_url,
      ];
    }

    $this->configuration['title'] = trim((string) $form_state->getValue('title') ?? '');
    $this->configuration['timeline_id'] = trim((string) $form_state->getValue('timeline_id') ?? 'principal-timeline');
    $this->configuration['events'] = array_values($clean);

    $form_state->set('working_events', $this->configuration['events']);
  }

  /**
   * Working rows resolver for classic form and LB subform.
   */
  private static function getWorkingEvents(FormStateInterface $form_state, array $cfg_events): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    if ($form_state->has('working_events')) {
      $tmp = $form_state->get('working_events');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('working_events')) {
        $tmp = $parent->get('working_events');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    if (!$is_sub) {
      $submitted = $form_state->getValue(['timeline_ui', 'events']);
      if (is_array($submitted)) {
        return array_values($submitted);
      }
    }

    return array_values(is_array($cfg_events) ? $cfg_events : []);
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
