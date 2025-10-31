<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template;

use Drupal\Core\Database\Connection;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Drupal\pds_recipe_template\Service\RowImagePromoter;
use Drupal\pds_recipe_template\Service\GroupEnsurer;
use Drupal\Core\Database\Query\SelectInterface;

final class TemplateRepository {

  public function __construct(
    private Connection $db,
    private UuidInterface $uuid,
    private TimeInterface $time,
    private LoggerInterface $logger,
    private RowImagePromoter $promoter,
    private GroupEnsurer $ensurer,
  ) {}

  /* =========================
   * Helpers
   * ========================= */

  /** Build an "active rows" condition tolerant of legacy placeholders. */
  private function buildActiveCondition(string $alias = 'i') {
    $or = $this->db->orConditionGroup()
      ->isNull("$alias.deleted_at")
      ->condition("$alias.deleted_at", '', '=')
      ->condition("$alias.deleted_at", '0', '=')
      ->condition("$alias.deleted_at", 0, '=');
    return $or;
  }

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


  /** Build a relaxed type condition (strict type OR legacy null/empty). */
  private function buildTypeCondition(string $alias, ?string $type) {
    if ($type === null || $type === '') {
      // No type filter at all.
      return null;
    }
    return $this->db->orConditionGroup()
      ->condition("$alias.type", $type, '=')
      ->isNull("$alias.type")
      ->condition("$alias.type", '', '=');
  }

  /* =========================
   * UUID + GROUP RESOLUTION
   * ========================= */

  public function resolveInstanceUuid(?string $storedUuid, ?int $storedGroupId): string {
    $storedUuid = is_string($storedUuid) ? trim($storedUuid) : '';
    if ($storedUuid !== '' && Uuid::isValid($storedUuid)) {
      return $storedUuid;
    }

    if (is_int($storedGroupId) && $storedGroupId > 0) {
      $groupUuid = $this->getUuidByGroupId($storedGroupId);
      if (is_string($groupUuid) && $groupUuid !== '' && Uuid::isValid($groupUuid)) {
        return $groupUuid;
      }
      if ($groupUuid !== null) {
        $replacement = $this->uuid->generate();
        $this->setUuidForGroup($storedGroupId, $replacement);
        return $replacement;
      }
    }
    return $this->uuid->generate();
  }

  /**
   * Resolve group id by UUID (optionally scoped by recipe type with legacy fallback).
   */
  public function getGroupIdByUuid(string $uuid, ?string $type = null): int {
    if ($uuid === '' || !Uuid::isValid($uuid)) {
      return 0;
    }

    $q = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.uuid', $uuid)
      ->range(0, 1);

    $this->addActiveCondition($q, 'g');     // <-- changed
    $this->addTypeCondition($q, 'g', $type);// <-- changed

    $id = $q->execute()->fetchField();
    if (is_numeric($id)) {
      return (int) $id;
    }

    // Retry without type if strict match failed.
    if ($type !== null && $type !== '') {
      $q2 = $this->db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $uuid)
        ->range(0, 1);
      $this->addActiveCondition($q2, 'g');  // <-- changed
      $id2 = $q2->execute()->fetchField();
      return is_numeric($id2) ? (int) $id2 : 0;
    }

