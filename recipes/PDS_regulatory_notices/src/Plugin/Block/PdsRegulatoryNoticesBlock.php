<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Regulatory Notices recipe block.
 *
 * @Block(
 *   id = "pds_regulatory_notices_block",
 *   admin_label = @Translation("PDS Regulatory Notices"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsRegulatoryNoticesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_regulatory_notices',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--regulatory-notices',
        ],
      ],
      '#title' => $this->t('Regulatory notices'),
      '#description' => $this->t('Starter content for the pds regulatory notices experience.'),
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
        'url' => '/pds/regulatory-notices',
      ],
    ];
  }

}
