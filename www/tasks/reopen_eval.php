<?php
// Reopens a completed task (POST from tasks/view.php).
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
$return = validate_relative_next_path($_POST['return'] ?? '');
if ($return === '') {
    $return = '/tasks/view.php?id=' . $taskId;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TaskManagement::reopenTask($ctx, $taskId);
    $_SESSION['success'] = 'Task marked as incomplete.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ' . $return);
exit;
