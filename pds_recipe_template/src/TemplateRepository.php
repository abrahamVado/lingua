<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template;

use Drupal\Core\Database\Connection;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;            // + DI
use Drupal\Component\Datetime\TimeInterface;        // + DI
use Psr\Log\LoggerInterface;                        // + DI
use Drupal\pds_recipe_template\Service\RowImagePromoter; // + DI
use Drupal\pds_recipe_template\Service\GroupEnsurer;

/**
 * TemplateRepository
 * Single source of truth for DB access and legacy-repair logic.
 */
final class TemplateRepository {

  public function __construct(
    private Connection $db,
    private UuidInterface $uuid,            // + DI
    private TimeInterface $time,            // + DI
    private LoggerInterface $logger,        // + DI
    private RowImagePromoter $promoter,     // + DI
    private GroupEnsurer $ensurer,
  ) {}

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
        // Row exists but no/invalid uuid → repair row.
        $replacement = $this->uuid->generate();            // CHANGED: no Drupal::service
        $this->setUuidForGroup($storedGroupId, $replacement);
        return $replacement;
      }
    }

    // Brand-new.
    return $this->uuid->generate();                        // CHANGED
  }

  public function getGroupIdByUuid(string $uuid): int {
    if ($uuid === '' || !Uuid::isValid($uuid)) {
      return 0;
    }
    $id = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.uuid', $uuid)
      ->condition('g.deleted_at', null, 'IS NULL')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return is_numeric($id) ? (int) $id : 0;
  }

  public function getUuidByGroupId(int $groupId): ?string {
    $uuid = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['uuid'])
      ->condition('g.id', $groupId)
      ->condition('g.deleted_at', null, 'IS NULL')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($uuid === false) {
      return null; // no row
    }
    return (string) $uuid; // may be '' (legacy)
  }

  public function setUuidForGroup(int $groupId, string $uuid): void {
    if ($groupId <= 0 || $uuid === '' || !Uuid::isValid($uuid)) {
      return;
    }
    $this->db->update('pds_template_group')
      ->fields(['uuid' => $uuid])
      ->condition('id', $groupId)
      ->condition('deleted_at', null, 'IS NULL')
      ->execute();
  }

  public function ensureGroupAndGetId(string $uuid, string $type): int {
    return (int) $this->ensurer->ensureGroupAndGetId($uuid, $type);
  }

  public function resolveGroupId(string $uuid, ?int $legacyGroupId): int {
    $groupId = $this->getGroupIdByUuid($uuid);
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
   *
   * Ensures the row belongs to that group; sets deleted_at if not already set.
   */
  public function softDeleteRowByGroupAndRowUuid(string $groupUuid, string $rowUuid): bool {
    if (!\Drupal\Component\Uuid\Uuid::isValid($groupUuid) || !\Drupal\Component\Uuid\Uuid::isValid($rowUuid)) {
      return false;
    }

    $gid = $this->db->select('pds_template_group', 'g')
      ->fields('g', ['id'])
      ->condition('g.uuid', $groupUuid)
      ->condition('g.deleted_at', NULL, 'IS NULL')
      ->execute()
      ->fetchField();

    if (!$gid) {
      return false;
    }

    $now = $this->time->getRequestTime(); 

    $updated = $this->db->update('pds_template_item')
      ->fields(['deleted_at' => $now])
      ->condition('uuid', $rowUuid)
      ->condition('group_id', (int) $gid)
      ->isNull('deleted_at')
      ->execute();

    return ((int) $updated) > 0;
  }


  /* =========== ITEM LOAD =========== */
  public function loadItems(int $groupId): array {
    // 1) Quick rejects.
    if ($groupId <= 0) {
      return [];
    }

    $schema = $this->db->schema();
    $table  = 'pds_template_item';

    // 2) If the table itself is missing, return empty (controller can decide what to do).
    if (!$schema->tableExists($table)) {
      return [];
    }

    // 3) Expected fields in ideal/latest schema.
    $wanted = [
      'id','uuid','weight','header','subheader','description','url',
      'desktop_img','mobile_img','latitud','longitud',
    ];

    // 4) Discover which fields actually exist *now* to avoid selecting non-existent columns.
    //    Use a static cache per request to keep it cheap if called multiple times.
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

    // 5) Build the base query using only present fields.
    $query = $this->db->select($table, 'i')
      ->fields('i', $present)
      ->condition('i.group_id', $groupId);

    // 6) Add soft-delete filter only if the column exists in this environment.
    if ($schema->fieldExists($table, 'deleted_at')) {
      $query->condition('i.deleted_at', NULL, 'IS NULL');
    }

    // 7) Keep ordering stable if the column exists; otherwise, leave DB order.
    if ($schema->fieldExists($table, 'weight')) {
      $query->orderBy('i.weight', 'ASC');
    }

    $result = $query->execute();

    // 8) Map DB rows → normalized payload. Provide safe defaults when fields are absent.
    $rows = [];
    foreach ($result as $r) {
      // Defensive reads — only access properties that were selected (present in $present).
      $get = static function ($prop) use ($r, $present) {
        return in_array($prop, $present, true) ? ($r->$prop ?? NULL) : NULL;
      };

      $desktop = (string) ($get('desktop_img') ?? '');
      $mobile  = (string) ($get('mobile_img')  ?? '');
      $primary = $desktop !== '' ? $desktop : $mobile;

      $rawUuid = (string) ($get('uuid') ?? '');
      $uuid    = ($rawUuid !== '' && \Drupal\Component\Uuid\Uuid::isValid($rawUuid)) ? $rawUuid : '';

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
        'latitud'     => ($get('latitud')  !== NULL ? (float) $get('latitud')  : NULL),
        'longitud'    => ($get('longitud') !== NULL ? (float) $get('longitud') : NULL),
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
      ->condition('deleted_at', null, 'IS NULL')
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
          ])
          ->condition('id', $resolvedId)
          ->execute();
      }
      else {
        $uuid = $uuid !== '' ? $uuid : $this->uuid->generate();   // CHANGED
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

    $upd = $this->db->update('pds_template_item')
      ->fields(['deleted_at' => $nowUnix])
      ->condition('group_id', $groupId)
      ->condition('deleted_at', null, 'IS NULL');

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

      // Use the same promoter service as AJAX (no direct File API here).
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
      // Ensure keys exist.
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
      // add more here as you clone recipes
    ];
    $type = is_string($candidate) && $candidate !== '' ? $candidate : (string) $fallback;
    return in_array($type, $allowed, true) ? $type : 'pds_recipe_template';
  }
}