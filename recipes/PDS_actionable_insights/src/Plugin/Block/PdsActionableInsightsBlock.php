<?php

namespace Drupal\pds_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the Actionable Insights recipe block.
 *
 * @Block(
 *   id = "pds_actionable_insights_block",
 *   admin_label = @Translation("PDS Actionable Insights"),
 *   category = @Translation("PDS Recipes")
 * )
 */
class PdsActionableInsightsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Provide a consistent render array matching the recipe theme hook.
    //2.- Attach shared classes so the template can render a predictable layout.
    //3.- Offer placeholder content that site builders can replace after installing the recipe.
    return [
      '#theme' => 'pds_actionable_insights',
      '#attributes' => [
        'class' => [
          'recipe-component',
          'recipe-component--actionable-insights',
        ],
      ],
      '#title' => $this->t('Actionable insights'),
      '#description' => $this->t('Surface a curated collection of insights that guide business decisions.'),
      '#items' => [
        [
          'title' => $this->t('Insight headline'),
          'description' => $this->t('Describe how the insight helps decision makers act quickly.'),
        ],
        [
          'title' => $this->t('Supporting data point'),
          'description' => $this->t('Highlight data that reinforces the primary recommendation.'),
        ],
      ],
      '#cta' => [
        'label' => $this->t('View all insights'),
        'url' => '/insights',
      ],
    ];
  }

}
