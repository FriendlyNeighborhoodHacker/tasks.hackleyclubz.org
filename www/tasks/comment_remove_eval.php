<?php
// Deletes a task update/comment (POST from tasks/view.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

require_csrf();

$commentId = (int)($_POST['comment_id'] ?? 0);
$comment = TaskManagement::getComment($commentId);
$taskId = $comment ? (int)$comment['task_id'] : 0;

try {
    $ctx = UserContext::getLoggedInUserContext();
    TaskManagement::deleteComment($ctx, $commentId);
    $_SESSION['success'] = 'Update deleted.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ' . ($taskId ? '/tasks/view.php?id=' . $taskId : '/index.php'));
exit;
