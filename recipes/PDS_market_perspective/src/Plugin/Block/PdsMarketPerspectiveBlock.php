<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Market Perspective recipe block.
 *
 * @Block(
 *   id = "pds_market_perspective_block",
 *   admin_label = @Translation("PDS Market Perspective"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsMarketPerspectiveBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_market_perspective',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--market-perspective',
        ],
      ],
      '#title' => $this->t('Market perspective'),
      '#description' => $this->t('Starter content for the pds market perspective experience.'),
      '#items' => [
        [
          'title' => $this->t('Sample headline'),
          'description' => $this->t('Replace this placeholder copy with meaningful information.'),
        ],
        [
          'title' => $this->t('Supporting detail'),
          'description' => $this->t('Use the items array to communicate key takeaways.'),
        ],
      ],
      '#cta' => [
        'label' => $this->t('Learn more'),
        'url' => '/pds/market-perspective',
      ],
    ];
  }

}
