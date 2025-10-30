<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Throwable;

final class LegacySchemaRepairer {

  private Connection $connection;

  private TimeInterface $time;

  private UuidInterface $uuid;

  private LoggerChannelInterface $logger;

  public function __construct(Connection $connection, TimeInterface $time, UuidInterface $uuid, LoggerChannelFactoryInterface $logger_factory) {
    $this->connection = $connection;
    $this->time = $time;
    $this->uuid = $uuid;
    $this->logger = $logger_factory->get('pds_recipe_timeline');
  }

  public function ensureItemTableUpToDate(): bool {
    try {
      //1.- Provision the master and child tables when they are entirely missing.
      $schema = $this->connection->schema();
      $group_definition = $this->buildGroupDefinition();
      if (!$this->ensureGroupTableUpToDate($group_definition)) {
        return FALSE;
      }

      $item_definition = $this->buildItemDefinition();
      if (!$schema->tableExists('pds_template_item')) {
        $schema->createTable('pds_template_item', $item_definition);
      }
      else {
        //2.- Inspect the existing schema for missing or legacy columns.
        $expected_fields = array_keys($item_definition['fields']);
        $missing_fields = [];
        foreach ($expected_fields as $field) {
          if (!$schema->fieldExists('pds_template_item', $field)) {
            $missing_fields[] = $field;
          }
        }

        $legacy_fields_present = FALSE;
        $legacy_fields = ['block_uuid', 'link', 'image_url', 'latitude', 'longitude'];
        foreach ($legacy_fields as $legacy_field) {
          if ($schema->fieldExists('pds_template_item', $legacy_field)) {
            $legacy_fields_present = TRUE;
            break;
          }
        }

        if ($missing_fields || $legacy_fields_present) {
          //3.- Rebuild the table on the fly so runtime operations use the modern layout.
          if (!$this->rebuildItemTable($item_definition)) {
            return FALSE;
          }
        }
      }

      //4.- Copy data from legacy timeline-specific tables when present.
      $legacy_group_map = $this->migrateLegacyTimelineGroups();
      $this->migrateLegacyTimelineItems($legacy_group_map);
      $this->migrateSharedTimelineDescriptionPayloads();

      return TRUE;
    }
    catch (Throwable $throwable) {
      //4.- Capture unexpected failures so callers can fall back to manual updates.
      $this->logger->error('Failed to verify timeline storage: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }
  }

  private function ensureGroupTableUpToDate(array $definition): bool {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_template_group')) {
      //1.- Provision the table when it was never created so runtime repairs can continue.
      $schema->createTable('pds_template_group', $definition);
      return TRUE;
    }

    //2.- Compare the live schema with the expected definition to detect missing columns.
    $expected_fields = array_keys($definition['fields']);
    $missing_fields = [];
    foreach ($expected_fields as $field) {
      if (!$schema->fieldExists('pds_template_group', $field)) {
        $missing_fields[] = $field;
      }
    }

    //3.- Watch for legacy leftovers that indicate the table still uses the pre-UUID structure.
    $legacy_fields_present = FALSE;
    $legacy_fields = ['block_uuid', 'layout_id', 'deleted'];
    foreach ($legacy_fields as $legacy_field) {
      if ($schema->fieldExists('pds_template_group', $legacy_field)) {
        $legacy_fields_present = TRUE;
        break;
      }
    }

    if ($missing_fields === [] && !$legacy_fields_present) {
      return TRUE;
    }

    //4.- Rebuild the table to upgrade legacy installations that never received the UUID column.
    return $this->rebuildGroupTable($definition);
  }

  private function rebuildGroupTable(array $definition): bool {
    $schema = $this->connection->schema();
    $base_legacy_table = 'pds_template_group_legacy_runtime';
    $legacy_table = $base_legacy_table;
    $suffix = 0;

    //1.- Pick a unique name for the temporary table so repeated repairs never clash.
    while ($schema->tableExists($legacy_table)) {
      $suffix++;
      $legacy_table = $base_legacy_table . '_' . $suffix;
    }

    try {
      $schema->renameTable('pds_template_group', $legacy_table);
    }
    catch (SchemaObjectDoesNotExistException $exception) {
      //2.- Abort gracefully when the source table disappeared mid-run and log the failure.
      $this->logger->error('Unable to migrate timeline groups: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->logger->error('Unexpected failure while preparing legacy group table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }

    try {
      $schema->createTable('pds_template_group', $definition);
    }
    catch (SchemaObjectExistsException $exception) {
      //3.- Restore the previous table when concurrent rebuilds already recreated it.
      $this->attemptGroupTableRestore($legacy_table);
      $this->logger->error('Unable to recreate timeline group table: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->attemptGroupTableRestore($legacy_table);
      $this->logger->error('Unexpected error rebuilding timeline group storage: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }

    $select = $this->connection->select($legacy_table, 'legacy')
      ->fields('legacy');

    try {
      $result = $select->execute();
    }
    catch (Throwable $throwable) {
      $this->logger->error('Unable to read legacy timeline groups: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      $this->attemptGroupTableRestore($legacy_table);
      return FALSE;
    }

    $now = $this->time->getRequestTime();

    foreach ($result as $record) {
      $id = isset($record->id) ? (int) $record->id : 0;
      if ($id <= 0) {
        //4.- Skip malformed records because they cannot be associated with existing rows.
        continue;
      }

      $stored_uuid = isset($record->uuid) ? trim((string) $record->uuid) : '';
      if (!Uuid::isValid($stored_uuid)) {
        //5.- Prefer legacy block_uuid values before generating a replacement identifier.
        $legacy_uuid = isset($record->block_uuid) ? trim((string) $record->block_uuid) : '';
        if (Uuid::isValid($legacy_uuid)) {
          $stored_uuid = $legacy_uuid;
        }
        else {
          $stored_uuid = $this->uuid->generate();
        }
      }

      $type = isset($record->type) && is_string($record->type) && $record->type !== ''
        ? (string) $record->type
        : 'pds_recipe_timeline';

      $created_at = isset($record->created_at) && is_numeric($record->created_at)
        ? (int) $record->created_at
        : $now;

      $deleted_at = NULL;
      if (isset($record->deleted_at)) {
        if ($record->deleted_at === NULL || $record->deleted_at === '') {
          $deleted_at = NULL;
        }
        elseif (is_numeric($record->deleted_at)) {
          $deleted_at = (int) $record->deleted_at;
        }
      }

      try {
        //6.- Reinsert the normalized row while preserving the original identifier for item relations.
        $this->connection->insert('pds_template_group')
          ->fields([
            'id' => $id,
            'uuid' => $stored_uuid,
            'type' => $type,
            'created_at' => $created_at,
            'deleted_at' => $deleted_at,
          ])
          ->execute();
      }
      catch (Throwable $throwable) {
        //7.- Log and skip duplicates so the rebuild can continue processing healthy rows.
        $this->logger->warning('Skipped legacy timeline group @id: @message', [
          '@id' => $id,
          '@message' => $throwable->getMessage(),
        ]);
        continue;
      }
    }

    try {
      //8.- Drop the legacy copy now that the replacement table has been fully populated.
      $schema->dropTable($legacy_table);
    }
    catch (Throwable $throwable) {
      $this->logger->warning('Unable to remove temporary legacy group table @table: @message', [
        '@table' => $legacy_table,
        '@message' => $throwable->getMessage(),
      ]);
    }

    return TRUE;
  }

  private function attemptGroupTableRestore(string $legacy_table): void {
    $schema = $this->connection->schema();

    try {
      if ($schema->tableExists('pds_template_group')) {
        $schema->dropTable('pds_template_group');
      }

      if ($schema->tableExists($legacy_table)) {
        $schema->renameTable($legacy_table, 'pds_template_group');
      }
    }
    catch (Throwable $throwable) {
      //1.- Swallow restore failures because the caller has already reported the root issue.
      $this->logger->warning('Unable to restore original timeline group table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  private function rebuildItemTable(array $definition): bool {
    $schema = $this->connection->schema();
    $base_legacy_table = 'pds_template_item_legacy_runtime';
    $legacy_table = $base_legacy_table;
    $suffix = 0;

    //1.- Pick a unique temporary table name when earlier repairs left artefacts behind.
    while ($schema->tableExists($legacy_table)) {
      $suffix++;
      $legacy_table = $base_legacy_table . '_' . $suffix;
    }

    try {
      $schema->renameTable('pds_template_item', $legacy_table);
    }
    catch (SchemaObjectDoesNotExistException $exception) {
      //2.- Abort gracefully when the rename fails because the table disappeared mid-run.
      $this->logger->error('Unable to migrate timeline items: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->logger->error('Unexpected failure while preparing legacy table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }

    try {
      $schema->createTable('pds_template_item', $definition);
    }
    catch (SchemaObjectExistsException $exception) {
      //3.- Restore the previous table when creation collides with a concurrent repair.
      $this->attemptTableRestore($legacy_table);
      $this->logger->error('Unable to recreate timeline items table: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->attemptTableRestore($legacy_table);
      $this->logger->error('Unexpected error rebuilding timeline storage: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }

    $select = $this->connection->select($legacy_table, 'legacy')
      ->fields('legacy');

    try {
      $result = $select->execute();
    }
    catch (Throwable $throwable) {
      $this->logger->error('Unable to read legacy timeline items: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      $this->attemptTableRestore($legacy_table);
      return FALSE;
    }

    $now = $this->time->getRequestTime();

    foreach ($result as $record) {
      //4.- Skip incomplete legacy rows so corrupt entries never block the rebuild.
      $header = trim((string) ($record->header ?? ''));
      if ($header === '') {
        continue;
      }

      $group_id = NULL;
      $block_uuid = isset($record->block_uuid) ? trim((string) $record->block_uuid) : '';
      if ($block_uuid !== '') {
        $group_id = \pds_recipe_timeline_ensure_group_and_get_id($block_uuid, 'pds_recipe_timeline');
      }
      elseif (isset($record->group_id) && is_numeric($record->group_id)) {
        $group_id = (int) $record->group_id;
      }

      if (!$group_id) {
        continue;
      }

      $stored_uuid = isset($record->uuid) ? (string) $record->uuid : '';
      if (!Uuid::isValid($stored_uuid)) {
        $stored_uuid = $this->uuid->generate();
      }

      $weight = isset($record->weight) && is_numeric($record->weight) ? (int) $record->weight : 0;
      $subheader = isset($record->subheader) ? (string) $record->subheader : '';
      $description = isset($record->description) ? (string) $record->description : '';
      $description_json = '';
      if (isset($record->description_json)) {
        $description_json = \pds_recipe_timeline_normalize_timeline_json($record->description_json);
      }
      $packed_description = \pds_recipe_timeline_pack_description_payload($description, $description_json);

      $url = '';
      if (isset($record->url)) {
        $url = (string) $record->url;
      }
      elseif (isset($record->link)) {
        $url = (string) $record->link;
      }

      $desktop_img = '';
      if (isset($record->desktop_img)) {
        $desktop_img = (string) $record->desktop_img;
      }
      elseif (isset($record->image_url)) {
        $desktop_img = (string) $record->image_url;
      }

      $mobile_img = '';
      if (isset($record->mobile_img)) {
        $mobile_img = (string) $record->mobile_img;
      }
      elseif ($desktop_img !== '') {
        $mobile_img = $desktop_img;
      }

      $latitud = NULL;
      if (property_exists($record, 'latitud')) {
        $latitud = $record->latitud === NULL ? NULL : (float) $record->latitud;
      }
      elseif (property_exists($record, 'latitude')) {
        $latitud = $record->latitude === NULL ? NULL : (float) $record->latitude;
      }

      $longitud = NULL;
      if (property_exists($record, 'longitud')) {
        $longitud = $record->longitud === NULL ? NULL : (float) $record->longitud;
      }
      elseif (property_exists($record, 'longitude')) {
        $longitud = $record->longitude === NULL ? NULL : (float) $record->longitude;
      }

      $created_at = isset($record->created_at) && is_numeric($record->created_at) ? (int) $record->created_at : $now;

      try {
        //5.- Persist the normalized row so every legacy entry survives the rebuild.
        $this->connection->insert('pds_template_item')
          ->fields([
            'uuid' => $stored_uuid,
            'group_id' => (int) $group_id,
            'weight' => $weight,
            'header' => $header,
            'subheader' => $subheader,
            'description' => $packed_description,
            'url' => $url,
            'desktop_img' => $desktop_img,
            'mobile_img' => $mobile_img,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'created_at' => $created_at,
            'deleted_at' => NULL,
          ])
          ->execute();
      }
      catch (Throwable $throwable) {
        //6.- Skip duplicate UUIDs or other insert errors so healthy rows continue migrating.
        $this->logger->warning('Skipped legacy timeline row: @message', [
          '@message' => $throwable->getMessage(),
        ]);
        continue;
      }
    }

    try {
      //7.- Drop the temporary table to avoid cluttering the database with unused copies.
      $schema->dropTable($legacy_table);
    }
    catch (Throwable $throwable) {
      $this->logger->warning('Unable to remove temporary legacy table @table: @message', [
        '@table' => $legacy_table,
        '@message' => $throwable->getMessage(),
      ]);
    }

    return TRUE;
  }

  private function migrateLegacyTimelineGroups(): array {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_timeline_group')) {
      //1.- Skip migration when the timeline-specific table never existed on this site.
      return [];
    }

    try {
      //2.- Read every legacy row so we can copy the metadata into the shared template table.
      $result = $this->connection->select('pds_timeline_group', 'legacy')
        ->fields('legacy')
        ->execute();
    }
    catch (Throwable $throwable) {
      //3.- Record the failure but continue bootstrapping the modern schema.
      $this->logger->warning('Unable to read legacy timeline groups: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return [];
    }

    $now = $this->time->getRequestTime();
    $mapping = [];

    foreach ($result as $record) {
      $legacy_id = isset($record->id) ? (int) $record->id : 0;
      if ($legacy_id <= 0) {
        //4.- Ignore malformed rows because they cannot be referenced by timeline items.
        continue;
      }

      $stored_uuid = isset($record->uuid) ? trim((string) $record->uuid) : '';
      if (!Uuid::isValid($stored_uuid)) {
        //5.- Prefer the historical block_uuid before generating a replacement identifier.
        $legacy_uuid = isset($record->block_uuid) ? trim((string) $record->block_uuid) : '';
        if (Uuid::isValid($legacy_uuid)) {
          $stored_uuid = $legacy_uuid;
        }
        else {
          $stored_uuid = $this->uuid->generate();
        }
      }

      $type = isset($record->type) ? trim((string) $record->type) : '';
      if ($type === '') {
        $type = 'pds_recipe_timeline';
      }

      $created_at = isset($record->created_at) && is_numeric($record->created_at)
        ? (int) $record->created_at
        : $now;

      $deleted_at = NULL;
      if (isset($record->deleted_at) && is_numeric($record->deleted_at)) {
        $deleted_at = (int) $record->deleted_at;
      }
      elseif (isset($record->deleted) && ((int) $record->deleted) === 1) {
        $deleted_at = $now;
      }

      $existing = $this->connection->select('pds_template_group', 'target')
        ->fields('target', ['id'])
        ->condition('uuid', $stored_uuid)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($existing) {
        //6.- Reuse the matching row and capture the mapping for the item migration phase.
        $mapping[$legacy_id] = (int) $existing;
        continue;
      }

      try {
        //7.- Insert the normalized group row into the shared template table.
        $insert_id = $this->connection->insert('pds_template_group')
          ->fields([
            'uuid' => $stored_uuid,
            'type' => $type,
            'created_at' => $created_at,
            'deleted_at' => $deleted_at,
          ])
          ->execute();

        $mapping[$legacy_id] = (int) $insert_id;
      }
      catch (Throwable $throwable) {
        //8.- Log and continue so the migration does not halt on duplicate UUIDs.
        $this->logger->warning('Unable to migrate legacy timeline group @id: @message', [
          '@id' => $legacy_id,
          '@message' => $throwable->getMessage(),
        ]);
      }
    }

    return $mapping;
  }

  private function migrateLegacyTimelineItems(array $legacy_group_map): void {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_timeline_item')) {
      //1.- Nothing to migrate when the legacy item table is absent.
      return;
    }

    try {
      //2.- Load every legacy record so we can normalize them into the shared table.
      $result = $this->connection->select('pds_timeline_item', 'legacy')
        ->fields('legacy')
        ->execute();
    }
    catch (Throwable $throwable) {
      //3.- Emit a warning and skip migration when the legacy table cannot be read.
      $this->logger->warning('Unable to read legacy timeline items: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return;
    }

    $now = $this->time->getRequestTime();
    $resolved_map = $legacy_group_map;

    foreach ($result as $record) {
      $header = trim((string) ($record->header ?? ''));
      if ($header === '') {
        //4.- Skip blank rows because they cannot be rendered meaningfully in the timeline.
        continue;
      }

      $legacy_group_id = isset($record->group_id) && is_numeric($record->group_id)
        ? (int) $record->group_id
        : 0;

      $target_group_id = $legacy_group_id > 0 && isset($resolved_map[$legacy_group_id])
        ? (int) $resolved_map[$legacy_group_id]
        : NULL;

      if (!$target_group_id && $legacy_group_id > 0) {
        //5.- Derive the new group id from the legacy table when the initial mapping missed it.
        $resolved_group_id = $this->resolveGroupIdFromLegacy($legacy_group_id);
        if ($resolved_group_id) {
          $target_group_id = $resolved_group_id;
          $resolved_map[$legacy_group_id] = $resolved_group_id;
        }
      }

      if (!$target_group_id) {
        $block_uuid = isset($record->block_uuid) ? trim((string) $record->block_uuid) : '';
        if (Uuid::isValid($block_uuid)) {
          //6.- Fall back to the helper so orphaned rows with only block_uuid still migrate.
          $ensured_id = \pds_recipe_timeline_ensure_group_and_get_id($block_uuid, 'pds_recipe_timeline');
          if ($ensured_id) {
            $target_group_id = (int) $ensured_id;
          }
        }
      }

      if (!$target_group_id) {
        //7.- Skip rows we cannot link to a group because the new schema requires the relation.
        continue;
      }

      $stored_uuid = isset($record->uuid) ? trim((string) $record->uuid) : '';
      if (!Uuid::isValid($stored_uuid)) {
        $stored_uuid = $this->uuid->generate();
      }

      $existing = $this->connection->select('pds_template_item', 'current')
        ->fields('current', ['id'])
        ->condition('uuid', $stored_uuid)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($existing) {
        //8.- Avoid duplicating rows when the UUID already exists in the shared table.
        continue;
      }

      $weight = isset($record->weight) && is_numeric($record->weight) ? (int) $record->weight : 0;
      $subheader = isset($record->subheader) ? (string) $record->subheader : '';
      $description = isset($record->description) ? (string) $record->description : '';
      $description_json = '';
      if (isset($record->description_json)) {
        $description_json = \pds_recipe_timeline_normalize_timeline_json($record->description_json);
      }
      $packed_description = \pds_recipe_timeline_pack_description_payload($description, $description_json);

      $url = '';
      if (isset($record->url)) {
        $url = (string) $record->url;
      }
      elseif (isset($record->link)) {
        $url = (string) $record->link;
      }

      $desktop_img = '';
      if (isset($record->desktop_img)) {
        $desktop_img = (string) $record->desktop_img;
      }
      elseif (isset($record->image_url)) {
        $desktop_img = (string) $record->image_url;
      }

      $mobile_img = '';
      if (isset($record->mobile_img)) {
        $mobile_img = (string) $record->mobile_img;
      }
      elseif ($desktop_img !== '') {
        $mobile_img = $desktop_img;
      }

      $latitud = NULL;
      if (property_exists($record, 'latitud')) {
        $latitud = $record->latitud === NULL ? NULL : (float) $record->latitud;
      }
      elseif (property_exists($record, 'latitude')) {
        $latitud = $record->latitude === NULL ? NULL : (float) $record->latitude;
      }

      $longitud = NULL;
      if (property_exists($record, 'longitud')) {
        $longitud = $record->longitud === NULL ? NULL : (float) $record->longitud;
      }
      elseif (property_exists($record, 'longitude')) {
        $longitud = $record->longitude === NULL ? NULL : (float) $record->longitude;
      }

      $created_at = isset($record->created_at) && is_numeric($record->created_at)
        ? (int) $record->created_at
        : $now;

      $deleted_at = NULL;
      if (isset($record->deleted_at) && is_numeric($record->deleted_at)) {
        $deleted_at = (int) $record->deleted_at;
      }

      try {
        //9.- Persist the migrated row in the shared template item table.
        $this->connection->insert('pds_template_item')
          ->fields([
            'uuid' => $stored_uuid,
            'group_id' => (int) $target_group_id,
            'weight' => $weight,
            'header' => $header,
            'subheader' => $subheader,
            'description' => $packed_description,
            'url' => $url,
            'desktop_img' => $desktop_img,
            'mobile_img' => $mobile_img,
            'latitud' => $latitud,
            'longitud' => $longitud,
            'created_at' => $created_at,
            'deleted_at' => $deleted_at,
          ])
          ->execute();
      }
      catch (Throwable $throwable) {
        //10.- Continue migrating other records even when duplicates or errors appear.
        $this->logger->warning('Unable to migrate legacy timeline item @uuid: @message', [
          '@uuid' => $stored_uuid,
          '@message' => $throwable->getMessage(),
        ]);
        continue;
      }
    }
  }

  private function migrateSharedTimelineDescriptionPayloads(): void {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_template_item') || !$schema->tableExists('pds_template_group')) {
      //1.- Skip the migration when either shared table is missing.
      return;
    }

    do {
      $select = $this->connection->select('pds_template_item', 'items')
        ->fields('items', ['id', 'description', 'description_json'])
        ->condition('items.deleted_at', NULL, 'IS NULL')
        ->condition('items.description_json', '', '<>')
        ->range(0, 50);
      $select->innerJoin('pds_template_group', 'groups', 'groups.id = items.group_id');
      $select->condition('groups.type', 'pds_recipe_timeline');

      try {
        $records = $select->execute()->fetchAll();
      }
      catch (Throwable $throwable) {
        //2.- Abort gracefully when the lookup fails so schema repairs can continue.
        $this->logger->warning('Unable to inspect shared timeline items: @message', [
          '@message' => $throwable->getMessage(),
        ]);
        return;
      }

      if ($records === [] || $records === NULL) {
        //3.- Stop processing when every row already stores packed descriptions.
        break;
      }

      $updated_any = FALSE;

      foreach ($records as $record) {
        $id = isset($record->id) ? (int) $record->id : 0;
        if ($id <= 0) {
          continue;
        }

        $description = isset($record->description) ? (string) $record->description : '';
        $raw_json = isset($record->description_json) ? $record->description_json : '';
        $normalized_json = \pds_recipe_timeline_normalize_timeline_json($raw_json);
        $packed = \pds_recipe_timeline_pack_description_payload($description, $normalized_json);

        try {
          $this->connection->update('pds_template_item')
            ->fields([
              'description' => $packed,
              'description_json' => '',
            ])
            ->condition('id', $id)
            ->execute();
          $updated_any = TRUE;
        }
        catch (Throwable $throwable) {
          //4.- Log failures but keep iterating so remaining rows still migrate.
          $this->logger->warning('Unable to migrate description payload for timeline item @id: @message', [
            '@id' => $id,
            '@message' => $throwable->getMessage(),
          ]);
        }
      }

      if (!$updated_any) {
        //5.- Avoid infinite loops when updates keep failing by aborting gracefully.
        break;
      }
    }
    while (TRUE);
  }

  private function resolveGroupIdFromLegacy(int $legacy_group_id): ?int {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_timeline_group')) {
      //1.- Without the legacy table there is no mapping to resolve.
      return NULL;
    }

    try {
      //2.- Fetch the legacy record so we can reuse or reconstruct its UUID.
      $record = $this->connection->select('pds_timeline_group', 'legacy')
        ->fields('legacy')
        ->condition('id', $legacy_group_id)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
    }
    catch (Throwable $throwable) {
      //3.- Skip the mapping when the legacy row cannot be read.
      $this->logger->warning('Unable to resolve legacy group @id: @message', [
        '@id' => $legacy_group_id,
        '@message' => $throwable->getMessage(),
      ]);
      return NULL;
    }

    if (!$record) {
      return NULL;
    }

    $stored_uuid = isset($record['uuid']) ? trim((string) $record['uuid']) : '';
    if (!Uuid::isValid($stored_uuid)) {
      //4.- Use block_uuid as a fallback before generating a replacement identifier.
      $legacy_uuid = isset($record['block_uuid']) ? trim((string) $record['block_uuid']) : '';
      if (Uuid::isValid($legacy_uuid)) {
        $stored_uuid = $legacy_uuid;
      }
      else {
        $stored_uuid = $this->uuid->generate();
      }
    }

    $type = isset($record['type']) ? trim((string) $record['type']) : '';
    if ($type === '') {
      $type = 'pds_recipe_timeline';
    }

    $existing = $this->connection->select('pds_template_group', 'target')
      ->fields('target', ['id'])
      ->condition('uuid', $stored_uuid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($existing) {
      //5.- Reuse the already migrated identifier.
      return (int) $existing;
    }

    //6.- Delegate to the helper so the canonical insert logic provisions the row.
    $ensured = \pds_recipe_timeline_ensure_group_and_get_id($stored_uuid, $type);
    return $ensured ? (int) $ensured : NULL;
  }

  private function attemptTableRestore(string $legacy_table): void {
    $schema = $this->connection->schema();

    try {
      if ($schema->tableExists('pds_template_item')) {
        $schema->dropTable('pds_template_item');
      }

      if ($schema->tableExists($legacy_table)) {
        $schema->renameTable($legacy_table, 'pds_template_item');
      }
    }
    catch (Throwable $throwable) {
      //1.- Swallow restore failures because the caller already handles the original exception.
      $this->logger->warning('Unable to restore original timeline items table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  private function buildGroupDefinition(): array {
    //1.- Mirror the schema defined during installation so runtime repairs stay in sync.
    return [
      'description' => 'Logical group of timeline items (one rendered component instance).',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'uuid' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'type' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'created_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'deleted_at' => [
          'type' => 'int',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'type' => ['type'],
        'deleted_at' => ['deleted_at'],
      ],
    ];
  }

  private function buildItemDefinition(): array {
    //1.- Match the production schema so inserts and updates share the same expectations.
    return [
      'description' => 'Items/cards that belong to a timeline group.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'uuid' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'group_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'weight' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'header' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'subheader' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'description' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        'url' => [
          'type' => 'varchar',
          'length' => 512,
          'not null' => TRUE,
          'default' => '',
        ],
        'desktop_img' => [
          'type' => 'varchar',
          'length' => 512,
          'not null' => TRUE,
          'default' => '',
        ],
        'mobile_img' => [
          'type' => 'varchar',
          'length' => 512,
          'not null' => TRUE,
          'default' => '',
        ],
        'latitud' => [
          'type' => 'float',
          'not null' => FALSE,
        ],
        'longitud' => [
          'type' => 'float',
          'not null' => FALSE,
        ],
        'created_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'deleted_at' => [
          'type' => 'int',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'group_id' => ['group_id'],
        'weight' => ['weight'],
        'deleted_at' => ['deleted_at'],
      ],
    ];
  }

}

