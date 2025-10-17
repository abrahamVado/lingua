<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Ways to Invest recipe block.
 *
 * @Block(
 *   id = "pds_ways_to_invest_block",
 *   admin_label = @Translation("PDS Ways to Invest"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsWaysToInvestBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_ways_to_invest',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--ways-to-invest',
        ],
      ],
      '#title' => $this->t('Ways to invest'),
      '#description' => $this->t('Starter content for the pds ways to invest experience.'),
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
        'url' => '/pds/ways-to-invest',
      ],
    ];
  }

}
