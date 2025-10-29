<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\pds_recipe_template\Controller\RowController as BaseRowController;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Decorates the template row controller to persist timeline entries per item.
 */
final class TimelineRowController extends ControllerBase {

  private const TIMELINE_TABLE = 'pds_template_item_timeline';
  private const TIMELINE_REQUEST_MARKER = '_pds_recipe_timeline_payload';

  /**
   * Handle row creation while syncing timeline entries to the custom table.
   */
  public function createRow(Request $request, string $uuid): JsonResponse {
    //1.- Reuse the shared implementation when the request does not target the timeline recipe.
    if (!$this->requestTargetsTimeline($request)) {
      return $this->baseController()->createRow($request, $uuid);
    }

    //2.- Extract and normalize the timeline payload before delegating to the shared handler.
    $payload = $this->decodePayload($request);
    $timeline = $this->extractTimelineEntries($payload);

    $response = $this->baseController()->createRow($request, $uuid);
    if ($response->getStatusCode() !== 200) {
      return $response;
    }

    $data = $this->decodeResponse($response);
    if (($data['status'] ?? '') !== 'ok') {
      return $response;
    }

    $item_id = (int) ($data['id'] ?? 0);
    if ($item_id > 0) {
      //3.- Persist the sanitized timeline entries so each executive exposes chronological data.
      $stored = $this->syncTimeline($item_id, $timeline);
      $data['row']['timeline'] = $stored;
      $response->setData($data);
    }

    return $response;
  }

  /**
   * Return the list of rows while appending stored timeline datasets.
   */
  public function list(Request $request, string $uuid): JsonResponse {
    //1.- Delegate to the shared controller so existing access and legacy repairs execute.
    $response = $this->baseController()->list($request, $uuid);

    if (!$this->requestTargetsTimeline($request)) {
      return $response;
    }
    if ($response->getStatusCode() !== 200) {
      return $response;
    }

    $data = $this->decodeResponse($response);
    if (($data['status'] ?? '') !== 'ok') {
      return $response;
    }

    $rows = $data['rows'] ?? [];
    if (!is_array($rows) || $rows === []) {
      return $response;
    }

    //2.- Collect the database identifiers so the timeline table can be queried in a single round-trip.
    $item_ids = [];
    foreach ($rows as $row) {
      $candidate = is_array($row) ? ($row['id'] ?? NULL) : NULL;
      if (is_numeric($candidate)) {
        $item_ids[] = (int) $candidate;
      }
    }
    $item_ids = array_values(array_unique($item_ids));
    if ($item_ids === []) {
      return $response;
    }

    $timelines = $this->loadTimelineCollections($item_ids);

    //3.- Attach the stored entries to the response payload so the preview mirrors chronological data.
    foreach ($rows as $index => $row) {
      $id = is_array($row) && isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
      if (!$id) {
        continue;
      }
      $rows[$index]['timeline'] = $timelines[$id] ?? [];
    }

    $data['rows'] = $rows;
    $response->setData($data);

    return $response;
  }

