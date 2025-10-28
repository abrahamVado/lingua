<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Throwable;

final class TemplateGroupManager {

  /**
   * Database connection used to resolve and persist template groups.
   */
  private Connection $connection;

  /**
   * Time service leveraged for timestamp generation.
   */
  private TimeInterface $time;

  public function __construct(
    Connection $connection,
    TimeInterface $time,
  ) {
    //1.- Store the shared database connection for future queries and inserts.
    $this->connection = $connection;

    //2.- Keep a reference to the time service for timestamp creation.
    $this->time = $time;
  }

  public function ensureGroupAndGetId(string $uuid, string $type = 'pds_recipe_template'): ?int {
    //1.- Reject empty UUIDs early so we avoid unnecessary database work.
    if ($uuid === '') {
      return NULL;
    }

    $normalized_type = $type !== '' ? $type : 'pds_recipe_template';

    try {
      //2.- Return the active record when it already exists for the incoming UUID.
      $existing_id = $this->connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      if ($existing_id) {
        return (int) $existing_id;
      }

      $now = $this->time->getRequestTime();

      try {
        //3.- Create the group row so subsequent calls can reuse the identifier.
        $this->connection->insert('pds_template_group')
          ->fields([
            'uuid' => $uuid,
            'type' => $normalized_type,
            'created_at' => $now,
            'deleted_at' => NULL,
          ])
          ->execute();
      }
      catch (Throwable $insert_exception) {
        //4.- Ignore duplicate key races because another request may have inserted it.
      }

      //5.- Read the persisted identifier, returning NULL when it still cannot be found.
      $new_id = $this->connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      return $new_id ? (int) $new_id : NULL;
    }
    catch (Throwable $exception) {
      //6.- Fail softly so callers can surface friendly errors instead of fatals.
      return NULL;
    }
  }

}
