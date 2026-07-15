<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class Files {
  // Build URL for a public file id (profile photos)
  // Caches allowed public types (images, pdf) as static files in /cache/public/<first>/<md5(id)>.ext
  public static function publicFileUrl(int $publicFileId): string {
    if ($publicFileId <= 0) return '';

    try {
      // Fetch metadata to determine if cacheable and derive extension
      $st = pdo()->prepare("SELECT content_type, original_filename FROM public_files WHERE id = ? LIMIT 1");
      $st->execute([$publicFileId]);
      $meta = $st->fetch();
      if (!$meta) return '';

      $ctype = strtolower(trim((string)($meta['content_type'] ?? '')));
      $orig  = (string)($meta['original_filename'] ?? '');
      $ext = self::extForPublic($ctype, $orig);

      // If not a well-known cacheable type, return dynamic endpoint
      if ($ext === null) {
        return '/public_file_download.php?id=' . $publicFileId;
      }

      $hash = md5((string)$publicFileId);
      $dirKey = substr($hash, 0, 1);
      $baseDir = self::cachePublicBaseDir();
      $baseUrl = self::cachePublicBaseUrl();
      $targetDir = $baseDir . '/' . $dirKey;
      $filename = $hash . $ext;
      $path = $targetDir . '/' . $filename;
      $url  = $baseUrl . '/' . $dirKey . '/' . $filename;

      // If already cached, return the static URL
      if (is_file($path)) {
        return $url;
      }

      // Ensure directory exists
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
      }

      // Load blob data and write atomically
      $st2 = pdo()->prepare("SELECT data FROM public_files WHERE id = ? LIMIT 1");
      $st2->execute([$publicFileId]);
      $row = $st2->fetch();
      if (!$row) {
        return '/public_file_download.php?id=' . $publicFileId;
      }

      $data = (string)$row['data'];
      $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
      $ok = @file_put_contents($tmp, $data, LOCK_EX);
      if ($ok === false) {
        @unlink($tmp);
        return '/public_file_download.php?id=' . $publicFileId;
      }
      @chmod($tmp, 0644);
      if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return '/public_file_download.php?id=' . $publicFileId;
      }

      return $url;
    } catch (\Throwable $e) {
      // On any error, return dynamic endpoint
      return '/public_file_download.php?id=' . $publicFileId;
    }
  }

  // Profile photo URL (DB-backed only)
  public static function profilePhotoUrl(?int $publicFileId, ?int $width = null): string {
    if ($publicFileId && $publicFileId > 0) {
      if ($width !== null) {
        return self::publicFileImageUrl($publicFileId, $width);
      }
      return self::publicFileUrl($publicFileId);
    }
    return '';
  }

  // Image URL with optional width resizing
  public static function publicFileImageUrl(int $publicFileId, ?int $width = null): string {
    if ($publicFileId <= 0) return '';

    try {
      // Fetch metadata to determine if it's an image
      $st = pdo()->prepare("SELECT content_type, original_filename FROM public_files WHERE id = ? LIMIT 1");
      $st->execute([$publicFileId]);
      $meta = $st->fetch();
      if (!$meta) return '';

      $ctype = strtolower(trim((string)($meta['content_type'] ?? '')));
      $orig  = (string)($meta['original_filename'] ?? '');
      $ext = self::extForPublic($ctype, $orig);

      // Only process images
      $imageTypes = ['.jpg', '.png', '.webp', '.gif'];
      if ($ext === null || !in_array($ext, $imageTypes)) {
        return '';
      }

      // If no width specified, use regular publicFileUrl
      if ($width === null || $width <= 0) {
        return self::publicFileUrl($publicFileId);
      }

      $hash = md5((string)$publicFileId);
      $dirKey = substr($hash, 0, 1);
      $baseDir = self::cachePublicBaseDir();
      $baseUrl = self::cachePublicBaseUrl();
      $targetDir = $baseDir . '/' . $dirKey;
      
      // Resized filename with width suffix
      $resizedFilename = $hash . '_w' . $width . $ext;
      $resizedPath = $targetDir . '/' . $resizedFilename;
      $resizedUrl = $baseUrl . '/' . $dirKey . '/' . $resizedFilename;

      // If resized version already cached, return it
      if (is_file($resizedPath)) {
        return $resizedUrl;
      }

      // Ensure directory exists
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
      }

      // Get original cached file or create it
      $originalFilename = $hash . $ext;
      $originalPath = $targetDir . '/' . $originalFilename;
      
      if (!is_file($originalPath)) {
        // Create original cached file first
        $st2 = pdo()->prepare("SELECT data FROM public_files WHERE id = ? LIMIT 1");
        $st2->execute([$publicFileId]);
        $row = $st2->fetch();
        if (!$row) {
          return '/public_file_download.php?id=' . $publicFileId;
        }

        $data = (string)$row['data'];
        $tmp = $originalPath . '.tmp' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($tmp, $data, LOCK_EX);
        if ($ok === false) {
          @unlink($tmp);
          return '/public_file_download.php?id=' . $publicFileId;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $originalPath)) {
          @unlink($tmp);
          return '/public_file_download.php?id=' . $publicFileId;
        }
      }

      // Now resize the original
      $resized = self::resizeImage($originalPath, $width, $ctype);
      if ($resized === false) {
        // If resize failed, return original
        return self::publicFileUrl($publicFileId);
      }

      // Save resized image atomically
      $tmp = $resizedPath . '.tmp' . bin2hex(random_bytes(4));
      $ok = @file_put_contents($tmp, $resized, LOCK_EX);
      if ($ok === false) {
        @unlink($tmp);
        return self::publicFileUrl($publicFileId);
      }
      @chmod($tmp, 0644);
      if (!@rename($tmp, $resizedPath)) {
        @unlink($tmp);
        return self::publicFileUrl($publicFileId);
      }

      return $resizedUrl;
    } catch (\Throwable $e) {
      // On any error, fall back to original
      return self::publicFileUrl($publicFileId);
    }
  }

  // Helper: insert a public file row and return new id
  public static function insertPublicFile(string $data, ?string $contentType, ?string $originalFilename, ?int $createdByUserId): int {
    $sha = hash('sha256', $data);
    $len = strlen($data);
    $st = pdo()->prepare("
      INSERT INTO public_files (data, content_type, original_filename, byte_length, sha256, created_by_user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([$data, $contentType, $originalFilename, $len, $sha, $createdByUserId]);
    return (int)pdo()->lastInsertId();
  }

  // ===== Private files (Document Vault) =====
  // Served ONLY through a login-checked endpoint; never written to the disk cache.

  // Validate and store an uploaded private file ($_FILES entry): checks the
  // upload error, size cap, and server-sniffed MIME against the allowlist.
  // Returns the new private_files id. Used for task comment
  // attachments.
  public static function storeUploadedPrivateFile(?int $createdByUserId, array $file, int $maxBytes, array $allowedMimeTypes): int {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
      throw new InvalidArgumentException('File upload failed (error ' . $err . ').');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
      throw new InvalidArgumentException('Uploaded file is empty.');
    }
    if ($size > $maxBytes) {
      throw new InvalidArgumentException('File is too large (max ' . round($maxBytes / 1048576) . ' MB).');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!in_array($mime, $allowedMimeTypes, true)) {
      throw new InvalidArgumentException('Unsupported file type (' . $mime . '). Allowed: PDF, images, Word, Excel, text.');
    }

    $data = @file_get_contents($tmp);
    if ($data === false) {
      throw new RuntimeException('Failed to read uploaded file.');
    }

    $originalName = (string)($file['name'] ?? 'document');
    return self::insertPrivateFile($data, $mime, $originalName, $createdByUserId);
  }

  // Insert a private file row and return the new id
  public static function insertPrivateFile(string $data, ?string $contentType, ?string $originalFilename, ?int $createdByUserId): int {
    $sha = hash('sha256', $data);
    $len = strlen($data);
    $st = pdo()->prepare("
      INSERT INTO private_files (data, content_type, original_filename, byte_length, sha256, created_by_user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([$data, $contentType, $originalFilename, $len, $sha, $createdByUserId]);
    return (int)pdo()->lastInsertId();
  }

  // Metadata only (no blob) — for listings
  public static function getPrivateFileMeta(int $id): ?array {
    if ($id <= 0) return null;
    $st = pdo()->prepare("SELECT id, content_type, original_filename, byte_length, sha256, created_by_user_id, created_at FROM private_files WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // Full row including blob — the caller MUST have verified login already
  public static function getPrivateFileForDownload(int $id): ?array {
    if ($id <= 0) return null;
    $st = pdo()->prepare("SELECT data, content_type, original_filename, byte_length FROM private_files WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // Delete a private file's bytes (used when a vault document is deleted —
  // sensitive content should not linger as an orphaned row)
  public static function deletePrivateFile(int $id): bool {
    if ($id <= 0) return false;
    $st = pdo()->prepare("DELETE FROM private_files WHERE id = ?");
    return $st->execute([$id]);
  }

  // ===== Public cache helpers =====

  // Filesystem base for public cache (web-accessible). Example: /path/to/project/cache/public
  private static function cachePublicBaseDir(): string {
    return dirname(__DIR__) . '/cache/public';
  }

  // URL base for public cache. Example: /cache/public
  private static function cachePublicBaseUrl(): string {
    return '/cache/public';
  }

  // Determine extension for cacheable public files. Returns one of (.jpg,.png,.webp,.gif,.pdf) or null if not cacheable.
  private static function extForPublic(?string $ctype, ?string $original): ?string {
    $ctype = strtolower(trim((string)($ctype ?? '')));

    // Map known, allowed content types
    $mapCtype = [
      'image/jpeg' => '.jpg',
      'image/png'  => '.png',
      'image/webp' => '.webp',
      'image/gif'  => '.gif',
      'application/pdf' => '.pdf',
    ];
    if ($ctype !== '' && isset($mapCtype[$ctype])) {
      return $mapCtype[$ctype];
    }

    // If content type is unknown, allow based on known filename extensions
    $ext = strtolower((string)pathinfo((string)($original ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') return null;

    $mapExt = [
      'jpg' => '.jpg',
      'jpeg' => '.jpg',
      'png' => '.png',
      'webp' => '.webp',
      'gif' => '.gif',
      'pdf' => '.pdf',
    ];
    return $mapExt[$ext] ?? null;
  }

  // Get public file data for download (for public_file_download.php)
  public static function getPublicFileForDownload(int $id): ?array {
    if ($id <= 0) return null;
    
    $st = pdo()->prepare("SELECT data, content_type, original_filename, byte_length, sha256 FROM public_files WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  // Resize image using GD
  private static function resizeImage(string $originalPath, int $width, string $contentType): string|false {
    if (!extension_loaded('gd')) {
      return false;
    }

    try {
      // Create image resource from file
      $source = null;
      switch ($contentType) {
        case 'image/jpeg':
          $source = @imagecreatefromjpeg($originalPath);
          break;
        case 'image/png':
          $source = @imagecreatefrompng($originalPath);
          break;
        case 'image/webp':
          if (function_exists('imagecreatefromwebp')) {
            $source = @imagecreatefromwebp($originalPath);
          }
          break;
        case 'image/gif':
          $source = @imagecreatefromgif($originalPath);
          break;
      }

      if ($source === false || $source === null) {
        return false;
      }

      // Get original dimensions
      $originalWidth = imagesx($source);
      $originalHeight = imagesy($source);

      // If original is smaller than requested width, return original
      if ($originalWidth <= $width) {
        imagedestroy($source);
        return file_get_contents($originalPath) ?: false;
      }

      // Calculate new height maintaining aspect ratio
      $newHeight = (int)round(($originalHeight * $width) / $originalWidth);

      // Create new image
      $resized = imagecreatetruecolor($width, $newHeight);
      if ($resized === false) {
        imagedestroy($source);
        return false;
      }

      // Preserve transparency for PNG and GIF
      if ($contentType === 'image/png' || $contentType === 'image/gif') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefill($resized, 0, 0, $transparent);
      }

      // Resize the image
      $success = imagecopyresampled(
        $resized, $source,
        0, 0, 0, 0,
        $width, $newHeight,
        $originalWidth, $originalHeight
      );

      if (!$success) {
        imagedestroy($source);
        imagedestroy($resized);
        return false;
      }

      // Output to string
      ob_start();
      $outputSuccess = false;
      switch ($contentType) {
        case 'image/jpeg':
          $outputSuccess = imagejpeg($resized, null, 85); // 85% quality
          break;
        case 'image/png':
          $outputSuccess = imagepng($resized, null, 6); // Compression level 6
          break;
        case 'image/webp':
          if (function_exists('imagewebp')) {
            $outputSuccess = imagewebp($resized, null, 85); // 85% quality
          }
          break;
        case 'image/gif':
          $outputSuccess = imagegif($resized);
          break;
      }

      $imageData = ob_get_contents();
      ob_end_clean();

      // Clean up
      imagedestroy($source);
      imagedestroy($resized);

      if (!$outputSuccess || $imageData === false) {
        return false;
      }

      return $imageData;
    } catch (\Throwable $e) {
      return false;
    }
  }
}