    return 0;
  }


  public function getUuidByGroupId(int $groupId): ?string {
    $q = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['uuid'])
      ->condition('g.id', $groupId)
      ->range(0, 1);

    $this->addActiveCondition($q, 'g');   // <-- changed

    $uuid = $q->execute()->fetchField();
    if ($uuid === false) {
      return null;
    }
    return (string) $uuid;
  }


  public function setUuidForGroup(int $groupId, string $uuid): void {
    if ($groupId <= 0 || $uuid === '' || !Uuid::isValid($uuid)) {
      return;
    }
    $this->db->update('pds_template_group')
      ->fields(['uuid' => $uuid])
      ->condition('id', $groupId)
      ->execute();
  }

  public function ensureGroupAndGetId(string $uuid, string $type): int {
    return (int) $this->ensurer->ensureGroupAndGetId($uuid, $type);
  }

  public function resolveGroupId(string $uuid, ?int $legacyGroupId): int {
    // Try with the canonical type first (if ensurer knows it), but keep compatibility by calling without type.
    $groupId = $this->getGroupIdByUuid($uuid /*, optional type could be added here */);
    if ($groupId > 0) {
      return $groupId;
    }

    if (is_int($legacyGroupId) && $legacyGroupId > 0) {
      $legacy = $this->getUuidByGroupId($legacyGroupId);
      if ($legacy !== null) {
        if ($uuid !== '' && (!is_string($legacy) || !Uuid::isValid((string) $legacy) || (string) $legacy !== $uuid)) {
          $this->setUuidForGroup($legacyGroupId, $uuid);
        }
        return $legacyGroupId;
      }
    }
    return 0;
  }

  /**
   * Soft-delete a row by (group uuid, row uuid).
   * Accepts legacy states (''/'0'/0) as “not deleted” and will set a timestamp.
   */
    public function softDeleteRowByGroupAndRowUuid(string $groupUuid, string $rowUuid): bool {
      if (!Uuid::isValid($groupUuid) || !Uuid::isValid($rowUuid)) {
        return false;
      }

      $q = $this->db->select('pds_template_group', 'g')
        ->fields('g', ['id'])
        ->condition('g.uuid', $groupUuid)
        ->range(0, 1);
      $this->addActiveCondition($q, 'g');   // <-- changed

      $gid = $q->execute()->fetchField();
      if (!$gid) {
        return false;
      }

      $now = $this->time->getRequestTime();

      // No IS NULL guard here — legacy placeholders are allowed to be overwritten.
      $updated = $this->db->update('pds_template_item')
        ->fields(['deleted_at' => $now])
        ->condition('uuid', $rowUuid)
        ->condition('group_id', (int) $gid)
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
      $this->addActiveCondition($query, 'i');   // <-- changed
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

      $rawUuid = (string) ($get('uuid') ?? '');
      $uuid    = ($rawUuid !== '' && Uuid::isValid($rawUuid)) ? $rawUuid : '';

      $weightRaw = $get('weight');
      $weight    = (is_numeric($weightRaw) ? (int) $weightRaw : 0);

      $rows[] = [
        'id'          => (int) ($get('id') ?? 0),
        'uuid'        => $uuid,
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

  public function upsertItems(int $groupId, array $cleanItems, int $nowUnix): array {
    if ($groupId <= 0) {
      return [];
    }

    $existingByUuid = [];
    $existingById   = [];
    $records = $this->db->select('pds_template_item', 'i')
      ->fields('i', ['id', 'uuid'])
      ->condition('group_id', $groupId)
      // Only consider active rows when mapping existing → prevents reviving deleted by mistake,
      // but we will explicitly NULL deleted_at on update below.
      ->execute();

    foreach ($records as $r) {
      $existingById[(int) $r->id] = (string) $r->uuid;
      if (is_string($r->uuid) && $r->uuid !== '') {
        $existingByUuid[(string) $r->uuid] = (int) $r->id;
      }
    }

    $keptIds  = [];
    $snapshot = [];

    foreach ($cleanItems as $delta => $row) {
      $uuid = (isset($row['uuid']) && is_string($row['uuid']) && Uuid::isValid($row['uuid'])) ? $row['uuid'] : '';
      $candidateId = (isset($row['id']) && is_numeric($row['id'])) ? (int) $row['id'] : null;

      $resolvedId = null;
      if ($uuid !== '' && isset($existingByUuid[$uuid])) {
        $resolvedId = $existingByUuid[$uuid];
      }
      elseif ($candidateId && isset($existingById[$candidateId])) {
        $resolvedId = $candidateId;
        $uuid = $existingById[$candidateId] ?? $uuid;
      }

      if ($resolvedId) {
        // Reactivate on update: always set deleted_at = NULL.
        $this->db->update('pds_template_item')
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
          ->condition('id', $resolvedId)
          ->execute();
      }
      else {
        $uuid = $uuid !== '' ? $uuid : $this->uuid->generate();
        $resolvedId = (int) $this->db->insert('pds_template_item')
          ->fields([
            'uuid'        => $uuid,
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
          ])
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
        'uuid'        => $uuid,
      ];
    }

    // Soft-delete anything not kept (regardless of its previous placeholder value).
    $upd = $this->db->update('pds_template_item')
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
      $stored_id   = isset($item['id'])   && is_numeric($item['id'])   ? (int) $item['id']   : null;
      $stored_uuid = isset($item['uuid']) && is_string($item['uuid'])  ? trim($item['uuid']) : '';

      if ($stored_uuid !== '' && !Uuid::isValid($stored_uuid)) {
        $stored_uuid = '';
      }

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
        'uuid'        => $stored_uuid,
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
}
