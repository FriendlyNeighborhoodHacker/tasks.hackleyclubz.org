<?php
// Downloads a comment attachment. Requires login and membership in the task's
// group; attachments are private files and never disk-cached.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$commentId = (int)($_GET['comment_id'] ?? 0);
$comment = $commentId ? TaskManagement::getComment($commentId) : null;
if (!$comment || empty($comment['private_file_id'])) {
    http_response_code(404);
    die('Attachment not found');
}

$task = TaskManagement::getTask((int)$comment['task_id']);
$ctx = UserContext::getLoggedInUserContext();
try {
    TaskManagement::assertCanViewTask($ctx, $task);
} catch (Throwable $e) {
    http_response_code(403);
    die('Forbidden');
}

$file = Files::getPrivateFileForDownload((int)$comment['private_file_id']);
if (!$file) {
    http_response_code(404);
    die('Attachment not found');
}

$filename = (string)($file['original_filename'] ?? 'attachment');
header('Content-Type: ' . ($file['content_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . strlen((string)$file['data']));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $file['data'];
