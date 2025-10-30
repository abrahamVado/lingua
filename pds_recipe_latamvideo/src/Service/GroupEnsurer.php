<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_latamvideo\Service;

/**
 * Provides helper methods to ensure template groups exist.
 */
final class GroupEnsurer {

  /**
   * Ensure the database row for the given UUID exists and return its id.
   */
  public function ensure(string $uuid, string $type = 'pds_recipe_latamvideo'): ?int {
    //1.- Delegate to the shared helper that encapsulates database access.
    return $this->ensureGroupAndGetId($uuid, $type);
  }

  /**
   * Maintain compatibility with legacy callers expecting this exact method.
   */
  public function ensureGroupAndGetId(string $uuid, string $type = 'pds_recipe_latamvideo'): ?int {
    //1.- Delegate to the procedural helper so the authoritative logic lives in the .module file.
    $resolved_id = \pds_recipe_latamvideo_ensure_group_and_get_id($uuid, $type);
    //2.- Mirror the nullable integer contract expected by service consumers.
    return $resolved_id === NULL ? NULL : (int) $resolved_id;
  }

}
