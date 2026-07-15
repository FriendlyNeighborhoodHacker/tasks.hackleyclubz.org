<?php
// Deletes a task (POST from tasks/edit.php).
require_once __DIR__ . '/../partials.php';
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
    $groupId = $task ? (int)$task['group_id'] : 0;
    TaskManagement::deleteTask($ctx, $taskId);
    $_SESSION['success'] = 'Task deleted.';
    header('Location: /tasks/index.php' . ($groupId ? '?group_id=' . $groupId : ''));
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: /tasks/edit.php?id=' . $taskId);
    exit;
}
