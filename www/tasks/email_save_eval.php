<?php
// Saves ("Save for scheduled send") or clears (action=reset) a task's
// hand-edited reminder email from the preview modal. JSON response.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only.']);
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$task = ($id = (int)($_POST['task_id'] ?? 0)) ? TaskManagement::getTask($id) : null;

if (!$ctx || !$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found.']);
    exit;
}
if (!GroupManagement::canManageGroup($ctx, (int)$task['group_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the group owner or a group admin can edit this email.']);
    exit;
}

if (($_POST['action'] ?? '') === 'reset') {
    pdo()->prepare('UPDATE tasks SET custom_email_subject=NULL, custom_email_body=NULL WHERE id=?')->execute([$id]);
    ActivityLog::log($ctx, 'task.email_override.reset', ['task_id' => $id]);
    echo json_encode(['ok' => true, 'is_custom' => false]);
    exit;
}

$subject = trim((string)($_POST['subject'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
if ($subject === '' || $body === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Subject and body are both required.']);
    exit;
}

pdo()->prepare('UPDATE tasks SET custom_email_subject=?, custom_email_body=? WHERE id=?')->execute([$subject, $body, $id]);
ActivityLog::log($ctx, 'task.email_override.save', ['task_id' => $id]);
echo json_encode(['ok' => true, 'is_custom' => true]);
