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
   * POST /pds-recipe/{uuid}/ensure-group?type=...
   *
   * REQUEST:
   *   - Path: {uuid} (required, instance UUID)
   *   - Query: ?type=... (optional; also accepted via body, but query wins)
   *   - Body: {"recipe_type":"..."} (optional fallback)
   *
   * RESPONSE (success):
   *   { "status": "ok", "group_id": 123, "type": "pds_recipe_template" }
   */
  public function ensureGroup(Request $request, string $uuid): JsonResponse {
    // 1) Validate UUID early: never touch DB with malformed identifiers.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    // 2) Permission gate: layout editors/admins only.
    if (!pds_recipe_template_user_can_manage_template()) {
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    // 3) Resolve + whitelist the recipe type (supports multi-recipe on one endpoint).
    $repo = $this->repo();
    $payload = json_decode($request->getContent() ?: '[]', true);
    $payload = is_array($payload) ? $payload : [];

    $type_candidate = $request->query->get('type');
    if (!is_string($type_candidate) || $type_candidate === '') {
      $type_candidate = isset($payload['recipe_type']) && is_string($payload['recipe_type'])
        ? $payload['recipe_type']
        : null;
    }
    $type = $repo->resolveRecipeType($type_candidate, 'pds_recipe_template');

    try {
      $connection = \Drupal::database();

      // 4) Try the canonical (uuid + type) directly. This is the happy path.
      $existing_id = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.type', $type)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($existing_id) {
        // 4.1) Already exists – return it.
        return new JsonResponse([
          'status'   => 'ok',
          'group_id' => (int) $existing_id,
          'type'     => $type,
        ]);
      }

      // 5) Legacy repair path:
      //    Find a row with the same uuid but missing/empty type and fix it.
      $legacy_id = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->isNull('g.type')                    // type IS NULL
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if (!$legacy_id) {
        // Some sites may store empty-string instead of NULL for type; handle that too.
        $legacy_id = $connection->select('pds_template_group', 'g')
          ->fields('g', ['id'])
          ->condition('g.uuid', $uuid)
          ->condition('g.type', '', '=')     // type = ''
          ->condition('g.deleted_at', NULL, 'IS NULL')
          ->range(0, 1)
          ->execute()
          ->fetchField();
      }

      if ($legacy_id) {
        // 5.1) Repair legacy row by setting the desired type.
        $connection->update('pds_template_group')
          ->fields(['type' => $type])
          ->condition('id', (int) $legacy_id)
          ->condition('deleted_at', NULL, 'IS NULL')
          ->execute();

        return new JsonResponse([
          'status'   => 'ok',
          'group_id' => (int) $legacy_id,
          'type'     => $type,
        ]);
      }

      // 6) Insert a fresh group row (race-condition tolerant).
      $now = \Drupal::time()->getRequestTime();
      try {
        $connection->insert('pds_template_group')
          ->fields([
            'uuid'       => $uuid,
            'type'       => $type,
            'created_at' => $now,
            'deleted_at' => NULL,
          ])
          ->execute();
      } catch (\Exception $race) {
        // 6.1) If two requests race, ignore duplicate errors and reselect below.
      }

      // 7) Reselect by (uuid + type) to return the correct id post-race.
      $existing_id = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.type', $type)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if (!$existing_id) {
        // 7.1) Defensive: still not found → report a clean 500 for the UI.
        return new JsonResponse([
          'status'  => 'error',
          'message' => 'Unable to resolve group.',
        ], 500);
      }

      // 8) Success: return id + type so callers can cache both.
      return new JsonResponse([
        'status'   => 'ok',
        'group_id' => (int) $existing_id,
        'type'     => $type,
      ]);
    }
    catch (\Exception $e) {
      // 9) Graceful failure for the modal; log can be added if you prefer.
      return new JsonResponse([
        'status'  => 'error',
        'message' => 'Unable to ensure group.',
      ], 500);
    }
  }

}
