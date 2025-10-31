<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Drupal\pds_recipe_template\Service\RowImagePromoter;
use Drupal\pds_recipe_template\Service\GroupEnsurer;
use Drupal\Core\Database\Query\SelectInterface;

final class TemplateRepository {

  /**
   * INPUT: ID-first repository. No UuidInterface.
   * WHY: Use primary keys for lookups and writes.
   */
  public function __construct(
    private Connection $db,
    private TimeInterface $time,
    private LoggerInterface $logger,
    private RowImagePromoter $promoter,
    private GroupEnsurer $ensurer,
  ) {}

  /** Cache whether the group table exposes the "type" column. */
  private ?bool $groupHasTypeColumn = null;

  /* =========================
   * Helpers
   * ========================= */

  /** Attach an "active rows" condition tolerant of legacy placeholders to a query. */
  private function addActiveCondition(SelectInterface $q, string $alias = 'i'): void {
    $or = $q->orConditionGroup()
      ->isNull("$alias.deleted_at")
      ->condition("$alias.deleted_at", '', '=')
      ->condition("$alias.deleted_at", '0', '=')
      ->condition("$alias.deleted_at", 0, '=');
    $q->condition($or);
  }

  /** Attach a relaxed type condition (strict type OR legacy null/empty) to a query. */
  private function addTypeCondition(SelectInterface $q, string $alias, ?string $type): void {
    if ($type === null || $type === '') {
      return; // no type filtering
    }
    $or = $q->orConditionGroup()
      ->condition("$alias.type", $type, '=')
      ->isNull("$alias.type")
      ->condition("$alias.type", '', '=');
    $q->condition($or);
  }

  /* =========================
   * GROUP LOAD
   * ========================= */

  /**
   * Load an active group row by numeric id.
   */
  public function loadActiveGroupById(int $groupId): ?array {
    if ($groupId <= 0) {
      return null;
    }

    $query = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['id', 'uuid']) // uuid kept for legacy reads only
      ->condition('g.id', $groupId)
      ->range(0, 1);

    if ($this->groupTableSupportsType()) {
      // 1) Request the recipe discriminator when available to keep editors scoped.
      $query->addField('g', 'type');
    }

    $this->addActiveCondition($query, 'g');

    $record = $query->execute()->fetchAssoc();
    if (!$record) {
      return null;
    }

