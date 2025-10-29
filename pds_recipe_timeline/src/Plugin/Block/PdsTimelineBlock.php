<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Template\Attribute;
use Drupal\pds_recipe_template\Plugin\Block\PdsTemplateBlock;

/**
 * Provides a timeline showcase for executive trajectories.
 *
 * @Block(
 *   id = "pds_timeline_block",
 *   admin_label = @Translation("PDS Timeline"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTimelineBlock extends BlockBase {

  /**
   * Build the delegated configuration array for the shared template block.
   */
  private function buildDelegatedConfiguration(?array $configuration = NULL): array {
    //1.- Start from the provided configuration so edits performed in the modal persist.
    $config = $configuration ?? $this->configuration;
    if (!is_array($config)) {
      $config = [];
    }

    //2.- Force the recipe type used by shared controllers so rows stay isolated per recipe.
    $config['recipe_type'] = 'pds_recipe_timeline';

    //3.- Guarantee Drupal knows which module owns the delegated instance when saving state.
    $config['provider'] = 'pds_recipe_template';

    return $config;
  }

  /**
   * Instantiate the rich template block so we can reuse its admin experience.
   */
  private function createTemplateDelegate(?array $configuration = NULL): PdsTemplateBlock {
    //1.- Ask Drupal for the block manager each time to avoid persisting stateful instances.
    $manager = \Drupal::service('plugin.manager.block');

    //2.- Create the inner block plugin with the normalized configuration payload.
    $delegate = $manager->createInstance('pds_template_block', $this->buildDelegatedConfiguration($configuration));

    //3.- Propagate active contexts (when any) so Layout Builder previews behave the same way.
    if ($delegate instanceof ContextAwarePluginInterface && method_exists($this, 'getContexts')) {
      foreach ($this->getContexts() as $context_id => $context) {
        $delegate->setContext($context_id, $context);
      }
    }

    return $delegate;
  }

  /**
   * Mirror the delegate configuration back into this block after shared operations.
   */
  private function syncConfigurationFromDelegate(PdsTemplateBlock $delegate): void {
    //1.- Capture the delegate configuration so saved identifiers persist on the outer block.
    $configuration = $this->buildDelegatedConfiguration($delegate->getConfiguration());

    //2.- Rely on the inherited setter to merge defaults exactly like any other block plugin.
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    //1.- Reuse the template defaults to keep schema alignment between both recipes.
    $delegate = $this->createTemplateDelegate([]);
    $defaults = $delegate->defaultConfiguration();

    //2.- Override the recipe type so saved rows are tagged for the timeline presentation.
    $defaults['recipe_type'] = 'pds_recipe_timeline';

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Leverage the shared build to fetch items, metadata, and drupalSettings.
    $delegate = $this->createTemplateDelegate();
    $build = $delegate->build();

    //2.- Normalize the HTML attributes so the Twig template receives an Attribute object.
    $attributes = $build['#attributes'] ?? [];
    if (!$attributes instanceof Attribute) {
      if (is_array($attributes)) {
        $attributes = new Attribute($attributes);
      }
      else {
        $attributes = new Attribute();
      }
    }
    $attributes->addClass('principal-timeline');
    $attributes->setAttribute('id', 'principal-timeline');

    //3.- Expose database identifiers as data attributes to support runtime integrations.
    $master_metadata = $build['#master_metadata'] ?? [];
    if (!empty($master_metadata['group_id'])) {
      $attributes->setAttribute('data-pds-template-master-id', (string) $master_metadata['group_id']);
    }
    if (!empty($master_metadata['instance_uuid'])) {
      $attributes->setAttribute('data-pds-template-master-uuid', (string) $master_metadata['instance_uuid']);
    }
    if (!empty($master_metadata['items_count'])) {
      $attributes->setAttribute('data-pds-template-items-count', (string) $master_metadata['items_count']);
    }

    //4.- Replace the theme with the timeline variant while keeping the shared dataset intact.
    $build['#theme'] = 'pds_timeline_block';
    $build['#attributes'] = $attributes;

    //5.- Append the public timeline library without discarding the shared template assets.
    if (!isset($build['#attached']['library']) || !is_array($build['#attached']['library'])) {
      $build['#attached']['library'] = [];
    }
    $build['#attached']['library'][] = 'pds_recipe_timeline/pds_recipe_timeline.public';
    $build['#attached']['library'] = array_values(array_unique($build['#attached']['library']));

    //6.- Guarantee drupalSettings advertise the timeline recipe type for custom consumers.
    if (!isset($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters']) || !is_array($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'])) {
      $build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'] = [];
    }
    foreach ($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'] as &$master) {
      if (is_array($master)) {
        $master['metadata']['recipe_type'] = 'pds_recipe_timeline';
      }
    }
    unset($master);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    //1.- Delegate the full modal construction so Tab A/Tab B remain identical to the template recipe.
    $delegate = $this->createTemplateDelegate();
    $form = $delegate->blockForm($form, $form_state);

    //2.- Capture configuration mutations (like ensured group ids) produced during form building.
    $this->syncConfigurationFromDelegate($delegate);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    //1.- Reuse the shared submit handler so saving rows updates both DB tables and configuration snapshots.
    $delegate = $this->createTemplateDelegate();
    $delegate->blockSubmit($form, $form_state);

    //2.- Mirror the fresh configuration back so subsequent renders expose the saved dataset.
    $this->syncConfigurationFromDelegate($delegate);
  }

}
