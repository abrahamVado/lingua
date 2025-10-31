<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * RowController
 *
 * WHAT THIS CONTROLLER DOES (EN):
 * - Single set of endpoints (create, list, update) that serve *multiple* recipe types.
 * - The active recipe type is passed via ?type=... or payload.row.recipe_type
 *   and is sanitized through TemplateRepository::resolveRecipeType().
 * - All DB writes/reads are scoped by (instance UUID + recipe type) via the group row.
 *
 * LO QUE HACE ESTE CONTROLADOR (ES):
 * - Un solo conjunto de endpoints (create, list, update) que soportan *múltiples* recetas.
 * - El tipo de receta activo llega por ?type=... o payload.row.recipe_type
 *   y se valida con TemplateRepository::resolveRecipeType().
 * - Todas las operaciones se acotan por (UUID de instancia + tipo de receta) usando la tabla "group".
 *
 * SECURITY NOTES:
 * - Every endpoint checks:
 *   (1) UUID format (prevent malformed keys),
 *   (2) pds_recipe_template_user_can_manage_template() (permission gate),
 *   (3) Schema repair (defensive: avoids column drift during deployments).
 */
final class RowController extends ControllerBase {

  /**
   * Tiny helper to fetch the repository from the container.
   * Keeps callsites compact and testable.
   */
  private function repo(): \Drupal\pds_recipe_template\TemplateRepository {
    /** @var \Drupal\pds_recipe_template\TemplateRepository $r */
    $r = \Drupal::service('pds_recipe_template.repository');
    return $r;
  }

  /**
   * POST /pds-recipe/{uuid}/row
   * Create a new row within the group resolved by (uuid + recipe type).
   *
   * INPUT (JSON):
   * {
   *   "recipe_type": "pds_recipe_template" | "...",
   *   "weight": 0..n (optional),
   *   "row": {
   *     "header": "required",
   *     "subheader": "...",
   *     "description": "...",
   *     "link": "https://...",
   *     "desktop_img": "https://... (optional)",
   *     "mobile_img":  "https://... (optional)",
   *     "image_url":   "https://... (legacy fallback)",
   *     "image_fid":   123 (optional, to promote),
   *     "latitud": 19.4 | null,
   *     "longitud": -99.1 | null
   *   }
   * }
   */
  public function createRow(Request $request, string $uuid): JsonResponse {
    // 1) Validate UUID early.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid UUID.'], 400);
    }

    // 2) Permission gate for editors/admins.
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    // 3) Parse JSON body safely.
    $payload = json_decode($request->getContent() ?: '[]', TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }

    // 4) Resolve and whitelist the recipe type (multi-recipe friendly).
    $repo = $this->repo();
    $type_candidate = $request->query->get('type');
    if (!is_string($type_candidate) || $type_candidate === '') {
      $type_candidate = isset($payload['recipe_type']) && is_string($payload['recipe_type'])
        ? $payload['recipe_type']
        : null;
    }
    $type = $repo->resolveRecipeType($type_candidate, 'pds_recipe_template');

    // 5) Extract row payload & validate required fields.
    $row = isset($payload['row']) && is_array($payload['row']) ? $payload['row'] : [];
    $header = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';
    if ($header === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Missing header.'], 400);
    }

    // 6) Optional fields (kept lenient to avoid user friction).
    $subheader   = isset($row['subheader'])   && is_string($row['subheader'])   ? $row['subheader']   : '';
    $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : '';
    $link        = isset($row['link'])        && is_string($row['link'])        ? $row['link']        : '';
    $desktop_img = isset($row['desktop_img']) && is_string($row['desktop_img']) ? $row['desktop_img'] : '';
    $mobile_img  = isset($row['mobile_img'])  && is_string($row['mobile_img'])  ? $row['mobile_img']  : '';
    $image_url   = isset($row['image_url'])   && is_string($row['image_url'])   ? $row['image_url']   : '';

