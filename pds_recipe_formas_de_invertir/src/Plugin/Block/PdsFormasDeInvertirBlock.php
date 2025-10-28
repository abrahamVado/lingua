<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_formas_de_invertir\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Component\Utility\NestedArray;

/**
 * @Block(
 *   id = "pds_formas_de_invertir_block",
 *   admin_label = @Translation("PDS Formas de Investir"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsFormasDeInvertirBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'header' => 'Conoce nuestras',
      'header_accent' => 'formas de invertir',
      'cards' => [],
      'aria_label' => 'Formas de invertir',
      'prev_label' => 'Anterior',
      'next_label' => 'Siguiente',
    ];
  }

  /** Settings globales con soporte a legacy JSON. */
  private function settingsDefaults(): array {
    $cfg = \Drupal::config('pds_recipe_formas_de_invertir.settings');
    $out = [
      'header'        => (string) ($cfg->get('header') ?? 'Conoce nuestras'),
      'header_accent' => (string) ($cfg->get('header_accent') ?? 'formas de invertir'),
      'aria_label'    => (string) ($cfg->get('aria_label') ?? 'Formas de invertir'),
      'prev_label'    => (string) ($cfg->get('prev_label') ?? 'Anterior'),
      'next_label'    => (string) ($cfg->get('next_label') ?? 'Siguiente'),
      'cards'         => [],
    ];
    $cards = [];
    if ($list = $cfg->get('cards')) {
      foreach ((array) $list as $i) {
        $cards[] = [
          'header' => (string) ($i['header'] ?? ($i['title'] ?? '')),
          'text'   => (string) ($i['text'] ?? ''),
          'url'    => (string) ($i['url'] ?? '#'),
        ];
      }
    } elseif ($json = $cfg->get('cards_json')) {
      foreach (json_decode((string) $json, true) ?: [] as $i) {
        $cards[] = [
          'header' => (string) ($i['header'] ?? ($i['title'] ?? '')),
          'text'   => (string) ($i['text'] ?? ''),
          'url'    => (string) ($i['url'] ?? '#'),
        ];
      }
    }
    $out['cards'] = $cards;
    return $out;
  }

  /** Lee las filas tipeadas del estado completo (LB o normal). */
  private function readTyped(FormStateInterface $form_state): array {
    $vals = ($form_state instanceof SubformState)
      ? $form_state->getCompleteFormState()->getValues()
      : $form_state->getValues();

    $v = NestedArray::getValue($vals, ['settings', 'cards_wrapper', 'cards'])
      ?? NestedArray::getValue($vals, ['cards_wrapper', 'cards'])
      ?? null;

    return is_array($v) ? array_values($v) : [];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $inst = $this->getConfiguration() ?? [];
    $inst_cards = is_array($inst['cards'] ?? null) ? $inst['cards'] : [];

    // Inicializa snapshot una sola vez.
    if (!$form_state->has('cards_snapshot')) {
      $typed = $this->readTyped($form_state);
      $form_state->set('cards_snapshot', $typed ?: $inst_cards);
    }

    $cards = array_values($form_state->get('cards_snapshot') ?? []);
    $rows  = max(1, min(count($cards), 12));
    $form_state->set('cards_count', $rows);

    $form['cards_wrapper'] = [
      '#type' => 'container',
      '#prefix' => '<div id="pds-formas-cards-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    $form['cards_wrapper']['cards'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [$this->t('Header'), $this->t('Text'), $this->t('URL'), $this->t('Operations')],
      '#empty' => $this->t('No cards added yet.'),
    ];

    for ($i = 0; $i < $rows; $i++) {
      $d = $cards[$i] ?? ['header' => '', 'text' => '', 'url' => ''];
      $form['cards_wrapper']['cards'][$i]['header'] = [
        '#type' => 'textfield',
        '#default_value' => $d['header'],
        '#title' => $this->t('Header'),
        '#title_display' => 'invisible',
        '#required' => TRUE,
      ];
      $form['cards_wrapper']['cards'][$i]['text'] = [
        '#type' => 'textfield',
        '#default_value' => $d['text'],
        '#title' => $this->t('Text'),
        '#title_display' => 'invisible',
        '#required' => TRUE,
      ];
      $form['cards_wrapper']['cards'][$i]['url'] = [
        '#type' => 'textfield',
        '#default_value' => $d['url'],
        '#title' => $this->t('URL'),
        '#title_display' => 'invisible',
        '#required' => TRUE,
      ];
      $form['cards_wrapper']['cards'][$i]['ops'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => "pds_formas_remove_$i",
        '#submit' => [[ $this, 'removeRowSubmit' ]],   // <— antes: '::removeRowSubmit'
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [ $this, 'ajaxRefresh' ],      // <— antes: '::ajaxRefresh'
          'wrapper'  => 'pds-formas-cards-wrapper',
          'event'    => 'click',
        ],
        '#attributes' => ['data-row' => (string) $i],
      ];
    }

    $form['cards_wrapper']['actions'] = ['#type' => 'actions'];
    $form['cards_wrapper']['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add card'),
      '#name' => 'pds_formas_add_card',
      '#submit' => [[ $this, 'addRowSubmit' ]],      // <— antes: '::addRowSubmit'
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [ $this, 'ajaxRefresh' ],      // <— antes: '::ajaxRefresh'
        'wrapper'  => 'pds-formas-cards-wrapper',
        'event'    => 'click',
      ],
];

    // Labels (instancia).
    $form['header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#default_value' => (string) ($inst['header'] ?? ''),
      '#required' => TRUE,
    ];
    $form['header_accent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título destacado'),
      '#default_value' => (string) ($inst['header_accent'] ?? ''),
      '#required' => TRUE,
    ];
    $form['aria_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etiqueta ARIA del slider'),
      '#default_value' => (string) ($inst['aria_label'] ?? ''),
    ];
    $form['prev_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etiqueta botón anterior'),
      '#default_value' => (string) ($inst['prev_label'] ?? ''),
    ];
    $form['next_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etiqueta botón siguiente'),
      '#default_value' => (string) ($inst['next_label'] ?? ''),
    ];

    return $form;
  }

  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    if (isset($form['cards_wrapper'])) {
      return $form['cards_wrapper'];
    }
    $el = NestedArray::getValue($form, ['settings', 'cards_wrapper']);
    return is_array($el) ? $el : $form;
  }

  public function addRowSubmit(array &$form, FormStateInterface $form_state): void {
    $cards = $form_state->get('cards_snapshot') ?? $this->readTyped($form_state);
    $cards[] = ['header' => '', 'text' => '', 'url' => ''];
    $cards = array_slice(array_values($cards), 0, 12);
    $form_state->set('cards_snapshot', $cards);
    $form_state->set('cards_count', count($cards));
    $form_state->setRebuild(TRUE);
  }

  public function removeRowSubmit(array &$form, FormStateInterface $form_state): void {
    $cards = $form_state->get('cards_snapshot') ?? $this->readTyped($form_state);
    $row = (int) (($form_state->getTriggeringElement()['#attributes']['data-row'] ?? -1));
    if (isset($cards[$row])) {
      unset($cards[$row]);
    }
    $cards = array_values($cards);
    $form_state->set('cards_snapshot', $cards);
    $form_state->set('cards_count', max(1, count($cards)));
    $form_state->setRebuild(TRUE);
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $vals = ($form_state instanceof SubformState)
      ? $form_state->getCompleteFormState()->getValues()
      : $form_state->getValues();

    $rows = NestedArray::getValue($vals, ['settings', 'cards_wrapper', 'cards'])
         ?? NestedArray::getValue($vals, ['cards_wrapper', 'cards'])
         ?? [];

    $cards = [];
    foreach ((array) $rows as $r) {
      $h = trim((string) ($r['header'] ?? ''));
      $t = trim((string) ($r['text'] ?? ''));
      $u = trim((string) ($r['url'] ?? ''));
      if ($h === '' && $t === '' && ($u === '' || $u === '#')) {
        continue;
      }
      $cards[] = ['header' => $h, 'text' => $t, 'url' => ($u !== '' ? $u : '#')];
    }

    $this->setConfiguration([
      'header'        => (string) (NestedArray::getValue($vals, ['settings', 'header'])        ?? $vals['header']        ?? ''),
      'header_accent' => (string) (NestedArray::getValue($vals, ['settings', 'header_accent']) ?? $vals['header_accent'] ?? ''),
      'cards'         => $cards,
      'aria_label'    => (string) (NestedArray::getValue($vals, ['settings', 'aria_label'])    ?? $vals['aria_label']    ?? ''),
      'prev_label'    => (string) (NestedArray::getValue($vals, ['settings', 'prev_label'])    ?? $vals['prev_label']    ?? ''),
      'next_label'    => (string) (NestedArray::getValue($vals, ['settings', 'next_label'])    ?? $vals['next_label']    ?? ''),
    ]);

    $form_state->set('cards_snapshot', NULL);
    $form_state->set('cards_count', max(1, min(count($cards), 12)));
  }

  public function build(): array {
    $inst = $this->getConfiguration() ?? [];
    $labels = $this->settingsDefaults();

    return [
      '#theme' => 'pds_formas_de_invertir',
      '#header' => (string) ($inst['header'] ?? $labels['header']),
      '#header_accent' => (string) ($inst['header_accent'] ?? $labels['header_accent']),
      '#cards' => is_array($inst['cards'] ?? null) ? $inst['cards'] : [],
      '#aria_label' => (string) ($inst['aria_label'] ?? $labels['aria_label']),
      '#prev_label' => (string) ($inst['prev_label'] ?? $labels['prev_label']),
      '#next_label' => (string) ($inst['next_label'] ?? $labels['next_label']),
      '#attached' => ['library' => ['pds_recipe_formas_de_invertir/principal_invest']],
      '#cache' => [
        'max-age' => 0,
        'tags' => ['config:pds_recipe_formas_de_invertir.settings'],
      ],
    ];
  }
}
