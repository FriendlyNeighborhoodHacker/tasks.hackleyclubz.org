<?php
// Saves who a task is assigned to (POST from the inline "Owner(s)" editor on
// tasks/view.php): assigned_user_ids[] of group member ids. Group owner /
// group admins only. Newly added people get an assignment email.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/TaskNotificationManagement.php';
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
        throw new RuntimeException('Only the group owner or a group admin can change who a task is assigned to.');
    }

    $ids = array_map('intval', (array)($_POST['assigned_user_ids'] ?? []));
    $added = TaskManagement::setAssignees($ctx, $taskId, $ids);

    // Best-effort: a mail problem must not block the save.
    if ($added) {
        try {
            TaskNotificationManagement::sendAssignmentEmail($taskId, $ctx, null, $added);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $_SESSION['success'] = 'Assignees saved.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /tasks/view.php?id=' . $taskId);
exit;
