<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_avisos_normativos\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Throwable;

final class RowImagePromoter {

  private EntityStorageInterface $fileStorage;

  private FileUrlGeneratorInterface $fileUrlGenerator;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileUrlGeneratorInterface $fileUrlGenerator
  ) {
    //1.- Resolve the reusable file storage so each promotion call can load entities cheaply.
    $this->fileStorage = $entityTypeManager->getStorage('file');

    //2.- Keep the URL generator handy to translate URIs into public URLs for the UI.
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  public function promote(array $row): array {
    //1.- Normalize the incoming payload so downstream logic works with predictable values.
    $imageFidRaw = $row['image_fid'] ?? NULL;
    $imageFid = is_numeric($imageFidRaw) ? (int) $imageFidRaw : 0;

    $mobileImageFidRaw = $row['mobile_image_fid'] ?? NULL;
    $mobileImageFid = is_numeric($mobileImageFidRaw) ? (int) $mobileImageFidRaw : 0;

    $desktopImg = trim((string) ($row['desktop_img'] ?? ''));
    $mobileImg = trim((string) ($row['mobile_img'] ?? ''));
    $imageUrl = trim((string) ($row['image_url'] ?? ''));

    if ($desktopImg === '' && $imageUrl !== '') {
      //2.- Honor legacy callers that only stored image_url by mirroring it to desktop_img.
      $desktopImg = $imageUrl;
    }
    if ($mobileImg === '' && $imageUrl !== '') {
      //3.- Apply the same fallback for the mobile slot so both targets stay in sync.
      $mobileImg = $imageUrl;
    }

    if ($imageFid === 0 && $mobileImageFid === 0) {
      //4.- When no fid is provided, simply return the sanitized URLs we already have.
      $fallback = $desktopImg !== ''
        ? $desktopImg
        : ($mobileImg !== '' ? $mobileImg : $imageUrl);

      if ($desktopImg === '' && $fallback !== '') {
        $desktopImg = $fallback;
      }
      if ($mobileImg === '' && $fallback !== '') {
        $mobileImg = $fallback;
      }
      if ($imageUrl === '' && $fallback !== '') {
        $imageUrl = $fallback;
      }

      return [
        'status' => 'ok',
        'image_fid' => NULL,
        'desktop_img' => $desktopImg,
        'mobile_img' => $mobileImg,
        'image_url' => $imageUrl,
      ];
    }

    $desktopPromotion = NULL;
    if ($imageFid > 0) {
      //5.- Promote the desktop upload first so both slots can fall back to it when needed.
      $desktopPromotion = $this->promoteFileById($imageFid);
      if (($desktopPromotion['status'] ?? '') !== 'ok') {
        return $desktopPromotion;
      }

      $desktopImg = $desktopPromotion['url'] ?? $desktopImg;
      $imageFid = (int) ($desktopPromotion['fid'] ?? $imageFid);
    }

    $mobilePromotion = NULL;
    if ($mobileImageFid > 0) {
      if ($desktopPromotion && $mobileImageFid === ($desktopPromotion['fid'] ?? 0)) {
        //6.- Reuse the desktop upload when both widgets point to the same fid to avoid duplicate work.
        $mobileImg = $desktopImg;
        $mobileImageFid = $imageFid;
      }
      else {
        //7.- Promote the dedicated mobile upload so the slider can render device-specific assets.
        $mobilePromotion = $this->promoteFileById($mobileImageFid);
        if (($mobilePromotion['status'] ?? '') !== 'ok') {
          return $mobilePromotion;
        }

        $mobileImg = $mobilePromotion['url'] ?? $mobileImg;
        $mobileImageFid = (int) ($mobilePromotion['fid'] ?? $mobileImageFid);
      }
    }

    if ($desktopImg === '' && $mobileImg !== '') {
      //8.- Guarantee a desktop fallback even when only a mobile upload is present.
      $desktopImg = $mobileImg;
    }
    if ($mobileImg === '' && $desktopImg !== '') {
      //9.- Mirror the desktop asset to mobile when the dedicated upload is missing.
      $mobileImg = $desktopImg;
    }
    if ($imageUrl === '') {
      //10.- Preserve canonical URL compatibility by choosing the first non-empty promoted asset.
      $imageUrl = $desktopImg !== '' ? $desktopImg : $mobileImg;
    }

    return [
      'status' => 'ok',
      'image_fid' => $imageFid > 0 ? $imageFid : ($mobileImageFid > 0 ? $mobileImageFid : NULL),
      'mobile_image_fid' => $mobileImageFid > 0 ? $mobileImageFid : NULL,
      'desktop_img' => $desktopImg,
      'mobile_img' => $mobileImg,
      'image_url' => $imageUrl,
    ];
  }

  private function promoteFileById(int $fid): array {
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->fileStorage->load($fid);
    if (!$file instanceof FileInterface) {
      //1.- Return a descriptive error when the stored fid no longer resolves to an entity.
      return [
        'status' => 'error',
        'message' => 'File not found.',
        'code' => 404,
      ];
    }

    try {
      //2.- Promote the upload to permanent so Drupal keeps the asset across cache clears.
      $file->setPermanent();
      $file->save();

      //3.- Translate the file URI into a publicly accessible URL for the render layer.
      $resolvedUrl = $this->fileUrlGenerator->generateString($file->getFileUri());
    }
    catch (Throwable $throwable) {
      //4.- Guard against file system issues to avoid corrupting the caller's state.
      return [
        'status' => 'error',
        'message' => 'Unable to persist file.',
        'code' => 500,
      ];
    }

    return [
      'status' => 'ok',
      'fid' => (int) $file->id(),
      'url' => $resolvedUrl,
    ];
  }

}
