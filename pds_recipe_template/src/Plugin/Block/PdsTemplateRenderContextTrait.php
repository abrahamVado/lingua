<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

/**
 * Trait: PdsTemplateRenderContextTrait
 *
 * PURPOSE
 * -------
 * Centralizes *read-only* render context builders that both Twig and JS consume:
 *  - `buildMasterMetadata()` → single, canonical "who am I?" payload for the block instance.
 *  - `buildExtendedDatasets()` → derivative indexes (images, geolocation) computed from items[].
 *
 * SCOPE
 * -----
 * - No DB calls here. These helpers only transform already-loaded configuration/state.
 * - Keep them side-effect free so they’re cheap to call multiple times during render.
 *
 * CONSUMERS
 * ---------
 * - Twig template `pds-template-block.html.twig` via `#master_metadata` and `#extended_datasets`.
 * - Front-end JS (via drupalSettings) for sliders/galleries and map widgets.
 *
 * PERFORMANCE NOTES
 * -----------------
 * - O(n) over items; early returns for empty inputs to avoid loops/allocations.
 * - No heavy normalization: assume `items` have already been cleaned by the repository.
 *
 * EXTENSIBILITY
 * -------------
 * - If you add new derived datasets (e.g., tag clouds, categories), extend the return shape of
 *   `buildExtendedDatasets()` in a non-breaking way (append new keys).
 * - Keep master metadata minimal and stable; treat it like a contract.
 */
trait PdsTemplateRenderContextTrait {

  /**
   * Build a compact, stable "master metadata" payload shared by Twig and JS.
   *
   * WHY SEPARATE?
   * -------------
   * - Keeps templates simple: they read labeled fields instead of poking into $configuration.
   * - JS gets a single object to key drupalSettings by instance UUID without guessing.
   *
   * @param int    $group_id       Canonical storage group id (0 when unknown/unset).
   * @param string $instance_uuid  Stable logical identifier for this block instance.
   *
   * @return array {
   *   @type int    group_id      Numeric id (0 when not established yet).
   *   @type string instance_uuid UUID4 string used by routes & settings scoping.
   *   @type string recipe_type   Derivative label for front-end branching.
   *   @type int    items_count   Snapshot count for quick "empty?" checks client-side.
   * }
   */
  private function buildMasterMetadata(int $group_id, string $instance_uuid): array {
    // 1.- Normalize the numeric identifier so the template only handles integers.
    $normalized_group_id = $group_id > 0 ? $group_id : 0;

    // 2.- Capture the recipe type so consumers can branch their behavior by subtype.
    //     NOTE: This must remain stable across renders of the same instance_uuid.
    $recipe_type = (string) ($this->configuration['recipe_type'] ?? 'pds_recipe_template');

    // 3.- Mirror the snapshot item count for quick client-side checks (e.g., show/hide carousels).
    //     We *do not* validate rows here; assume repository already normalized items[].
    $items_snapshot = $this->configuration['items'] ?? [];
    $items_count = is_array($items_snapshot) ? count($items_snapshot) : 0;

    // 4.- Keep this small and predictable; anything "heavy" belongs in extended datasets below.
    return [
      'group_id'      => $normalized_group_id,
      'instance_uuid' => $instance_uuid,
      'recipe_type'   => $recipe_type,
      'items_count'   => $items_count,
    ];
  }

  /**
   * Compute derivative datasets from normalized items for public renderers.
   *
   * INPUT CONTRACT
   * --------------
   * - $items is expected to be the repository-normalized shape:
   *   [
   *     [
   *       'header' => string,
   *       'desktop_img' => string, 'mobile_img' => string, 'image_url' => string (fallback),
   *       'latitud' => float|null, 'longitud' => float|null,
   *       ...
   *     ],
   *     ...
   *   ]
   *
   * OUTPUT SHAPE
   * ------------
   * [
   *   'media' => [
   *     'desktop' => [ { index:int, label:string, url:string }, ... ],
   *     'mobile'  => [ { index:int, label:string, url:string }, ... ],
   *   ],
   *   'geo' => [
   *     { index:int, label:string, latitude:float, longitude:float }, ...
   *   ],
   * ]
   *
   * RATIONALE
   * ---------
   * - "desktop" and "mobile" lists allow front-end to build responsive galleries quickly
   *   without re-deriving URLs for each row.
   * - "geo" list powers map pins/markers; stores index + label to relate back to the card.
   *
   * GUARDRAILS
   * ----------
   * - Empty/invalid inputs return stable empty lists (never null) to simplify consumers.
   */
  private function buildExtendedDatasets(array $items): array {
    // 1.- Defend against non-arrays and short-circuit with a stable, empty shape.
    if (!is_array($items) || $items === []) {
      return [
        'media' => [
          'desktop' => [],
          'mobile'  => [],
        ],
        'geo' => [],
      ];
    }

    // 2.- Preallocate result containers; keep them local to this method (no shared state).
    $desktop_images   = [];
    $mobile_images    = [];
    $geo_coordinates  = [];

    // 3.- Single pass: derive all secondary indexes in O(n).
    foreach ($items as $delta => $row) {
      // 3.1.- Human label for front-end UI: fall back to '' to avoid "undefined index".
      $label = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';

      // 3.2.- Resolve desktop source with legacy fallback (image_url).
      $desktop_source = '';
      if (isset($row['desktop_img']) && is_string($row['desktop_img'])) {
        $desktop_source = trim($row['desktop_img']);
      }
      if ($desktop_source === '' && isset($row['image_url']) && is_string($row['image_url'])) {
        // Support legacy snapshots that only stored image_url (pre-dual field era).
        $desktop_source = trim($row['image_url']);
      }

      // 3.3.- Resolve mobile source; fall back to desktop to keep galleries usable by default.
      $mobile_source = '';
      if (isset($row['mobile_img']) && is_string($row['mobile_img'])) {
        $mobile_source = trim($row['mobile_img']);
      }
      if ($mobile_source === '' && $desktop_source !== '') {
        $mobile_source = $desktop_source;
      }

      // 3.4.- Emit desktop image entry when present.
      if ($desktop_source !== '') {
        $desktop_images[] = [
          'index' => $delta,   // back-reference to items[] position
          'label' => $label,   // friendly caption for UI/alt text
          'url'   => $desktop_source,
        ];
      }

      // 3.5.- Emit mobile image entry when present.
      if ($mobile_source !== '') {
        $mobile_images[] = [
          'index' => $delta,
          'label' => $label,
          'url'   => $mobile_source,
        ];
      }

      // 3.6.- Emit geo marker only for fully numeric lat/lon pairs.
      //       (This avoids JS NaN issues and map library crashes.)
      if (
        isset($row['latitud'], $row['longitud'])
        && $row['latitud'] !== null
        && $row['longitud'] !== null
        && is_numeric($row['latitud'])
        && is_numeric($row['longitud'])
      ) {
        $geo_coordinates[] = [
          'index'     => $delta,
          'label'     => $label,
          'latitude'  => (float) $row['latitud'],
          'longitude' => (float) $row['longitud'],
        ];
      }
    }

    // 4.- Return a predictable structure (arrays, never null) so Twig/JS can blindly iterate.
    return [
      'media' => [
        'desktop' => $desktop_images,
        'mobile'  => $mobile_images,
      ],
      'geo' => $geo_coordinates,
    ];
  }

}
