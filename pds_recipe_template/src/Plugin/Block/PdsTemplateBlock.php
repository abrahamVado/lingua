<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Component\Utility\Uuid;
use Drupal\Core\Block\BlockBase;
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

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // Snapshot fallback for render if DB or tables are not available yet.
      'items' => [],
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
    if (!empty($this->configuration['instance_uuid'])) {
      return $this->configuration['instance_uuid'];
    }
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

    //1.- Delegate the persistence work to the shared service so controllers reuse it too.
    $group_id = \Drupal::service('pds_recipe_template.group_manager')
      ->ensureGroupAndGetId($uuid, $type);

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
      // 1. Find group id for this block UUID.
      $group_row = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchAssoc();

      if (!$group_row || empty($group_row['id'])) {
        // No rows yet in DB. Use config snapshot.
        return $this->configuration['items'] ?? [];
      }

      $group_id = (int) $group_row['id'];

      // 2. Load all active items for that group.
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
        $rows[] = [
          'header'      => $record->header,
          'subheader'   => $record->subheader,
          'description' => $record->description,
          'link'        => $record->url,
          'desktop_img' => $record->desktop_img,
          'mobile_img'  => $record->mobile_img,
          'latitud'     => $record->latitud,
          'longitud'    => $record->longitud,
        ];
      }

      if ($rows) {
        return $rows;
      }

      // If DB empty, fallback to snapshot.
      return $this->configuration['items'] ?? [];
    }
    catch (\Exception $e) {
      // Tables not created yet or DB error.
      return $this->configuration['items'] ?? [];
    }
  }

  /**
   * Save cleaned items into DB for this block.
   * Overwrite existing active items for this group.
   * Also update configuration['items'] snapshot.
   */
  private function saveItemsForBlock(array $clean_items): void {
    $connection = \Drupal::database();
    $group_id = $this->ensureGroupAndGetId();
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
  }

  /**
   * {@inheritdoc}
   * Render block on frontend.
   */
  public function build(): array {
    $items = $this->loadItemsForBlock();

    return [
      '#theme' => 'pds_template_block',
      '#items' => $items,
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

    $form['pds_template_admin'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'pds-template-admin',
          'js-pds-template-admin',
        ],
        //5.- Expose AJAX endpoint and identifiers so JS can ensure persistence on add.
        'data-pds-template-ensure-group-url' => $ensure_group_url,
        'data-pds-template-resolve-row-url' => $resolve_row_url,
        'data-pds-template-create-row-url' => $create_row_url,
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
      '#value' => (string) $group_id,
    ];

  // Hidden JSON state.
  $form['pds_template_admin']['cards_state'] = [
    '#type' => 'hidden',
    // Drupal won't keep #id stable for hidden, so we rely on data-drupal-selector.
    '#attributes' => [
      'data-drupal-selector' => 'pds-template-cards-state',
    ],
    '#value' => json_encode($working_items),
  ];

  // Hidden edit index.
  $form['pds_template_admin']['edit_index'] = [
    '#type' => 'hidden',
    '#attributes' => [
      'data-drupal-selector' => 'pds-template-edit-index',
    ],
    '#value' => '-1',
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

  $form['pds_template_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b']['preview_list'] = [
    '#type' => 'markup',
    '#markup' => '<div id="pds-template-preview-list"></div>',
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
    $raw_json = $form_state->getValue([
      'pds_template_admin',
      'cards_state',
    ]);

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
    $this->saveItemsForBlock($clean);

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

    //2.- Parse the JSON payload regardless of whether the caller wrapped it in "row".
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

    $image_fid_raw = $payload['image_fid'] ?? NULL;
    $image_fid = is_numeric($image_fid_raw) ? (int) $image_fid_raw : 0;

    if ($image_fid === 0) {
      //3.- When no fid was provided (editing an existing row) simply echo URLs back.
      $fallback_url = (string) ($payload['desktop_img']
        ?? $payload['mobile_img']
        ?? $payload['image_url']
        ?? ''
      );

      return new JsonResponse([
        'status' => 'ok',
        'image_url' => $fallback_url,
        'image_fid' => NULL,
        'desktop_img' => (string) ($payload['desktop_img'] ?? $fallback_url),
        'mobile_img' => (string) ($payload['mobile_img'] ?? $fallback_url),
      ]);
    }

    $file = File::load($image_fid);
    if (!$file) {
      //4.- Let the UI know the fid is stale so it can react accordingly.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'File not found.',
      ], 404);
    }

    try {
      //5.- Flip the upload to permanent straight away so later loads work without the final save.
      $file->setPermanent();
      $file->save();
      $url = \Drupal::service('file_url_generator')
        ->generateString($file->getFileUri());
    }
    catch (\Exception $e) {
      //6.- Fail gracefully so the front-end can keep the temporary state.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to persist file.',
      ], 500);
    }

    //7.- Hand the resolved URL back to the caller along with the confirmed fid.
    return new JsonResponse([
      'status' => 'ok',
      'image_url' => $url,
      'image_fid' => (int) $file->id(),
      'desktop_img' => $url,
      'mobile_img' => $url,
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
