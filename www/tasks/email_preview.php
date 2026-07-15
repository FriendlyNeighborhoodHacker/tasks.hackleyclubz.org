<?php
// JSON: the scheduled reminder email for one task — the task's saved custom
// email if there is one, otherwise the group's single-task reminder template.
// Subject and body keep their [token] placeholders (rendered as pink tags in
// the modal; substituted with the task's CURRENT details only at send time);
// token_values carries today's values for the tags' tooltips. Feeds the
// "Email preview" modal on tasks/index.php (group owner / group admins only).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/TaskNotificationManagement.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
Application::init();

header('Content-Type: application/json');

$ctx = UserContext::getLoggedInUserContext();
$task = ($id = (int)($_GET['task_id'] ?? 0)) ? TaskManagement::getTask($id) : null;

if (!$ctx || !$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found.']);
    exit;
}
if (!GroupManagement::canManageGroup($ctx, (int)$task['group_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the group owner or a group admin can preview emails.']);
    exit;
}

$group = GroupManagement::getGroup((int)$task['group_id']);

// taskTokens falls back creator → owner for [task_assigner]; getTask has no
// owner columns, so graft them on from the group row.
$task['owner_first_name'] = $group['owner_first_name'] ?? '';
$task['owner_last_name'] = $group['owner_last_name'] ?? '';

$hasAssignee = !empty($task['assigned_to_user_id']);
$recipient = ['first_name' => $hasAssignee ? (string)$task['assignee_first_name'] : (string)($group['owner_first_name'] ?? '')];
$tokens = TaskNotificationManagement::taskTokens($task, $recipient);

$isCustom = TaskNotificationManagement::hasCustomEmail($task);
if ($isCustom) {
    $subject = (string)$task['custom_email_subject'];
    $body = (string)$task['custom_email_body'];
} else {
    $tpl = EmailTemplates::getTemplate((int)$task['group_id'], EmailTemplates::TYPE_REMINDER_SINGLE);
    $subject = $tpl['subject'];
    $body = $tpl['body'];
}

$assigneeName = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
$to = $hasAssignee
    ? $assigneeName . (!empty($task['assignee_email']) ? ' <' . $task['assignee_email'] . '>' : ' (no email — will go to the group owner & admins)')
    : 'Group owner & admins (task is unassigned)';

// Emails go out from the configured SMTP sender, not the task creator.
$fromEmail = (defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL) ? SMTP_FROM_EMAIL
    : ((defined('SMTP_USER') && SMTP_USER) ? SMTP_USER : '');
$fromName = (defined('SMTP_FROM_NAME') && SMTP_FROM_NAME) ? SMTP_FROM_NAME : 'Tasks';

echo json_encode([
    'task_id' => (int)$task['id'],
    'task_title' => (string)$task['title'],
    'is_custom' => $isCustom,
    'subject' => $subject,
    'body' => $body,
    'token_values' => $tokens,
    'to' => $to,
    'from' => $fromName . ($fromEmail !== '' ? ' <' . $fromEmail . '>' : ''),
]);