    // 7) Geo (nullable numeric).
    $latitud = NULL;
    if (array_key_exists('latitud', $row) && ($row['latitud'] === NULL || is_numeric($row['latitud']))) {
      $latitud = $row['latitud'] === NULL ? NULL : (float) $row['latitud'];
    }
    $longitud = NULL;
    if (array_key_exists('longitud', $row) && ($row['longitud'] === NULL || is_numeric($row['longitud']))) {
      $longitud = $row['longitud'] === NULL ? NULL : (float) $row['longitud'];
    }

    // 8) Weight (optional; append to end if missing).
    $weight = NULL;
    if (isset($payload['weight']) && is_numeric($payload['weight'])) {
      $weight = (int) $payload['weight'];
    }
    elseif (isset($row['weight']) && is_numeric($row['weight'])) {
      $weight = (int) $row['weight'];
    }

    try {

      // 10) Resolve numeric group id by (uuid + type).
      $group_id = $repo->ensureGroupAndGetId($uuid, $type);
      if (!$group_id) {
        return new JsonResponse(['status' => 'error', 'message' => 'Unable to resolve group.'], 500);
      }

      $connection = \Drupal::database();
      $now = \Drupal::time()->getRequestTime();

      // Mirror URLs into row so the promoter can reuse them if no fid.
      $row['desktop_img'] = $desktop_img;
      $row['mobile_img']  = $mobile_img;
      $row['image_url']   = $image_url;

      // 11) Promote image (temporary fid → permanent URL), resilient to container rebuilds.
      $promoter = \pds_recipe_template_resolve_row_image_promoter();
      if (!$promoter) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Image promotion is unavailable. Rebuild caches and try again.',
        ], 500);
      }
      $promotion  = $promoter->promote($row);
      if (($promotion['status'] ?? '') !== 'ok') {
        $message = $promotion['message'] ?? 'Unable to promote image.';
        $code    = $promotion['code'] ?? 500;
        return new JsonResponse(['status' => 'error', 'message' => $message], $code);
      }

      // Canonical URLs after promotion.
      $desktop_img = (string) ($promotion['desktop_img'] ?? $desktop_img);
      $mobile_img  = (string) ($promotion['mobile_img']  ?? $mobile_img);
      $image_url   = (string) ($promotion['image_url']   ?? $image_url);
      $image_fid   = $promotion['image_fid'] ?? NULL;

      // 12) Compute default weight (append).
      if ($weight === NULL) {
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

      // 13) Row UUID (keep supplied if valid, else generate).
      $row_uuid = isset($row['uuid']) && is_string($row['uuid']) && Uuid::isValid($row['uuid'])
        ? $row['uuid']
        : \Drupal::service('uuid')->generate();

      // 14) Insert DB record.
      $insert_id = $connection->insert('pds_template_item')
        ->fields([
          'uuid'        => $row_uuid,
          'group_id'    => (int) $group_id,
          'weight'      => $weight,
          'header'      => $header,
          'subheader'   => $subheader,
          'description' => $description,
          'url'         => $link,
          'desktop_img' => $desktop_img,
          'mobile_img'  => $mobile_img,
          'latitud'     => $latitud,
          'longitud'    => $longitud,
          'created_at'  => $now,
          'deleted_at'  => NULL,
        ])
        ->execute();

      // 15) Response payload = exactly what the UI needs to refresh local cache.
      $response_row = [
        'header'      => $header,
        'subheader'   => $subheader,
        'description' => $description,
        'link'        => $link,
        'desktop_img' => $desktop_img,
        'mobile_img'  => $mobile_img,
        'latitud'     => $latitud,
        'longitud'    => $longitud,
      ];
      if ($image_url !== '') { $response_row['image_url'] = $image_url; }
      if ($image_fid)        { $response_row['image_fid'] = $image_fid; }
      if ($weight !== NULL)  { $response_row['weight']    = $weight;    }

      return new JsonResponse([
        'status' => 'ok',
        'id'     => (int) $insert_id,
        'uuid'   => $row_uuid,
        'weight' => $weight,
        'row'    => $response_row,
      ]);
    }
    catch (Throwable $throwable) {
      // 16) Log exact reason for admins; return friendly error to UI.
      \Drupal::logger('pds_recipe_template')->error('Row creation failed for group @group: @message', [
        '@group' => $uuid,
        '@message' => $throwable->getMessage(),
      ]);
      return new JsonResponse(['status' => 'error', 'message' => 'Unable to create row.'], 500);
    }
  }

  /**
   * GET /pds-recipe/{uuid}/rows?type=...&group_id=...
   * List rows for a (uuid + recipe type). No legacy regeneration/repairs.
   */
  public function list(Request $request, string $uuid): JsonResponse {
    // 1) Validate UUID and permissions.
    if (!\Drupal\Component\Uuid\Uuid::isValid($uuid)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid UUID.'], 400);
    }
    if (!\pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    try {


      $connection    = \Drupal::database();
      $supports_type = $this->groupTableSupportsType($connection);

      /** @var \Drupal\pds_recipe_template\TemplateRepository $repo */
      $repo = \Drupal::service('pds_recipe_template.repository');

      // 3) Resolve/whitelist recipe type.
      $type = $repo->resolveRecipeType($request->query->get('type') ?: null, 'pds_recipe_template');

      // 4) Canonical lookup by (uuid[, type]).
      $group_query = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->range(0, 1);

      if ($supports_type) {
        $group_query->condition('g.type', $type);
      }

      $group_id = (int) ($group_query->execute()->fetchField() ?: 0);

      // 5) No group yet → empty ok response (first open / not created).
      if ($group_id === 0) {
        return new JsonResponse([
          'status'   => 'ok',
          'group_id' => 0,
          'type'     => $type,
          'rows'     => [],
        ]);
      }

      // 6) Load rows for resolved group id (repository handles legacy deleted_at cases).
      $rows = $repo->loadItems($group_id);

      // 7) Success.
      return new JsonResponse([
        'status'   => 'ok',
        'group_id' => $group_id,
        'type'     => $type,
        'rows'     => is_array($rows) ? $rows : [],
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('pds_recipe_template')->error(
        'List rows failed for @uuid: @message',
        ['@uuid' => $uuid, '@message' => $e->getMessage()]
      );
      return new JsonResponse(['status' => 'error', 'message' => 'Unable to load rows.'], 500);
    }
  }


  /**
   * GET/PUT /pds-recipe/{uuid}/row/{row_uuid}
   * Update an existing row (scoped to the group resolved by uuid + type).
   */
  public function update(Request $request, string $uuid, string $row_uuid): JsonResponse {
    // 1) Validate UUIDs and permissions.
    if (!Uuid::isValid($uuid) || !Uuid::isValid($row_uuid)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid UUID.'], 400);
    }
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    // 2) Parse payload and resolve recipe type.
    $payload = json_decode($request->getContent() ?: '[]', TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }

    $repo = $this->repo();
    $type_candidate = $request->query->get('type');
    if (!is_string($type_candidate) || $type_candidate === '') {
      $type_candidate = isset($payload['recipe_type']) && is_string($payload['recipe_type'])
        ? $payload['recipe_type']
        : null;
    }
    $type = $repo->resolveRecipeType($type_candidate, 'pds_recipe_template');

    // 3) Extract and validate row fields.
    $row = isset($payload['row']) && is_array($payload['row']) ? $payload['row'] : [];

    $header = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';
    if ($header === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Missing header.'], 400);
    }

    $subheader   = isset($row['subheader'])   && is_string($row['subheader'])   ? $row['subheader']   : '';
    $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : '';
    $link        = isset($row['link'])        && is_string($row['link'])        ? $row['link']        : '';
    $desktop_img = isset($row['desktop_img']) && is_string($row['desktop_img']) ? $row['desktop_img'] : '';
    $mobile_img  = isset($row['mobile_img'])  && is_string($row['mobile_img'])  ? $row['mobile_img']  : '';
    $image_url   = isset($row['image_url'])   && is_string($row['image_url'])   ? $row['image_url']   : '';

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


      // 5) Resolve group id (uuid + type) and verify ownership.
      $group_id = $repo->ensureGroupAndGetId($uuid, $type);
      if (!$group_id) {
        return new JsonResponse(['status' => 'error', 'message' => 'Unable to resolve group.'], 500);
      }

      $connection = \Drupal::database();

      $existing = $connection->select('pds_template_item', 'i')
        ->fields('i', ['id', 'group_id', 'weight'])
        ->condition('i.uuid', $row_uuid)
        ->condition('i.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchAssoc();

      if (!$existing) {
        return new JsonResponse(['status' => 'error', 'message' => 'Row not found.'], 404);
      }
      if ((int) $existing['group_id'] !== (int) $group_id) {
        return new JsonResponse(['status' => 'error', 'message' => 'Row does not belong to this block.'], 403);
      }

      // 6) Let promoter finalize canonical URLs (handles fid/temporary files).
      $row['desktop_img'] = $desktop_img;
      $row['mobile_img']  = $mobile_img;
      $row['image_url']   = $image_url;

      $promoter = \pds_recipe_template_resolve_row_image_promoter();
      if (!$promoter) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Image promotion is unavailable. Rebuild caches and try again.',
        ], 500);
      }

      $promotion  = $promoter->promote($row);
      if (($promotion['status'] ?? '') !== 'ok') {
        $message = $promotion['message'] ?? 'Unable to promote image.';
        $code    = $promotion['code'] ?? 500;
        return new JsonResponse(['status' => 'error', 'message' => $message], $code);
      }

      $desktop_img = (string) ($promotion['desktop_img'] ?? $desktop_img);
      $mobile_img  = (string) ($promotion['mobile_img']  ?? $mobile_img);
      $image_url   = (string) ($promotion['image_url']   ?? $image_url);
      $image_fid   = $promotion['image_fid'] ?? NULL;

      // 7) Build update payload (preserve numeric/null columns).
      $fields = [
        'header'      => $header,
        'subheader'   => $subheader,
        'description' => $description,
        'url'         => $link,
        'desktop_img' => $desktop_img,
        'mobile_img'  => $mobile_img,
        'latitud'     => $latitud,
        'longitud'    => $longitud,
      ];
      if ($weight !== NULL) {
        $fields['weight'] = $weight;
      }

      $connection->update('pds_template_item')
        ->fields($fields)
        ->condition('uuid', $row_uuid)
        ->execute();

      // 8) Echo stored values for UI state refresh.
      $response_row = [
        'header'      => $fields['header'],
        'subheader'   => $fields['subheader'],
        'description' => $fields['description'],
        'link'        => $fields['url'],
        'desktop_img' => $fields['desktop_img'],
        'mobile_img'  => $fields['mobile_img'],
        'latitud'     => $fields['latitud'],
        'longitud'    => $fields['longitud'],
      ];
      if ($image_url !== '') { $response_row['image_url'] = $image_url; }
      if ($image_fid)        { $response_row['image_fid'] = $image_fid; }

      if (array_key_exists('weight', $fields)) {
        $response_row['weight'] = $fields['weight'];
      }
      elseif (isset($existing['weight'])) {
        $response_row['weight'] = (int) $existing['weight'];
      }

      return new JsonResponse([
        'status' => 'ok',
        'id'     => (int) $existing['id'],
        'uuid'   => $row_uuid,
        'weight' => $response_row['weight'] ?? NULL,
        'row'    => $response_row,
      ]);
    }
    catch (Throwable $throwable) {
      //7.- Persist the failure so the operations dashboard can highlight which (uuid,row_uuid) update failed and why.
      \Drupal::logger('pds_recipe_template')->error('Row update failed for @uuid/@row: @message', [
        '@uuid' => $uuid,
        '@row' => $row_uuid,
        '@message' => $throwable->getMessage(),
      ]);      
      return new JsonResponse(['status' => 'error', 'message' => 'Unable to update row.'], 500);
    }
  }

  /**
   * Centralized schema repair resolver.
   * Using the helper keeps behavior identical across all entry points.
   */
  private function resolveSchemaRepairer(): ?object {
    // 1) Delegate to procedural helper to be robust during container rebuilds.
    return \pds_recipe_template_resolve_schema_repairer();
  }

  /**
   * Detect whether the group table already exposes the "type" column.
   */
  private function groupTableSupportsType(Connection $connection): bool {
    static $has_type = NULL;

    if ($has_type === NULL) {
      try {
        //5.- Cache the lookup per-request so repeated checks stay inexpensive during multi-call flows.
        $has_type = $connection->schema()->fieldExists('pds_template_group', 'type');
      }
      catch (Throwable $throwable) {
        //6.- Log once per request and gracefully degrade to UUID-only behavior when introspection fails.
        \Drupal::logger('pds_recipe_template')->warning('Unable to detect pds_template_group.type column: @message', [
          '@message' => $throwable->getMessage(),
        ]);
        $has_type = FALSE;
      }
    }

    return (bool) $has_type;
  }

