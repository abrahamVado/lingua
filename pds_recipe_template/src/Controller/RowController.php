<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
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

    $payload = json_decode($request->getContent() ?: '[]', TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }

    //2.- Accept optional type from query string or payload so multi-recipe setups reuse the endpoint.
    $type = $request->query->get('type');
    if (!is_string($type) || $type === '') {
      if (isset($payload['recipe_type']) && is_string($payload['recipe_type']) && $payload['recipe_type'] !== '') {
        $type = $payload['recipe_type'];
      }
      else {
        $type = 'pds_recipe_template';
      }
    }

    //3.- Pull the row array from the request and require the same minimum fields as creation.
    $row = isset($payload['row']) && is_array($payload['row']) ? $payload['row'] : [];

    $header = isset($row['header']) && is_string($row['header']) ? trim($row['header']) : '';
    if ($header === '') {
      //2.- Require a header because the UI treats it as the minimal amount of data.
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
      //4.- Guarantee the storage schema matches expectations before inserting brand-new rows.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Template storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      //5.- Resolve the numeric group id so we can assert ownership before updating anything.
      $group_id = \pds_recipe_template_ensure_group_and_get_id($uuid, $type);
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

      $promotion = \Drupal::service('pds_recipe_template.row_image_promoter')->promote($row);
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
        //3.- When the caller omits weight we append to the end by using max + 1.
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

      //4.- Insert the brand-new database record and capture the assigned identifier.
      $insert_id = $connection->insert('pds_template_item')
        ->fields([
          'uuid' => $row_uuid,
          'group_id' => (int) $group_id,
          'weight' => $weight,
          'header' => $header,
          'subheader' => $subheader,
          'description' => $description,
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

      //6.- Confirm success so the client can store the stable identifiers locally together with canonical URLs.
      return new JsonResponse([
        'status' => 'ok',
        'id' => (int) $insert_id,
        'uuid' => $row_uuid,
        'weight' => $weight,
        'row' => $response_row,
      ]);
    }
    catch (Throwable $throwable) {
      //7.- Record the underlying reason so administrators can diagnose failed insert attempts from dblog.
      \Drupal::logger('pds_recipe_template')->error('Row creation failed for group @group: @message', [
        '@group' => $uuid,
        '@message' => $throwable->getMessage(),
      ]);

      //8.- Shield the UI from low-level errors by returning a clear failure response.
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

    try {
      //2.- Repair the legacy schema automatically so listings keep working after deployments.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Template storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      $connection = \Drupal::database();

      //3.- Load the numeric group id so we can scope the item query correctly.
      $group_id = $connection->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->condition('g.deleted_at', NULL, 'IS NULL')
        ->execute()
        ->fetchField();

      if (!$group_id) {
        //4.- Return an empty dataset when the block has not stored rows yet.
        return new JsonResponse([
          'status' => 'ok',
          'rows' => [],
        ]);
      }

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
        ->condition('i.group_id', (int) $group_id)
        ->condition('i.deleted_at', NULL, 'IS NULL')
        ->orderBy('i.weight', 'ASC');

      $result = $query->execute();

      $rows = [];
      foreach ($result as $record) {
        //5.- Normalize every column into the structure expected by the admin UI.
        $desktop = (string) $record->desktop_img;
        $mobile = (string) $record->mobile_img;

        $rows[] = [
          'id' => (int) $record->id,
          'uuid' => (string) $record->uuid,
          'header' => (string) $record->header,
          'subheader' => (string) $record->subheader,
          'description' => (string) $record->description,
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

      return new JsonResponse([
        'status' => 'ok',
        'rows' => $rows,
      ]);
    }
    catch (Throwable $throwable) {
      //6.- Surface a friendly error when the database lookup fails unexpectedly.
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Unable to load rows.',
      ], 500);
    }
  }

  public function update(Request $request, string $uuid, string $row_uuid): JsonResponse {
    //1.- Validate both UUIDs so we never touch the database with malformed identifiers.
    if (!Uuid::isValid($uuid) || !Uuid::isValid($row_uuid)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Invalid UUID.',
      ], 400);
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
        $type = 'pds_recipe_template';
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
      //4.- Repair the legacy schema so updates cannot fail due to mismatched column sets.
      $schema_repairer = $this->resolveSchemaRepairer();
      if (!$schema_repairer || !$schema_repairer->ensureItemTableUpToDate()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => 'Template storage needs attention. Re-run database updates and try again.',
        ], 500);
      }

      $group_id = \pds_recipe_template_ensure_group_and_get_id($uuid, $type);
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

      $promotion = \Drupal::service('pds_recipe_template.row_image_promoter')->promote($row);
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

      //5.- Build the sanitized update payload while preserving numeric and nullable columns.
      $fields = [
        'header' => $header,
        'subheader' => $subheader,
        'description' => $description,
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

      //6.- Echo the stored values back so the caller can refresh its cached state accurately.
      $response_row = [
        'header' => $fields['header'],
        'subheader' => $fields['subheader'],
        'description' => $fields['description'],
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
    //1.- Reuse the shared service when the dependency injection container already bootstrapped it.
    if (\Drupal::hasService('pds_recipe_template.legacy_schema_repairer')) {
      try {
        return \Drupal::service('pds_recipe_template.legacy_schema_repairer');
      }
      catch (Throwable $throwable) {
        \Drupal::logger('pds_recipe_template')->error('Unable to load schema repairer service: @message', [
          '@message' => $throwable->getMessage(),
        ]);
      }
    }

    //2.- Attempt to include the class manually when the module autoloader is unavailable (for example during cache rebuilds or
    //    when the module is disabled but the runtime helpers are still required).
    if (!class_exists('\\Drupal\\pds_recipe_template\\Service\\LegacySchemaRepairer')) {
      try {
        //3.- Locate the module path dynamically so the logic works in any installation profile layout.
        $module_path = \Drupal::service('extension.list.module')->getPath('pds_recipe_template');
        if (is_string($module_path) && $module_path !== '') {
          $legacy_repairer_path = DRUPAL_ROOT . '/' . $module_path . '/src/Service/LegacySchemaRepairer.php';
          if (is_readable($legacy_repairer_path)) {
            require_once $legacy_repairer_path;
          }
        }
      }
      catch (Throwable $throwable) {
        \Drupal::logger('pds_recipe_template')->error('Unable to include schema repairer class manually: @message', [
          '@message' => $throwable->getMessage(),
        ]);
      }
    }

    //4.- Instantiate the repairer manually when the container cache is rebuilding or unavailable.
    if (class_exists('\\Drupal\\pds_recipe_template\\Service\\LegacySchemaRepairer')) {
      try {
        return new \Drupal\pds_recipe_template\Service\LegacySchemaRepairer(
          \Drupal::database(),
          \Drupal::service('datetime.time'),
          \Drupal::service('uuid'),
          \Drupal::service('logger.factory')
        );
      }
      catch (Throwable $throwable) {
        \Drupal::logger('pds_recipe_template')->error('Unable to instantiate schema repairer manually: @message', [
          '@message' => $throwable->getMessage(),
        ]);
      }
    }

    //5.- Return NULL so callers can surface a clear error instead of triggering PHP fatals.
    return NULL;
  }

}
