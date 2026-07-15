<?php
// Marks a task complete via the token-authenticated email flow (POST from
// t/index.php). The token authenticates its user for exactly this task; no
// session login is created and the token passes through back to t/index.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/TaskAccessTokens.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

require_csrf();

$rawToken = (string)($_POST['token'] ?? '');
$auth = TaskAccessTokens::verify($rawToken);
if (!$auth) {
    header('Location: /t/index.php?token=' . urlencode($rawToken));
    exit;
}

// The token is scoped to exactly one task.
if ((int)($_POST['task_id'] ?? 0) !== $auth['task_id']) {
    http_response_code(403);
    die('This link cannot modify that task.');
}

// A limited, request-local context for the token's user. Never stored in the
// session — the token only authenticates this flow.
$ctx = new UserContext($auth['user_id'], false, false);

try {
    TaskManagement::markComplete($ctx, $auth['task_id']);
    ActivityLog::log($ctx, 'task.complete_via_token', ['task_id' => $auth['task_id']]);
    $_SESSION['t_success'] = 'Task marked complete. Thank you!';
} catch (Throwable $e) {
    $_SESSION['t_error'] = $e->getMessage();
}

header('Location: /t/index.php?token=' . urlencode($rawToken));
exit;
