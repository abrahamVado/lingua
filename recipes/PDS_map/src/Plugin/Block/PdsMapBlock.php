<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Map recipe block.
 *
 * @Block(
 *   id = "pds_map_block",
 *   admin_label = @Translation("PDS Map"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsMapBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_map',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--map',
        ],
      ],
      '#title' => $this->t('Global presence'),
      '#description' => $this->t('Starter content for the pds map experience.'),
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
        'url' => '/pds/map',
      ],
    ];
  }

}
