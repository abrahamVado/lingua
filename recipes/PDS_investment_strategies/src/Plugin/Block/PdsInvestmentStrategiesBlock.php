<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Investment Strategies recipe block.
 *
 * @Block(
 *   id = "pds_investment_strategies_block",
 *   admin_label = @Translation("PDS Investment Strategies"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsInvestmentStrategiesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_investment_strategies',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--investment-strategies',
        ],
      ],
      '#title' => $this->t('Investment strategies'),
      '#description' => $this->t('Starter content for the pds investment strategies experience.'),
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
        'url' => '/pds/investment-strategies',
      ],
    ];
  }

}
