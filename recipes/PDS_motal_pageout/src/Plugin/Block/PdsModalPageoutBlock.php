<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the PDS Modal Pageout recipe block.
 *
 * @Block(
 *   id = "pds_modal_pageout_block",
 *   admin_label = @Translation("PDS Modal Pageout"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsModalPageoutBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_modal_pageout',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--modal-pageout',
        ],
      ],
      '#title' => $this->t('Important notice'),
      '#description' => $this->t('Starter content for the pds modal pageout experience.'),
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
        'url' => '/pds/modal-pageout',
      ],
    ];
  }

}
