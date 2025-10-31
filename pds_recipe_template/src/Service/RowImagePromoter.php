<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Throwable;

/**
 * RowImagePromoter
 *
 * PURPOSE (EN)
 * - Normalize and "promote" row image inputs (desktop/mobile) into canonical,
 *   publicly-resolvable URLs, using file fids when present.
 * - Guarantees sane fallbacks so callers always get usable URLs for both slots.
 *
 * PROPÓSITO (ES)
 * - Normaliza y “promueve” imágenes (desktop/mobile) a URLs públicas y canónicas,
 *   usando fids cuando existan.
 * - Asegura respaldos razonables para que ambos campos queden utilizables.
 *
 * INPUT CONTRACT
 * - $row may contain:
 *   - 'image_fid' (int|string numeric)           → desktop primary fid
 *   - 'mobile_image_fid' (int|string numeric)    → mobile override fid
 *   - 'desktop_img' (string URL)                 → pre-resolved URL (optional)
 *   - 'mobile_img'  (string URL)                 → pre-resolved URL (optional)
 *   - 'image_url'   (string URL)                 → legacy single-slot URL
 *
 * OUTPUT CONTRACT
 * - On success: [
 *     'status'          => 'ok',
 *     'image_fid'       => ?int,            // canonical fid used (desktop or mobile)
 *     'mobile_image_fid'=> ?int,            // mobile fid if promoted separately
 *     'desktop_img'     => string URL,      // resolved desktop URL
 *     'mobile_img'      => string URL,      // resolved mobile  URL
 *     'image_url'       => string URL,      // canonical/first-non-empty URL
 *   ]
 * - On error: [
 *     'status'  => 'error',
 *     'message' => '…',
 *     'code'    => int HTTP-ish code,
 *   ]
 *
 * DESIGN NOTES
 * - Side-effect: marks files as permanent, preventing cleanup by cron.
 * - Resilient: if no fids are provided, returns sanitized URLs/fallbacks.
 * - Idempotent: safe to run multiple times on the same payload.
 */
final class RowImagePromoter {

  /** File storage (entity) for loading FileInterface by fid. */
  private EntityStorageInterface $fileStorage;

