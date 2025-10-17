<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Carousel Trajectory recipe block.
 *
 * @Block(
 *   id = "pds_carousel_trajectory_block",
 *   admin_label = @Translation("PDS Carousel Trajectory"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsCarouselTrajectoryBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_carousel_trajectory',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--carousel-trajectory',
        ],
      ],
      '#title' => $this->t('Trajectory highlights'),
      '#description' => $this->t('Starter content for the pds carousel trajectory experience.'),
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
        'url' => '/pds/carousel-trajectory',
      ],
    ];
  }

}
