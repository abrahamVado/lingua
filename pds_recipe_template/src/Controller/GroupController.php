<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GroupController
 *
 * PURPOSE (EN):
 * - Ensure there is a single canonical `pds_template_group` row for a given
 *   (instance_uuid + recipe_type). Returns its numeric `group_id`.
 *
 * PROPÓSITO (ES):
 * - Garantizar que exista una fila canónica en `pds_template_group` para el par
 *   (uuid_de_instancia + tipo_de_receta). Devuelve el `group_id` numérico.
 *
 * BEHAVIOR:
 * - Validates UUID and permissions.
 * - Whitelists the recipe type using TemplateRepository::resolveRecipeType().
 * - First attempts to find an existing row by (uuid + type).
 * - If not found, attempts to repair legacy rows that have the same uuid but
 *   no/empty type by setting the type.
 * - Finally, inserts a fresh row (race-condition tolerant).
 * - Always returns { status, group_id, type } on success.
 */
final class GroupController extends ControllerBase {

  /** Small helper to fetch the repository (keeps call sites concise). */
  private function repo(): \Drupal\pds_recipe_template\TemplateRepository {
    /** @var \Drupal\pds_recipe_template\TemplateRepository $r */
    $r = \Drupal::service('pds_recipe_template.repository');
    return $r;
  }

  /**
   * POST /pds-template/ensure-group/{id}?type=...
   *
   * REQUEST:
   *   - Path: {id} (numeric group id; use 0 to request creation)
   *   - Query: ?type=... (optional; overrides body) & ?instance_uuid=...
   *   - Body: {"recipe_type":"...","instance_uuid":"..."} (optional fallback)
   *
   * RESPONSE (success):
   *   { "status": "ok", "group_id": 123, "type": "pds_recipe_template" }
   */
  public function ensureGroup(Request $request, int $id): JsonResponse {
    //1.- Sanity-check the numeric identifier (negative ids are invalid).
    if ($id < 0) {
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Invalid group id.',
      ], 400);
    }

    //2.- Permission gate: layout editors/admins only.
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    //3.- Decode JSON body for optional hints.
    $payload = json_decode($request->getContent() ?: '[]', true);
    $payload = is_array($payload) ? $payload : [];

    //4.- Resolve recipe type, preferring the explicit query parameter.
    $repo = $this->repo();
    $type_candidate = $request->query->get('type');
    if (!is_string($type_candidate) || $type_candidate === '') {
      $type_candidate = isset($payload['recipe_type']) && is_string($payload['recipe_type'])
        ? $payload['recipe_type']
        : null;
    }
    $type = $repo->resolveRecipeType($type_candidate, 'pds_recipe_template');

    //5.- Gather the instance UUID (query wins, body is fallback).
    $instance_uuid = $request->query->get('instance_uuid');
    if (!is_string($instance_uuid) || $instance_uuid === '') {
      if (isset($payload['instance_uuid']) && is_string($payload['instance_uuid'])) {
        $instance_uuid = $payload['instance_uuid'];
      }
      elseif (isset($payload['uuid']) && is_string($payload['uuid'])) {
        $instance_uuid = $payload['uuid'];
      }
      else {
        $instance_uuid = '';
      }
    }
    $instance_uuid = trim((string) $instance_uuid);

    try {
      //6.- Reuse existing numeric groups when available.
      if ($id > 0) {
        $group = $repo->loadActiveGroupById($id);
        if ($group) {
          $type_ok = $repo->ensureGroupTypeValue($id, $type);
          if (!$type_ok && $repo->groupTableSupportsType()) {
            return new JsonResponse([
              'status'  => 'error',
              'message' => 'Group type mismatch.',
            ], 409);
          }

          return new JsonResponse([
            'status'         => 'ok',
            'group_id'       => $id,
            'type'           => $type,
            'instance_uuid'  => $group['uuid'] ?? '',
          ]);
        }
      }

      //7.- Without a valid UUID we cannot create or locate a group.
      if ($instance_uuid === '' || !Uuid::isValid($instance_uuid)) {
        $code = $id > 0 ? 404 : 400;
        $message = $id > 0 ? 'Group not found.' : 'Missing instance uuid.';
        return new JsonResponse([
          'status'  => 'error',
          'message' => $message,
        ], $code);
      }

      //8.- Create or fetch the group by UUID, then ensure its type.
      $group_id = $repo->ensureGroupAndGetId($instance_uuid, $type);
      if ($group_id <= 0) {
        return new JsonResponse([
          'status'  => 'error',
          'message' => 'Unable to ensure group.',
        ], 500);
      }

      $type_ok = $repo->ensureGroupTypeValue($group_id, $type);
      if (!$type_ok && $repo->groupTableSupportsType()) {
        return new JsonResponse([
          'status'  => 'error',
          'message' => 'Group type mismatch.',
        ], 409);
      }

      return new JsonResponse([
        'status'         => 'ok',
        'group_id'       => $group_id,
        'type'           => $type,
        'instance_uuid'  => $instance_uuid,
      ]);
    }
    catch (\Throwable $e) {
      //9.- Graceful failure for the modal; keep the error generic for editors.
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Unable to ensure group.',
      ], 500);
    }
  }

}
