<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

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
 *   id = "pds_timeline_block",
 *   admin_label = @Translation("PDS Timeline"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTimelineBlock extends BlockBase {
  use PdsTimelineBlockStateTrait;
  use PdsTimelineRenderContextTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      //1.- Snapshot fallback for render if DB or tables are not available yet.
      'items' => [],
      //2.- Persist the numeric group id so front-end renderers can reference it.
      'group_id' => 0,
      //3.- Store the stable UUID that ties this block instance to rows in pds_timeline_group.
      'instance_uuid' => '',
      //4.- Keep track of the logical recipe type so derivatives can specialize behavior.
      'recipe_type' => 'pds_recipe_timeline',
    ];
  }

  /**
   * Return stable UUID for this block instance.
   * Stored in configuration['instance_uuid'].
   */
  private function getBlockInstanceUuid(): string {
    $stored_uuid = $this->configuration['instance_uuid'] ?? '';
    if (is_string($stored_uuid) && $stored_uuid !== '') {
      if (Uuid::isValid($stored_uuid)) {
        return $stored_uuid;
      }

      //1.- Clear malformed identifiers so the legacy recovery path can repair the configuration using the persisted numeric group id.
      $stored_uuid = '';
      $this->configuration['instance_uuid'] = '';
    }

    //1.- Attempt to recover the historical UUID from the persisted group id so legacy blocks created before instance_uuid was stored continue to load their saved rows when editors reopen the modal.
    $stored_group_id = $this->configuration['group_id'] ?? 0;
    if (is_numeric($stored_group_id) && (int) $stored_group_id > 0) {
      try {
        $connection = \Drupal::database();
        $group_uuid = $connection->select('pds_timeline_group', 'g')
          ->fields('g', ['uuid'])
          ->condition('g.id', (int) $stored_group_id)
          ->condition('g.deleted_at', NULL, 'IS NULL')
          ->range(0, 1)
          ->execute()
          ->fetchField();

        if (is_string($group_uuid) && $group_uuid !== '' && Uuid::isValid($group_uuid)) {
          //2.- Cache the recovered identifier so future lookups skip the database round-trip while keeping old rows linked to the block.
          $this->configuration['instance_uuid'] = $group_uuid;
          return $group_uuid;
        }

        if ($group_uuid !== FALSE) {
          //3.- Repair legacy rows that never stored a UUID so historical data continues to load in the editor by assigning a fresh identifier directly on the existing group record.
          $replacement_uuid = \Drupal::service('uuid')->generate();
          $connection->update('pds_timeline_group')
            ->fields(['uuid' => $replacement_uuid])
            ->condition('id', (int) $stored_group_id)
            ->condition('deleted_at', NULL, 'IS NULL')
            ->execute();

          //4.- Persist the new identifier so subsequent AJAX requests reuse the repaired value instead of generating more UUIDs.
          $this->configuration['instance_uuid'] = $replacement_uuid;
          return $replacement_uuid;
        }
      }
      catch (\Exception $exception) {
        //5.- Ignore lookup failures so newly created blocks can still receive a fresh UUID without interrupting the editor experience.
      }
    }

    //6.- Fallback to generating a new UUID for brand-new blocks or when the historical lookup could not find a matching record.
    $uuid = \Drupal::service('uuid')->generate();
    $this->configuration['instance_uuid'] = $uuid;
    return $uuid;
  }

  /**
   * Ensure a row exists in pds_timeline_group for this block UUID.
   * Return that row's numeric id (group_id).
   */
  private function ensureGroupAndGetId(): int {
    $uuid = $this->getBlockInstanceUuid();
    $type = $this->configuration['recipe_type'] ?? 'pds_recipe_timeline';

    //1.- Leverage the shared procedural helper so controllers and forms reuse identical logic.
    $group_id = \pds_recipe_timeline_ensure_group_and_get_id($uuid, $type);

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
      //1.- Remember the historical numeric identifier stored in configuration for legacy fallbacks.
      $legacy_group_id = 0;
      $raw_legacy_group = $this->configuration['group_id'] ?? 0;
      if (is_numeric($raw_legacy_group) && (int) $raw_legacy_group > 0) {
        $legacy_group_id = (int) $raw_legacy_group;
      }

      //2.- Resolve the numeric group id that matches the current UUID so AJAX previews stay in sync.
      $group_row = $connection->select('pds_timeline_group', 'g')
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
        //3.- Fall back to the stored group id when legacy blocks never recorded an instance UUID.
        if ($legacy_group_id > 0) {
          $group_id = $legacy_group_id;

          //4.- Attempt to hydrate the cached UUID from the legacy group so subsequent requests reuse it.
          $legacy_uuid = $connection->select('pds_timeline_group', 'g')
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

        if ($legacy_group_id > 0 && $legacy_group_id !== $group_id) {
          //5.- Retry the historical group id so legacy datasets created before UUID storage still hydrate the preview.
          $fallback_rows = $this->loadRowsForGroup($connection, $legacy_group_id);
          if ($fallback_rows !== []) {
            //6.- Repair the legacy record by storing the active UUID and caching the recovered identifier.
            $legacy_uuid = $connection->select('pds_timeline_group', 'g')
              ->fields('g', ['uuid'])
              ->condition('g.id', $legacy_group_id)
              ->condition('g.deleted_at', NULL, 'IS NULL')
              ->range(0, 1)
              ->execute()
              ->fetchField();

            if ($uuid !== '' && (!is_string($legacy_uuid) || !Uuid::isValid((string) $legacy_uuid) || (string) $legacy_uuid !== $uuid)) {
              $connection->update('pds_timeline_group')
                ->fields(['uuid' => $uuid])
                ->condition('id', $legacy_group_id)
                ->condition('deleted_at', NULL, 'IS NULL')
                ->execute();
            }

            $this->configuration['group_id'] = $legacy_group_id;
            return $fallback_rows;
          }
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
    $query = $connection->select('pds_timeline_item', 'i')
      ->fields('i', [
        'id',
        'uuid',
        'weight',
        'header',
        'subheader',
        'description',
        'description_json',
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

      //4.- Normalize identifier metadata so the modal preview can reuse the same payload structure as the JSON listing endpoint and keep edit actions wired to the persisted rows.
      $resolved_id = isset($record->id) ? (int) $record->id : 0;
      $resolved_uuid = isset($record->uuid) ? (string) $record->uuid : '';
      if ($resolved_uuid !== '' && !Uuid::isValid($resolved_uuid)) {
        //5.- Ignore malformed UUIDs so legacy noise does not block rendering.
        $resolved_uuid = '';
      }

      $resolved_weight = isset($record->weight) && is_numeric($record->weight)
        ? (int) $record->weight
        : NULL;

      $rows[] = [
        'id'          => $resolved_id,
        'uuid'        => $resolved_uuid,
        'weight'      => $resolved_weight,
        'header'      => $record->header,
        'subheader'   => $record->subheader,
        'description' => $record->description,
        'description_json' => (string) $record->description_json,
        'link'        => $record->url,
        'desktop_img' => $record->desktop_img,
        'mobile_img'  => $record->mobile_img,
        'image_url'   => $primary_image,
        'thumbnail'   => $primary_image,
        'latitud'     => $record->latitud,
        'longitud'    => $record->longitud,
      ];
    }

    return $rows;
  }

  /**
   * Decode the stored JSON structure into normalized timeline segments.
   */
  private function parseTimelineStructure(array $row): array {
    $result = [
      'segments' => [],
      'metadata' => [
        'heading' => '',
        'years' => [],
      ],
    ];

    $raw_json = '';
    if (isset($row['description_json']) && is_string($row['description_json'])) {
      $raw_json = trim($row['description_json']);
    }
    if ($raw_json === '') {
      return $result;
    }

    $decoded = json_decode($raw_json, TRUE);
    if ($decoded === NULL && json_last_error() !== JSON_ERROR_NONE) {
      return $result;
    }
    if (!is_array($decoded)) {
      return $result;
    }

    $candidate_segments = [];
    if (isset($decoded['segments']) && is_array($decoded['segments'])) {
      $candidate_segments = $decoded['segments'];
    }
    elseif (isset($decoded['timeline']) && is_array($decoded['timeline'])) {
      $candidate_segments = $decoded['timeline'];
    }
    elseif ($this->isListArray($decoded)) {
      //1.- Accept list-style arrays directly so simple JSON payloads remain valid.
      $candidate_segments = $decoded;
    }
    else {
      //2.- Treat associative objects as label => data collections for compact authoring.
      $candidate_segments = $decoded;
    }

    $position = 0;
    foreach ($candidate_segments as $key => $segment_raw) {
      $normalized = $this->normalizeTimelineSegment($key, $segment_raw, $position);
      if ($normalized !== NULL) {
        $result['segments'][] = $normalized;
      }
      $position++;
    }

    $metadata_sources = [];
    if (isset($decoded['metadata']) && is_array($decoded['metadata'])) {
      $metadata_sources[] = $decoded['metadata'];
    }
    if (isset($decoded['meta']) && is_array($decoded['meta'])) {
      $metadata_sources[] = $decoded['meta'];
    }
    $metadata_sources[] = $decoded;

    foreach ($metadata_sources as $meta_source) {
      if ($result['metadata']['heading'] === '' && isset($meta_source['heading']) && is_string($meta_source['heading'])) {
        $result['metadata']['heading'] = trim($meta_source['heading']);
      }
      if ($result['metadata']['heading'] === '' && isset($meta_source['title']) && is_string($meta_source['title'])) {
        $result['metadata']['heading'] = trim($meta_source['title']);
      }
      if ($result['metadata']['years'] === [] && isset($meta_source['years']) && is_array($meta_source['years'])) {
        $result['metadata']['years'] = $this->normalizeTimelineYears($meta_source['years']);
      }
    }

    return $result;
  }

  /**
   * Normalize an individual timeline segment definition.
   */
  private function normalizeTimelineSegment($key, $segment_raw, int $position): ?array {
    if (is_string($segment_raw)) {
      $segment_raw = ['label' => $segment_raw];
    }
    elseif (is_numeric($segment_raw)) {
      $segment_raw = ['width' => $segment_raw];
    }
    elseif (!is_array($segment_raw)) {
      return NULL;
    }

    $label = '';
    if (isset($segment_raw['label']) && is_string($segment_raw['label'])) {
      $label = trim($segment_raw['label']);
    }
    elseif (isset($segment_raw['title']) && is_string($segment_raw['title'])) {
      $label = trim($segment_raw['title']);
    }
    elseif (is_string($key)) {
      $label = trim($key);
    }

    $info_text = '';
    foreach (['info', 'tooltip', 'description', 'text', 'label'] as $info_key) {
      if ($info_text === '' && isset($segment_raw[$info_key]) && is_string($segment_raw[$info_key])) {
        $info_text = trim($segment_raw[$info_key]);
      }
    }
    if ($info_text === '' && $label !== '') {
      $info_text = $label;
    }

    $image = '';
    foreach (['image', 'image_url', 'logo', 'img'] as $image_key) {
      if ($image === '' && isset($segment_raw[$image_key]) && is_string($segment_raw[$image_key])) {
        $image = trim($segment_raw[$image_key]);
      }
    }

    $image_alt = '';
    foreach (['image_alt', 'alt'] as $alt_key) {
      if ($image_alt === '' && isset($segment_raw[$alt_key]) && is_string($segment_raw[$alt_key])) {
        $image_alt = trim($segment_raw[$alt_key]);
      }
    }
    if ($image_alt === '' && $label !== '') {
      $image_alt = $label;
    }

    $is_principal = FALSE;
    foreach (['principal', 'is_principal'] as $principal_key) {
      if (isset($segment_raw[$principal_key])) {
        $is_principal = (bool) $segment_raw[$principal_key];
      }
    }
    if (!$is_principal && isset($segment_raw['type']) && is_string($segment_raw['type'])) {
      $is_principal = strtolower($segment_raw['type']) === 'principal';
    }
    if (!$is_principal && isset($segment_raw['variant']) && is_string($segment_raw['variant'])) {
      $is_principal = strtolower($segment_raw['variant']) === 'principal';
    }

    $is_first = FALSE;
    foreach (['first', 'is_first', 'placeholder'] as $first_key) {
      if (isset($segment_raw[$first_key])) {
        $is_first = (bool) $segment_raw[$first_key];
        if ($is_first) {
          break;
        }
      }
    }

    $classes = [];
    if (isset($segment_raw['classes'])) {
      if (is_string($segment_raw['classes'])) {
        $classes = preg_split('/\s+/', trim($segment_raw['classes']));
      }
      elseif (is_array($segment_raw['classes'])) {
        $classes = array_map(static function ($value) {
          return is_string($value) ? trim($value) : '';
        }, $segment_raw['classes']);
      }
      $classes = array_values(array_filter($classes, static function ($value) {
        return $value !== '';
      }));
    }

    $width_style = '0%';
    foreach (['width_percent', 'width', 'percentage', 'span'] as $width_key) {
      if (isset($segment_raw[$width_key])) {
        $width_style = $this->formatWidthStyle($segment_raw[$width_key]);
        break;
      }
    }

    $tooltip_variant = 'default';
    if (isset($segment_raw['tooltip_variant']) && is_string($segment_raw['tooltip_variant'])) {
      $tooltip_variant = trim($segment_raw['tooltip_variant']);
    }
    elseif ($is_principal) {
      $tooltip_variant = 'principal';
    }

    return [
      'label' => $label,
      'info_text' => $info_text,
      'info_image' => $image,
      'info_image_alt' => $image_alt,
      'width_style' => $width_style,
      'is_principal' => $is_principal,
      'is_first' => $is_first,
      'classes' => $classes,
      'tooltip_variant' => $tooltip_variant,
      'position' => $position,
    ];
  }

  /**
   * Ensure arrays that declare timeline years are normalized to trimmed strings.
   */
  private function normalizeTimelineYears(array $years): array {
    $normalized = [];
    foreach ($years as $year) {
      if (is_array($year)) {
        if (isset($year['label']) && is_string($year['label'])) {
          $year = $year['label'];
        }
        elseif (isset($year['value']) && is_string($year['value'])) {
          $year = $year['value'];
        }
        else {
          continue;
        }
      }

      $candidate = trim((string) $year);
      if ($candidate === '') {
        continue;
      }

      if (function_exists('mb_substr')) {
        $candidate = mb_substr($candidate, 0, 32);
      }
      else {
        $candidate = substr($candidate, 0, 32);
      }

      if ($candidate !== '') {
        $normalized[] = $candidate;
      }
    }

    return $normalized;
  }

  /**
   * Format width declarations into CSS-ready percentages.
   */
  private function formatWidthStyle($value): string {
    if (is_string($value)) {
      $trimmed = trim($value);
      if ($trimmed === '') {
        return '0%';
      }
      if (str_contains($trimmed, '%')) {
        return $trimmed;
      }
      if (!is_numeric($trimmed)) {
        return '0%';
      }
      $value = (float) $trimmed;
    }

    if (!is_numeric($value)) {
      return '0%';
    }

    $number = (float) $value;
    $rounded = round($number, 6);
    $formatted = rtrim(rtrim(number_format($rounded, 6, '.', ''), '0'), '.');
    if ($formatted === '') {
      $formatted = '0';
    }

    return $formatted . '%';
  }

  /**
   * Compatibility layer for PHP < 8.1 list detection.
   */
  private function isListArray(array $value): bool {
    if (function_exists('array_is_list')) {
      return array_is_list($value);
    }

    $expected = 0;
    foreach ($value as $key => $_) {
      if ($key !== $expected) {
        return FALSE;
      }
      $expected++;
    }

    return TRUE;
  }

  /**
   * Save cleaned items into DB for this block.
   * Preserve existing identifiers while syncing the active items for this group.
   * Also update configuration['items'] snapshot.
   */
  private function saveItemsForBlock(array $clean_items, ?int $group_id = NULL): void {
    $connection = \Drupal::database();
    if ($group_id === NULL || $group_id === 0) {
      //1.- Resolve the backing group row when the caller did not provide one explicitly.
      $group_id = $this->ensureGroupAndGetId();
    }
    $now = \Drupal::time()->getRequestTime();

    //2.- Index the existing rows so we can reuse identifiers preserved on AJAX saves.
    $existing_by_uuid = [];
    $existing_by_id = [];
    $records = $connection->select('pds_timeline_item', 'i')
      ->fields('i', ['id', 'uuid'])
      ->condition('group_id', $group_id)
      ->condition('deleted_at', NULL, 'IS NULL')
      ->execute();
    foreach ($records as $record) {
      $existing_by_id[(int) $record->id] = (string) $record->uuid;
      if (is_string($record->uuid) && $record->uuid !== '') {
        $existing_by_uuid[(string) $record->uuid] = (int) $record->id;
      }
    }

    $kept_ids = [];
    $snapshot = [];

    //3.- Update existing rows or create new ones, keeping database identifiers whenever possible.
    foreach ($clean_items as $delta => $row) {
      $uuid = isset($row['uuid']) && is_string($row['uuid']) && Uuid::isValid($row['uuid'])
        ? $row['uuid']
        : '';
      $candidate_id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : NULL;

      $resolved_id = NULL;
      if ($uuid !== '' && isset($existing_by_uuid[$uuid])) {
        //1.- Match by UUID to maintain the relationship with auxiliary tables like the timeline dataset.
        $resolved_id = $existing_by_uuid[$uuid];
      }
      elseif ($candidate_id && isset($existing_by_id[$candidate_id])) {
        //2.- Accept numeric IDs from the hidden snapshot when the UUID was absent.
        $resolved_id = $candidate_id;
        $uuid = $existing_by_id[$candidate_id] ?? $uuid;
      }

      if ($resolved_id) {
        //3.- Refresh the stored values while keeping the primary key untouched.
        $connection->update('pds_timeline_item')
          ->fields([
            'weight'      => $delta,
            'header'      => $row['header'] ?? '',
            'subheader'   => $row['subheader'] ?? '',
            'description' => $row['description'] ?? '',
            'description_json' => $row['description_json'] ?? '',
            'url'         => $row['link'] ?? '',
            'desktop_img' => $row['desktop_img'] ?? '',
            'mobile_img'  => $row['mobile_img'] ?? '',
            'latitud'     => $row['latitud'] ?? NULL,
            'longitud'    => $row['longitud'] ?? NULL,
          ])
          ->condition('id', $resolved_id)
          ->execute();
      }
      else {
        //4.- Insert fresh rows when no reusable identifier is present in the submitted snapshot.
        $uuid = $uuid !== '' ? $uuid : \Drupal::service('uuid')->generate();
        $resolved_id = (int) $connection->insert('pds_timeline_item')
          ->fields([
            'uuid'        => $uuid,
            'group_id'    => $group_id,
            'weight'      => $delta,
            'header'      => $row['header'] ?? '',
            'subheader'   => $row['subheader'] ?? '',
            'description' => $row['description'] ?? '',
            'description_json' => $row['description_json'] ?? '',
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

      $kept_ids[] = $resolved_id;
      $snapshot[] = [
        'header'      => $row['header'] ?? '',
        'subheader'   => $row['subheader'] ?? '',
        'description' => $row['description'] ?? '',
        'description_json' => $row['description_json'] ?? '',
        'link'        => $row['link'] ?? '',
        'desktop_img' => $row['desktop_img'] ?? '',
        'mobile_img'  => $row['mobile_img'] ?? '',
        'latitud'     => $row['latitud'] ?? NULL,
        'longitud'    => $row['longitud'] ?? NULL,
        'id'          => $resolved_id,
        'uuid'        => $uuid,
      ];
    }

    //4.- Mark rows missing from the submission as deleted without touching preserved identifiers.
    $deletion = $connection->update('pds_timeline_item')
      ->fields(['deleted_at' => $now])
      ->condition('group_id', $group_id)
      ->condition('deleted_at', NULL, 'IS NULL');
    if ($kept_ids !== []) {
      $deletion->condition('id', $kept_ids, 'NOT IN');
    }
    $deletion->execute();

    //5.- Keep a configuration snapshot for fallback renders when the database is unavailable.
    $this->configuration['items'] = $snapshot;
    if ($group_id) {
      //5.- Mirror the id in configuration so future renders expose it without DB lookups.
      $this->configuration['group_id'] = (int) $group_id;
    }
  }

  /**
   * {@inheritdoc}
   * Render block on frontend.
   */
  public function build(): array {
    $items = $this->loadItemsForBlock();
    $normalized_items = [];
    foreach ($items as $delta => $row) {
      if (!is_array($row)) {
        continue;
      }

      $parsed = $this->parseTimelineStructure($row);
      $row['timeline_segments'] = $parsed['segments'];
      $row['timeline_meta'] = $parsed['metadata'];

      $normalized_items[$delta] = $row;
    }
    if ($normalized_items !== []) {
      $items = $normalized_items;
    }

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
    $timeline_meta = [
      'heading' => (string) $this->t('Principais executivos'),
      'years' => [],
    ];
    if (isset($extended_datasets['timeline']['meta']) && is_array($extended_datasets['timeline']['meta'])) {
      $meta = $extended_datasets['timeline']['meta'];
      if (isset($meta['heading']) && is_string($meta['heading']) && trim($meta['heading']) !== '') {
        $timeline_meta['heading'] = trim($meta['heading']);
      }
      if (isset($meta['years']) && is_array($meta['years'])) {
        $timeline_meta['years'] = array_values(array_filter(array_map(static function ($value) {
          return is_string($value) ? trim($value) : '';
        }, $meta['years'])));
      }
    }

    return [
      '#theme' => 'pds_timeline_block',
      '#items' => $items,
      '#group_id' => $group_id,
      '#instance_uuid' => $instance_uuid,
      '#master_metadata' => $master_metadata,
      '#extended_datasets' => $extended_datasets,
      '#timeline_meta' => $timeline_meta,
      '#attached' => [
        'library' => [
          'pds_recipe_timeline/pds_timeline.public',
        ],
        'drupalSettings' => [
          //1.- Provide a keyed registry so multiple instances on the same page stay isolated.
          'pdsRecipeTimeline' => [
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
    $stored_group_id = 0;
    $raw_stored_group_id = $this->configuration['group_id'] ?? 0;
    if (is_numeric($raw_stored_group_id) && (int) $raw_stored_group_id > 0) {
      //1.- Remember the historical identifier so legacy datasets without UUID links can still hydrate Tab B.
      $stored_group_id = (int) $raw_stored_group_id;
    }
    if (!$group_id && $stored_group_id > 0) {
      //2.- Reuse the persisted id as the active pointer when the current form state does not expose one yet.
      $group_id = $stored_group_id;
    }
    $block_uuid = $this->getBlockInstanceUuid();
    $recipe_type = $this->configuration['recipe_type'] ?? 'pds_recipe_timeline';

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
      'pds_recipe_timeline.ensure_group',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => ['type' => $recipe_type],
      ],
    )->toString();
    $resolve_row_url = Url::fromRoute(
      'pds_recipe_timeline.resolve_row',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
      ],
    )->toString();
    $create_row_url = Url::fromRoute(
      'pds_recipe_timeline.create_row',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => ['type' => $recipe_type],
      ],
    )->toString();
    $update_row_url = Url::fromRoute(
      'pds_recipe_timeline.update_row',
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
    if ($stored_group_id > 0 && $stored_group_id !== $group_id) {
      //2.- Include the historical identifier as a fallback so the controller can recover rows tied to the previous group.
      $list_query['fallback_group_id'] = (string) $stored_group_id;
    }

    $list_rows_url = Url::fromRoute(
      'pds_recipe_timeline.list_rows',
      ['uuid' => $block_uuid],
      [
        'absolute' => TRUE,
        'query' => $list_query,
      ],
    )->toString();

    $form['pds_timeline_admin'] = [
      '#type' => 'container',
      //1.- Enable tree handling so blockSubmit() can retrieve nested values via parent keys.
      '#tree' => TRUE,
      '#attributes' => [
        'class' => [
          'pds-timeline-admin',
          'js-pds-timeline-admin',
        ],
        //5.- Expose AJAX endpoint and identifiers so JS can ensure persistence on add.
        'data-pds-timeline-ensure-group-url' => $ensure_group_url,
        'data-pds-timeline-resolve-row-url' => $resolve_row_url,
        'data-pds-timeline-create-row-url' => $create_row_url,
        'data-pds-timeline-update-row-url' => $update_row_url,
        'data-pds-timeline-list-rows-url' => $list_rows_url,
        'data-pds-timeline-group-id' => (string) $group_id,
        'data-pds-timeline-block-uuid' => $block_uuid,
        'data-pds-timeline-recipe-type' => $recipe_type,
      ],
      '#attached' => [
        'library' => [
          'pds_recipe_timeline/pds_timeline.admin',
        ],
      ],
    ];

    $form['pds_timeline_admin']['group_id'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-timeline-group-id',
        'id' => 'pds-timeline-group-id',
      ],
      //1.- Store the resolved id as a mutable default so AJAX updates from JS persist through submit.
      '#default_value' => (string) $group_id,
    ];

    //2.- Hidden JSON state keeps the serialized rows synchronized with PHP submissions.
    $form['pds_timeline_admin']['cards_state'] = [
      '#type' => 'hidden',
      //3.- Use data-drupal-selector because Drupal does not preserve raw #id attributes on hidden elements.
      '#attributes' => [
        'data-drupal-selector' => 'pds-timeline-cards-state',
      ],
      //4.- Expose the serialized rows via a default value so runtime edits reach PHP on submit.
      '#default_value' => json_encode($working_items),
    ];

    //5.- Hidden edit index tracks which card is being edited for JS interactions.
    $form['pds_timeline_admin']['edit_index'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-drupal-selector' => 'pds-timeline-edit-index',
      ],
      //6.- Seed the edit pointer with a mutable default so JS can signal which row is active.
      '#default_value' => '-1',
    ];

    //7.- Build the tabs navigation wrapper that Layout Builder renders inside the modal.
    $form['pds_timeline_admin']['tabs_nav'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'pds-timeline-admin__tabs-horizontal',
        ],
        'data-pds-timeline-tabs' => 'nav',
      ],
    ];

    //8.- Keep these elements as buttons even though Drupal renders submit inputs because JS cancels submission.
    $form['pds_timeline_admin']['tabs_nav']['tab_a'] = [
      '#type' => 'button',
      '#value' => $this->t('Tab A'),
      '#attributes' => [
        'class' => [
          'pds-timeline-admin__tab-btn-horizontal',
        ],
        'data-pds-timeline-tab-target' => 'panel-a',
      ],
    ];

    $form['pds_timeline_admin']['tabs_nav']['tab_b'] = [
      '#type' => 'button',
      '#value' => $this->t('Tab B'),
      '#attributes' => [
        'class' => [
          'pds-timeline-admin__tab-btn-horizontal',
        ],
        'data-pds-timeline-tab-target' => 'panel-b',
      ],
    ];

    //9.- Create the panels wrapper that houses the editable form and preview tabs.
    $form['pds_timeline_admin']['tabs_panels_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-timeline-panels-wrapper',
      ],
    ];

    $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'pds-timeline-admin__panels-horizontal',
        ],
        'data-pds-timeline-tabs' => 'panels',
      ],
    ];

    //10.- Provide the edit panel container where the form widgets live.
    $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-timeline-panel-a',
        'class' => [
          'pds-timeline-admin__panel-horizontal',
          'js-pds-timeline-panel',
        ],
        'data-pds-timeline-panel-id' => 'panel-a',
      ],
    ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['header'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Header'),
    '#maxlength' => 255,
    '#attributes' => [
      'data-drupal-selector' => 'pds-timeline-header',
      'id' => 'pds-timeline-header',
    ],
  ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['subheader'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Subheader'),
    '#maxlength' => 255,
    '#attributes' => [
      'data-drupal-selector' => 'pds-timeline-subheader',
      'id' => 'pds-timeline-subheader',
    ],
  ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['description'] = [
    '#type' => 'textarea',
    '#title' => $this->t('Description'),
    '#rows' => 3,
    '#attributes' => [
      'data-drupal-selector' => 'pds-timeline-description',
      'id' => 'pds-timeline-description',
    ],
  ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['description_json'] = [
    '#type' => 'textarea',
    '#title' => $this->t('Milestones JSON'),
    '#rows' => 5,
    '#description' => $this->t('Store structured timeline milestones in JSON format, for example {"segments":[{"label":"Role","width":25}]}.'),
    '#attributes' => [
      'data-drupal-selector' => 'pds-timeline-description-json',
      'id' => 'pds-timeline-description-json',
    ],
  ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['image'] = [
    '#type' => 'managed_file',
    '#title' => $this->t('Image'),
    '#upload_location' => 'public://pds_timeline/',
    '#default_value' => [],
    '#upload_validators' => [
      'file_validate_extensions' => ['png jpg jpeg webp'],
    ],
    '#attributes' => [
      'id' => 'pds-timeline-image',
    ],
  ];

  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['link'] = [
    '#type' => 'url',
    '#title' => $this->t('Link'),
    '#maxlength' => 512,
    '#attributes' => [
      'data-drupal-selector' => 'pds-timeline-link',
      'id' => 'pds-timeline-link',
    ],
  ];

  //11.- Render the add row trigger that allows editors to append new cards via JS.
  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_a']['add_item'] = [
    '#type' => 'markup',
    //12.- Use a <div> instead of <button> because Layout Builder strips button elements while rendering the form.
    //13.- Keep data-drupal-selector and id so JS can consistently find the trigger element.
    '#markup' => '<div class="pds-timeline-admin__add-btn" data-drupal-selector="pds-timeline-add-card" id="pds-timeline-add-card" role="button" tabindex="0">Add row</div>',
  ];


  //14.- Define the preview panel container that receives hydrated markup from AJAX calls.
  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b'] = [
    '#type' => 'container',
    '#attributes' => [
      'id' => 'pds-timeline-panel-b',
      'class' => [
        'pds-timeline-admin__panel-horizontal',
        'js-pds-timeline-panel',
        'is-active',
      ],
      'data-pds-timeline-panel-id' => 'panel-b',
    ],
  ];

  //1.- Build translated helper messages once so the markup stays readable.
  $loading_text = Html::escape((string) $this->t('Loading preview…'));
  $empty_text = Html::escape((string) $this->t('No rows yet.'));
  $error_text = Html::escape((string) $this->t('Unable to load preview.'));

  //2.- Expose dedicated regions for loading, empty, error and content states.
  $form['pds_timeline_admin']['tabs_panels_wrapper']['tabs_panels']['panel_b']['preview_list'] = [
    '#type' => 'markup',
    '#markup' => '<div id="pds-timeline-preview-list" class="pds-timeline-preview" data-pds-timeline-preview-root="1">'
      . '<div class="pds-timeline-preview__loading" data-pds-timeline-preview-state="loading" hidden>' . $loading_text . '</div>'
      . '<div class="pds-timeline-preview__empty" data-pds-timeline-preview-state="empty" hidden>' . $empty_text . '</div>'
      . '<div class="pds-timeline-preview__error" data-pds-timeline-preview-state="error" hidden>' . $error_text . '</div>'
      . '<div class="pds-timeline-preview__content" data-pds-timeline-preview-state="content"></div>'
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
      ['pds_timeline_admin', 'cards_state'],
      //2.- Check the Layout Builder parent wrapper when the subform prefixes values with settings.
      ['settings', 'pds_timeline_admin', 'cards_state'],
      //3.- Fall back to a flat lookup so legacy builders still return the payload.
      ['cards_state'],
    ], '');

    $group_id = NULL;
    $raw_group = $this->extractNestedFormValue($form_state, [
      //1.- Prefer the nested hidden value supplied by the admin container.
      ['pds_timeline_admin', 'group_id'],
      //2.- Support Layout Builder's prefixed structure when the plugin lives inside settings.
      ['settings', 'pds_timeline_admin', 'group_id'],
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
      $raw_description_json = isset($item['description_json']) && is_string($item['description_json'])
        ? trim($item['description_json'])
        : '';
      $description_json = '';
      if ($raw_description_json !== '') {
        $decoded_json = json_decode($raw_description_json, TRUE);
        if ($decoded_json !== NULL || json_last_error() === JSON_ERROR_NONE) {
          //1.- Only keep well-formed JSON payloads so the front-end renderer receives predictable structures.
          $description_json = $raw_description_json;
        }
      }
      $desktop_img = trim((string) ($item['desktop_img'] ?? ''));
      $mobile_img  = trim((string) ($item['mobile_img']  ?? ''));
      $image_url   = trim((string) ($item['image_url']   ?? ''));
      $stored_id   = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : NULL;
      $stored_uuid = isset($item['uuid']) && is_string($item['uuid']) ? trim($item['uuid']) : '';
      if ($stored_uuid !== '' && !Uuid::isValid($stored_uuid)) {
        //1.- Reset malformed UUIDs so futuras operaciones no vinculen filas ajenas por accidente.
        $stored_uuid = '';
      }

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
        'description_json' => $description_json,
        'link'        => $link,
        'desktop_img' => $desktop_img,
        'mobile_img'  => $mobile_img,
        'latitud'     => $item['latitud']  ?? NULL,
        'longitud'    => $item['longitud'] ?? NULL,
        'id'          => $stored_id,
        'uuid'        => $stored_uuid,
      ];
    }

    //4.- Persist items in both the database and the configuration snapshot for future renders.
    $this->saveItemsForBlock($clean, $group_id);

    //5.- Refresh the working state so the dialog reopens with the newly saved rows.
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

    if (!pds_recipe_timeline_user_can_manage_timeline()) {
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
    $promoter = \pds_recipe_timeline_resolve_row_image_promoter();
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
          '<th class="pds-timeline-table__col-thumb">' . $this->t('Image') . '</th>' .
          '<th>' . $this->t('Header') . '</th>' .
          '<th>' . $this->t('Subheader') . '</th>' .
          '<th>' . $this->t('Milestones JSON') . '</th>' .
          '<th>' . $this->t('Link') . '</th>' .
          '<th>' . $this->t('Actions') . '</th>' .
        '</tr>' .
      '</thead>';

    if ($items === [] || count($items) === 0) {
      //2.- Mirror the empty-state markup so the AJAX refresh matches client rendering.
      $table_body =
        '<tbody>' .
          '<tr>' .
            '<td colspan="6"><em>' . $this->t('No rows yet.') . '</em></td>' .
          '</tr>' .
        '</tbody>';

      return '<table class="pds-timeline-table">' . $table_head . $table_body . '</table>';
    }

    //3.- Iterate through rows and keep attribute escaping consistent with the JS counterpart.
    $rows_markup = '';
    foreach ($items as $delta => $item) {
      $header = htmlspecialchars((string) ($item['header'] ?? ''), ENT_QUOTES, 'UTF-8');
      $subheader = htmlspecialchars((string) ($item['subheader'] ?? ''), ENT_QUOTES, 'UTF-8');
      $link = htmlspecialchars((string) ($item['link'] ?? ''), ENT_QUOTES, 'UTF-8');
      $description_json = (string) ($item['description_json'] ?? '');
      if ($description_json !== '') {
        $preview_json = function_exists('mb_substr')
          ? mb_substr($description_json, 0, 120)
          : substr($description_json, 0, 120);
        if (strlen($description_json) > strlen($preview_json)) {
          $preview_json .= '…';
        }
        $json_markup = htmlspecialchars($preview_json, ENT_QUOTES, 'UTF-8');
      }
      else {
        $json_markup = '&mdash;';
      }
      $thumb = (string) ($item['desktop_img'] ?? $item['image_url'] ?? '');
      $thumb_markup = $thumb !== ''
        ? '<img src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="" />'
        : '';

      $rows_markup .= '<tr data-row-index="' . (int) $delta . '">';
      $rows_markup .=   '<td class="pds-timeline-table__thumb">' . $thumb_markup . '</td>';
      $rows_markup .=   '<td>' . $header . '</td>';
      $rows_markup .=   '<td>' . $subheader . '</td>';
      $rows_markup .=   '<td class="pds-timeline-table__json">' . $json_markup . '</td>';
      $rows_markup .=   '<td>' . $link . '</td>';
      $rows_markup .=   '<td>';
      $rows_markup .=     '<button type="button" class="pds-timeline-row-edit">' . $this->t('Edit') . '</button> ';
      $rows_markup .=     '<button type="button" class="pds-timeline-row-del">' . $this->t('Delete') . '</button>';
      $rows_markup .=   '</td>';
      $rows_markup .= '</tr>';
    }

    //4.- Return the assembled table HTML ready for the AJAX container.
    return '<table class="pds-timeline-table">' .
      $table_head .
      '<tbody>' . $rows_markup . '</tbody>' .
      '</table>';
  }

  /**
   * Kept only so LB AJAX callbacks referencing old code do not fatal.
   */
  public function addItemSubmit(array &$form, FormStateInterface $form_state): void {
    //1.- Intentionally left empty so historical AJAX callbacks referencing this handler continue to succeed.
  }

  public function ajaxItems(array &$form, FormStateInterface $form_state) {
    $items = $this->getWorkingItems($form_state);
    $cards_markup = $this->buildCardsMarkup($items);

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'pds-timeline-preview-list',
      ],
      'inner' => [
        '#type' => 'item',
        '#markup' => $cards_markup,
      ],
    ];
  }

}
