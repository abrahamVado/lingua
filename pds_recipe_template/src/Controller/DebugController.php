<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Controller;

use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\JsonResponse;

final class DebugController {

  public function table(string $table): JsonResponse {
    // Sanitize to [a-z0-9_], then lowercase (ASCII only is fine for table names).
    $table = strtolower(preg_replace('/[^a-z0-9_]/i', '', $table ?? ''));

    $schema = \Drupal::database()->schema();
    if (!$schema->tableExists($table)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Table not found.'], 404);
    }

    $info   = Database::getConnectionInfo();
    $driver = $info['default']['driver'] ?? 'mysql';

    try {
      if ($driver === 'mysql') {
        $dbName = $info['default']['database'] ?? '';
        $sql = "SELECT COLUMN_NAME   AS name,
                       DATA_TYPE      AS data_type,
                       IS_NULLABLE    AS is_nullable,
                       COLUMN_DEFAULT AS column_default,
                       CHARACTER_MAXIMUM_LENGTH AS char_length,
                       NUMERIC_PRECISION        AS num_precision,
                       NUMERIC_SCALE            AS num_scale
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
              ORDER BY ORDINAL_POSITION";
        $args = [':schema' => $dbName, ':table' => $table];
        $rows = \Drupal::database()->query($sql, $args)->fetchAllAssoc('name', \PDO::FETCH_ASSOC);
        $columns = array_values($rows);
      }
      elseif ($driver === 'pgsql') {
        $sql = "SELECT c.column_name AS name,
                       c.data_type,
                       c.is_nullable,
                       c.column_default,
                       c.character_maximum_length AS char_length,
                       c.numeric_precision        AS num_precision,
                       c.numeric_scale            AS num_scale
                  FROM information_schema.columns c
                 WHERE c.table_schema = current_schema()
                   AND c.table_name = :table
              ORDER BY c.ordinal_position";
        $args = [':table' => $table];
        $rows = \Drupal::database()->query($sql, $args)->fetchAll(\PDO::FETCH_ASSOC);
        $columns = $rows;
      }
      elseif ($driver === 'sqlite') {
        $sql = "PRAGMA table_info($table)";
        $rows = \Drupal::database()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $columns = array_map(static function ($r) {
          return [
            'name'           => $r['name'] ?? '',
            'data_type'      => $r['type'] ?? '',
            'is_nullable'    => (!empty($r['notnull']) ? 'NO' : 'YES'),
            'column_default' => $r['dflt_value'] ?? null,
            'char_length'    => null,
            'num_precision'  => null,
            'num_scale'      => null,
          ];
        }, $rows);
      }
      else {
        return new JsonResponse(['status' => 'error', 'message' => 'Unsupported driver.'], 500);
      }
    } catch (\Throwable $e) {
      \Drupal::logger('pds_recipe_template')->error('DebugController error: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['status' => 'error', 'message' => 'Query failed.'], 500);
    }

    return new JsonResponse([
      'status'  => 'ok',
      'driver'  => $driver,
      'table'   => $table,
      'columns' => $columns,
    ]);
  }

}
