<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GroupController extends ControllerBase {

  public function ensureGroup(Request $request, string $uuid): JsonResponse {
    //1.- Validate the UUID upfront so we never hit the database with invalid identifiers.
    if (!Uuid::isValid($uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
    }

    if (!pds_recipe_template_user_can_manage_template()) {
      //2.- Refuse unauthorized callers so layout builder editors without block admin still pass.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Access denied.',
      ], 403);
    }

    //3.- Normalize the optional recipe type coming from the caller.
    $type = $request->query->get('type');
    if (!is_string($type) || $type === '') {
      $type = 'pds_recipe_template';
    }

    try {
      $connection = \Drupal::database();

      //4.- Reuse any active record tied to the UUID when it already exists.
      $existing_id = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      if (!$existing_id) {
        //5.- Insert a fresh group row when the UUID has not been registered yet.
        $now = \Drupal::time()->getRequestTime();
        try {
          $connection->insert('pds_template_group')
            ->fields([
              'uuid' => $uuid,
              'type' => $type,
              'created_at' => $now,
              'deleted_at' => NULL,
            ])
            ->execute();
        }
        catch (\Exception $insert_exception) {
          //6.- Silence race-condition duplicate errors and fall through to reselect.
        }

        $existing_id = $connection->select('pds_template_group', 'g')
          ->fields('g', ['id'])
          ->condition('g.uuid', $uuid)
          ->condition('g.deleted_at', NULL, 'IS NULL')
          ->execute()
          ->fetchField();
      }

      //6.- Return the id even when null so caller can decide how to react.
      if (!$existing_id) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Unable to resolve group.',
        ], 500);
      }

      //7.- Acknowledge success with the resolved identifier.
      return new JsonResponse([
        'status' => 'ok',
        'group_id' => (int) $existing_id,
      ]);
    }
    catch (\Exception $e) {
      //8.- Fail gracefully so UI can log or retry without crashing the dialog.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to ensure group.',
      ], 500);
    }
  }

}