  /** Generates public URLs from file URIs (stream wrapper aware). */
  private FileUrlGeneratorInterface $fileUrlGenerator;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileUrlGeneratorInterface $fileUrlGenerator
  ) {
    // 1) Resolve reusable file storage once (cheaper than per-call lookups).
    $this->fileStorage = $entityTypeManager->getStorage('file');

    // 2) Keep URL generator for uri→URL conversion (private/public schemes).
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  /**
   * Promote a row's images into canonical URLs (desktop/mobile).
   * - Honors pre-resolved URLs.
   * - Promotes fids to permanent and resolves to URLs.
   * - Applies sensible fallbacks (mirror when one slot missing).
   */
  public function promote(array $row): array {
    // 1) Normalize inbound values to predictable types.
    $imageFidRaw       = $row['image_fid'] ?? NULL;
    $imageFid          = is_numeric($imageFidRaw) ? (int) $imageFidRaw : 0;

    $mobileImageFidRaw = $row['mobile_image_fid'] ?? NULL;
    $mobileImageFid    = is_numeric($mobileImageFidRaw) ? (int) $mobileImageFidRaw : 0;

    $desktopImg        = trim((string) ($row['desktop_img'] ?? ''));
    $mobileImg         = trim((string) ($row['mobile_img']  ?? ''));
    $imageUrl          = trim((string) ($row['image_url']   ?? ''));

    // 2) Legacy support: if only image_url is provided, mirror to both slots.
    if ($desktopImg === '' && $imageUrl !== '') {
      $desktopImg = $imageUrl;
    }
    // 3) Same for mobile.
    if ($mobileImg === '' && $imageUrl !== '') {
      $mobileImg = $imageUrl;
    }

    // 4) If we have zero fids, return sanitized URLs with consistent fallbacks.
    if ($imageFid === 0 && $mobileImageFid === 0) {
      // Choose first non-empty of (desktop, mobile, legacy) as canonical fallback.
      $fallback = $desktopImg !== ''
        ? $desktopImg
        : ($mobileImg !== '' ? $mobileImg : $imageUrl);

      if ($desktopImg === '' && $fallback !== '') { $desktopImg = $fallback; }
      if ($mobileImg  === '' && $fallback !== '') { $mobileImg  = $fallback; }
      if ($imageUrl   === '' && $fallback !== '') { $imageUrl   = $fallback; }

      return [
        'status'           => 'ok',
        'image_fid'        => NULL,
        'desktop_img'      => $desktopImg,
        'mobile_img'       => $mobileImg,
        'image_url'        => $imageUrl,
      ];
    }

    // 5) Promote desktop fid first; mobile may mirror it if identical.
    $desktopPromotion = NULL;
    if ($imageFid > 0) {
      $desktopPromotion = $this->promoteFileById($imageFid);
      if (($desktopPromotion['status'] ?? '') !== 'ok') {
        // Early-exit: bubble up precise error to caller.
        return $desktopPromotion;
      }
      $desktopImg = $desktopPromotion['url'] ?? $desktopImg;
      $imageFid   = (int) ($desktopPromotion['fid'] ?? $imageFid);
    }

    // 6) Promote mobile if provided; reuse desktop if same fid.
    $mobilePromotion = NULL;
    if ($mobileImageFid > 0) {
      if ($desktopPromotion && $mobileImageFid === ($desktopPromotion['fid'] ?? 0)) {
        // Same physical file: skip duplicate work and mirror URL.
        $mobileImg      = $desktopImg;
        $mobileImageFid = $imageFid;
      } else {
        $mobilePromotion = $this->promoteFileById($mobileImageFid);
        if (($mobilePromotion['status'] ?? '') !== 'ok') {
          return $mobilePromotion;
        }
        $mobileImg      = $mobilePromotion['url'] ?? $mobileImg;
        $mobileImageFid = (int) ($mobilePromotion['fid'] ?? $mobileImageFid);
      }
    }

    // 7) Ensure both slots are populated (mirror whichever is available).
    if ($desktopImg === '' && $mobileImg !== '') {
      $desktopImg = $mobileImg;
    }
    if ($mobileImg === '' && $desktopImg !== '') {
      $mobileImg = $desktopImg;
    }

    // 8) Keep a single canonical URL for legacy consumers (prefer desktop).
    if ($imageUrl === '') {
      $imageUrl = $desktopImg !== '' ? $desktopImg : $mobileImg;
    }

    // 9) Return consistent, explicit payload.
    return [
      'status'            => 'ok',
      'image_fid'         => $imageFid > 0 ? $imageFid : ($mobileImageFid > 0 ? $mobileImageFid : NULL),
      'mobile_image_fid'  => $mobileImageFid > 0 ? $mobileImageFid : NULL,
      'desktop_img'       => $desktopImg,
      'mobile_img'        => $mobileImg,
      'image_url'         => $imageUrl,
    ];
  }

  /**
   * Promote a single fid:
   * - Loads file entity
   * - Marks as permanent
   * - Saves
   * - Resolves a public URL
   */
  private function promoteFileById(int $fid): array {
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->fileStorage->load($fid);

    // 1) Not found → return descriptive error (caller stays consistent).
    if (!$file instanceof FileInterface) {
      return [
        'status'  => 'error',
        'message' => 'File not found.',
        'code'    => 404,
      ];
    }

    try {
      // 2) Make permanent (prevents cleanup), then persist.
      $file->setPermanent();
      $file->save();

      // 3) Convert scheme URI (e.g., public://) to an absolute URL for the UI.
      $resolvedUrl = $this->fileUrlGenerator->generateString($file->getFileUri());
    }
    catch (Throwable $throwable) {
      // 4) Any filesystem/storage failures → bubble up as a controlled error.
      return [
        'status'  => 'error',
        'message' => 'Unable to persist file.',
        'code'    => 500,
      ];
    }

    // 5) Success → echo stable identifiers + resolved URL.
    return [
      'status' => 'ok',
      'fid'    => (int) $file->id(),
      'url'    => $resolvedUrl,
    ];
  }

}
