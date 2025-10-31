<?php
/**
 * FILE: modules/custom/pds_mxsuite/pds_recipe_template/src/Plugin/Block/PdsTemplateBlock.php
 * PURPOSE: Layout Builder block that renders and edits a card list stored in DB.
 *
 * INPUTS:
 *  - $this->configuration: items[], group_id (int), instance_uuid (UUID), recipe_type
 *  - DB tables: pds_template_group, pds_template_item
 *  - Hidden form fields: pds_template_admin[group_id], pds_template_admin[cards_state], pds_template_admin[instance_uuid]
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
 * DEPENDENCIES:
 *  - Services: TemplateRepository (internally uses db/uuid/time/logger/promoter)
 *  - Traits: PdsTemplateBlockStateTrait, PdsTemplateRenderContextTrait
 *
 * NOTES:
 *  - On first open, we resolve/generate a UUID and expose it to JS via data-* and a hidden field.
 *  - We persist instance_uuid in configuration on submit to keep it stable across reopens.
 */

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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

  private const ROW_ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

  use PdsTemplateBlockStateTrait;         // working_items, group id in FormState
  use PdsTemplateRenderContextTrait;      // buildMasterMetadata(), buildExtendedDatasets()

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private TemplateRepository $repo) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $c, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $c->get('pds_recipe_template.repository'));
  }

  /**
   * DEFAULTS
   */
  public function defaultConfiguration(): array {
    return [
      'items' => [],                 // Render fallback when DB/tables are missing.
      'group_id' => 0,               // Numeric FK (legacy reuse).
      'instance_uuid' => '',         // Stable logical key that links this block instance to group row.
      'recipe_type' => 'pds_recipe_template',
    ];
  }

  /**
   * Return (and cache) the instance UUID. Repairs from legacy group_id if needed.
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
   * Ensure group row exists for current UUID and return id.
   */
  private function ensureGroupAndGetId(): int {
    $uuid = $this->getBlockInstanceUuid();
    $type_raw = $this->configuration['recipe_type'] ?? 'pds_recipe_template';
    $type = $this->repo->resolveRecipeType($type_raw);
    return $this->repo->ensureGroupAndGetId($uuid, $type);
  }

  /**
   * Load active items by UUID; fallback to legacy group_id or config snapshot.
   */
  private function loadItemsForBlock(): array {
    $uuid = $this->getBlockInstanceUuid();
    $legacy_group_id = 0;
    $raw = $this->configuration['group_id'] ?? 0;
    if (is_numeric($raw) && (int) $raw > 0) {
      $legacy_group_id = (int) $raw;
    }

    try {
      $group_id = $this->repo->resolveGroupId($uuid, $legacy_group_id);
      if ($group_id > 0) {
        $rows = $this->repo->loadItems($group_id);
        if ($rows !== []) {
          if (($this->configuration['group_id'] ?? 0) !== $group_id) {
            $this->configuration['group_id'] = $group_id;
          }
          return $rows;
        }
        if ($legacy_group_id > 0 && $legacy_group_id !== $group_id) {
          $fallback = $this->repo->loadItems($legacy_group_id);
          if ($fallback !== []) {
            $this->configuration['group_id'] = $legacy_group_id;
            return $fallback;
          }
        }
      }
      return $this->configuration['items'] ?? [];
    }
    catch (\Exception) {
      return $this->configuration['items'] ?? [];
    }
  }

  /**
   * Upsert submitted items, soft-delete missing, refresh config snapshot.
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
   * Render frontend block with datasets and settings. Ensures group id exists.
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
   * Build LB modal UI. Inject endpoints, hidden JSON state, tabs, and preview root.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    // Ensure a UUID *on first open* so AJAX endpoints receive a valid {uuid}.
    $block_uuid = $this->getBlockInstanceUuid();

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
    $this->setGroupIdOnFormState($form_state, $group_id);

    // Endpoint URLs for JS (absolute so LB iframe is safe).
    $ensure_group_url = Url::fromRoute(
      'pds_recipe_template.ensure_group',
      ['id' => $group_id ?: 0],
      [
        'absolute' => TRUE,
        'query' => [
          'type' => $recipe_type,
          'instance_uuid' => $block_uuid,
        ],
      ],
    )->toString();

    $resolve_row_url = Url::fromRoute(
      'pds_recipe_template.resolve_row',
      ['group_id' => $group_id ?: 0],
      ['absolute' => TRUE],
    )->toString();

    $create_row_url = Url::fromRoute(
      'pds_recipe_template.create_row',
      ['group_id' => $group_id ?: 0],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();

    $update_row_url = Url::fromRoute(
      'pds_recipe_template.update_row',
      ['group_id' => $group_id ?: 0, 'row_id' => 0],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();
    if (is_string($update_row_url)) {
      //1.- Provide a stable token so the browser can inject the numeric row id on demand.
      $update_row_url = preg_replace(
        '/\/0(\?|$)/',
        '/' . self::ROW_ID_PLACEHOLDER . '$1',
        $update_row_url,
        1,
      ) ?? $update_row_url;
    }

    $delete_row_url = Url::fromRoute(
      'pds_recipe_template.delete_row',
      [
        'group_id' => $group_id ?: 0,
        // Temporary placeholder—JS will replace with actual row id.
        'row_id' => 0,
      ],
      ['absolute' => TRUE, 'query' => ['type' => $recipe_type]],
    )->toString();
    if (is_string($delete_row_url)) {
      //1.- Mirror the placeholder swap so DELETE endpoints receive numeric identifiers too.
      $delete_row_url = preg_replace(
        '/\/0(\?|$)/',
        '/' . self::ROW_ID_PLACEHOLDER . '$1',
        $delete_row_url,
        1,
      ) ?? $delete_row_url;
    }

    // Listing supports legacy fallback ids for hydration.
    $list_query = ['type' => $recipe_type];
    if ($stored_group_id > 0 && $stored_group_id !== $group_id) {
      $list_query['fallback_group_id'] = (string) $stored_group_id;
    }
    $list_rows_url = Url::fromRoute(
      'pds_recipe_template.list_rows',
      ['group_id' => $group_id ?: 0],
      ['absolute' => TRUE, 'query' => $list_query],
    )->toString();

    // Root container with data-* for JS.
    $form['pds_template_admin'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['pds-template-admin', 'js-pds-template-admin'],
        'data-pds-template-ensure-group-url' => $ensure_group_url,
        'data-pds-template-resolve-row-url' => $resolve_row_url,
        'data-pds-template-create-row-url' => $create_row_url,
        'data-pds-template-update-row-url' => $update_row_url,
        'data-pds-template-delete-row-url' => $delete_row_url,
        'data-pds-template-list-rows-url' => $list_rows_url,
        'data-pds-template-group-id' => (string) $group_id,
        'data-pds-template-block-uuid' => $block_uuid,     // <-- expose UUID to JS
        'data-pds-template-recipe-type' => $recipe_type,
      ],
      '#attached' => [
        'library' => ['pds_recipe_template/pds_template.admin'],
      ],
    ];

    // Hidden pointers and JSON state.
    $form['pds_template_admin']['instance_uuid'] = [
      '#type' => 'hidden',
      '#default_value' => $block_uuid,
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-block-uuid',
      ],
    ];

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
    $empty_text   = Html::escape((string) $this->t('No rows yet.'));
    $error_text   = Html::escape((string) $this->t('Unable to load preview.'));

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
   * Persist UUID, group id, and rows JSON.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    // Always persist the resolved instance UUID.
    $this->configuration['instance_uuid'] = $this->getBlockInstanceUuid();

    // Read JSON snapshot.
    $raw_json = $this->extractNestedFormValue($form_state, [
      ['pds_template_admin', 'cards_state'],
      ['settings', 'pds_template_admin', 'cards_state'],
      ['cards_state'],
    ], '');

    // Resolve group id.
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

    // Normalize and persist.
    $items  = $this->repo->decodeJsonToArray($raw_json);
    $clean  = $this->repo->normalizeSubmittedItems($items);

    $this->saveItemsForBlock($clean, $group_id);
    $this->setWorkingItems($form_state, $clean);
  }

  /**
   * Promote a temporary upload (fid) into a permanent file and return URLs.
   */
  public static function ajaxResolveRow(Request $request, int $group_id): JsonResponse {
    if ($group_id < 0) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid group id.'], 400);
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
