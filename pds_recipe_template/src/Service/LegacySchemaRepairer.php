<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Throwable;

/**
 * LegacySchemaRepairer
 *
 * PURPOSE (EN)
 * - Runtime safety-net that verifies and (if needed) upgrades the storage schema
 *   for PDS templates:
 *   - Ensures `pds_template_group` exists and has modern columns (uuid/type).
 *   - Ensures `pds_template_item` exists and has modern columns.
 *   - Transparently migrates/repairs legacy tables/columns without requiring
 *     manual updates or downtime.
 *
 * PROPÓSITO (ES)
 * - Red de seguridad en tiempo de ejecución para verificar y (si hace falta)
 *   actualizar el esquema de almacenamiento:
 *   - Garantiza `pds_template_group` (uuid/type).
 *   - Garantiza `pds_template_item`.
 *   - Migra/repara tablas/columnas heredadas sin pasos manuales ni downtime.
 *
 * DESIGN NOTES
 * - Idempotent: safe to call repeatedly.
 * - Race-tolerant: rename/create wrapped with fallbacks and restores.
 * - Conservative: logs errors, bails out safely instead of throwing fatals.
 */
final class LegacySchemaRepairer {

  /** Low-level DB connection used for schema ops and bulk inserts. */
  private Connection $connection;

  /** Time service to keep timestamps consistent/testable. */
  private TimeInterface $time;

  /** Channel logger (pds_recipe_template). */
  private LoggerChannelInterface $logger;

  /**
   * DI constructor.
   * - We derive a namespaced logger via the factory to keep logs grouped.
   */
  public function __construct(
    Connection $connection,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->connection = $connection;
    $this->time = $time;
    $this->logger = $logger_factory->get('pds_recipe_template');
  }