// src/Controller/RowController.php (add this method)

  public function delete(Request $request, string $uuid, string $row_uuid): JsonResponse {
    // 1) Validate inputs + permission.
    if (!Uuid::isValid($uuid) || !Uuid::isValid($row_uuid)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid UUID.'], 400);
    }
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    try {


      $repo = $this->repo();
      $type = $repo->resolveRecipeType($request->query->get('type') ?: null, 'pds_recipe_template');

      $db = \Drupal::database();
      $supports_type = $this->groupTableSupportsType($db);

      // 3) Find group by (uuid,type). If not present yet, treat as no-op.
      $group_query = $db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->range(0, 1);
      if ($supports_type) {
        //4.- Honor the recipe discriminator when the schema supports it to avoid cross-recipe deletions.
        $group_query->condition('g.type', $type);
      }
      $gid = (int) $group_query
        ->execute()
        ->fetchField();

      if ($gid <= 0) {
        // No group → nothing to delete.
        return new JsonResponse(['status' => 'ok', 'deleted' => 0]);
      }

      // 4) Soft-delete the row by UUID within that group.
      $now = \Drupal::time()->getRequestTime();
      $affected = $db->update('pds_template_item')
        ->fields(['deleted_at' => $now])
        ->condition('group_id', $gid)
        ->condition('uuid', $row_uuid)
        ->isNull('deleted_at')
        ->execute();

      if ($affected > 0) {
        return new JsonResponse(['status' => 'ok', 'deleted' => $affected]);
      }

      // 5) If nothing updated, check if it was already deleted or never existed → still OK.
      $exists = (bool) $db->select('pds_template_item', 'i')
        ->fields('i', ['id'])
        ->condition('group_id', $gid)
        ->condition('uuid', $row_uuid)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($exists) {
        // Already soft-deleted; treat as success.
        return new JsonResponse(['status' => 'ok', 'deleted' => 0]);
      }

      // Not found at all (maybe a stale client). Return ok to keep UI consistent.
      return new JsonResponse(['status' => 'ok', 'deleted' => 0]);
    }
    catch (\Throwable $e) {
      // Minimal instrumentation so you can see why it fails (watchdog).
      \Drupal::logger('pds_recipe_template')->error('Delete failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['status' => 'error', 'message' => 'Unable to delete row.'], 500);
    }
  }



}
