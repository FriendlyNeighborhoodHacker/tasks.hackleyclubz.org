<?php
// Evaluates the add-update form on tasks/view.php: comment text, optional
// attachment, optional "mark complete".
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

    $privateFileId = null;
    if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $privateFileId = TaskManagement::storeUploadedAttachment($ctx, $_FILES['attachment']);
    }

    $comment = (string)($_POST['comment'] ?? '');
    $markComplete = !empty($_POST['mark_complete']);

    if ($markComplete) {
        TaskManagement::markComplete($ctx, $taskId, $_POST['completed_on'] ?? null, $comment);
        // The completion recorded the comment; attach the file separately if provided.
        if ($privateFileId !== null) {
            TaskManagement::addComment($ctx, $taskId, null, $privateFileId);
        }
        $_SESSION['success'] = 'Task marked complete.';
    } else {
        TaskManagement::addComment($ctx, $taskId, $comment, $privateFileId);
        $_SESSION['success'] = 'Update posted.';
    }

    header('Location: /tasks/view.php?id=' . $taskId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /tasks/view.php?id=' . $taskId);
    exit;
}
