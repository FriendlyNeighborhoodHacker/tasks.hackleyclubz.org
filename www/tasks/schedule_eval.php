<?php
// Sets or clears a task's admin-editable reminder send time (POST from the
// "Email sends" column on tasks/index.php). Empty send_at = back to the
// automatic schedule. Group owner / group admins only.
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
$return = validate_relative_next_path($_POST['return'] ?? '');
if ($return === '') {
    $return = '/tasks/index.php';
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $task = TaskManagement::getTask($taskId);
    if (!$task) {
        throw new InvalidArgumentException('Task not found.');
    }
    if (!GroupManagement::canManageGroup($ctx, (int)$task['group_id'])) {
        throw new RuntimeException('Only the group owner or a group admin can change the email schedule.');
    }

    $sendAt = trim((string)($_POST['send_at'] ?? ''));
    if ($sendAt === '') {
        pdo()->prepare('UPDATE tasks SET custom_email_send_at=NULL WHERE id=?')->execute([$taskId]);
        ActivityLog::log($ctx, 'task.email_schedule.clear', ['task_id' => $taskId]);
        $_SESSION['success'] = 'Email schedule reset to automatic.';
    } else {
        // The date input submits "YYYY-MM-DD"; the daily runner's cron decides
        // the time of day, so only the date is stored (midnight placeholder).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sendAt) || !strtotime($sendAt)) {
            throw new InvalidArgumentException('Invalid date.');
        }
        pdo()->prepare('UPDATE tasks SET custom_email_send_at=? WHERE id=?')->execute([$sendAt . ' 00:00:00', $taskId]);
        ActivityLog::log($ctx, 'task.email_schedule.set', ['task_id' => $taskId, 'send_at' => $sendAt]);
        $_SESSION['success'] = 'Email scheduled for ' . date('M j', strtotime($sendAt)) . '.';
    }
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ' . $return);
exit;
