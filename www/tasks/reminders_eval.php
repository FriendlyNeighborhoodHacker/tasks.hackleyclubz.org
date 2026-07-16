<?php
// Saves a task's reminder schedule (POST from the inline editor on
// tasks/view.php): a comma-separated list of "days before the due date".
// Group owner / group admins only.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}
require_csrf();

$taskId = (int)($_POST['task_id'] ?? 0);

try {
    $ctx = UserContext::getLoggedInUserContext();
    $task = TaskManagement::getTask($taskId);
    if (!$task) {
        throw new InvalidArgumentException('Task not found.');
    }
    if (!GroupManagement::canManageGroup($ctx, (int)$task['group_id'])) {
        throw new RuntimeException('Only the group owner or a group admin can edit the reminders.');
    }

    // "7, 1" -> [7, 1]; blank input clears all reminders.
    $days = [];
    foreach (preg_split('/[,\s]+/', trim((string)($_POST['days'] ?? ''))) as $part) {
        if ($part === '') continue;
        if (!ctype_digit($part)) {
            throw new InvalidArgumentException('Reminders must be whole numbers of days, e.g. "7, 1".');
        }
        $days[] = (int)$part;
    }

    TaskManagement::setReminders($ctx, $taskId, $days);
    $_SESSION['success'] = $days ? 'Reminders saved.' : 'Reminders cleared.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /tasks/view.php?id=' . $taskId);
exit;