  /**
   * PUBLIC ENTRYPOINT
   * Ensure that item storage is ready for use (and group table too).
   *
   * RETURNS
   * - true  → schema is present and up-to-date (or successfully rebuilt).
   * - false → an unexpected failure happened (caller should abort politely).
   */
  public function ensureItemTableUpToDate(): bool {
    try {
      // 1) Ensure/repair GROUP table first (items depend on it).
      $schema = $this->connection->schema();
      $group_definition = $this->buildGroupDefinition();
      if (!$this->ensureGroupTableUpToDate($group_definition)) {
        return FALSE;
      }

      // 2) Create ITEM table if it does not exist.
      $item_definition = $this->buildItemDefinition();
      if (!$schema->tableExists('pds_template_item')) {
        $schema->createTable('pds_template_item', $item_definition);
        return TRUE; // Freshly created → done.
      }

      // 3) Inspect for missing modern fields and presence of legacy ones.
      $expected_fields = array_keys($item_definition['fields']);
      $missing_fields = [];
      foreach ($expected_fields as $field) {
        if (!$schema->fieldExists('pds_template_item', $field)) {
          $missing_fields[] = $field;
        }
      }

      $legacy_fields_present = FALSE;
      // Legacy columns we no longer use, but may exist in older installs.
      $legacy_fields = ['block_uuid', 'link', 'image_url', 'latitude', 'longitude'];
      foreach ($legacy_fields as $legacy_field) {
        if ($schema->fieldExists('pds_template_item', $legacy_field)) {
          $legacy_fields_present = TRUE;
          break;
        }
      }

      // 4) If nothing to fix, we’re done. Otherwise rebuild with a migration.
      if (!$missing_fields && !$legacy_fields_present) {
        return TRUE;
      }

      // 5) Rebuild item table (rename → create → migrate → drop temp).
      return $this->rebuildItemTable($item_definition);
    }
    catch (Throwable $throwable) {
      // 6) Defensive logging. Let caller react gracefully.
      $this->logger->error('Failed to verify template storage: @message', [
        '@message' => $throwable->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Ensure/repair GROUP table (`pds_template_group`).
   * - Creates table if missing.
   * - Rebuilds table if legacy columns are detected or fields are missing.
   */
  private function ensureGroupTableUpToDate(array $definition): bool {
    $schema = $this->connection->schema();

    // 1) Create from scratch if absent.
    if (!$schema->tableExists('pds_template_group')) {
      $schema->createTable('pds_template_group', $definition);
      return TRUE;
    }

    // 2) Detect missing modern fields.
    $expected_fields = array_keys($definition['fields']);
    $missing_fields = [];
    foreach ($expected_fields as $field) {
      if (!$schema->fieldExists('pds_template_group', $field)) {
        $missing_fields[] = $field;
      }
    }

    // 3) Detect obvious legacy footprint.
    $legacy_fields_present = FALSE;
    $legacy_fields = ['block_uuid', 'layout_id', 'deleted'];
    foreach ($legacy_fields as $legacy_field) {
      if ($schema->fieldExists('pds_template_group', $legacy_field)) {
        $legacy_fields_present = TRUE;
        break;
      }
    }

    // 4) Up-to-date? done. Else reconstruct via rename→create→migrate.
    if ($missing_fields === [] && !$legacy_fields_present) {
      return TRUE;
    }

    return $this->rebuildGroupTable($definition);
  }

  /**
   * Rebuild GROUP table:
   * - Renames current → temp
   * - Creates new
   * - Migrates data (repairs UUID/TYPE if absent)
   * - Drops temp
   */
  private function rebuildGroupTable(array $definition): bool {
    $schema = $this->connection->schema();
    $base_legacy_table = 'pds_template_group_legacy_runtime';
    $legacy_table = $base_legacy_table;
    $suffix = 0;

    // 1) Ensure temp name is unique even if prior repairs left a temp table around.
    while ($schema->tableExists($legacy_table)) {
      $suffix++;
      $legacy_table = $base_legacy_table . '_' . $suffix;
    }

    // 2) Move old table out of the way.
    try {
      $schema->renameTable('pds_template_group', $legacy_table);
    }
    catch (SchemaObjectDoesNotExistException $exception) {
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

    // 3) Create new, modern table.
    try {
      $schema->createTable('pds_template_group', $definition);
    }
    catch (SchemaObjectExistsException $exception) {
      // Concurrent builder won → restore and bail.
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

    // 4) Read all legacy rows for migration.
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

    // 5) Normalize + insert row-by-row into the modern table.
    foreach ($result as $record) {
      $id = isset($record->id) ? (int) $record->id : 0;
      if ($id <= 0) {
        // Skip malformed ids.
        continue;
      }

      //1.- Reuse any legacy UUID without generating replacements.
      $stored_uuid = NULL;
      $primary_uuid = isset($record->uuid) ? trim((string) $record->uuid) : '';
      if ($primary_uuid !== '' && Uuid::isValid($primary_uuid)) {
        $stored_uuid = $primary_uuid;
      }
      elseif (isset($record->block_uuid)) {
        $legacy_uuid = trim((string) $record->block_uuid);
        if ($legacy_uuid !== '' && Uuid::isValid($legacy_uuid)) {
          $stored_uuid = $legacy_uuid;
        }
      }

      // 5.2) TYPE default.
      $type = isset($record->type) && is_string($record->type) && $record->type !== ''
        ? (string) $record->type
        : 'pds_recipe_template';

      // 5.3) Timestamps.
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
        // 5.4) Insert preserving original numeric id (so FK relations remain valid).
        $fields = [
          'id'         => $id,
          'type'       => $type,
          'created_at' => $created_at,
          'deleted_at' => $deleted_at,
        ];
        if (is_string($stored_uuid) && $stored_uuid !== '') {
          $fields['uuid'] = $stored_uuid;
        }

        $this->connection->insert('pds_template_group')
          ->fields($fields)
          ->execute();
      }
      catch (Throwable $throwable) {
        // If duplicate or any other issue, log and continue to next.
        $this->logger->warning('Skipped legacy template group @id: @message', [
          '@id' => $id,
          '@message' => $throwable->getMessage(),
        ]);
        continue;
      }
    }

    // 6) Drop temporary legacy table (non-fatal if it fails).
    try {
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
   * Best-effort rollback for GROUP table when a rebuild fails midway.
   */
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
      // Soft-fail: caller already handled the root error.
      $this->logger->warning('Unable to restore original template group table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  /**
   * Rebuild ITEM table:
   * - Renames current → temp
   * - Creates new
   * - Migrates rows (maps legacy columns → modern ones)
   * - Drops temp
   */
  private function rebuildItemTable(array $definition): bool {
    $schema = $this->connection->schema();
    $base_legacy_table = 'pds_template_item_legacy_runtime';
    $legacy_table = $base_legacy_table;
    $suffix = 0;

    // 1) Unique temp name.
    while ($schema->tableExists($legacy_table)) {
      $suffix++;
      $legacy_table = $base_legacy_table . '_' . $suffix;
    }

    // 2) Move old table aside.
    try {
      $schema->renameTable('pds_template_item', $legacy_table);
    }
    catch (SchemaObjectDoesNotExistException $exception) {
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

    // 3) Create modern table.
    try {
      $schema->createTable('pds_template_item', $definition);
    }
    catch (SchemaObjectExistsException $exception) {
      // Another process already created it → restore old and exit.
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

    // 4) Stream legacy rows for migration.
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

    // 5) Map each legacy row → modern schema.
    foreach ($result as $record) {
      // 5.1) Minimal gate: header must exist.
      $header = trim((string) ($record->header ?? ''));
      if ($header === '') {
        continue;
      }

      // 5.2) Group resolution:
      //      Prefer legacy block_uuid → ensure group, else reuse numeric group_id.
      $group_id = NULL;
      $block_uuid = isset($record->block_uuid) ? trim((string) $record->block_uuid) : '';
      if ($block_uuid !== '') {
        // NOTE: central helper ensures (uuid,type) exist; type defaults to template base.
        $group_id = \pds_recipe_template_ensure_group_and_get_id($block_uuid, 'pds_recipe_template');
      }
      elseif (isset($record->group_id) && is_numeric($record->group_id)) {
        $group_id = (int) $record->group_id;
      }
      if (!$group_id) {
        continue; // cannot place the row without a group id
      }

      //2.- Preserve a legacy UUID only when it is valid.
      $stored_uuid = NULL;
      if (isset($record->uuid)) {
        $candidate_uuid = trim((string) $record->uuid);
        if ($candidate_uuid !== '' && Uuid::isValid($candidate_uuid)) {
          $stored_uuid = $candidate_uuid;
        }
      }

      // 5.4) Other normalized fields.
      $weight      = isset($record->weight) && is_numeric($record->weight) ? (int) $record->weight : 0;
      $subheader   = isset($record->subheader)   ? (string) $record->subheader   : '';
      $description = isset($record->description) ? (string) $record->description : '';

      // 5.5) URL mapping: prefer modern 'url', fallback to legacy 'link'.
      $url = '';
      if (isset($record->url)) {
        $url = (string) $record->url;
      } elseif (isset($record->link)) {
        $url = (string) $record->link;
      }

      // 5.6) Media mapping: prefer modern; fallback from single legacy 'image_url'.
      $desktop_img = '';
      if (isset($record->desktop_img)) {
        $desktop_img = (string) $record->desktop_img;
      } elseif (isset($record->image_url)) {
        $desktop_img = (string) $record->image_url;
      }

      $mobile_img = '';
      if (isset($record->mobile_img)) {
        $mobile_img = (string) $record->mobile_img;
      } elseif ($desktop_img !== '') {
        $mobile_img = $desktop_img;
      }

      // 5.7) Geo mapping: (latitud/longitud) or (latitude/longitude).
      $latitud = NULL;
      if (property_exists($record, 'latitud')) {
        $latitud = $record->latitud === NULL ? NULL : (float) $record->latitud;
      } elseif (property_exists($record, 'latitude')) {
        $latitud = $record->latitude === NULL ? NULL : (float) $record->latitude;
      }

      $longitud = NULL;
      if (property_exists($record, 'longitud')) {
        $longitud = $record->longitud === NULL ? NULL : (float) $record->longitud;
      } elseif (property_exists($record, 'longitude')) {
        $longitud = $record->longitude === NULL ? NULL : (float) $record->longitude;
      }

      $created_at = isset($record->created_at) && is_numeric($record->created_at)
        ? (int) $record->created_at
        : $now;

      // 5.8) Insert normalized row; skip on conflict but continue migration.
      try {
        $fields = [
          'group_id'    => (int) $group_id,
          'weight'      => $weight,
          'header'      => $header,
          'subheader'   => $subheader,
          'description' => $description,
          'url'         => $url,
          'desktop_img' => $desktop_img,
          'mobile_img'  => $mobile_img,
          'latitud'     => $latitud,
          'longitud'    => $longitud,
          'created_at'  => $created_at,
          'deleted_at'  => NULL,
        ];
        if (is_string($stored_uuid) && $stored_uuid !== '') {
          $fields['uuid'] = $stored_uuid;
        }

        $this->connection->insert('pds_template_item')
          ->fields($fields)
          ->execute();
      }
      catch (Throwable $throwable) {
        $this->logger->warning('Skipped legacy template row: @message', [
          '@message' => $throwable->getMessage(),
        ]);
        continue;
      }
    }

    // 6) Drop temp legacy table; non-fatal if busy/locked.
    try {
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

  /**
   * Best-effort rollback for ITEM table when a rebuild fails midway.
   */
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
      // Soft-fail: caller already surfaced the main error.
      $this->logger->warning('Unable to restore original template items table: @message', [
        '@message' => $throwable->getMessage(),
      ]);
    }
  }

  /**
   * Canonical GROUP schema definition (kept in sync with install).
   */
  private function buildGroupDefinition(): array {
    // 1) Keep this aligned with your .install schema to avoid drift.
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
          'not null' => FALSE,
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
        // NOTE: If you later enforce multi-recipe uniqueness, consider a composite.
        // For now we keep historical unique(uuid) to avoid breaking older data.
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'type' => ['type'],
        'deleted_at' => ['deleted_at'],
      ],
    ];
  }

  /**
   * Canonical ITEM schema definition (kept in sync with install).
   */
  private function buildItemDefinition(): array {
    // 1) Keep this aligned with your .install schema to avoid drift.
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
          'not null' => FALSE,
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
        'group_id'   => ['group_id'],
        'weight'     => ['weight'],
        'deleted_at' => ['deleted_at'],
      ],
    ];
  }

}
