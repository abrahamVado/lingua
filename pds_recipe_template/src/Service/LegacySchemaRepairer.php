<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

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
    $this->logger = $logger_factory->get('pds_recipe_template');
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

      $timeline_definition = $this->buildTimelineDefinition();
      if (!$this->ensureTimelineTableUpToDate($timeline_definition)) {
        return FALSE;
      }

      return TRUE;
    }
    catch (Throwable $throwable) {
      //4.- Capture unexpected failures so callers can fall back to manual updates.
      $this->logger->error('Failed to verify template storage: @message', [
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
      $this->logger->error('Unable to migrate template groups: @message', [
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
      $this->logger->error('Unable to recreate template group table: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->attemptGroupTableRestore($legacy_table);
      $this->logger->error('Unexpected error rebuilding template group storage: @message', [
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
      $this->logger->error('Unable to read legacy template groups: @message', [
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
        : 'pds_recipe_template';

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
        $this->logger->warning('Skipped legacy template group @id: @message', [
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

  /**
   * Guarantee the timeline table matches the expected structure.
   */
  private function ensureTimelineTableUpToDate(array $definition): bool {
    $schema = $this->connection->schema();

    if (!$schema->tableExists('pds_template_item_timeline')) {
      //1.- Create the table outright for fresh deployments that never stored segments.
      $schema->createTable('pds_template_item_timeline', $definition);
      return TRUE;
    }

    //2.- Confirm every required field exists so runtime inserts never fail.
    $expected_fields = array_keys($definition['fields']);
    foreach ($expected_fields as $field) {
      if (!$schema->fieldExists('pds_template_item_timeline', $field)) {
        return $this->rebuildTimelineTable($definition);
      }
    }

    return TRUE;
  }

  /**
   * Rebuild the timeline table while preserving existing data.
   */
  private function rebuildTimelineTable(array $definition): bool {
    $schema = $this->connection->schema();
    $base_legacy_table = 'pds_template_item_timeline_legacy_runtime';
    $legacy_table = $base_legacy_table;
    $suffix = 0;

    //1.- Pick a unique temporary name so repeated repairs avoid collisions.
    while ($schema->tableExists($legacy_table)) {
      $suffix++;
      $legacy_table = $base_legacy_table . '_' . $suffix;
    }

    try {
      $schema->renameTable('pds_template_item_timeline', $legacy_table);
    }
    catch (SchemaObjectDoesNotExistException $exception) {
      $this->logger->error('Unable to migrate template timeline segments: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->logger->error('Unexpected failure while preparing legacy timeline table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }

    try {
      $schema->createTable('pds_template_item_timeline', $definition);
    }
    catch (SchemaObjectExistsException $exception) {
      $schema->dropTable('pds_template_item_timeline');
      $schema->renameTable($legacy_table, 'pds_template_item_timeline');
      $this->logger->error('Unable to recreate template timeline table: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $schema->dropTable('pds_template_item_timeline');
      $schema->renameTable($legacy_table, 'pds_template_item_timeline');
      $this->logger->error('Unexpected error rebuilding template timeline storage: @message', [
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
      $this->logger->error('Unable to read legacy timeline segments: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      $schema->dropTable('pds_template_item_timeline');
      $schema->renameTable($legacy_table, 'pds_template_item_timeline');
      return FALSE;
    }

    $now = $this->time->getRequestTime();

    foreach ($result as $record) {
      $item_id = isset($record->item_id) ? (int) $record->item_id : 0;
      if ($item_id <= 0) {
        continue;
      }

      $weight = isset($record->weight) ? (int) $record->weight : 0;
      $year = trim((string) ($record->year_label ?? ''));
      $label = trim((string) ($record->description ?? ''));

      if ($year === '' && $label === '') {
        continue;
      }

      try {
        $this->connection->insert('pds_template_item_timeline')
          ->fields([
            'item_id' => $item_id,
            'weight' => $weight,
            'year_label' => $year,
            'description' => $label,
            'created_at' => isset($record->created_at) ? (int) $record->created_at : $now,
            'deleted_at' => NULL,
          ])
          ->execute();
      }
      catch (Throwable $throwable) {
        //2.- Skip rows that fail to migrate while keeping the remainder intact.
        continue;
      }
    }

    $schema->dropTable($legacy_table);

    return TRUE;
  }

  /**
   * Build the canonical schema definition for the timeline table.
   */
  private function buildTimelineDefinition(): array {
    return [
      'description' => 'Timeline segments linked to template items.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'item_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'weight' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'year_label' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
        ],
        'description' => [
          'type' => 'varchar',
          'length' => 512,
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
      'indexes' => [
        'item_id' => ['item_id'],
        'weight' => ['weight'],
        'deleted_at' => ['deleted_at'],
      ],
    ];
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
      $this->logger->warning('Unable to restore original template group table: @message', [
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
      $this->logger->error('Unable to migrate template items: @message', [
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
      $this->logger->error('Unable to recreate template items table: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
    catch (Throwable $throwable) {
      $this->attemptTableRestore($legacy_table);
      $this->logger->error('Unexpected error rebuilding template storage: @message', [
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
      $this->logger->error('Unable to read legacy template items: @message', [
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
        $group_id = \pds_recipe_template_ensure_group_and_get_id($block_uuid, 'pds_recipe_template');
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
            'description' => $description,
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
        $this->logger->warning('Skipped legacy template row: @message', [
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
      $this->logger->warning('Unable to restore original template items table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  private function buildGroupDefinition(): array {
    //1.- Mirror the schema defined during installation so runtime repairs stay in sync.
    return [
      'description' => 'Logical group of template items (one rendered component instance).',
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
      'description' => 'Items/cards that belong to a template group.',
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

