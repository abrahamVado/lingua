<?php

namespace Drupal\pds_recipe_mapa\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Url;

/**
 * @Block(
 *   id = "pds_mapa_block",
 *   admin_label = @Translation("PDS Map with pins"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsMapaBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'mapa_src' => 'images/places-map.png',
      'mapa_alt' => 'Mapa mundial',
      'pins' => [],
    ];
  }

  /**
   * Resolve map path for public render.
   */
  private function resolveModulePath(string $pathOrUri, string $fallbackModule = 'pds_recipe_mapa'): string {
    // Absolute or external.
    if (preg_match('@^https?://|^/@', $pathOrUri)) {
      return $pathOrUri;
    }

    // module://<module>/<relpath>
    if (preg_match('@^module://([^/]+)/(.+)$@', $pathOrUri, $m)) {
      $mod = $m[1];
      $rel = $m[2];
    }
    else {
      $mod = $fallbackModule;
      $rel = ltrim($pathOrUri, '/');
    }

    $modPath = \Drupal::service('extension.list.module')->getPath($mod);
    if (!$modPath) {
      return $pathOrUri;
    }

    return Url::fromUri('base:/' . $modPath . '/' . $rel)->toString();
  }

  /**
   * Get the pin list that should currently be rendered in the admin form.
   *
   * Priority:
   * 1. working_pins on this FormState (after Add pin / Remove selected).
   * 2. working_pins on parent FormState if we're in SubformState (Layout Builder).
   * 3. Submitted values (pins_ui[pins]) when in normal block config form.
   * 4. Saved configuration.
   */
  private static function getWorkingPins(FormStateInterface $form_state, array $config_pins): array {
    $is_sub = $form_state instanceof SubformStateInterface;

    // 1. Check local working_pins.
    if ($form_state->has('working_pins')) {
      $tmp = $form_state->get('working_pins');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // 2. If SubformState, try the complete parent state.
    if ($is_sub && method_exists($form_state, 'getCompleteFormState')) {
      $parent_state = $form_state->getCompleteFormState();
      if ($parent_state && $parent_state->has('working_pins')) {
        $tmp = $parent_state->get('working_pins');
        if (is_array($tmp)) {
          return array_values($tmp);
        }
      }
    }

    // 3. Normal block config after submit but before save.
    if (!$is_sub) {
      $submitted = $form_state->getValue(['pins_ui', 'pins']);
      if (is_array($submitted)) {
        return array_values($submitted);
      }
    }

    // 4. Fallback to saved config.
    if (!is_array($config_pins)) {
      $config_pins = [];
    }
    return array_values($config_pins);
  }

  /**
   * {@inheritdoc}
   * Frontend render (public site).
   */
  public function build(): array {
    $cfg = $this->getConfiguration();

    $resolved_src = $this->resolveModulePath(
      $cfg['mapa_src'] ?? 'images/places-map.png',
      'pds_recipe_mapa'
    );

    return [
      '#theme'    => 'pds_mapa',
      '#mapa_src' => $resolved_src,
      '#mapa_alt' => $cfg['mapa_alt'] ?? 'Mapa mundial',
      '#pins'     => array_values($cfg['pins'] ?? []),
      '#attached' => [
        'library' => [
          // public-facing tooltip/highlight behavior
          'pds_recipe_mapa/principal_mapa',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   * Admin config UI and Layout Builder edit UI.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $cfg = $this->getConfiguration();

    // This is the canonical in-memory list for this rebuild.
    $pins_working = self::getWorkingPins($form_state, $cfg['pins'] ?? []);

    // Map image URL textfield.
    $form['mapa_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map image URL'),
      '#default_value' => $cfg['mapa_src'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Example: module://pds_recipe_mapa/images/places-map.png'),
    ];

    // Map alt text.
    $form['mapa_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map alt text'),
      '#default_value' => $cfg['mapa_alt'] ?? 'Mapa mundial',
    ];

    // AJAX wrapper for preview + table + actions.
    $form['pins_ui'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-map-form',
      ],
      '#attached' => [
        'library' => [
          // admin-only JS with drag support
          'pds_recipe_mapa/principal_mapa.admin',
        ],
      ],
    ];

    // Preview map with draggable pins.
    $form['pins_ui']['preview'] = [
      '#type' => 'inline_template',
      '#template' => '
        <div class="pds-map-preview-wrapper">
          <div class="pds-map-preview" data-drupal-pds-map-preview>
            <img class="pds-map-preview__img"
                 src="{{ src }}"
                 alt="{{ alt }}" />

            {% for i,p in pins %}
              {% set px = p.x|default(0) %}
              {% set py = p.y|default(0) %}
              <button
                type="button"
                class="pin pds-map-preview__pin"
                data-index="{{ i }}"
                style="left: {{ (px * 100)|number_format(3, ".", "") }}%;
                       top:  {{ (py * 100)|number_format(3, ".", "") }}%;"
                data-x="{{ px }}"
                data-y="{{ py }}"
                data-city="{{ p.city|default("")|e }}"
                data-txt="{{ p.txt|default("")|e("html_attr") }}"
                aria-label="{{ p.aria|default(p.city)|default("")|e }}"
              >
                <span class="pulse" aria-hidden="true"></span>
              </button>
            {% endfor %}
          </div>

          <div class="description">
            {{ desc }}
          </div>
        </div>
      ',
      '#context' => [
        'src'  => $this->resolveModulePath(
          $cfg['mapa_src'] ?? 'images/places-map.png',
          'pds_recipe_mapa'
        ),
        'alt'  => $cfg['mapa_alt'] ?? 'Mapa mundial',
        'pins' => $pins_working,
        'desc' => $this->t('Arraste os pinos. Use "Add pin" para criar outro. Campos X/Y são frações de 0 a 1.'),
      ],
    ];

    // Table of pin fields.
    $form['pins_ui']['pins'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('X (0..1)'),
        $this->t('Y (0..1)'),
        $this->t('City'),
        $this->t('Tooltip HTML'),
        $this->t('ARIA label'),
        $this->t('Remove'),
      ],
      '#empty' => $this->t('No pins yet. Add one below.'),
    ];

    foreach ($pins_working as $i => $pin_row) {
      $form['pins_ui']['pins'][$i]['x'] = [
        '#type' => 'number',
        '#step' => '.001',
        '#min'  => 0,
        '#max'  => 1,
        '#default_value' => $pin_row['x'] ?? 0,
        '#attributes' => [
          'data-pds-map-field' => 'x',
          'data-pds-map-index' => $i,
        ],
      ];
      $form['pins_ui']['pins'][$i]['y'] = [
        '#type' => 'number',
        '#step' => '.001',
        '#min'  => 0,
        '#max'  => 1,
        '#default_value' => $pin_row['y'] ?? 0,
        '#attributes' => [
          'data-pds-map-field' => 'y',
          'data-pds-map-index' => $i,
        ],
      ];
      $form['pins_ui']['pins'][$i]['city'] = [
        '#type' => 'textfield',
        '#default_value' => $pin_row['city'] ?? '',
      ];
      $form['pins_ui']['pins'][$i]['txt'] = [
        '#type' => 'textarea',
        '#rows' => 2,
        '#default_value' => $pin_row['txt'] ?? '',
        '#description'   => $this->t('HTML allowed.'),
      ];
      $form['pins_ui']['pins'][$i]['aria'] = [
        '#type' => 'textfield',
        '#default_value' => $pin_row['aria'] ?? ($pin_row['city'] ?? ''),
      ];
      $form['pins_ui']['pins'][$i]['remove'] = [
        '#type' => 'checkbox',
      ];
    }

    // Action buttons inside same AJAX wrapper.
    $form['pins_ui']['actions'] = [
      '#type' => 'actions',
    ];

    // Add pin button.
    $form['pins_ui']['actions']['add_row'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add pin'),
      '#name'  => 'pds_recipe_mapa_add_row',
      '#submit' => [
        [$this, 'submitAddPin'],
      ],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [$this, 'ajaxPins'],
        'wrapper'  => 'pds-map-form',
      ],
    ];

    // Remove selected button.
    $form['pins_ui']['actions']['remove_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove selected'),
      '#name'  => 'pds_recipe_mapa_remove_selected',
      '#submit' => [
        [$this, 'submitRemovePins'],
      ],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [$this, 'ajaxPins'],
        'wrapper'  => 'pds-map-form',
      ],
    ];

    return $form;
  }

  /**
   * AJAX callback to re-render just the pins_ui container.
   * Called after Add pin / Remove selected in both normal block form and LB.
   */
  public function ajaxPins(array &$form, FormStateInterface $form_state) {
    // Layout Builder wraps block config under 'settings'.
    if (isset($form['settings']['pins_ui'])) {
      return $form['settings']['pins_ui'];
    }
    return $form['pins_ui'];
  }

  /**
   * Submit handler for "Add pin".
   * Appends a new pin to the current working list and requests a rebuild.
   */
  public function submitAddPin(array &$form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();

    // Current working list before adding.
    $pins = self::getWorkingPins($form_state, $cfg['pins'] ?? []);

    // Append new pin with default position 0.5,0.5.
    $pins[] = [
      'x'    => 0.5,
      'y'    => 0.5,
      'city' => '',
      'txt'  => '',
      'aria' => '',
    ];

    // Stash updated list in working_pins for the next rebuild cycle.
    $form_state->set('working_pins', $pins);

    // Rebuild the form so blockForm() sees the new list and draws it.
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for "Remove selected".
   * Drops rows where "remove" checkbox is checked.
   */
  public function submitRemovePins(array &$form, FormStateInterface $form_state): void {
    $cfg = $this->getConfiguration();

    // Get whatever user has right now (including checkboxes).
    $pins_raw = self::getWorkingPins($form_state, $cfg['pins'] ?? []);

    // Filter out checked rows.
    $clean = [];
    foreach ($pins_raw as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (!empty($row['remove'])) {
        continue;
      }
      $clean[] = $row;
    }

    $form_state->set('working_pins', $clean);
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   * Save config when user hits Save in the block form.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    // Try table values from normal block edit first.
    $submitted_pins = $form_state->getValue(['pins_ui', 'pins']);

    // Fallback Layout Builder nested form.
    if (!is_array($submitted_pins)) {
      $submitted_pins = $form_state->getValue(['settings', 'pins_ui', 'pins']);
    }
    if (!is_array($submitted_pins)) {
      $submitted_pins = [];
    }

    $clean = [];
    foreach ($submitted_pins as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (!empty($row['remove'])) {
        continue;
      }
      $city = trim($row['city'] ?? '');
      $has_xy = isset($row['x'], $row['y']);
      if ($city === '' || !$has_xy) {
        continue;
      }
      $clean[] = [
        'x'    => (float) $row['x'],
        'y'    => (float) $row['y'],
        'city' => $city,
        'txt'  => $row['txt'] ?? '',
        'aria' => $row['aria'] ?? $city,
      ];
    }

    // Save on the block.
    $this->configuration['mapa_src'] = (string) $form_state->getValue('mapa_src');
    $this->configuration['mapa_alt'] = (string) $form_state->getValue('mapa_alt');
    $this->configuration['pins']     = array_values($clean);

    // Sync working_pins so if the form stays open post-save we still show what was saved.
    $form_state->set('working_pins', array_values($clean));
  }

}
