<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template;

/**
 * Shared normalizer for timeline segment payloads.
 */
trait TimelineSegmentsTrait {

  /**
   * Normalize raw timeline segment payloads into a stable array structure.
   */
  private function normalizeTimelineSegments($segments): array {
    //1.- Restrict processing to iterables so unexpected scalars are ignored safely.
    if (!is_array($segments) || $segments === []) {
      return [];
    }

    $normalized = [];

    foreach ($segments as $segment) {
      if (!is_array($segment)) {
        //2.- Skip malformed entries because they cannot be rendered or stored reliably.
        continue;
      }

      //3.- Accept both keyed and indexed payloads so JSON submissions remain flexible.
      $raw_year = '';
      if (array_key_exists('year', $segment)) {
        $raw_year = (string) $segment['year'];
      }
      elseif (array_key_exists(0, $segment)) {
        $raw_year = (string) $segment[0];
      }

      $raw_label = '';
      if (array_key_exists('label', $segment)) {
        $raw_label = (string) $segment['label'];
      }
      elseif (array_key_exists(1, $segment)) {
        $raw_label = (string) $segment[1];
      }

      $year = trim($raw_year);
      $label = trim($raw_label);

      if ($year === '' && $label === '') {
        //4.- Ignore empty pairs so the consumer receives meaningful entries only.
        continue;
      }

      $normalized[] = [
        'year' => $year,
        'label' => $label,
      ];
    }

    return $normalized;
  }

}

