<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Throwable;

final class TemplateGroupManager {

  public function __construct(
    private readonly Connection $connection,
    private readonly TimeInterface $time,
  ) {}

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