    return [
      'id'   => (int) ($record['id'] ?? 0),
      'uuid' => is_string($record['uuid'] ?? null) ? (string) $record['uuid'] : '',
      'type' => isset($record['type']) && is_string($record['type']) ? (string) $record['type'] : null,
    ];
  }

  /** Ensure a group row stores the provided recipe type (when column exists). */
  public function ensureGroupTypeValue(int $groupId, string $type): bool {
    if ($groupId <= 0 || $type === '' || !$this->groupTableSupportsType()) {
      return true;
    }

    $current = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['type'])
      ->condition('g.id', $groupId)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $stored = is_string($current) ? trim($current) : '';

    if ($stored === '') {
      // 2) Backfill missing type values to keep legacy rows aligned.
      $this->db->update('pds_template_group')
        ->fields(['type' => $type])
        ->condition('id', $groupId)
        ->execute();
      return true;
    }

    if ($stored !== $type) {
      // 3) Reject conflicting assignments but record the mismatch for troubleshooting.
      $this->logger->warning('Group @id type mismatch: expected @expected, found @stored.', [
        '@id' => $groupId,
        '@expected' => $type,
        '@stored' => $stored,
      ]);
      return false;
    }
    return true;
  }

  /**
   * Ensure group and return its id.
   * INPUT: numeric ID and recipe type.
   * NOTE: Ensurer is ID-based now.
   */
  public function ensureGroupAndGetId(int $id, string $type): int {
    return (int) $this->ensurer->ensureGroupAndGetId($id, $type);
  }

  /* ============ ROW DELETE ============ */

  /**
   * Soft-delete a row by (group_id, row_id).
   * WHY: ID-first. No UUID lookups.
   */
  public function softDeleteRowByIds(int $groupId, int $rowId): bool {
    if ($groupId <= 0 || $rowId <= 0) {
      return false;
    }
    $now = $this->time->getRequestTime();
    $updated = $this->db->update('pds_template_item')
      ->fields(['deleted_at' => $now])
      ->condition('group_id', $groupId)
      ->condition('id', $rowId)
      ->execute();

    return ((int) $updated) > 0;
  }

  /* =========== ITEM LOAD =========== */

  public function loadItems(int $groupId): array {
    if ($groupId <= 0) {
      return [];
    }

    $schema = $this->db->schema();
    $table  = 'pds_template_item';

    if (!$schema->tableExists($table)) {
      return [];
    }

    // Keep uuid in reads for legacy UI tolerance. Do not depend on it.
    $wanted = [
      'id','uuid','weight','header','subheader','description','url',
      'desktop_img','mobile_img','latitud','longitud',
    ];

    static $presentCache = [];
    if (!isset($presentCache[$table])) {
      $present = [];
      foreach ($wanted as $f) {
        if ($schema->fieldExists($table, $f)) {
          $present[] = $f;
        }
      }
      $presentCache[$table] = $present;
    }
    $present = $presentCache[$table];

    $query = $this->db->select($table, 'i')
      ->fields('i', $present)
      ->condition('i.group_id', $groupId);

    // Tolerant active filter if column exists.
    if ($schema->fieldExists($table, 'deleted_at')) {
      $this->addActiveCondition($query, 'i');
    }

    if ($schema->fieldExists($table, 'weight')) {
      $query->orderBy('i.weight', 'ASC');
    }

    $result = $query->execute();

    $rows = [];
    foreach ($result as $r) {
      $get = static function ($prop) use ($r, $present) {
        return in_array($prop, $present, true) ? ($r->$prop ?? null) : null;
      };

      $desktop = (string) ($get('desktop_img') ?? '');
      $mobile  = (string) ($get('mobile_img')  ?? '');
      $primary = $desktop !== '' ? $desktop : $mobile;

      $weightRaw = $get('weight');
      $weight    = (is_numeric($weightRaw) ? (int) $weightRaw : 0);

      $rows[] = [
        'id'          => (int) ($get('id') ?? 0),
        'uuid'        => (string) ($get('uuid') ?? ''), // legacy read-only
        'weight'      => $weight,
        'header'      => (string) ($get('header')    ?? ''),
        'subheader'   => (string) ($get('subheader') ?? ''),
        'description' => (string) ($get('description') ?? ''),
        'link'        => (string) ($get('url') ?? ''),
        'desktop_img' => $desktop,
        'mobile_img'  => $mobile,
        'image_url'   => $primary,
        'thumbnail'   => $primary,
        'latitud'     => ($get('latitud')  !== null ? (float) $get('latitud')  : null),
        'longitud'    => ($get('longitud') !== null ? (float) $get('longitud') : null),
      ];
    }

    return $rows;
  }

  /* ============ ITEM UPSERT ============ */

  /**
   * Upsert items by numeric ID. UUID is optional and never generated.
   * POLICY: Prefer ID. If ID missing, insert a new row.
   */
  public function upsertItems(int $groupId, array $cleanItems, int $nowUnix): array {
    if ($groupId <= 0) {
      return [];
    }

    $schema = $this->db->schema();
    $table  = 'pds_template_item';
    $hasUuidCol = $schema->fieldExists($table, 'uuid');

    // Map existing rows by ID only.
    $records = $this->db->select($table, 'i')
      ->fields('i', ['id'])
      ->condition('group_id', $groupId)
      ->execute();

    $existingIds = [];
    foreach ($records as $r) {
      $existingIds[(int) $r->id] = true;
    }

    $keptIds  = [];
    $snapshot = [];

    foreach ($cleanItems as $delta => $row) {
      $candidateId = (isset($row['id']) && is_numeric($row['id'])) ? (int) $row['id'] : null;

      if ($candidateId && isset($existingIds[$candidateId])) {
        // Reactivate on update: always set deleted_at = NULL.
        $this->db->update($table)
          ->fields([
            'weight'      => $delta,
            'header'      => $row['header']      ?? '',
            'subheader'   => $row['subheader']   ?? '',
            'description' => $row['description'] ?? '',
            'url'         => $row['link']        ?? '',
            'desktop_img' => $row['desktop_img'] ?? '',
            'mobile_img'  => $row['mobile_img']  ?? '',
            'latitud'     => $row['latitud']     ?? null,
            'longitud'    => $row['longitud']    ?? null,
            'deleted_at'  => null,
          ])
          ->condition('id', $candidateId)
          ->execute();

        $resolvedId = $candidateId;
      } else {
        // Insert. Do NOT generate UUIDs. If a uuid value was provided and column exists, persist it as-is.
        $fields = [
          'group_id'    => $groupId,
          'weight'      => $delta,
          'header'      => $row['header']      ?? '',
          'subheader'   => $row['subheader']   ?? '',
          'description' => $row['description'] ?? '',
          'url'         => $row['link']        ?? '',
          'desktop_img' => $row['desktop_img'] ?? '',
          'mobile_img'  => $row['mobile_img']  ?? '',
          'latitud'     => $row['latitud']     ?? null,
          'longitud'    => $row['longitud']    ?? null,
          'created_at'  => $nowUnix,
          'deleted_at'  => null,
        ];
        if ($hasUuidCol && !empty($row['uuid']) && is_string($row['uuid'])) {
          // Legacy tolerance only. No validation, no generation.
          $fields['uuid'] = $row['uuid'];
        }

        $resolvedId = (int) $this->db->insert($table)
          ->fields($fields)
          ->execute();
      }

      $keptIds[] = $resolvedId;
      $snapshot[] = [
        'header'      => $row['header']      ?? '',
        'subheader'   => $row['subheader']   ?? '',
        'description' => $row['description'] ?? '',
        'link'        => $row['link']        ?? '',
        'desktop_img' => $row['desktop_img'] ?? '',
        'mobile_img'  => $row['mobile_img']  ?? '',
        'latitud'     => $row['latitud']     ?? null,
        'longitud'    => $row['longitud']    ?? null,
        'id'          => $resolvedId,
        // uuid echoed back only if client sent it and column exists.
        'uuid'        => (!empty($row['uuid']) && is_string($row['uuid'])) ? $row['uuid'] : '',
      ];
    }

    // Soft-delete anything not kept.
    $upd = $this->db->update($table)
      ->fields(['deleted_at' => $nowUnix])
      ->condition('group_id', $groupId);

    if ($keptIds !== []) {
      $upd->condition('id', $keptIds, 'NOT IN');
    }
    $upd->execute();

    return $snapshot;
  }

  // ---------------- JSON → ARRAY ----------------
  public function decodeJsonToArray(?string $raw): array {
    if (!is_string($raw) || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
  }

  // ---------------- ITEM NORMALIZATION ----------------
  /**
   * Normalize client payload.
   * POLICY: Keep 'id'. 'uuid' is accepted but ignored unless inserting with legacy col present.
   */
  public function normalizeSubmittedItems(array $items): array {
    $clean = [];
    foreach ($items as $delta => $item) {
      if (!is_array($item)) { continue; }

      $header = trim($item['header'] ?? '');
      if ($header === '') { continue; }

      $subheader   = trim($item['subheader']   ?? '');
      $description = trim($item['description'] ?? '');
      $link        = trim($item['link']        ?? '');
      $fid         = $item['image_fid']        ?? null;
      $desktop_img = trim((string) ($item['desktop_img'] ?? ''));
      $mobile_img  = trim((string) ($item['mobile_img']  ?? ''));
      $image_url   = trim((string) ($item['image_url']   ?? ''));
      $stored_id   = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;

      if ($desktop_img === '' && $image_url !== '') { $desktop_img = $image_url; }
      if ($mobile_img  === '' && $image_url !== '') { $mobile_img  = $image_url; }

      if (($desktop_img === '' || $mobile_img === '') && $fid) {
        try {
          $res = $this->promoter->promote(['image_fid' => $fid]);
          if (($res['status'] ?? '') === 'ok') {
            $resolved = (string) ($res['image_url'] ?? '');
            if ($desktop_img === '' && $resolved !== '') { $desktop_img = $resolved; }
            if ($mobile_img  === '' && $resolved !== '') { $mobile_img  = $resolved; }
            if ($image_url   === '' && $resolved !== '') { $image_url   = $resolved; }
          }
        } catch (\Throwable $e) {
          $this->logger->warning('normalizeSubmittedItems: promoter failed: @m', ['@m' => $e->getMessage()]);
        }
      }

      $clean[] = [
        'header'      => $header,
        'subheader'   => $subheader,
        'description' => $description,
        'link'        => $link,
        'desktop_img' => $desktop_img,
        'mobile_img'  => $mobile_img,
        'latitud'     => $item['latitud']  ?? null,
        'longitud'    => $item['longitud'] ?? null,
        'id'          => $stored_id,
        // Keep any provided uuid verbatim for legacy insert tolerance.
        'uuid'        => is_string($item['uuid'] ?? null) ? trim((string) $item['uuid']) : '',
      ];
    }

    return $clean;
  }

  // ---------------- AJAX ROW IMAGE PROMOTION ----------------
  public function promoteRowPayload(array $payload): array {
    try {
      $result = $this->promoter->promote($payload);
      if (!is_array($result)) {
        return ['status' => 'error', 'code' => 500, 'message' => 'Unexpected promoter response.'];
      }
      return $result + [
        'status'      => $result['status'] ?? 'error',
        'code'        => $result['code']   ?? 500,
        'message'     => $result['message'] ?? 'Unknown error.',
        'image_url'   => $result['image_url']   ?? '',
        'image_fid'   => $result['image_fid']   ?? null,
        'desktop_img' => $result['desktop_img'] ?? '',
        'mobile_img'  => $result['mobile_img']  ?? '',
      ];
    } catch (\Throwable $e) {
      $this->logger->error('Row image promotion failed: @m', ['@m' => $e->getMessage()]);
      return ['status' => 'error', 'code' => 500, 'message' => 'Unable to promote image.'];
    }
  }

  /** Return a safe recipe type from query/body/default, filtered by a whitelist. */
  public function resolveRecipeType(?string $candidate, ?string $fallback = 'pds_recipe_template'): string {
    $allowed = [
      'pds_recipe_template',
      'pds_recipe_executives',
      'pds_recipe_formas_de_invertir',
    ];
    $type = is_string($candidate) && $candidate !== '' ? $candidate : (string) $fallback;
    return in_array($type, $allowed, true) ? $type : 'pds_recipe_template';
  }

  /** Detect whether the group table contains a "type" column. */
  public function groupTableSupportsType(): bool {
    if ($this->groupHasTypeColumn === null) {
      try {
        // 4) Cache schema introspection so each request only probes the database once.
        $this->groupHasTypeColumn = $this->db->schema()->fieldExists('pds_template_group', 'type');
      }
      catch (\Throwable $throwable) {
        // 5) Degrade gracefully on broken schemas while flagging the issue for administrators.
        $this->logger->warning('Unable to detect pds_template_group.type column: @message', [
          '@message' => $throwable->getMessage(),
        ]);
        $this->groupHasTypeColumn = false;
      }
    }

    return (bool) $this->groupHasTypeColumn;
  }
}
