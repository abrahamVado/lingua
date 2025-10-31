<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Throwable;

/**
 * Ensures pds_template_group rows exist and resolves their IDs.
 *
 * DESIGN
 * - Pure DI (no calls back to procedural helpers) to avoid recursion.
 * - Idempotent by UUID; safe for concurrent AJAX/LB calls.
 * - Swallows duplicate-insert races by reselecting after insert attempt.
 */
final class GroupEnsurer {

  public function __construct(
    private readonly Connection $db,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Back-compat convenience; same as ensureGroupAndGetId().
   *
   * @return int Group id or 0 on failure.
   */
  public function ensure(string $uuid, string $type = 'pds_recipe_template'): int {
    return $this->ensureGroupAndGetId($uuid, $type);
  }

  /**
   * Ensure a group row exists for $uuid and return its numeric id.
   *
   * CONTRACT
   * - Validates UUID early (returns 0 if invalid).
   * - Inserts if missing; tolerates duplicate on race.
   * - Never calls procedural helpers (no recursion).
   */
  public function ensureGroupAndGetId(string $uuid, string $type = 'pds_recipe_template'): int {
    // 1) Validate early.
    if (!Uuid::isValid($uuid)) {
      return 0;
    }
    $type = $type !== '' ? $type : 'pds_recipe_template';

    try {
      // 2) Reuse existing.
      $existing = $this->db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      if ($existing) {
        return (int) $existing;
      }

      // 3) Create if missing (race-safe).
      $now = $this->time->getRequestTime();
      try {
        $this->db->insert('pds_template_group')
          ->fields([
            'uuid' => $uuid,
            'type' => $type,
            'created_at' => $now,
            'deleted_at' => NULL,
          ])
          ->execute();
      }
      catch (Throwable $e) {
        // Likely a concurrent insert; continue to reselect.
      }

      $resolved = $this->db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      return $resolved ? (int) $resolved : 0;
    }
    catch (Throwable $e) {
      $this->logger->error('Failed to ensure template group for @uuid: @msg', [
        '@uuid' => $uuid,
        '@msg' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Resolve an existing group id without creating it.
   *
   * @return int Group id or 0 if not found/invalid.
   */
  public function resolveGroupId(string $uuid): int {
    if (!Uuid::isValid($uuid)) {
      return 0;
    }
    try {
      $id = $this->db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      return $id ? (int) $id : 0;
    }
    catch (Throwable $e) {
      $this->logger->warning('Unable to resolve group for @uuid: @msg', [
        '@uuid' => $uuid,
        '@msg' => $e->getMessage(),
      ]);
      return 0;
    }
  }
}
