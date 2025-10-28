<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Service;

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

    if ($imageFid === 0) {
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

    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->fileStorage->load($imageFid);
    if (!$file instanceof FileInterface) {
      //5.- Signal that the fid is stale so the caller can prompt the editor to re-upload.
      return [
        'status' => 'error',
        'message' => 'File not found.',
        'code' => 404,
      ];
    }

    try {
      //6.- Promote the upload to permanent immediately so future loads resolve without extra saves.
      $file->setPermanent();
      $file->save();

      //7.- Produce the canonical public URL that should populate both desktop and mobile slots.
      $resolvedUrl = $this->fileUrlGenerator->generateString($file->getFileUri());
    }
    catch (Throwable $throwable) {
      //8.- Fail gracefully to avoid corrupting row state when the file system rejects the save.
      return [
        'status' => 'error',
        'message' => 'Unable to persist file.',
        'code' => 500,
      ];
    }

    return [
      'status' => 'ok',
      'image_fid' => (int) $file->id(),
      'desktop_img' => $resolvedUrl,
      'mobile_img' => $resolvedUrl,
      'image_url' => $resolvedUrl,
    ];
  }

}
