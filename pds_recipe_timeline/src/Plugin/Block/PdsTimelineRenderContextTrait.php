<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

/**
 * Shared helpers that assemble master metadata and derivative datasets.
 */
trait PdsTimelineRenderContextTrait {

  /**
   * Assemble master metadata shared between Twig and JS consumers.
   */
  private function buildMasterMetadata(int $group_id, string $instance_uuid): array {
    //1.- Normalize the numeric identifier so the template only handles integers.
    $normalized_group_id = $group_id > 0 ? $group_id : 0;

    //2.- Capture the recipe type so consumers can branch their behavior by subtype.
    $recipe_type = (string) ($this->configuration['recipe_type'] ?? 'pds_recipe_timeline');

    //3.- Mirror the snapshot item count for quick client-side checks.
    $items_snapshot = $this->configuration['items'] ?? [];
    $items_count = is_array($items_snapshot) ? count($items_snapshot) : 0;

    return [
      'group_id' => $normalized_group_id,
      'instance_uuid' => $instance_uuid,
      'recipe_type' => $recipe_type,
      'items_count' => $items_count,
    ];
  }

  /**
   * Build helper datasets derived from the stored items for public renderers.
   */
  private function buildExtendedDatasets(array $items): array {
    //1.- Ensure we only iterate on arrays so PHP warnings are avoided.
    if (!is_array($items) || $items === []) {
      return [
        'media' => [
          'desktop' => [],
          'mobile' => [],
        ],
        'geo' => [],
      ];
    }

    $desktop_images = [];
    $mobile_images = [];
    $geo_coordinates = [];

    foreach ($items as $delta => $row) {
      //2.- Extract the canonical label to pair with derivative information.
      $label = isset($row['header']) && is_string($row['header'])
        ? trim($row['header'])
        : '';

      $desktop_source = '';
      if (isset($row['desktop_img']) && is_string($row['desktop_img'])) {
        $desktop_source = trim($row['desktop_img']);
      }
      if ($desktop_source === '' && isset($row['image_url']) && is_string($row['image_url'])) {
        //3.- Support legacy snapshots that only stored image_url.
        $desktop_source = trim($row['image_url']);
      }

      $mobile_source = '';
      if (isset($row['mobile_img']) && is_string($row['mobile_img'])) {
        $mobile_source = trim($row['mobile_img']);
      }
      if ($mobile_source === '' && $desktop_source !== '') {
        //4.- Reuse the desktop asset as a reasonable default for mobile consumers.
        $mobile_source = $desktop_source;
      }

      //5.- Capture the desktop image URL when available for hero/thumbnail usage.
      if ($desktop_source !== '') {
        $desktop_images[] = [
          'index' => $delta,
          'label' => $label,
          'url' => $desktop_source,
        ];
      }

      //6.- Capture the mobile image URL so responsive galleries can select it directly.
      if ($mobile_source !== '') {
        $mobile_images[] = [
          'index' => $delta,
          'label' => $label,
          'url' => $mobile_source,
        ];
      }

      //7.- Promote coordinate pairs for map-based widgets or geolocation markers.
      if (
        isset($row['latitud'], $row['longitud'])
        && $row['latitud'] !== NULL
        && $row['longitud'] !== NULL
        && is_numeric($row['latitud'])
        && is_numeric($row['longitud'])
      ) {
        $geo_coordinates[] = [
          'index' => $delta,
          'label' => $label,
          'latitude' => (float) $row['latitud'],
          'longitude' => (float) $row['longitud'],
        ];
      }
    }

    return [
      'media' => [
        'desktop' => $desktop_images,
        'mobile' => $mobile_images,
      ],
      'geo' => $geo_coordinates,
    ];
  }

}

