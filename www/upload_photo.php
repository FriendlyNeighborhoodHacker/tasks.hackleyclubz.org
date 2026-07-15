<?php
// upload_photo.php - Handles profile photo uploads for users.
// Usage (POST, multipart/form-data):
//   /upload_photo.php?user_id=123&return_to=/profile/edit_picture.php

require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Files.php';
Application::init();

function redirect_back(string $returnTo, array $params = []): void {
  // Basic allowlist: require leading slash to avoid offsite redirects
  if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/index.php';
  if (!empty($params)) {
    $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
    $returnTo .= $sep . http_build_query($params);
  }
  header('Location: ' . $returnTo);
  exit;
}

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

require_csrf();

$action = strtolower(trim((string)($_POST['action'] ?? 'upload')));

$u = current_user();
$currentId = (int)($u['id'] ?? 0);

$userId = (int)($_GET['user_id'] ?? 0);
$returnTo = (string)($_GET['return_to'] ?? '/index.php');
if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/index.php';

// Validate target + permissions
if ($userId <= 0) redirect_back($returnTo, ['err' => 'missing_user_id']);

// Only allow users to upload their own photos (or admins can upload for anyone)
if ($currentId !== $userId && empty($u['is_admin'])) {
  http_response_code(403);
  exit('Forbidden: cannot upload photo for this user');
}

if ($action === 'delete') {
  // Delete existing photo reference
  try {
    $ctx = UserContext::getLoggedInUserContext();
    UserManagement::updateUserPhoto($ctx, $userId, null);
  } catch (Throwable $e) {
    redirect_back($returnTo, ['err' => 'db_failed']);
  }
  redirect_back($returnTo, ['deleted' => 1]);
  exit;
}

// Validate file
if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  redirect_back($returnTo, ['err' => 'missing_file']);
}

$err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  redirect_back($returnTo, ['err' => 'upload_error_' . $err]);
}

$tmp = (string)$_FILES['photo']['tmp_name'];
$size = (int)($_FILES['photo']['size'] ?? 0);
if ($size <= 0) redirect_back($returnTo, ['err' => 'empty_file']);
if ($size > 8 * 1024 * 1024) redirect_back($returnTo, ['err' => 'too_large']); // 8MB

// Mime type detection
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];
if (!array_key_exists($mime, $allowed)) {
  redirect_back($returnTo, ['err' => 'invalid_type']);
}

// Verify it's an image
$imgInfo = @getimagesize($tmp);
if ($imgInfo === false) {
  redirect_back($returnTo, ['err' => 'not_image']);
}

$ext = $allowed[$mime];

// Store in DB-backed public_files and update reference
$origName = (string)($_FILES['photo']['name'] ?? ('profile.' . $ext));
$data = @file_get_contents($tmp);
if ($data === false) {
  redirect_back($returnTo, ['err' => 'save_failed']);
}

try {
  $publicId = Files::insertPublicFile($data, $mime, $origName, $currentId);
  $ctx = UserContext::getLoggedInUserContext();
  UserManagement::updateUserPhoto($ctx, $userId, $publicId);
} catch (Throwable $e) {
  redirect_back($returnTo, ['err' => 'db_failed']);
}

redirect_back($returnTo, ['uploaded' => 1]);
