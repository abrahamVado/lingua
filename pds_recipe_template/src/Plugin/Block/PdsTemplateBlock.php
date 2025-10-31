<?php
/**
 * FILE: modules/custom/pds_mxsuite/pds_recipe_template/src/Plugin/Block/PdsTemplateBlock.php
 * PURPOSE: Layout Builder block that renders and edits a card list stored in DB.
 *
 * INPUTS:
 *  - $this->configuration: items[], group_id (int), instance_uuid (UUID), recipe_type
 *  - DB tables: pds_template_group, pds_template_item
 *  - Hidden form fields: pds_template_admin[group_id], pds_template_admin[cards_state]
 *
 * OUTPUTS:
 *  - Frontend: render array #theme('pds_template_block') + drupalSettings datasets
 *  - Admin: LB modal form with two panels, JSON state, live preview container
 *
 * ENTRY POINTS:
 *  - Block plugin id: pds_template_block
 *  - Form handlers: blockForm(), blockSubmit()
 *  - AJAX: ajaxResolveRow() (promote temporary file → URL)
 *
 * USED BY:
 *  - Layout Builder UI and page render where block is placed
 *
 * DEPENDENCIES:
 *  - Services: database, uuid, file_url_generator, time()
 *  - Traits: PdsTemplateBlockStateTrait, PdsTemplateRenderContextTrait
 *  - Procedural helpers: pds_recipe_template_ensure_group_and_get_id(), pds_recipe_template_resolve_row_image_promoter()
 *
 * SIDE EFFECTS:
 *  - Creates/updates pds_template_group and pds_template_item
 *  - Soft-deletes missing rows by setting deleted_at
 *  - Promotes File entities to permanent
 *
 * SAFE TO DELETE?
 *  - Whole class: No, core to feature.
 *  - buildCardsMarkup(), ajaxItems(), addItemSubmit(): Legacy preview. Delete if no routes/callers.
 *
 * NOTES:
 *  - Robust legacy recovery: resolves UUID from old group_id and backfills.
 *  - Configuration snapshot kept for render fallback when DB unavailable.
 */

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\pds_recipe_template\TemplateRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Block(
 *   id = "pds_template_block",
 *   admin_label = @Translation("PDS Template"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTemplateBlock extends BlockBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private TemplateRepository $repo) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $c->get('pds_recipe_template.repository'));
  }
  use PdsTemplateBlockStateTrait;         // State helpers: working_items, group id in FormState.
  use PdsTemplateRenderContextTrait;      // Render helpers: buildMasterMetadata(), buildExtendedDatasets().

  /**
   * DEFAULTS
   * PURPOSE: Provide render fallbacks and forward-compat flags.
   */
  public function defaultConfiguration(): array {
    return [
      // 1) Render fallback when DB/tables are missing.
      'items' => [],
      // 2) Numeric FK to group table for legacy lookups and cross-requests reuse.
      'group_id' => 0,
      // 3) Stable logical key that links this block instance to group row.
      'instance_uuid' => '',
      // 4) Logical recipe label for derivatives.
      'recipe_type' => 'pds_recipe_template',
    ];
  }

  /**
   * getBlockInstanceUuid()
   * PURPOSE: Return a stable UUID for this block. Repair legacy configs from group_id if needed.
   * OUTPUT: string UUID, also persisted into $this->configuration['instance_uuid'].
   * SAFE TO CHANGE: Keep recovery and caching logic; UI depends on stable identifier.
   */
  private function getBlockInstanceUuid(): string {
    $resolved = $this->repo->resolveInstanceUuid(
      $this->configuration['instance_uuid'] ?? '',
      is_numeric($this->configuration['group_id'] ?? 0) ? (int) $this->configuration['group_id'] : null
    );
    // Cache to config so subsequent calls skip DB.
    if (($this->configuration['instance_uuid'] ?? '') !== $resolved) {
      $this->configuration['instance_uuid'] = $resolved;
    }
    return $resolved;
  }


  /**
   * ensureGroupAndGetId()
   * PURPOSE: Ensure a pds_template_group row exists for current UUID and return its id.
   * OUTPUT: int group_id or 0.
   */
  private function ensureGroupAndGetId(): int {
    $uuid = $this->getBlockInstanceUuid();
    $type_raw = $this->configuration['recipe_type'] ?? 'pds_recipe_template';
    $type = $this->repo->resolveRecipeType($type_raw);
    return $this->repo->ensureGroupAndGetId($uuid, $type);
  }


  /**
   * loadItemsForBlock()
   * PURPOSE: Load active items from DB by current UUID; fall back to legacy group_id or config snapshot.
   * OUTPUT: array of normalized rows ready for Twig and JS.
   * ERROR TOLERANCE: Swallows DB exceptions and returns config snapshot.
   */
  private function loadItemsForBlock(): array {
    $uuid = $this->getBlockInstanceUuid();
    $legacy_group_id = 0;
    $raw = $this->configuration['group_id'] ?? 0;
    if (is_numeric($raw) && (int) $raw > 0) {
      $legacy_group_id = (int) $raw;
    }

    try {
      // Prefer group by UUID, fallback/repair with legacy id.
      $group_id = $this->repo->resolveGroupId($uuid, $legacy_group_id);
      if ($group_id > 0) {
        $rows = $this->repo->loadItems($group_id);
        if ($rows !== []) {
          // Cache the effective group id for reuse.
          if (($this->configuration['group_id'] ?? 0) !== $group_id) {
            $this->configuration['group_id'] = $group_id;
          }
          return $rows;
        }

        // If no rows, try legacy_id specifically (rare legacy case).
        if ($legacy_group_id > 0 && $legacy_group_id !== $group_id) {
          $fallback = $this->repo->loadItems($legacy_group_id);
          if ($fallback !== []) {
            $this->configuration['group_id'] = $legacy_group_id;
            return $fallback;
          }
        }
      }

      // Snapshot fallback.
      return $this->configuration['items'] ?? [];
    }
    catch (\Exception $e) {
      return $this->configuration['items'] ?? [];
    }
  }

  /**
   * saveItemsForBlock()
   * PURPOSE: Upsert submitted items for group, soft-delete missing ones, update config snapshot.
   * INPUT: array $clean_items, ?int $group_id
   * SIDE EFFECTS: DB writes, config snapshot refresh.
   */
  private function saveItemsForBlock(array $clean_items, ?int $group_id = NULL): void {
    if (!$group_id) {
      $group_id = $this->ensureGroupAndGetId();
    }
    $now = \Drupal::time()->getRequestTime();
    $snapshot = $this->repo->upsertItems($group_id, $clean_items, $now);

    $this->configuration['items'] = $snapshot;
    if ($group_id) {
      $this->configuration['group_id'] = (int) $group_id;
    }
  }


  /**
   * build()
   * PURPOSE: Render frontend block with datasets and settings. Ensures group id exists.
   * OUTPUT: theme('pds_template_block') + attached libraries and drupalSettings.
   * CACHE: Disabled (max-age -1), varied by route context.
   */
  public function build(): array {
    $items = $this->loadItemsForBlock();
    $group_id = (int) ($this->configuration['group_id'] ?? 0);
    if ($group_id === 0) {
      $group_id = $this->ensureGroupAndGetId();
      if ($group_id) {
        $this->configuration['group_id'] = $group_id;
      }
    }

    $instance_uuid = $this->getBlockInstanceUuid();
    $master_metadata = $this->buildMasterMetadata($group_id, $instance_uuid);
    $extended_datasets = $this->buildExtendedDatasets($items);

    return [
      '#theme' => 'pds_template_block',
      '#items' => $items,
      '#group_id' => $group_id,
      '#instance_uuid' => $instance_uuid,
      '#master_metadata' => $master_metadata,
      '#extended_datasets' => $extended_datasets,
      '#attached' => [
        'library' => [
          'pds_recipe_template/pds_template.public',
        ],
        'drupalSettings' => [
          'pdsRecipeTemplate' => [
            'masters' => [
              $instance_uuid => [
                'metadata' => $master_metadata,
                'datasets' => $extended_datasets,
              ],
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => [],
        'contexts' => ['route'],
        'max-age' => -1,
      ],
    ];
  }

  /**
   * blockForm()
   * PURPOSE: Build LB modal UI. Injects endpoints, hidden JSON state, tabs, and preview root.
   * OUTPUT: $form subtree 'pds_template_admin'
   * NOTE: Buttons are plain <button> but JS cancels submission; one add trigger is a <div>.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $working_items = $this->getWorkingItems($form_state);
    $group_id = $this->getGroupIdFromFormState($form_state);
    $stored_group_id = 0;
    $raw_stored_group_id = $this->configuration['group_id'] ?? 0;
    if (is_numeric($raw_stored_group_id) && (int) $raw_stored_group_id > 0) {
      $stored_group_id = (int) $raw_stored_group_id;
    }
    if (!$group_id && $stored_group_id > 0) {
      $group_id = $stored_group_id;
    }
    $block_uuid = $this->getBlockInstanceUuid();
    $recipe_type = $this->repo->resolveRecipeType($this->configuration['recipe_type'] ?? null);

    if (
      !$form_state->has('working_items')
      && !$form_state->hasTemporaryValue('working_items')
    ) {
      $this->setWorkingItems($form_state, $working_items);

      if (!$group_id) {
        $group_id = $this->ensureGroupAndGetId();
      }
    }

    if (!$group_id) {
      $group_id = $this->ensureGroupAndGetId();
    }

    // Persist id into FormState for AJAX rebuilds.
    $this->setGroupIdOnFormState($form_state, $group_id);

    // Endpoint URLs for JS.
    $ensure_group_url = Url::fromRoute(
      'pds_recipe_template.ensure_group',
      ['uuid' => $block_uuid],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();
    $resolve_row_url = Url::fromRoute(
      'pds_recipe_template.resolve_row',
      ['uuid' => $block_uuid],
      ['absolute' => TRUE],
    )->toString();
    $create_row_url = Url::fromRoute(
      'pds_recipe_template.create_row',
      ['uuid' => $block_uuid],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();
    $update_row_url = Url::fromRoute(
      'pds_recipe_template.update_row',
      ['uuid' => $block_uuid, 'row_uuid' => '00000000-0000-0000-0000-000000000000'],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();

    // List endpoint supports legacy fallback ids.
    $list_query = ['type' => $recipe_type];
    if ($group_id > 0) {
      $list_query['group_id'] = (string) $group_id;
    }
    if ($stored_group_id > 0 && $stored_group_id !== $group_id) {
      $list_query['fallback_group_id'] = (string) $stored_group_id;
    }

    $list_rows_url = Url::fromRoute(
      'pds_recipe_template.list_rows',
      ['uuid' => $block_uuid],
      ['absolute' => TRUE, 'query' => $list_query],
    )->toString();

    // Root container with data-* for JS.
    $form['pds_template_admin'] = [
      '#type' => 'container',
      '#tree' => TRUE, // Keep nested values accessible on submit.
      '#attributes' => [
        'class' => ['pds-template-admin', 'js-pds-template-admin'],
        'data-pds-template-ensure-group-url' => $ensure_group_url,
        'data-pds-template-resolve-row-url' => $resolve_row_url,
        'data-pds-template-create-row-url' => $create_row_url,
        'data-pds-template-update-row-url' => $update_row_url,
        'data-pds-template-list-rows-url' => $list_rows_url,
        'data-pds-template-group-id' => (string) $group_id,
        'data-pds-template-block-uuid' => $block_uuid,
        'data-pds-template-recipe-type' => $recipe_type,
      ],
      '#attached' => [
        'library' => [
          'pds_recipe_template/pds_template.admin',
        ],
      ],
    ];

    // Hidden pointers and JSON state.
    $form['pds_template_admin']['group_id'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-group-id',
        'id' => 'pds-template-group-id',
      ],
      '#default_value' => (string) $group_id,
    ];

    $form['pds_template_admin']['cards_state'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-cards-state',
      ],
      '#default_value' => json_encode($working_items),
    ];

    $form['pds_template_admin']['edit_index'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-edit-index',
      ],
      '#default_value' => '-1',
    ];

    // Tabs nav.
    $form['pds_template_admin']['tabs_nav'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-template-admin__tabs-horizontal'],
        'data-pds-template-tabs' => 'nav',
      ],
    ];

    // Nav buttons (submission is blocked by JS).
    $form['pds_template_admin']['tabs_nav']['tab_a'] = [
      '#type' => 'button',
      '#value' => $this->t('Tab A'),
      '#attributes' => [
        'class' => ['pds-template-admin__tab-btn-horizontal'],
        'data-pds-template-tab-target' => 'panel-a',
      ],
    ];
    $form['pds_template_admin']['tabs_nav']['tab_b'] = [
      '#type' => 'button',
      '#value' => $this->t('Tab B'),
      '#attributes' => [
        'class' => ['pds-template-admin__tab-btn-horizontal'],
        'data-pds-template-tab-target' => 'panel-b',
      ],
    ];

    // Panels wrapper.
    $form['pds_template_admin']['tabs_panels_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'pds-template-panels-wrapper'],
    ];
    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pds-template-admin__panels-horizontal'],
        'data-pds-template-tabs' => 'panels',
      ],
    ];

    // Panel A: edit widgets.
    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-template-panel-a',
        'class' => ['pds-template-admin__panel-horizontal', 'js-pds-template-panel'],
        'data-pds-template-panel-id' => 'panel-a',
      ],
    ];

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Header'),
      '#maxlength' => 255,
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-header',
        'id' => 'pds-template-header',
      ],
    ];

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['subheader'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subheader'),
      '#maxlength' => 255,
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-subheader',
        'id' => 'pds-template-subheader',
      ],
    ];

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-description',
        'id' => 'pds-template-description',
      ],
    ];

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Image'),
      '#upload_location' => 'public://pds_template/',
      '#default_value' => [],
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg webp'],
      ],
      '#attributes' => [
        'id' => 'pds-template-image',
      ],
    ];

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['link'] = [
      '#type' => 'url',
      '#title' => $this->t('Link'),
      '#maxlength' => 512,
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-link',
        'id' => 'pds-template-link',
      ],
    ];

    // Add-row trigger (DIV to survive LB rendering).
    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['add_item'] = [
      '#type' => 'markup',
      '#markup' => '<div class="pds-template-admin__add-btn" data-drupal-selector="pds-template-add-card" id="pds-template-add-card" role="button" tabindex="0">Add row</div>',
    ];

    // Panel B: preview root and states.
    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-template-panel-b',
        'class' => ['pds-template-admin__panel-horizontal', 'js-pds-template-panel', 'is-active'],
        'data-pds-template-panel-id' => 'panel-b',
      ],
    ];

    $loading_text = Html::escape((string) $this->t('Loading preview…'));
    $empty_text = Html::escape((string) $this->t('No rows yet.'));
    $error_text = Html::escape((string) $this->t('Unable to load preview.'));

    $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b']['preview_list'] = [
      '#type' => 'markup',
      '#markup' => '<div id="pds-template-preview-list" class="pds-template-preview" data-pds-template-preview-root="1">'
        . '<div class="pds-template-preview__loading" data-pds-template-preview-state="loading" hidden>' . $loading_text . '</div>'
        . '<div class="pds-template-preview__empty" data-pds-template-preview-state="empty" hidden>' . $empty_text . '</div>'
        . '<div class="pds-template-preview__error" data-pds-template-preview-state="error" hidden>' . $error_text . '</div>'
        . '<div class="pds-template-preview__content" data-pds-template-preview-state="content"></div>'
        . '</div>',
    ];

    return $form;
  }

  /**
   * blockSubmit()
   * PURPOSE: Read hidden JSON, normalize item payload, persist DB + config snapshot, refresh working state.
   * INPUT: cards_state JSON, group_id
   * SIDE EFFECTS: Promotes File entities if only fid provided.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $raw_json = $this->extractNestedFormValue($form_state, [
      ['pds_template_admin', 'cards_state'],
      ['settings', 'pds_template_admin', 'cards_state'],
      ['cards_state'],
    ], '');

    // Resolve group id (unchanged)
    $group_id = NULL;
    $raw_group = $this->extractNestedFormValue($form_state, [
      ['pds_template_admin', 'group_id'],
      ['settings', 'pds_template_admin', 'group_id'],
      ['group_id'],
    ]);
    if (is_scalar($raw_group) && $raw_group !== '') { $group_id = (int) $raw_group; }
    if (!$group_id) { $group_id = $this->getGroupIdFromFormState($form_state) ?? 0; }
    if (!$group_id) { $group_id = $this->ensureGroupAndGetId(); }
    if ($group_id) { $this->configuration['group_id'] = (int) $group_id; }

    // NEW: delegate to repository
    $items  = $this->repo->decodeJsonToArray($raw_json);
    $clean  = $this->repo->normalizeSubmittedItems($items);

    // Persist + refresh working state
    $this->saveItemsForBlock($clean, $group_id);
    $this->setWorkingItems($form_state, $clean);
  }


  /**
   * ajaxResolveRow()
   * PURPOSE: Promote a temporary upload (fid) into a permanent file and return URLs.
   * SECURITY: Validates UUID and access via helper permission check.
   * OUTPUT: JSON {status, image_url, image_fid, desktop_img, mobile_img}
   */
  public static function ajaxResolveRow(Request $request, string $uuid): JsonResponse {
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid UUID.'], 400);
    }
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    // Parse body
    $payload = [];
    $content = $request->getContent();
    if (is_string($content) && $content !== '') {
      $decoded = json_decode($content, true);
      if (is_array($decoded)) { $payload = $decoded; }
    }
    if (isset($payload['row']) && is_array($payload['row'])) {
      $payload = $payload['row'];
    }

    // NEW: use the repository wrapper for promotion
    /** @var \Drupal\pds_recipe_template\TemplateRepository $repo */
    $repo = \Drupal::service('pds_recipe_template.repository');
    $result = $repo->promoteRowPayload($payload);

    if (($result['status'] ?? '') !== 'ok') {
      return new JsonResponse([
        'status'  => 'error',
        'message' => $result['message'] ?? 'Unable to promote image.',
      ], $result['code'] ?? 500);
    }

    return new JsonResponse([
      'status'      => 'ok',
      'image_url'   => $result['image_url']   ?? '',
      'image_fid'   => $result['image_fid']   ?? null,
      'desktop_img' => $result['desktop_img'] ?? '',
      'mobile_img'  => $result['mobile_img']  ?? '',
    ]);
  }


}