  /**
   * Update an existing row and mirror timeline changes into the auxiliary table.
   */
  public function update(Request $request, string $uuid, string $row_uuid): JsonResponse {
    //1.- Skip the decorator when editors update recipes that do not leverage the timeline dataset.
    if (!$this->requestTargetsTimeline($request)) {
      return $this->baseController()->update($request, $uuid, $row_uuid);
    }

    $payload = $this->decodePayload($request);
    $timeline = $this->extractTimelineEntries($payload);

    //1.1.- Normalize the UUID even when legacy rows only expose numeric identifiers.
    $effective_uuid = $this->resolveRowUuid($payload, $row_uuid);
    if ($effective_uuid === NULL) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Row identifier is missing.',
      ], 400);
    }
    if ($effective_uuid !== $row_uuid) {
      $row_uuid = $effective_uuid;
      $request->attributes->set('row_uuid', $row_uuid);
    }

    $response = $this->baseController()->update($request, $uuid, $row_uuid);
    if ($response->getStatusCode() !== 200) {
      return $response;
    }

    $data = $this->decodeResponse($response);
    if (($data['status'] ?? '') !== 'ok') {
      return $response;
    }

    $item_id = (int) ($data['id'] ?? 0);
    if ($item_id > 0) {
      //2.- Replace the stored timeline entries with the freshly submitted dataset.
      $stored = $this->syncTimeline($item_id, $timeline);
      if (isset($data['row']) && is_array($data['row'])) {
        $data['row']['timeline'] = $stored;
      }
      $response->setData($data);
    }

    return $response;
  }

  /**
   * Determine whether the current request targets the timeline recipe type.
   */
  private function requestTargetsTimeline(Request $request): bool {
    //1.- Check the explicit query parameter because AJAX endpoints always append the recipe type.
    $type = $request->query->get('type');
    if (is_string($type) && $type !== '') {
      return $type === 'pds_recipe_timeline';
    }

    //2.- Inspect the JSON payload when the caller omitted the query string (e.g., direct service usage).
    $payload = $this->decodePayload($request);
    $candidate = $payload['recipe_type'] ?? ($payload['row']['recipe_type'] ?? NULL);
    if (is_string($candidate) && $candidate !== '') {
      return $candidate === 'pds_recipe_timeline';
    }

    //3.- Detect timeline intents when integrators forget to send the explicit recipe type but include timeline data.
    $row = $payload['row'] ?? NULL;
    if (is_array($row) && array_key_exists('timeline', $row)) {
      return TRUE;
    }
    if (array_key_exists('timeline', $payload)) {
      return is_array($payload['timeline']);
    }

    return FALSE;
  }

  /**
   * Lazily resolve the shared controller so core logic remains delegated.
   */
  private function baseController(): BaseRowController {
    //1.- Cache the instance in a static variable to avoid repeated class resolver lookups per request.
    static $controller;
    if (!$controller instanceof BaseRowController) {
      $controller = \Drupal::classResolver()->getInstanceFromDefinition(BaseRowController::class);
    }

    return $controller;
  }

  /**
   * Decode the JSON payload once per request to reduce repeated parsing work.
   */
  private function decodePayload(Request $request): array {
    //1.- Reuse cached decoding stored on the request to keep repeated operations inexpensive.
    $cached = $request->attributes->get(self::TIMELINE_REQUEST_MARKER);
    if (is_array($cached)) {
      return $cached;
    }

    $decoded = [];
    try {
      $raw = $request->getContent();
      if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      }
    }
    catch (JsonException $exception) {
      $decoded = [];
    }

    if (!is_array($decoded)) {
      $decoded = [];
    }

    //2.- Persist the parsed payload on the request object so subsequent lookups reuse it.
    $request->attributes->set(self::TIMELINE_REQUEST_MARKER, $decoded);

    return $decoded;
  }

  /**
   * Resolve the canonical row UUID even when callers provide numeric identifiers.
   */
  private function resolveRowUuid(array $payload, string $fallback): ?string {
    //1.- Honor valid UUIDs coming from the request payload or routing fallback immediately.
    if (is_string($fallback) && Uuid::isValid($fallback)) {
      return $fallback;
    }

    $row = $payload['row'] ?? [];
    if (isset($row['uuid']) && is_string($row['uuid']) && Uuid::isValid($row['uuid'])) {
      return $row['uuid'];
    }

    //2.- Extract the numeric id from either the top-level payload or the nested row definition.
    $id = NULL;
    if (isset($payload['row_id']) && is_numeric($payload['row_id'])) {
      $id = (int) $payload['row_id'];
    }
    elseif (isset($row['id']) && is_numeric($row['id'])) {
      $id = (int) $row['id'];
    }
    if (!$id) {
      return NULL;
    }

    //3.- Look up the UUID associated with the provided id while ignoring soft-deleted records.
    $connection = \Drupal::database();
    $uuid = $connection->select('pds_template_item', 'i')
      ->fields('i', ['uuid'])
      ->condition('i.id', $id)
      ->condition('i.deleted_at', NULL, 'IS NULL')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return is_string($uuid) && Uuid::isValid($uuid) ? $uuid : NULL;
  }

  /**
   * Extract sanitized timeline entries from the decoded payload.
   */
  private function extractTimelineEntries(array $payload): array {
    //1.- Locate the timeline collection under the row definition so admin requests remain compatible.
    $timeline = [];
    if (isset($payload['row']) && is_array($payload['row'])) {
      $timeline = $payload['row']['timeline'] ?? [];
    }
    elseif (isset($payload['timeline']) && is_array($payload['timeline'])) {
      $timeline = $payload['timeline'];
    }

    if (!is_array($timeline) || $timeline === []) {
      return [];
    }

    $clean = [];
    foreach ($timeline as $delta => $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $year = $entry['year'] ?? NULL;
      if (is_string($year) && is_numeric($year)) {
        $year = (int) $year;
      }
      elseif (is_numeric($year)) {
        $year = (int) $year;
      }
      else {
        $year = 0;
      }

      $label = isset($entry['label']) && is_scalar($entry['label'])
        ? trim((string) $entry['label'])
        : '';
      if ($label === '' && $year === 0) {
        continue;
      }

      $weight = $entry['weight'] ?? $delta;
      if (is_string($weight) && is_numeric($weight)) {
        $weight = (int) $weight;
      }
      elseif (is_numeric($weight)) {
        $weight = (int) $weight;
      }
      else {
        $weight = $delta;
      }

      $clean[] = [
        'year' => $year,
        'label' => $label,
        'weight' => $weight,
      ];
    }

    //2.- Sort by weight to provide a deterministic order across saves regardless of payload order.
    usort($clean, static function (array $a, array $b): int {
      return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
    });

    //3.- Reindex the weights sequentially so stored data keeps consistent deltas without gaps.
    foreach ($clean as $index => &$item) {
      $item['weight'] = $index;
    }
    unset($item);

    return $clean;
  }

  /**
   * Persist the provided timeline entries for the given template item id.
   */
  private function syncTimeline(int $item_id, array $timeline): array {
    //1.- Guarantee the storage table exists before attempting to mutate timeline entries for the item.
    if (!$this->ensureTimelineTableExists()) {
      return $timeline;
    }

    $connection = \Drupal::database();

    $now = \Drupal::time()->getRequestTime();

    //2.- Soft-delete previous active entries before inserting the fresh dataset.
    $connection->update(self::TIMELINE_TABLE)
      ->fields(['deleted_at' => $now])
      ->condition('item_id', $item_id)
      ->condition('deleted_at', NULL, 'IS NULL')
      ->execute();

    $stored = [];
    foreach ($timeline as $delta => $entry) {
      $label = $entry['label'] ?? '';
      if (!is_string($label)) {
        $label = (string) $label;
      }
      $label = trim($label);
      if ($label === '') {
        continue;
      }

      $year = isset($entry['year']) && is_numeric($entry['year'])
        ? (int) $entry['year']
        : 0;

      $weight = isset($entry['weight']) && is_numeric($entry['weight'])
        ? (int) $entry['weight']
        : $delta;

      $connection->insert(self::TIMELINE_TABLE)
        ->fields([
          'item_id' => $item_id,
          'year' => $year,
          'label' => $label,
          'weight' => $weight,
          'created_at' => $now,
          'deleted_at' => NULL,
        ])
        ->execute();

      $stored[] = [
        'year' => $year,
        'label' => $label,
        'weight' => $weight,
      ];
    }

    //3.- Present the stored dataset sorted by weight to mirror rendering order everywhere.
    usort($stored, static function (array $a, array $b): int {
      return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
    });

    return $stored;
  }

  /**
   * Load timeline collections for the provided item identifiers.
   */
  private function loadTimelineCollections(array $item_ids): array {
    //1.- Abort quickly when the storage table is missing or no items were requested.
    if ($item_ids === []) {
      return [];
    }

    if (!$this->ensureTimelineTableExists()) {
      return [];
    }

    $connection = \Drupal::database();

    $query = $connection->select(self::TIMELINE_TABLE, 't')
      ->fields('t', ['item_id', 'year', 'label', 'weight'])
      ->condition('t.item_id', $item_ids, 'IN')
      ->condition('t.deleted_at', NULL, 'IS NULL')
      ->orderBy('t.item_id', 'ASC')
      ->orderBy('t.weight', 'ASC');

    $result = $query->execute();
    $collections = [];
    foreach ($result as $record) {
      $item_id = (int) $record->item_id;
      if (!isset($collections[$item_id])) {
        $collections[$item_id] = [];
      }

      $collections[$item_id][] = [
        'year' => (int) $record->year,
        'label' => (string) $record->label,
        'weight' => (int) $record->weight,
      ];
    }

    return $collections;
  }

  /**
   * Decode the existing JsonResponse content into an array structure.
   */
  private function decodeResponse(JsonResponse $response): array {
    try {
      $data = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($data) ? $data : [];
    }
    catch (JsonException $exception) {
      return [];
    }
  }

  /**
   * Create the timeline table on demand when the installer has not provisioned it.
   */
  private function ensureTimelineTableExists(): bool {
    //1.- Look up the schema manager and short-circuit when the table is already available.
    $connection = \Drupal::database();
    $schema = $connection->schema();
    if ($schema->tableExists(self::TIMELINE_TABLE)) {
      return TRUE;
    }

    //2.- Describe the schema mirroring the installer definition so late creations stay consistent.
    $definition = [
      'description' => 'Timeline entries linked to template items for chronological rendering.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'item_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'year' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'label' => [
          'type' => 'varchar',
          'length' => 512,
          'not null' => TRUE,
          'default' => '',
        ],
        'weight' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'created_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'deleted_at' => [
          'type' => 'int',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'item_id' => ['item_id'],
        'year' => ['year'],
        'weight' => ['weight'],
        'deleted_at' => ['deleted_at'],
      ],
    ];

    try {
      //3.- Provision the table dynamically so timeline saves succeed even on legacy installs.
      $schema->createTable(self::TIMELINE_TABLE, $definition);
      return TRUE;
    }
    catch (Throwable $exception) {
      //4.- Record the failure and inform callers that persistence must be skipped for now.
      \Drupal::logger('pds_recipe_timeline')->error('Unable to create the timeline table automatically: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

}
