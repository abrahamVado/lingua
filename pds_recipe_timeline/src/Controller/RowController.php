<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class RowController extends ControllerBase {

  public function createRow(Request $request, string $uuid): JsonResponse {
    //1.- Validate UUID upfront so we never attempt inserts with malformed identifiers.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    if (!pds_recipe_timeline_user_can_manage_timeline()) {
      //2.- Enforce administrative access so layout builders without block admin can still save rows securely.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    $payload = json_decode($request->getContent() ?: '[]', TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }

    //3.- Accept optional type from query string or payload so multi-recipe setups reuse the endpoint.
    $type = $request->query->get('type');
    if (!is_string($type) || $type === '') {
      if (isset($payload['recipe_type']) && is_string($payload['recipe_type']) && $payload['recipe_type'] !== '') {
        $type = $payload['recipe_type'];
      }
      else {
        $type = 'pds_recipe_timeline';
      }
    }

    //4.- Pull the row array from the request and require the same minimum fields as creation.
    $row = isset($payload['row']) && is_array($payload['row']) ? $payload['row'] : [];

    $header = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';
    if ($header === '') {
      //5.- Require a header because the UI treats it as the minimal amount of data.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Missing header.',
      ], 400);
    }

    $subheader = isset($row['subheader']) && is_string($row['subheader']) ? $row['subheader'] : '';
    $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : '';
    $link = isset($row['link']) && is_string($row['link']) ? $row['link'] : '';
    $description_json = '';
    if (array_key_exists('description_json', $row)) {
      $description_json = \pds_recipe_timeline_normalize_timeline_json($row['description_json']);
    }
    $packed_description = \pds_recipe_timeline_pack_description_payload($description, $description_json);
    $desktop_img = isset($row['desktop_img']) && is_string($row['desktop_img']) ? $row['desktop_img'] : '';
    $mobile_img = isset($row['mobile_img']) && is_string($row['mobile_img']) ? $row['mobile_img'] : '';
    $image_url = isset($row['image_url']) && is_string($row['image_url']) ? $row['image_url'] : '';

    $latitud = NULL;
    if (array_key_exists('latitud', $row) && ($row['latitud'] === NULL || is_numeric($row['latitud']))) {
      $latitud = $row['latitud'] === NULL ? NULL : (float) $row['latitud'];
    }

    $longitud = NULL;
    if (array_key_exists('longitud', $row) && ($row['longitud'] === NULL || is_numeric($row['longitud']))) {
      $longitud = $row['longitud'] === NULL ? NULL : (float) $row['longitud'];
    }

    $weight = NULL;
    if (isset($payload['weight']) && is_numeric($payload['weight'])) {
      $weight = (int) $payload['weight'];
    }
    elseif (isset($row['weight']) && is_numeric($row['weight'])) {
      $weight = (int) $row['weight'];
    }

    try {
      //6.- Guarantee the storage schema matches expectations before inserting brand-new rows.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Timeline storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      //7.- Resolve the numeric group id so we can assert ownership before updating anything.
      $group_id = \pds_recipe_timeline_ensure_group_and_get_id($uuid, $type);
      if (!$group_id) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Unable to resolve group.',
        ], 500);
      }

      $connection = \Drupal::database();
      $now = \Drupal::time()->getRequestTime();

      $row['desktop_img'] = $desktop_img;
      $row['mobile_img'] = $mobile_img;
      $row['image_url'] = $image_url;

      //8.- Resolve the promoter through the helper so cache rebuilds do not break uploads.
      $promoter = \pds_recipe_timeline_resolve_row_image_promoter();
      if (!$promoter) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Image promotion is unavailable. Rebuild caches and try again.',
        ], 500);
      }

      $promotion = $promoter->promote($row);
      if (($promotion['status'] ?? '') !== 'ok') {
        $message = $promotion['message'] ?? 'Unable to promote image.';
        $code = $promotion['code'] ?? 500;

        return new JsonResponse([
          'status' => 'error',
          'message' => $message,
        ], $code);
      }

      $desktop_img = (string) ($promotion['desktop_img'] ?? $desktop_img);
      $mobile_img = (string) ($promotion['mobile_img'] ?? $mobile_img);
      $image_url = (string) ($promotion['image_url'] ?? $image_url);
      $image_fid = $promotion['image_fid'] ?? NULL;

      if ($weight === NULL) {
        //9.- When the caller omits weight we append to the end by using max + 1.
        $max_weight = $connection->select('pds_template_item', 'i')
          ->fields('i', ['weight'])
          ->condition('i.group_id', $group_id)
          ->condition('i.deleted_at', NULL, 'IS NULL')
          ->orderBy('i.weight', 'DESC')
          ->range(0, 1)
          ->execute()
          ->fetchField();
        $weight = $max_weight ? ((int) $max_weight + 1) : 0;
      }

      $row_uuid = isset($row['uuid']) && is_string($row['uuid']) && Uuid::isValid($row['uuid'])
        ? $row['uuid']
        : \Drupal::service('uuid')->generate();

      //10.- Insert the brand-new database record and capture the assigned identifier.
      $insert_id = $connection->insert('pds_template_item')
        ->fields([
          'uuid' => $row_uuid,
          'group_id' => (int) $group_id,
          'weight' => $weight,
          'header' => $header,
          'subheader' => $subheader,
          'description' => $packed_description,
          'url' => $link,
          'desktop_img' => $desktop_img,
          'mobile_img' => $mobile_img,
          'latitud' => $latitud,
          'longitud' => $longitud,
          'created_at' => $now,
          'deleted_at' => NULL,
        ])
        ->execute();

      $response_row = [
        'header' => $header,
        'subheader' => $subheader,
        'description' => $description,
        'description_json' => $description_json,
        'link' => $link,
        'desktop_img' => $desktop_img,
        'mobile_img' => $mobile_img,
        'latitud' => $latitud,
        'longitud' => $longitud,
      ];

      if ($image_url !== '') {
        $response_row['image_url'] = $image_url;
      }
      if ($image_fid) {
        $response_row['image_fid'] = $image_fid;
      }

      if ($weight !== NULL) {
        $response_row['weight'] = $weight;
      }

      //11.- Confirm success so the client can store the stable identifiers locally together with canonical URLs.
      return new JsonResponse([
        'status' => 'ok',
        'id' => (int) $insert_id,
        'uuid' => $row_uuid,
        'weight' => $weight,
        'row' => $response_row,
      ]);
    }
    catch (Throwable $throwable) {
      //12.- Record the underlying reason so administrators can diagnose failed insert attempts from dblog.
      \Drupal::logger('pds_recipe_timeline')->error('Row creation failed for group @group: @message', [
        '@group' => $uuid,
        '@message' => $throwable->getMessage(),
      ]);

      //13.- Shield the UI from low-level errors by returning a clear failure response.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to create row.',
      ], 500);
    }
  }

  public function list(Request $request, string $uuid): JsonResponse {
    //1.- Reject malformed UUIDs so the query never runs with invalid identifiers.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    if (!pds_recipe_timeline_user_can_manage_timeline()) {
      //2.- Ensure only authorized layout editors retrieve row listings for previews.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    try {
      //3.- Repair the legacy schema automatically so listings keep working after deployments.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Timeline storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      $connection = \Drupal::database();

      $fallback_candidates = [];

      $raw_fallback = $request->query->get('fallback_group_id');
      if (is_scalar($raw_fallback) && $raw_fallback !== '') {
        $candidate = (int) $raw_fallback;
        if ($candidate > 0) {
          //1.- Prioritize the historical identifier so legacy datasets reconnect before considering newer ids.
          $fallback_candidates[] = $candidate;
        }
      }

      $raw_group = $request->query->get('group_id');
      if (is_scalar($raw_group) && $raw_group !== '') {
        $candidate = (int) $raw_group;
        if ($candidate > 0 && !in_array($candidate, $fallback_candidates, TRUE)) {
          //2.- Append the active modal id without duplicating the already prioritized fallback reference.
          $fallback_candidates[] = $candidate;
        }
      }

      //4.- Load the numeric group id so we can scope the item query correctly.
      $group_row = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id', 'uuid'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchAssoc();

      $group_id = 0;
      if ($group_row && !empty($group_row['id'])) {
        $group_id = (int) $group_row['id'];
      }

      if (!$group_id) {
        //5.- Attempt to reuse a legacy group identifier supplied by the caller when the UUID lookup fails.
        foreach ($fallback_candidates as $candidate_id) {
          $legacy_row = $connection->select('pds_template_group', 'g')
            ->fields('g', ['id', 'uuid'])
            ->condition('g.id', $candidate_id)
            ->condition('g.deleted_at', NULL, 'IS NULL')
            ->range(0, 1)
            ->execute()
            ->fetchAssoc();

          if (!$legacy_row) {
            continue;
          }

          $group_id = (int) $legacy_row['id'];
          $stored_uuid = is_string($legacy_row['uuid'] ?? NULL)
            ? (string) $legacy_row['uuid']
            : '';

          if ($uuid !== '' && (!Uuid::isValid($stored_uuid) || $stored_uuid !== $uuid)) {
            //7.- Repair the legacy record by storing the fresh UUID so future AJAX calls no longer need the fallback id.
            $connection->update('pds_template_group')
              ->fields(['uuid' => $uuid])
              ->condition('id', $group_id)
              ->condition('deleted_at', NULL, 'IS NULL')
              ->execute();
          }

          break;
        }
      }

      if (!$group_id) {
        //8.- Return an empty dataset when no active record matched either lookup strategy.
        return new JsonResponse([
          'status' => 'ok',
          'rows' => [],
        ]);
      }

      $rows = $this->loadRowsForGroup($connection, (int) $group_id);

      if ($rows === [] && $fallback_candidates !== []) {
        foreach ($fallback_candidates as $candidate_id) {
          if ($candidate_id === (int) $group_id) {
            continue;
          }

          $candidate_rows = $this->loadRowsForGroup($connection, $candidate_id);
          if ($candidate_rows === []) {
            continue;
          }

          $legacy_uuid = $connection->select('pds_template_group', 'g')
            ->fields('g', ['uuid'])
            ->condition('g.id', $candidate_id)
            ->condition('g.deleted_at', NULL, 'IS NULL')
            ->range(0, 1)
            ->execute()
            ->fetchField();

          if ($uuid !== '' && (!is_string($legacy_uuid) || !Uuid::isValid((string) $legacy_uuid) || $legacy_uuid !== $uuid)) {
            //8.- Repair the historical record by storing the active UUID so future AJAX calls skip the fallback path.
            $connection->update('pds_template_group')
              ->fields(['uuid' => $uuid])
              ->condition('id', $candidate_id)
              ->condition('deleted_at', NULL, 'IS NULL')
              ->execute();
          }

          //9.- Reuse the populated legacy dataset so the preview renders the rows editors expect.
          $group_id = $candidate_id;
          $rows = $candidate_rows;
          break;
        }
      }

      //9.- Provide the resolved identifier so the admin UI can repair legacy dialogs that lost their cached group id.
      return new JsonResponse([
        'status' => 'ok',
        'group_id' => (int) $group_id,
        'rows' => $rows,
      ]);
    }
    catch (Throwable $throwable) {
      //10.- Surface a friendly error when the database lookup fails unexpectedly.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to load rows.',
      ], 500);
    }
  }

  private function loadRowsForGroup(Connection $connection, int $group_id): array {
    //1.- Query active timeline items ordered by their saved weight so previews mirror the editor ordering.
    $query = $connection->select('pds_template_item', 'i')
      ->fields('i', [
        'id',
        'uuid',
        'weight',
        'header',
        'subheader',
        'description',
        'url',
        'desktop_img',
        'mobile_img',
        'latitud',
        'longitud',
      ])
      ->condition('i.group_id', $group_id)
      ->condition('i.deleted_at', NULL, 'IS NULL')
      ->orderBy('i.weight', 'ASC');

    $result = $query->execute();

    $rows = [];
    foreach ($result as $record) {
      $desktop = (string) $record->desktop_img;
      $mobile = (string) $record->mobile_img;

      //2.- Normalize the database record into the structure expected by the admin preview table.
      $payload = \pds_recipe_timeline_unpack_description_payload($record->description);

      $rows[] = [
        'id' => (int) $record->id,
        'uuid' => (string) $record->uuid,
        'header' => (string) $record->header,
        'subheader' => (string) $record->subheader,
        'description' => (string) $payload['description'],
        'description_json' => (string) $payload['description_json'],
        'link' => (string) $record->url,
        'desktop_img' => $desktop,
        'mobile_img' => $mobile,
        'image_url' => $desktop,
        'latitud' => $record->latitud !== NULL ? (float) $record->latitud : NULL,
        'longitud' => $record->longitud !== NULL ? (float) $record->longitud : NULL,
        'weight' => (int) $record->weight,
        'thumbnail' => $desktop !== '' ? $desktop : $mobile,
      ];
    }

    return $rows;
  }

  public function update(Request $request, string $uuid, string $row_uuid): JsonResponse {
    //1.- Validate both UUIDs so we never touch the database with malformed identifiers.
    if (!Uuid::isValid($uuid) || !Uuid::isValid($row_uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    if (!pds_recipe_timeline_user_can_manage_timeline()) {
      //2.- Stop unauthorized edits while still honoring layout builder configuration permissions.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    $payload = json_decode($request->getContent() ?: '[]', TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }

    $type = $request->query->get('type');
    if (!is_string($type) || $type === '') {
      if (isset($payload['recipe_type']) && is_string($payload['recipe_type']) && $payload['recipe_type'] !== '') {
        $type = $payload['recipe_type'];
      }
      else {
        $type = 'pds_recipe_timeline';
      }
    }

    $row = isset($payload['row']) && is_array($payload['row']) ? $payload['row'] : [];

    $header = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';
    if ($header === '') {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Missing header.',
      ], 400);
    }

    $subheader = isset($row['subheader']) && is_string($row['subheader']) ? $row['subheader'] : '';
    $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : '';
    $link = isset($row['link']) && is_string($row['link']) ? $row['link'] : '';
    $desktop_img = isset($row['desktop_img']) && is_string($row['desktop_img']) ? $row['desktop_img'] : '';
    $mobile_img = isset($row['mobile_img']) && is_string($row['mobile_img']) ? $row['mobile_img'] : '';
    $image_url = isset($row['image_url']) && is_string($row['image_url']) ? $row['image_url'] : '';

    $latitud = NULL;
    if (array_key_exists('latitud', $row) && ($row['latitud'] === NULL || is_numeric($row['latitud']))) {
      $latitud = $row['latitud'] === NULL ? NULL : (float) $row['latitud'];
    }

    $longitud = NULL;
    if (array_key_exists('longitud', $row) && ($row['longitud'] === NULL || is_numeric($row['longitud']))) {
      $longitud = $row['longitud'] === NULL ? NULL : (float) $row['longitud'];
    }

    $weight = NULL;
    if (isset($payload['weight']) && is_numeric($payload['weight'])) {
      $weight = (int) $payload['weight'];
    }
    elseif (isset($row['weight']) && is_numeric($row['weight'])) {
      $weight = (int) $row['weight'];
    }

    try {
      //3.- Repair the legacy schema so updates cannot fail due to mismatched column sets.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Timeline storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      $group_id = \pds_recipe_timeline_ensure_group_and_get_id($uuid, $type);
      if (!$group_id) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Unable to resolve group.',
        ], 500);
      }

      $connection = \Drupal::database();

      $existing = $connection->select('pds_template_item', 'i')
        ->fields('i', ['id', 'group_id', 'weight'])
        ->condition('i.uuid', $row_uuid)
        ->condition('i.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchAssoc();

      if (!$existing) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Row not found.',
        ], 404);
      }

      if ((int) $existing['group_id'] !== (int) $group_id) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Row does not belong to this block.',
        ], 403);
      }

      //4.- Pass the sanitized URLs to the promoter so it can reuse them when no fid exists.
      $row['desktop_img'] = $desktop_img;
      $row['mobile_img'] = $mobile_img;
      $row['image_url'] = $image_url;

      //5.- Reuse the helper to avoid hard dependencies on the service container during edits.
      $promoter = \pds_recipe_timeline_resolve_row_image_promoter();
      if (!$promoter) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Image promotion is unavailable. Rebuild caches and try again.',
        ], 500);
      }

      $promotion = $promoter->promote($row);
      if (($promotion['status'] ?? '') !== 'ok') {
        $message = $promotion['message'] ?? 'Unable to promote image.';
        $code = $promotion['code'] ?? 500;

        return new JsonResponse([
          'status' => 'error',
          'message' => $message,
        ], $code);
      }

      $desktop_img = (string) ($promotion['desktop_img'] ?? $desktop_img);
      $mobile_img = (string) ($promotion['mobile_img'] ?? $mobile_img);
      $image_url = (string) ($promotion['image_url'] ?? $image_url);
      $image_fid = $promotion['image_fid'] ?? NULL;

      //6.- Build the sanitized update payload while preserving numeric and nullable columns.
      $fields = [
        'header' => $header,
        'subheader' => $subheader,
        'description' => $packed_description,
        'url' => $link,
        'desktop_img' => $desktop_img,
        'mobile_img' => $mobile_img,
        'latitud' => $latitud,
        'longitud' => $longitud,
      ];

      if ($weight !== NULL) {
        $fields['weight'] = $weight;
      }

      $connection->update('pds_template_item')
        ->fields($fields)
        ->condition('uuid', $row_uuid)
        ->execute();

      //7.- Echo the stored values back so the caller can refresh its cached state accurately.
      $response_row = [
        'header' => $fields['header'],
        'subheader' => $fields['subheader'],
        'description' => $description,
        'description_json' => $description_json,
        'link' => $fields['url'],
        'desktop_img' => $fields['desktop_img'],
        'mobile_img' => $fields['mobile_img'],
        'latitud' => $fields['latitud'],
        'longitud' => $fields['longitud'],
      ];

      if ($image_url !== '') {
        $response_row['image_url'] = $image_url;
      }
      if ($image_fid) {
        $response_row['image_fid'] = $image_fid;
      }

      if (array_key_exists('weight', $fields)) {
        $response_row['weight'] = $fields['weight'];
      }
      elseif (isset($existing['weight'])) {
        $response_row['weight'] = (int) $existing['weight'];
      }

      return new JsonResponse([
        'status' => 'ok',
        'id' => (int) $existing['id'],
        'uuid' => $row_uuid,
        'weight' => $response_row['weight'] ?? NULL,
        'row' => $response_row,
      ]);
    }
    catch (Throwable $throwable) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to update row.',
      ], 500);
    }
  }

  private function resolveSchemaRepairer(): ?object {
    //1.- Delegate to the shared helper so every entry point applies identical fallbacks.
    return \pds_recipe_timeline_resolve_schema_repairer();
  }

}
