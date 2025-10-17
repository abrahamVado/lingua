<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Financial Education recipe block.
 *
 * @Block(
 *   id = "pds_financial_education_block",
 *   admin_label = @Translation("PDS Financial Education"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsFinancialEducationBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_financial_education',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--financial-education',
        ],
      ],
      '#title' => $this->t('Financial education'),
      '#description' => $this->t('Starter content for the pds financial education experience.'),
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
        'url' => '/pds/financial-education',
      ],
    ];
  }

}
