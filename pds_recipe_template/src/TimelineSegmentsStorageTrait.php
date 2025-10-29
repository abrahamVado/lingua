<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template;

use Drupal\Core\Database\Connection;

/**
 * Helper methods to persist timeline segments alongside template items.
 */
trait TimelineSegmentsStorageTrait {
  use TimelineSegmentsTrait;

  /**
   * Load active timeline segments for the provided item identifiers.
   */
  private function loadTimelineSegments(Connection $connection, array $item_ids): array {
    //1.- Abort early when no identifiers were supplied to avoid redundant queries.
    if ($item_ids === []) {
      return [];
    }

    if (!$this->timelineTableExists($connection)) {
      //2.- Return an empty map when deployments have not created the timeline table yet.
      return [];
    }

    $segments = [];

    try {
      //3.- Fetch active segments sorted by item and weight so tooltips display chronologically.
      $query = $connection->select('pds_template_item_timeline', 'timeline')
        ->fields('timeline', ['item_id', 'year_label', 'description', 'weight'])
        ->condition('timeline.item_id', array_map('intval', $item_ids), 'IN')
        ->condition('timeline.deleted_at', NULL, 'IS NULL')
        ->orderBy('timeline.item_id', 'ASC')
        ->orderBy('timeline.weight', 'ASC');

      $result = $query->execute();
    }
    catch (\Throwable $throwable) {
      return [];
    }

    foreach ($result as $record) {
      $item_id = isset($record->item_id) ? (int) $record->item_id : 0;
      if ($item_id <= 0) {
        continue;
      }

      if (!isset($segments[$item_id])) {
        $segments[$item_id] = [];
      }

      $segments[$item_id][] = [
        'year' => trim((string) $record->year_label),
        'label' => trim((string) $record->description),
      ];
    }

    return $segments;
  }

  /**
   * Soft-delete timeline segments for the supplied item identifiers.
   */
  private function softDeleteTimelineSegments(Connection $connection, array $item_ids, int $timestamp): void {
    //1.- Keep only positive integers so the update statement remains valid.
    $filtered_ids = array_values(array_filter(array_map('intval', $item_ids), static fn ($id) => $id > 0));
    if ($filtered_ids === []) {
      return;
    }

    if (!$this->timelineTableExists($connection)) {
      return;
    }

    try {
      //2.- Mark existing rows as deleted instead of wiping them to preserve history.
      $connection->update('pds_template_item_timeline')
        ->fields(['deleted_at' => $timestamp])
        ->condition('item_id', $filtered_ids, 'IN')
        ->condition('deleted_at', NULL, 'IS NULL')
        ->execute();
    }
    catch (\Throwable $throwable) {
      //3.- Ignore failures so the calling process can continue saving parent items.
    }
  }

  /**
   * Replace all timeline segments for a specific item with the provided dataset.
   */
  private function replaceTimelineSegments(Connection $connection, int $item_id, array $segments, int $timestamp): array {
    if ($item_id <= 0 || !$this->timelineTableExists($connection)) {
      return [];
    }

    //1.- Normalize incoming payloads so both AJAX and form submissions reuse the same schema.
    $normalized = $this->normalizeTimelineSegments($segments);

    //2.- Remove previous active rows so the upcoming inserts define the new ordering.
    $this->softDeleteTimelineSegments($connection, [$item_id], $timestamp);

    if ($normalized === []) {
      return [];
    }

    foreach ($normalized as $weight => $segment) {
      try {
        //3.- Persist each entry while preserving its position for deterministic rendering.
        $connection->insert('pds_template_item_timeline')
          ->fields([
            'item_id' => $item_id,
            'weight' => (int) $weight,
            'year_label' => $segment['year'],
            'description' => $segment['label'],
            'created_at' => $timestamp,
            'deleted_at' => NULL,
          ])
          ->execute();
      }
      catch (\Throwable $throwable) {
        //4.- Skip failed inserts so one problematic entry does not block the rest.
        continue;
      }
    }

    return $normalized;
  }

  /**
   * Check if the timeline table exists without triggering fatal errors.
   */
  private function timelineTableExists(Connection $connection): bool {
    try {
      return $connection->schema()->tableExists('pds_template_item_timeline');
    }
    catch (\Throwable $throwable) {
      return FALSE;
    }
  }

}

