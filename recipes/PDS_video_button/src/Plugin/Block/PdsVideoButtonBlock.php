<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Video Button recipe block.
 *
 * @Block(
 *   id = "pds_video_button_block",
 *   admin_label = @Translation("PDS Video Button"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsVideoButtonBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_video_button',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--video-button',
        ],
      ],
      '#title' => $this->t('Watch the overview'),
      '#description' => $this->t('Starter content for the pds video button experience.'),
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
        'url' => '/pds/video-button',
      ],
    ];
  }

}
