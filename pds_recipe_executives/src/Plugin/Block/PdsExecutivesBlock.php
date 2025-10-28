<?php

namespace Drupal\pds_recipe_executives\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\file\Entity\File;

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
   *
   * Stored config shape per executive:
   * [
   *   'fid'      => (int|null),
   *   'url'      => (string) public file URL for <img src> or background-image,
   *   'name'     => (string),
   *   'title'    => (string),
   *   'linkedin' => (string),
   *   'cv_html'  => (string HTML),
   * ]
   */
  public function defaultConfiguration(): array {
    return [
      'executives' => [],
    ];
  }

  /**
   * Decide which exec rows to render in the admin form right now.
   *
   * Priority:
   * 1. $form_state->get('working_execs')
   * 2. parent complete form state's working_execs (if using Layout Builder SubformState)
   * 3. submitted values ['executives_ui','execs'] (only in normal block config path)
   * 4. saved configuration ($cfg_execs)
   */
  private static function getWorkingExecs(FormStateInterface $form_state, array $cfg_execs): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    // 1. direct working_execs
    if ($form_state->has('working_execs')) {
      $tmp = $form_state->get('working_execs');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // 2. parent form_state if SubformState (Layout Builder edit block dialog)
    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent && $parent->has('working_execs')) {
        $tmp = $parent->get('working_execs');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    // 3. normal non-subform case, pull raw submitted table values
    if (!$is_sub) {
      $submitted = $form_state->getValue(['executives_ui', 'execs']);
      if (is_array($submitted)) {
        return array_values($submitted);
      }
    }

    // 4. fallback to saved config
    if (!is_array($cfg_execs)) {
      $cfg_execs = [];
    }
    return array_values($cfg_execs);
  }

  /**
   * {@inheritdoc}
   * Frontend render array for this block.
   *
   * Converts stored config rows into what Twig expects.
   * Twig template pds-executives.html.twig loops through "executives".
   *
   * We pass:
   * - name
   * - title
   * - linkedin
   * - photo_url
   * - cv_html
   */
  public function build(): array {
    $cfg = $this->getConfiguration();
    $stored = $cfg['executives'] ?? [];
    if (!is_array($stored)) {
      $stored = [];
    }

    $executives_for_twig = [];
    foreach ($stored as $row) {
      if (!is_array($row)) {
        continue;
      }

      $executives_for_twig[] = [
        'name'       => $row['name']     ?? '',
        'title'      => $row['title']    ?? '',
        'linkedin'   => $row['linkedin'] ?? '',
        'photo_url'  => $row['url']      ?? '',
        'cv_html'    => $row['cv_html']  ?? '',
      ];
    }

    return [
      '#theme'      => 'pds_executives',
      '#executives' => $executives_for_twig,
      '#attached'   => [
        'library' => [
          // Must exist in pds_recipe_executives.libraries.yml
          // pds_executives:
          //   css:
          //     theme:
          //       css/pds_executives.css: {}
          //   js:
          //     js/pds_executives.js: {}
          //   dependencies:
          //     - core/drupal
          //     - core/drupalSettings
          'pds_recipe_executives/pds_executives',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   * Admin configuration form for the block.
   *
   * Lets you manage N executives.
   * Each row has managed_file + name + title + linkedin + bio + remove.
   * Supports AJAX "Add executive" / "Remove selected".
   * Works in both standard block config and Layout Builder dialog.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();

    // Grab current list.
    $execs_working = self::getWorkingExecs($form_state, $cfg['executives'] ?? []);

    // Seed working_execs on first build.
    // Without this, first AJAX "Add executive" in Layout Builder can lose existing rows,
    // because getValue([...]) may return empty and there is no fallback snapshot.
    if (!$form_state->has('working_execs')) {
      $form_state->set('working_execs', $execs_working);
    }

    // Wrapper container replaced by AJAX.
    $form['executives_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-executives-form',
      ],
    ];

    // Table of execs.
    $form['executives_ui']['execs'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Photo'),
        $this->t('Name'),
        $this->t('Title'),
        $this->t('LinkedIn URL'),
        $this->t('Bio (HTML)'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No executives yet. Click "Add executive".'),
    ];

    foreach ($execs_working as $i => $row) {
      $form['executives_ui']['execs'][$i]['fid'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Photo'),
        '#title_display' => 'invisible',
        '#upload_location' => 'public://executives/',
        '#default_value' => (isset($row['fid']) && $row['fid']) ? [$row['fid']] : [],
        '#upload_validators' => [
          'file_validate_extensions' => ['png jpg jpeg webp'],
        ],
      ];

      $form['executives_ui']['execs'][$i]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#title_display' => 'invisible',
        '#default_value' => $row['name'] ?? '',
        '#size' => 32,
        '#maxlength' => 255,
      ];

      $form['executives_ui']['execs'][$i]['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#title_display' => 'invisible',
        '#default_value' => $row['title'] ?? '',
        '#size' => 64,
        '#maxlength' => 255,
      ];

      $form['executives_ui']['execs'][$i]['linkedin'] = [
        '#type' => 'url',
        '#title' => $this->t('LinkedIn URL'),
        '#title_display' => 'invisible',
        '#default_value' => $row['linkedin'] ?? '',
        '#maxlength' => 512,
      ];

      $form['executives_ui']['execs'][$i]['cv_html'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Bio (HTML)'),
        '#title_display' => 'invisible',
        '#default_value' => $row['cv_html'] ?? '',
        '#rows' => 4,
        '#description' => $this->t('You may include basic HTML paragraphs, line breaks, etc.'),
      ];

      $form['executives_ui']['execs'][$i]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#title_display' => 'invisible',
      ];
    }

    // Actions.
    $form['executives_ui']['actions'] = [
      '#type' => 'actions',
    ];

    // Add executive.
    $form['executives_ui']['actions']['add_exec'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add executive'),
      '#name'  => 'pds_recipe_executives_add_exec',
      '#submit' => ['pds_recipe_executives_add_exec_submit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_execs',
        'wrapper'  => 'pds-executives-form',
      ],
    ];

    // Remove selected.
    $form['executives_ui']['actions']['remove_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name'  => 'pds_recipe_executives_remove_selected',
      '#submit' => ['pds_recipe_executives_remove_selected_submit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => 'pds_recipe_executives_ajax_execs',
        'wrapper'  => 'pds-executives-form',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   * Save block configuration.
   *
   * - Read rows from form_state (block config or Layout Builder).
   * - Drop rows marked remove or with empty name.
   * - For each uploaded file fid:
   *   - set file permanent
   *   - store public URL in config
   * - Save into $this->configuration['executives'].
   * - Mirror that result to working_execs so UI stays in sync.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $submitted_execs = $form_state->getValue(['executives_ui', 'execs']);
    if (!is_array($submitted_execs)) {
      $submitted_execs = $form_state->getValue(['settings', 'executives_ui', 'execs']);
    }
    if (!is_array($submitted_execs)) {
      $submitted_execs = [];
    }

    $clean_execs = [];

    foreach ($submitted_execs as $row) {
      if (!is_array($row)) {
        continue;
      }

      if (!empty($row['remove'])) {
        continue;
      }

      $name     = trim($row['name'] ?? '');
      $title    = trim($row['title'] ?? '');
      $linkedin = trim($row['linkedin'] ?? '');
      $cv_html  = trim($row['cv_html'] ?? '');

      $fid_list = $row['fid'] ?? [];
      $fid = (is_array($fid_list) && isset($fid_list[0])) ? $fid_list[0] : NULL;

      $url = '';
      if ($fid) {
        $file = File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();

          $url = \Drupal::service('file_url_generator')
            ->generateString($file->getFileUri());
        }
      }

      if ($name === '') {
        continue;
      }

      $clean_execs[] = [
        'fid'      => $fid,
        'url'      => $url,
        'name'     => $name,
        'title'    => $title,
        'linkedin' => $linkedin,
        'cv_html'  => $cv_html,
      ];
    }

    $this->configuration['executives'] = array_values($clean_execs);

    // keep latest list available for AJAX rebuilds
    $form_state->set('working_execs', $this->configuration['executives']);
  }

}
