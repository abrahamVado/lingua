<?php

namespace Drupal\pds_recipe_fondos_mutuos\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ExtensionList;
/**
 * @Block(
 *   id = "pds_fondos_mutuos_block",
 *   admin_label = @Translation("PDS Fondos Mutuos"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsFondosMutuosBlock extends BlockBase {

  /** @var int number of cards shown in the form */
  private const ITEM_COUNT = 3;

  public function defaultConfiguration() {
    $base = 'module://pds_recipe_fondos_mutuos/images';
    return [
      'heading_html' => 'Nossos <span>Fundos Mútuos</span>',
      'subheading_html' => 'Descubra nossas alternativas <span>de acordo com seu perfil de risco</span>',
      'items' => [
        [
          'title' => 'Fundo Conservador',
          'description' => 'Ideal para quem busca estabilidade e menor risco.',
          'icon' => "$base/icon.png",
          'arrow' => "$base/flecha.png",
          'url' => '#',
        ],
        [
          'title' => 'Fundos Balanceados',
          'description' => 'Alternativa diversificada que combina capitalização e dívida.',
          'icon' => "$base/icon.png",
          'arrow' => "$base/flecha.png",
          'url' => '#',
        ],
        [
          'title' => 'Fundos de Dívida',
          'description' => 'Investem em instrumentos de dívida nacional e internacional.',
          'icon' => "$base/icon.png",
          'arrow' => "$base/flecha.png",
          'url' => '#',
        ],
      ],
    ];
  }

  public function build(): array {
    $items = array_slice(array_values((array) ($this->configuration['items'] ?? [])), 0, self::ITEM_COUNT);

    // Resolve module://pds_recipe_fondos_mutuos/images/* → /modules/custom/.../images/*
    /** @var \Drupal\Core\Extension\ExtensionList $ext */
    $ext  = \Drupal::service('extension.list.module');
    $modPath = base_path() . $ext->getPath('pds_recipe_fondos_mutuos');
    $prefix  = 'module://pds_recipe_fondos_mutuos/images';
    foreach ($items as &$it) {
      foreach (['icon','arrow'] as $k) {
        if (!empty($it[$k]) && str_starts_with($it[$k], $prefix)) {
          $it[$k] = str_replace($prefix, $modPath . '/images', $it[$k]);
        }
      }
    } unset($it); 

    return [
      '#theme'      => 'pds_fondos_mutuos',
      '#attached'   => ['library' => ['pds_recipe_fondos_mutuos/fondos']],
      '#attributes' => ['class' => ['principal_modulo_conoce_fondos_mutuos']],
      '#heading'    => $this->configuration['heading_html'] ?? '',
      '#subheading' => $this->configuration['subheading_html'] ?? '',
      '#items'      => $items,
      '#cache'      => ['max-age' => Cache::PERMANENT, 'contexts' => ['url.path']],
    ];
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $conf  = $this->getConfiguration(); // includes defaults
    $items = array_values((array) ($conf['items'] ?? []));

    $form['heading_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Título (HTML permitido)'),
      '#default_value' => $conf['heading_html'] ?? '',
      '#rows' => 2,
    ];
    $form['subheading_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Subtítulo (HTML permitido)'),
      '#default_value' => $conf['subheading_html'] ?? '',
      '#rows' => 2,
    ];

    // Always render exactly 3 rows.
    $form['items'] = ['#type' => 'fieldset', '#title' => $this->t('Itens'), '#tree' => TRUE];
    for ($i = 0; $i < self::ITEM_COUNT; $i++) {
      $it = $items[$i] ?? [];
      $form['items']["$i"] = [
        '#type' => 'details',
        '#title' => $this->t('Item @n', ['@n' => $i + 1]),
        '#open' => ($i === 0),
      ];
      $form['items']["$i"]['title'] = ['#type'=>'textfield','#title'=>$this->t('Título'),'#default_value'=>$it['title'] ?? ''];
      $form['items']["$i"]['description'] = ['#type'=>'textarea','#title'=>$this->t('Descrição'),'#default_value'=>$it['description'] ?? ''];
      $form['items']["$i"]['icon'] = ['#type'=>'textfield','#title'=>$this->t('Ícone (URL)'), '#description'=>$this->t('Ex.: module://pds_recipe_fondos_mutuos/images/icon.png'), '#default_value'=>$it['icon'] ?? ''];
      $form['items']["$i"]['arrow'] = ['#type'=>'textfield','#title'=>$this->t('Seta (URL)'), '#description'=>$this->t('Ex.: module://pds_recipe_fondos_mutuos/images/flecha.png'), '#default_value'=>$it['arrow'] ?? ''];
      $form['items']["$i"]['url'] = ['#type'=>'textfield','#title'=>$this->t('Link'), '#default_value'=>$it['url'] ?? '#'];
    }

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    // Read all rows at once.
    $raw = (array) $form_state->getValue('items');
    ksort($raw); // ensure 0..N order

    // Normalize, drop empty rows.
    $items = [];
    foreach ($raw as $row) {
      $title = trim((string) ($row['title'] ?? ''));
      $desc  = trim((string) ($row['description'] ?? ''));
      $icon  = trim((string) ($row['icon'] ?? ''));
      $arrow = trim((string) ($row['arrow'] ?? ''));
      $url   = trim((string) ($row['url'] ?? ''));
      if ($title === '' && $desc === '' && $icon === '' && $arrow === '' && $url === '') {
        continue;
      }
      $items[] = ['title'=>$title,'description'=>$desc,'icon'=>$icon,'arrow'=>$arrow,'url'=>$url ?: '#'];
    }

    // REPLACE the whole configuration in one shot.
    $this->setConfiguration([
      'heading_html'    => trim((string) $form_state->getValue('heading_html')),
      'subheading_html' => trim((string) $form_state->getValue('subheading_html')),
      'items'         => array_slice($items, 0, self::ITEM_COUNT),
    ]);
  }


}
