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

    //2.- Enrich the shared dataset with timeline collections persisted for each item.
    $build = $this->attachTimelineDataset($build);

    //3.- Normalize the HTML attributes so the Twig template receives an Attribute object.
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

    //4.- Expose database identifiers as data attributes to support runtime integrations.
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

    //5.- Replace the theme with the timeline variant while keeping the shared dataset intact.
    $build['#theme'] = 'pds_timeline_block';
    $build['#attributes'] = $attributes;

    //6.- Append the public timeline library without discarding the shared template assets.
    if (!isset($build['#attached']['library']) || !is_array($build['#attached']['library'])) {
      $build['#attached']['library'] = [];
    }
    $build['#attached']['library'][] = 'pds_recipe_timeline/pds_recipe_timeline.public';
    $build['#attached']['library'] = array_values(array_unique($build['#attached']['library']));

    //7.- Guarantee drupalSettings advertise the timeline recipe type for custom consumers.
    if (!isset($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters']) || !is_array($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'])) {
      $build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'] = [];
    }
    foreach ($build['#attached']['drupalSettings']['pdsRecipeTemplate']['masters'] as &$master) {
      if (is_array($master)) {
        $master['metadata']['recipe_type'] = 'pds_recipe_timeline';
        //1.- Ensure the timeline dataset is exposed to front-end consumers alongside shared derivatives.
        if (!isset($master['datasets']) || !is_array($master['datasets'])) {
          $master['datasets'] = [];
        }
        $master['datasets']['timeline'] = $build['#extended_datasets']['timeline'] ?? [];
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

    //3.- Anexar la librería administrativa propia para habilitar el editor de hitos cronológicos.
    if (!isset($form['pds_template_admin']['#attached'])) {
      $form['pds_template_admin']['#attached'] = [];
    }
    if (!isset($form['pds_template_admin']['#attached']['library']) || !is_array($form['pds_template_admin']['#attached']['library'])) {
      $form['pds_template_admin']['#attached']['library'] = [];
    }
    $form['pds_template_admin']['#attached']['library'][] = 'pds_recipe_timeline/pds_recipe_timeline.admin';
    $form['pds_template_admin']['#attached']['library'] = array_values(array_unique($form['pds_template_admin']['#attached']['library']));

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

  /**
   * Append timeline information to the shared template dataset when available.
   */
  private function attachTimelineDataset(array $build): array {
    //1.- Capture the items array before mutating so delegates that reuse the build stay unaffected.
    if (!isset($build['#extended_datasets']) || !is_array($build['#extended_datasets'])) {
      $build['#extended_datasets'] = [];
    }

    $items = $build['#items'] ?? [];
    if (!is_array($items) || $items === []) {
      $build['#extended_datasets']['timeline'] = [];
      return $build;
    }

    //2.- Resolve the persisted identifiers to query the auxiliary timeline table efficiently.
    $item_ids = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $candidate = $item['id'] ?? NULL;
      if (is_numeric($candidate)) {
        $item_ids[] = (int) $candidate;
      }
    }
    $item_ids = array_values(array_unique($item_ids));

    $collections = $this->loadTimelineCollections($item_ids);

    //3.- Inject the timeline entries on each item so Twig and drupalSettings expose the chronology.
    foreach ($items as $index => $item) {
      if (!is_array($item)) {
        continue;
      }
      $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
      $items[$index]['timeline'] = $collections[$id] ?? [];
    }

    $build['#items'] = $items;
    $build['#extended_datasets']['timeline'] = $this->buildTimelineDataset($items);

    return $build;
  }

  /**
   * Query the auxiliary table for the timeline entries tied to the provided items.
   */
  private function loadTimelineCollections(array $item_ids): array {
    //1.- Avoid unnecessary work when no identifiers were resolved from the base dataset.
    if ($item_ids === []) {
      return [];
    }

    $connection = \Drupal::database();
    $schema = $connection->schema();
    if (!$schema->tableExists('pds_template_item_timeline')) {
      return [];
    }

    $query = $connection->select('pds_template_item_timeline', 't')
      ->fields('t', ['item_id', 'year', 'label', 'weight'])
      ->condition('t.item_id', $item_ids, 'IN')
      ->condition('t.deleted_at', NULL, 'IS NULL')
      ->orderBy('t.item_id', 'ASC')
      ->orderBy('t.weight', 'ASC');

    $result = $query->execute();
    $collections = [];
    foreach ($result as $record) {
      $item_id = (int) $record->item_id;
      if (!isset($collections[$item_id])) {
        $collections[$item_id] = [];
      }

      $collections[$item_id][] = [
        'year' => (int) $record->year,
        'label' => (string) $record->label,
        'weight' => (int) $record->weight,
      ];
    }

    return $collections;
  }

  /**
   * Prepare a condensed dataset keyed by identifiers for drupalSettings consumers.
   */
  private function buildTimelineDataset(array $items): array {
    //1.- Produce a stable associative map so JS can address entries through UUIDs when available.
    $dataset = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $uuid = isset($item['uuid']) && is_string($item['uuid']) ? $item['uuid'] : '';
      $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : NULL;

      if ($uuid !== '') {
        $key = $uuid;
      }
      elseif ($id !== NULL) {
        $key = 'id:' . $id;
      }
      else {
        continue;
      }

      $dataset[$key] = [
        'id' => $id,
        'uuid' => $uuid,
        'timeline' => $item['timeline'] ?? [],
      ];
    }

    return $dataset;
  }

}
