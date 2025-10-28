<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * @Block(
 *   id = "pds_template_block",
 *   admin_label = @Translation("PDS Template"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTemplateBlock extends BlockBase {

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
    $connection = \Drupal::database();
    $uuid = $this->getBlockInstanceUuid();
    $type = $this->configuration['recipe_type'] ?? 'pds_recipe_template';
    $now = \Drupal::time()->getRequestTime();

    // Try existing active group row.
    $existing_id = $connection->select('pds_template_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.uuid', $uuid)
      ->condition('g.deleted_at', NULL, 'IS NULL')
      ->execute()
      ->fetchField();

    if ($existing_id) {
      return (int) $existing_id;
    }

    // Insert new group.
    $connection->insert('pds_template_group')
      ->fields([
        'uuid' => $uuid,
        'type' => $type,
        'created_at' => $now,
        'deleted_at' => NULL,
      ])
      ->execute();

    // Fetch new id.
    $new_id = $connection->select('pds_template_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.uuid', $uuid)
      ->condition('g.deleted_at', NULL, 'IS NULL')
      ->execute()
      ->fetchField();

    return (int) $new_id;
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

  /**
   * Stash working_items in form_state for modal reuse.
   */
  private function setWorkingItems(FormStateInterface $form_state, array $items): void {
    $form_state->set('working_items', $items);
    $form_state->setTemporaryValue('working_items', $items);

    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        $parent->set('working_items', $items);
        $parent->setTemporaryValue('working_items', $items);
      }
    }
  }

  /**
   * Persist computed group id on the form state for AJAX rebuilds.
   */
  private function setGroupIdOnFormState(FormStateInterface $form_state, int $group_id): void {
    //1.- Save the id on the current form state so later requests keep the context.
    $form_state->set('pds_template_group_id', $group_id);
    //2.- Mirror into the temporary store for subform rebuilds triggered by AJAX.
    $form_state->setTemporaryValue('pds_template_group_id', $group_id);

    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        //3.- Bubble the id up so parent form handlers can also reach it.
        $parent->set('pds_template_group_id', $group_id);
        $parent->setTemporaryValue('pds_template_group_id', $group_id);
      }
    }
  }

  /**
   * Retrieve cached group id from the form state when available.
   */
  private function getGroupIdFromFormState(FormStateInterface $form_state): ?int {
    //1.- Look at the immediate form state bag first.
    if ($form_state->has('pds_template_group_id')) {
      $value = $form_state->get('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    //2.- Fallback to the temporary storage when inside AJAX subforms.
    if ($form_state->hasTemporaryValue('pds_template_group_id')) {
      $value = $form_state->getTemporaryValue('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    return NULL;
  }

  /**
   * Get items to prefill the modal when editing.
   */
  private function getWorkingItems(FormStateInterface $form_state): array {
    // Prefer temp state inside this LB dialog request.
    if ($form_state->has('working_items')) {
      $tmp = $form_state->get('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }
    if ($form_state->hasTemporaryValue('working_items')) {
      $tmp = $form_state->getTemporaryValue('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // Fallback to last saved snapshot in config.
    $saved = $this->configuration['items'] ?? [];
    return is_array($saved) ? array_values($saved) : [];
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

    $form['pds_template_admin'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'pds-template-admin',
          'js-pds-template-admin',
        ],
        //5.- Expose AJAX endpoint and identifiers so JS can ensure persistence on add.
        'data-pds-template-ensure-group-url' => $ensure_group_url,
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

      // Resolve URL from fid if needed.
      $image_url = $item['image_url'] ?? '';
      if ($fid && !$image_url) {
        $file = File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $image_url = \Drupal::service('file_url_generator')
            ->generateString($file->getFileUri());
        }
      }

      $clean[] = [
        'header'      => $header,
        'subheader'   => $subheader,
        'description' => $description,
        'link'        => $link,
        'desktop_img' => $image_url,
        'mobile_img'  => $image_url,
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
   * Legacy preview builder for ajaxItems().
   */
  private function buildCardsMarkup(array $items): string {
    $cards_markup = '';

    foreach ($items as $item) {
      $h   = $item['header']      ?? '';
      $sh  = $item['subheader']   ?? '';
      $d   = $item['description'] ?? '';
      $ln  = $item['link']        ?? '';
      $img = $item['desktop_img'] ?? $item['image_url'] ?? '';

      $cards_markup .= '<div class="pds-template-card">';
      if ($img !== '') {
        $cards_markup .= '<div class="pds-template-card__image"><img src="' . htmlspecialchars($img) . '" alt=""/></div>';
      }
      $cards_markup .= '<div class="pds-template-card__body">';
      $cards_markup .= '<div class="pds-template-card__header">' . htmlspecialchars($h) . '</div>';
      $cards_markup .= '<div class="pds-template-card__subheader">' . htmlspecialchars($sh) . '</div>';
      $cards_markup .= '<div class="pds-template-card__desc">' . htmlspecialchars($d) . '</div>';
      if ($ln !== '') {
        $cards_markup .= '<div class="pds-template-card__link">' . htmlspecialchars($ln) . '</div>';
      }
      $cards_markup .= '</div>';
      $cards_markup .= '</div>';
    }

    if ($cards_markup === '') {
      $cards_markup = '<div class="pds-template-card-empty">' . $this->t('No cards yet.') . '</div>';
    }

    return '<div class="pds-template-cardlist">' . $cards_markup . '</div>';
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
