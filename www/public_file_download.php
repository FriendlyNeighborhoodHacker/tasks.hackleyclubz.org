<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';

// Initialize application
Application::init();

// Public file download: serves files from public_files by id with no auth required.
// Supports simple ETag using sha256 to enable client caching.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

require_once __DIR__ . '/lib/Files.php';
$row = Files::getPublicFileForDownload($id);
if (!$row) { http_response_code(404); exit('Not found'); }

$data = (string)$row['data'];
$len = (int)($row['byte_length'] ?? strlen($data));
$ctype = (string)($row['content_type'] ?? '');
if ($ctype === '') $ctype = 'application/octet-stream';
$name = (string)($row['original_filename'] ?? '');
if ($name === '') $name = 'file';
$etag = (string)($row['sha256'] ?? '');
if ($etag === '') $etag = '"' . sha1($data) . '"';
else $etag = '"' . $etag . '"';

// Conditional GET: If-None-Match with ETag
$ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNone && trim($ifNone) === $etag) {
  header('ETag: ' . $etag);
  http_response_code(304);
  exit;
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . $len);
header('Content-Disposition: inline; filename="' . rawbasename($name) . '"');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);

// Output
echo $data;
exit;

// Helper for safe basename preserving utf-8
function rawbasename(string $path): string {
  $b = basename($path);
  // Prevent header injection
  return str_replace(['"', "\r", "\n"], ['_', '', ''], $b);
}
