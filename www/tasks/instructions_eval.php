<?php
// Saves a task's instructions (POST from the inline editor on tasks/view.php).
// Group owner / group admins only.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
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
        throw new RuntimeException('Only the group owner or a group admin can edit the instructions.');
    }

    $description = trim((string)($_POST['description'] ?? ''));
    pdo()->prepare('UPDATE tasks SET description=? WHERE id=?')->execute([$description !== '' ? $description : null, $taskId]);
    ActivityLog::log($ctx, 'task.instructions_edit', ['task_id' => $taskId]);
    $_SESSION['success'] = 'Instructions saved.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /tasks/view.php?id=' . $taskId);
exit;
