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

  /* =========== ITEM LOAD =========== */

  public function loadItems(int $groupId): array {
    if ($groupId <= 0) {
      return [];
    }

    $result = $this->db->select('pds_template_item', 'i')
      ->fields('i', [
        'id', 'uuid', 'weight', 'header', 'subheader', 'description', 'url',
        'desktop_img', 'mobile_img', 'latitud', 'longitud',
      ])
      ->condition('i.group_id', $groupId)
      ->condition('i.deleted_at', null, 'IS NULL')
      ->orderBy('i.weight', 'ASC')
      ->execute();

    $rows = [];
    foreach ($result as $record) {
      $primary = (string) $record->desktop_img;
      if ($primary === '') {
        $primary = (string) $record->mobile_img;
      }

      $resolvedUuid = isset($record->uuid) ? (string) $record->uuid : '';
      if ($resolvedUuid !== '' && !Uuid::isValid($resolvedUuid)) {
        $resolvedUuid = '';
      }

      $rows[] = [
        'id'          => (int) $record->id,
        'uuid'        => $resolvedUuid,
        'weight'      => (isset($record->weight) && is_numeric($record->weight)) ? (int) $record->weight : null,
        'header'      => $record->header,
        'subheader'   => $record->subheader,
        'description' => $record->description,
        'link'        => $record->url,
        'desktop_img' => $record->desktop_img,
        'mobile_img'  => $record->mobile_img,
        'image_url'   => $primary,
        'thumbnail'   => $primary,
        'latitud'     => $record->latitud,
        'longitud'    => $record->longitud,
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