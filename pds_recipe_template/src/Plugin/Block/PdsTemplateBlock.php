<?php

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

/**
 * @Block(
 *   id = "pds_template_block",
 *   admin_label = @Translation("PDS Template"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTemplateBlock extends BlockBase {
  use PdsTemplateBlockStateTrait;
  use PdsTemplateRenderContextTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // Snapshot fallback for render if DB or tables are not available yet.
      'items' => [],
      //1.- Persist the numeric group id so front-end renderers can reference it.
      'group_id' => 0,
      // Stable UUID to tie this block instance to rows in pds_template_group.
      'instance_uuid' => '',
      // Logical type of this block. Adjust per block subtype if needed.
      'recipe_type' => 'pds_recipe_template',
    ];
  }

  /**
   * Return stable UUID for this block instance.
   * Stored in configuration['instance_uuid'].
   */
  private function getBlockInstanceUuid(): string {
    $stored_uuid = $this->configuration['instance_uuid'] ?? '';
    if (is_string($stored_uuid) && $stored_uuid !== '') {
      return $stored_uuid;
    }

    //1.- Attempt to recover the historical UUID from the persisted group id so
    //    legacy blocks created before instance_uuid was stored continue to load
    //    their saved rows when editors reopen the modal.
    $stored_group_id = $this->configuration['group_id'] ?? 0;
    if (is_numeric($stored_group_id) && (int) $stored_group_id > 0) {
      try {
        $connection = \Drupal::database();
        $group_uuid = $connection->select('pds_template_group', 'g')
          ->fields('g', ['uuid'])
          ->condition('g.id', (int) $stored_group_id)
          ->condition('g.deleted_at', NULL, 'IS NULL')
          ->range(0, 1)
          ->execute()
          ->fetchField();

        if (is_string($group_uuid) && $group_uuid !== '' && Uuid::isValid($group_uuid)) {
          //2.- Cache the recovered identifier so future lookups skip the
          //    database round-trip while keeping old rows linked to the block.
          $this->configuration['instance_uuid'] = $group_uuid;
          return $group_uuid;
        }

        if ($group_uuid !== FALSE) {
          //3.- Repair legacy rows that never stored a UUID so historical data
          //    continues to load in the editor by assigning a fresh identifier
          //    directly on the existing group record.
          $replacement_uuid = \Drupal::service('uuid')->generate();
          $connection->update('pds_template_group')
            ->fields(['uuid' => $replacement_uuid])
            ->condition('id', (int) $stored_group_id)
            ->condition('deleted_at', NULL, 'IS NULL')
            ->execute();

          //4.- Persist the new identifier so subsequent AJAX requests reuse
          //    the repaired value instead of generating more UUIDs.
          $this->configuration['instance_uuid'] = $replacement_uuid;
          return $replacement_uuid;
        }
      }
      catch (\Exception $exception) {
        //5.- Ignore lookup failures so newly created blocks can still receive
        //    a fresh UUID without interrupting the editor experience.
      }
    }

    //6.- Fallback to generating a new UUID for brand-new blocks or when the
    //    historical lookup could not find a matching record.
    $uuid = \Drupal::service('uuid')->generate();
    $this->configuration['instance_uuid'] = $uuid;
    return $uuid;
  }

  /**
   * Ensure a row exists in pds_template_group for this block UUID.
   * Return that row's numeric id (group_id).
   */
  private function ensureGroupAndGetId(): int {
    $uuid = $this->getBlockInstanceUuid();
    $type = $this->configuration['recipe_type'] ?? 'pds_recipe_template';

    //1.- Leverage the shared procedural helper so controllers and forms reuse identical logic.
    $group_id = \pds_recipe_template_ensure_group_and_get_id($uuid, $type);

    if (!$group_id) {
      return 0;
    }

    return (int) $group_id;
  }

  /**
   * Load active items for this block from DB.
   * Fallback to $this->configuration['items'] if DB fails or none yet.
   */
  private function loadItemsForBlock(): array {
    $connection = \Drupal::database();
    $uuid = $this->getBlockInstanceUuid();

    try {
      //1.- Resolve the numeric group id that matches the current UUID so AJAX previews stay in sync.
      $group_row = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      $group_id = 0;
      if ($group_row && !empty($group_row['id'])) {
        $group_id = (int) $group_row['id'];
      }
      else {
        //2.- Fall back to the stored group id when legacy blocks never recorded an instance UUID.
        $legacy_group_id = $this->configuration['group_id'] ?? 0;
        if (is_numeric($legacy_group_id) && (int) $legacy_group_id > 0) {
          $group_id = (int) $legacy_group_id;

          //3.- Attempt to hydrate the cached UUID from the legacy group so subsequent requests reuse it.
          $legacy_uuid = $connection->select('pds_template_group', 'g')
            ->fields('g', ['uuid'])
            ->condition('g.id', $group_id)
            ->condition('g.deleted_at', NULL, 'IS NULL')
            ->range(0, 1)
            ->execute()
            ->fetchField();

          if (is_string($legacy_uuid) && $legacy_uuid !== '') {
            $this->configuration['instance_uuid'] = $legacy_uuid;
          }
        }
      }

      if ($group_id > 0) {
        $rows = $this->loadRowsForGroup($connection, $group_id);
        if ($rows !== []) {
          return $rows;
        }
      }

      //4.- If no active rows exist yet, reuse the configuration snapshot to keep previews hydrated.
      return $this->configuration['items'] ?? [];
    }
    catch (\Exception $e) {
      //5.- Tables not created yet or DB error.
      return $this->configuration['items'] ?? [];
    }
  }

  /**
   * Load and normalize rows for the provided group id.
   */
  private function loadRowsForGroup(Connection $connection, int $group_id): array {
    //1.- Query the active items for the group while ignoring soft-deleted records.
    $query = $connection->select('pds_template_item', 'i')
      ->fields('i', [
        'header',
        'subheader',
        'description',
        'url',
        'desktop_img',
        'mobile_img',
        'latitud',
        'longitud',
      ])
      ->condition('i.group_id', $group_id)
      ->condition('i.deleted_at', NULL, 'IS NULL')
      ->orderBy('i.weight', 'ASC');

    $result = $query->execute();

    $rows = [];
    foreach ($result as $record) {
      //2.- Prefer the stored desktop asset when generating the preview image URL.
      $primary_image = (string) $record->desktop_img;
      if ($primary_image === '') {
        //3.- Fall back to the mobile slot so legacy rows still expose an image URL.
        $primary_image = (string) $record->mobile_img;
      }

      $rows[] = [
        'header'      => $record->header,
        'subheader'   => $record->subheader,
        'description' => $record->description,
        'link'        => $record->url,
        'desktop_img' => $record->desktop_img,
        'mobile_img'  => $record->mobile_img,
        'image_url'   => $primary_image,
        'latitud'     => $record->latitud,
        'longitud'    => $record->longitud,
      ];
    }

    return $rows;
  }

  /**
   * Save cleaned items into DB for this block.
   * Overwrite existing active items for this group.
   * Also update configuration['items'] snapshot.
   */
  private function saveItemsForBlock(array $clean_items, ?int $group_id = NULL): void {
    $connection = \Drupal::database();
    if ($group_id === NULL || $group_id === 0) {
      //1.- Resolve the backing group row when the caller did not provide one explicitly.
      $group_id = $this->ensureGroupAndGetId();
    }
    $now = \Drupal::time()->getRequestTime();

    // Soft-delete all previous active items for this group.
    $connection->update('pds_template_item')
      ->fields(['deleted_at' => $now])
      ->condition('group_id', $group_id)
      ->condition('deleted_at', NULL, 'IS NULL')
      ->execute();

    // Insert new items in the given order.
    foreach ($clean_items as $delta => $row) {
      $row_uuid = \Drupal::service('uuid')->generate();

      $connection->insert('pds_template_item')
        ->fields([
          'uuid'        => $row_uuid,
          'group_id'    => $group_id,
          'weight'      => $delta,
          'header'      => $row['header'] ?? '',
          'subheader'   => $row['subheader'] ?? '',
          'description' => $row['description'] ?? '',
          'url'         => $row['link'] ?? '',
          'desktop_img' => $row['desktop_img'] ?? '',
          'mobile_img'  => $row['mobile_img'] ?? '',
          'latitud'     => $row['latitud'] ?? NULL,
          'longitud'    => $row['longitud'] ?? NULL,
          'created_at'  => $now,
          'deleted_at'  => NULL,
        ])
        ->execute();
    }

    // Keep snapshot for fallback render.
    $this->configuration['items'] = $clean_items;
    if ($group_id) {
      //2.- Mirror the id in configuration so future renders expose it without DB lookups.
      $this->configuration['group_id'] = (int) $group_id;
    }
  }

  /**
   * {@inheritdoc}
   * Render block on frontend.
   */
  public function build(): array {
    $items = $this->loadItemsForBlock();
    $group_id = (int) ($this->configuration['group_id'] ?? 0);
    if ($group_id === 0) {
      //1.- Guarantee a persisted identifier exists even on early renders.
      $group_id = $this->ensureGroupAndGetId();
      if ($group_id) {
        //2.- Cache the freshly resolved id so subsequent renders reuse it.
        $this->configuration['group_id'] = $group_id;
      }
    }

    $instance_uuid = $this->getBlockInstanceUuid();
    //1.- Precompute master metadata so the template and JS share a single source of truth.
    $master_metadata = $this->buildMasterMetadata($group_id, $instance_uuid);
    //2.- Produce derivative datasets that the public template can expose for consumers.
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
          //1.- Provide a keyed registry so multiple instances on the same page stay isolated.
          'pdsRecipeTemplate' => [
            'masters' => [
              $instance_uuid => [
                //2.- Expose identifiers and derivative collections to front-end scripts.
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

  public function blockForm($form, FormStateInterface $form_state) {
    $working_items = $this->getWorkingItems($form_state);
    $group_id = $this->getGroupIdFromFormState($form_state);
    $block_uuid = $this->getBlockInstanceUuid();
    $recipe_type = $this->configuration['recipe_type'] ?? 'pds_recipe_template';

    if (
      !$form_state->has('working_items')
      && !$form_state->hasTemporaryValue('working_items')
    ) {
      //1.- Seed the form state snapshot when the modal opens for the first time.
      $this->setWorkingItems($form_state, $working_items);

      if (!$group_id) {
        //2.- Force the DB group row to exist as soon as the first modal render happens.
        $group_id = $this->ensureGroupAndGetId();
      }
    }

    if (!$group_id) {
      //3.- Guarantee a valid id exists even when the modal rebuilds without prior cache.
      $group_id = $this->ensureGroupAndGetId();
    }

    //4.- Persist the resolved id for future AJAX rebuilds.
    $this->setGroupIdOnFormState($form_state, $group_id);

    $ensure_group_url = Url::fromRoute(
      'pds_recipe_template.ensure_group',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => ['type' => $recipe_type],
      ],
    )->toString();
    $resolve_row_url = Url::fromRoute(
      'pds_recipe_template.resolve_row',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
      ],
    )->toString();
    $create_row_url = Url::fromRoute(
      'pds_recipe_template.create_row',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => ['type' => $recipe_type],
      ],
    )->toString();
    $update_row_url = Url::fromRoute(
      'pds_recipe_template.update_row',
      [
        'uuid' => $block_uuid,
        'row_uuid' => '00000000-0000-0000-0000-000000000000',
      ],
      [
        'absolute' => TRUE,
        'query' => ['type' => $recipe_type],
      ],
    )->toString();

    $list_query = ['type' => $recipe_type];
    if ($group_id > 0) {
      //1.- Pass the resolved group id so the listing endpoint can hydrate legacy blocks that never stored a UUID.
      $list_query['group_id'] = (string) $group_id;
    }

    $list_rows_url = Url::fromRoute(
      'pds_recipe_template.list_rows',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => $list_query,
      ],
    )->toString();

    $form['pds_template_admin'] = [
      '#type' => 'container',
      //1.- Enable tree handling so blockSubmit() can retrieve nested values via parent keys.
      '#tree' => TRUE,
      '#attributes' => [
        'class' => [
          'pds-template-admin',
          'js-pds-template-admin',
        ],
        //5.- Expose AJAX endpoint and identifiers so JS can ensure persistence on add.
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

    $form['pds_template_admin']['group_id'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-template-group-id',
        'id' => 'pds-template-group-id',
      ],
      //1.- Store the resolved id as a mutable default so AJAX updates from JS persist through submit.
      '#default_value' => (string) $group_id,
    ];

  // Hidden JSON state.
  $form['pds_template_admin']['cards_state'] = [
    '#type' => 'hidden',
    // Drupal won't keep #id stable for hidden, so we rely on data-drupal-selector.
    '#attributes' => [
      'data-drupal-selector' => 'pds-template-cards-state',
    ],
    //1.- Expose the serialized rows via a default value so runtime edits reach PHP on submit.
    '#default_value' => json_encode($working_items),
  ];

  // Hidden edit index.
  $form['pds_template_admin']['edit_index'] = [
    '#type' => 'hidden',
    '#attributes' => [
      'data-drupal-selector' => 'pds-template-edit-index',
    ],
    //1.- Seed the edit pointer with a mutable default so JS can signal which row is active.
    '#default_value' => '-1',
  ];

  // Tabs nav.
  $form['pds_template_admin']['tabs_nav'] = [
    '#type' => 'container',
    '#attributes' => [
      'class' => [
        'pds-template-admin__tabs-horizontal',
      ],
      'data-pds-template-tabs' => 'nav',
    ],
  ];

  // We keep these as buttons but Drupal will render them as submit inputs.
  // That is fine for tab switching because our JS does preventDefault().
  $form['pds_template_admin']['tabs_nav']['tab_a'] = [
    '#type' => 'button',
    '#value' => $this->t('Tab A'),
    '#attributes' => [
      'class' => [
        'pds-template-admin__tab-btn-horizontal',
      ],
      'data-pds-template-tab-target' => 'panel-a',
    ],
  ];

  $form['pds_template_admin']['tabs_nav']['tab_b'] = [
    '#type' => 'button',
    '#value' => $this->t('Tab B'),
    '#attributes' => [
      'class' => [
        'pds-template-admin__tab-btn-horizontal',
      ],
      'data-pds-template-tab-target' => 'panel-b',
    ],
  ];

  // Panels wrapper.
  $form['pds_template_admin']['tabs_panels_wrapper'] = [
    '#type' => 'container',
    '#attributes' => [
      'id' => 'pds-template-panels-wrapper',
    ],
  ];

  $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels'] = [
    '#type' => 'container',
    '#attributes' => [
      'class' => [
        'pds-template-admin__panels-horizontal',
      ],
      'data-pds-template-tabs' => 'panels',
    ],
  ];

  // Panel A (edit form).
  $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a'] = [
    '#type' => 'container',
    '#attributes' => [
      'id' => 'pds-template-panel-a',
      'class' => [
        'pds-template-admin__panel-horizontal',
        'js-pds-template-panel',
      ],
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

  // Add row button.
  $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['add_item'] = [
    '#type' => 'markup',
    // Use a <div> instead of <button> because Layout Builder strips <button>.
    // Keep data-drupal-selector and id so JS can find it.
    '#markup' => '<div class="pds-template-admin__add-btn" data-drupal-selector="pds-template-add-card" id="pds-template-add-card" role="button" tabindex="0">Add row</div>',
  ];


  // Panel B (preview).
  $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b'] = [
    '#type' => 'container',
    '#attributes' => [
      'id' => 'pds-template-panel-b',
      'class' => [
        'pds-template-admin__panel-horizontal',
        'js-pds-template-panel',
        'is-active',
      ],
      'data-pds-template-panel-id' => 'panel-b',
    ],
  ];

  //1.- Build translated helper messages once so the markup stays readable.
  $loading_text = Html::escape((string) $this->t('Loading preview…'));
  $empty_text = Html::escape((string) $this->t('No rows yet.'));
  $error_text = Html::escape((string) $this->t('Unable to load preview.'));

  //2.- Expose dedicated regions for loading, empty, error and content states.
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
   * {@inheritdoc}
   * Save handler for the LB modal.
   * 1. Decode cards_state from hidden input.
   * 2. Normalize data.
   * 3. Persist to DB tables.
   * 4. Persist snapshot to config.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $raw_json = $this->extractNestedFormValue($form_state, [
      //1.- Target the direct container path first because most saves happen there.
      ['pds_template_admin', 'cards_state'],
      //2.- Check the Layout Builder parent wrapper when the subform prefixes values with settings.
      ['settings', 'pds_template_admin', 'cards_state'],
      //3.- Fall back to a flat lookup so legacy builders still return the payload.
      ['cards_state'],
    ], '');

    $group_id = NULL;
    $raw_group = $this->extractNestedFormValue($form_state, [
      //1.- Prefer the nested hidden value supplied by the admin container.
      ['pds_template_admin', 'group_id'],
      //2.- Support Layout Builder's prefixed structure when the plugin lives inside settings.
      ['settings', 'pds_template_admin', 'group_id'],
      //3.- Accept a flat key as a last resort so other embedders can reuse the block form.
      ['group_id'],
    ]);
    if (is_scalar($raw_group) && $raw_group !== '') {
      //1.- Prefer the hidden field payload so the exact id from JS persists.
      $group_id = (int) $raw_group;
    }
    if (!$group_id) {
      //2.- Fallback to the cached state helpers when the hidden input is missing.
      $group_id = $this->getGroupIdFromFormState($form_state) ?? 0;
    }
    if (!$group_id) {
      //3.- Ensure a record exists when both previous lookups failed (fresh block).
      $group_id = $this->ensureGroupAndGetId();
    }
    if ($group_id) {
      //4.- Mirror the resolved id to configuration for quick reuse on render.
      $this->configuration['group_id'] = (int) $group_id;
    }

    $items = [];
    if (is_string($raw_json) && $raw_json !== '') {
      $decoded = json_decode($raw_json, TRUE);
      if (is_array($decoded)) {
        $items = $decoded;
      }
    }

    $clean = [];
    foreach ($items as $delta => $item) {
      if (!is_array($item)) {
        continue;
      }

      $header = trim($item['header'] ?? '');
      if ($header === '') {
        continue;
      }

      $subheader   = trim($item['subheader']   ?? '');
      $description = trim($item['description'] ?? '');
      $link        = trim($item['link']        ?? '');
      $fid         = $item['image_fid']        ?? NULL;
      $desktop_img = trim((string) ($item['desktop_img'] ?? ''));
      $mobile_img  = trim((string) ($item['mobile_img']  ?? ''));
      $image_url   = trim((string) ($item['image_url']   ?? ''));

      if ($desktop_img === '' && $image_url !== '') {
        //1.- Preserve legacy rows where JS only stored image_url by copying it into desktop_img.
        $desktop_img = $image_url;
      }
      if ($mobile_img === '' && $image_url !== '') {
        //2.- Same fallback for mobile targets so both slots receive the resolved URL.
        $mobile_img = $image_url;
      }

      if (($desktop_img === '' || $mobile_img === '') && $fid) {
        //3.- Allow backward compatibility by resolving the URL only when it was not pre-populated.
        $file = File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $resolved_url = \Drupal::service('file_url_generator')
            ->generateString($file->getFileUri());
          if ($desktop_img === '') {
            $desktop_img = $resolved_url;
          }
          if ($mobile_img === '') {
            $mobile_img = $resolved_url;
          }
          if ($image_url === '') {
            $image_url = $resolved_url;
          }
        }
      }

      $clean[] = [
        'header'      => $header,
        'subheader'   => $subheader,
        'description' => $description,
        'link'        => $link,
        'desktop_img' => $desktop_img,
        'mobile_img'  => $mobile_img,
        'latitud'     => $item['latitud']  ?? NULL,
        'longitud'    => $item['longitud'] ?? NULL,
      ];
    }

    // Persist items in DB and config snapshot.
    $this->saveItemsForBlock($clean, $group_id);

    // Make sure dialog reopens prefilled.
    $this->setWorkingItems($form_state, $clean);
  }

  /**
   * AJAX endpoint that turns a temporary upload fid into a permanent URL.
   */
  public static function ajaxResolveRow(Request $request, string $uuid): JsonResponse {
    //1.- Reject invalid UUIDs early so we never touch the file system unnecessarily.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    if (!pds_recipe_template_user_can_manage_template()) {
      //2.- Block unauthorized access while trusting layout builder permissions to grant editors control.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    //3.- Parse the JSON payload regardless of whether the caller wrapped it in "row".
    $payload = [];
    $content = $request->getContent();
    if (is_string($content) && $content !== '') {
      $decoded = json_decode($content, TRUE);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }
    if (isset($payload['row']) && is_array($payload['row'])) {
      $payload = $payload['row'];
    }

    //4.- Use the shared helper so AJAX uploads work even during cache rebuilds.
    $promoter = \pds_recipe_template_resolve_row_image_promoter();
    if (!$promoter) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Image promotion is unavailable. Rebuild caches and try again.',
      ], 500);
    }

    $result = $promoter->promote($payload);

    if (($result['status'] ?? '') !== 'ok') {
      //5.- Propagate the precise failure so the front-end knows whether to retry or reset the fid.
      return new JsonResponse([
        'status' => 'error',
        'message' => $result['message'] ?? 'Unable to promote image.',
      ], $result['code'] ?? 500);
    }

    //6.- Hand the resolved payload back so the caller can cache the canonical URLs immediately.
    return new JsonResponse([
      'status' => 'ok',
      'image_url' => $result['image_url'] ?? '',
      'image_fid' => $result['image_fid'] ?? NULL,
      'desktop_img' => $result['desktop_img'] ?? '',
      'mobile_img' => $result['mobile_img'] ?? '',
    ]);
  }

  /**
   * Legacy preview builder for ajaxItems().
   */
  private function buildCardsMarkup(array $items): string {
    //1.- Prepare the table header so both JS and PHP outputs stay aligned.
    $table_head =
      '<thead>' .
        '<tr>' .
          '<th class="pds-template-table__col-thumb">' . $this->t('Image') . '</th>' .
          '<th>' . $this->t('Header') . '</th>' .
          '<th>' . $this->t('Subheader') . '</th>' .
          '<th>' . $this->t('Link') . '</th>' .
          '<th>' . $this->t('Actions') . '</th>' .
        '</tr>' .
      '</thead>';

    if ($items === [] || count($items) === 0) {
      //2.- Mirror the empty-state markup so the AJAX refresh matches client rendering.
      $table_body =
        '<tbody>' .
          '<tr>' .
            '<td colspan="5"><em>' . $this->t('No rows yet.') . '</em></td>' .
          '</tr>' .
        '</tbody>';

      return '<table class="pds-template-table">' . $table_head . $table_body . '</table>';
    }

    //3.- Iterate through rows and keep attribute escaping consistent with the JS counterpart.
    $rows_markup = '';
    foreach ($items as $delta => $item) {
      $header = htmlspecialchars((string) ($item['header'] ?? ''), ENT_QUOTES, 'UTF-8');
      $subheader = htmlspecialchars((string) ($item['subheader'] ?? ''), ENT_QUOTES, 'UTF-8');
      $link = htmlspecialchars((string) ($item['link'] ?? ''), ENT_QUOTES, 'UTF-8');
      $thumb = (string) ($item['desktop_img'] ?? $item['image_url'] ?? '');
      $thumb_markup = $thumb !== ''
        ? '<img src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="" />'
        : '';

      $rows_markup .= '<tr data-row-index="' . (int) $delta . '">';
      $rows_markup .=   '<td class="pds-template-table__thumb">' . $thumb_markup . '</td>';
      $rows_markup .=   '<td>' . $header . '</td>';
      $rows_markup .=   '<td>' . $subheader . '</td>';
      $rows_markup .=   '<td>' . $link . '</td>';
      $rows_markup .=   '<td>';
      $rows_markup .=     '<button type="button" class="pds-template-row-edit">' . $this->t('Edit') . '</button> ';
      $rows_markup .=     '<button type="button" class="pds-template-row-del">' . $this->t('Delete') . '</button>';
      $rows_markup .=   '</td>';
      $rows_markup .= '</tr>';
    }

    //4.- Return the assembled table HTML ready for the AJAX container.
    return '<table class="pds-template-table">' .
      $table_head .
      '<tbody>' . $rows_markup . '</tbody>' .
      '</table>';
  }

  /**
   * Kept only so LB AJAX callbacks referencing old code do not fatal.
   */
  public function addItemSubmit(array &$form, FormStateInterface $form_state): void {
    // No-op.
  }

  public function ajaxItems(array &$form, FormStateInterface $form_state) {
    $items = $this->getWorkingItems($form_state);
    $cards_markup = $this->buildCardsMarkup($items);

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-template-preview-list',
      ],
      'inner' => [
        '#type' => 'item',
        '#markup' => $cards_markup,
      ],
    ];
  }

}
