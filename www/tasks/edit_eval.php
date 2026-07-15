<?php
// Evaluates the edit-task form (POST from tasks/edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/TaskNotificationManagement.php';
require_once __DIR__ . '/form_fields.php';
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
    $groupId = (int)$task['group_id'];
    $data = task_data_from_post($_POST);

    if (($data['assigned_to_user_id'] ?? '') === '__new__') {
        if (!GroupManagement::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can add a new person.');
        }
        $newUserId = UserManagement::findOrCreateByEmail(
            $ctx,
            (string)($_POST['new_person_first_name'] ?? ''),
            (string)($_POST['new_person_last_name'] ?? ''),
            (string)($_POST['new_person_email'] ?? '')
        );
        GroupManagement::addMember($ctx, $groupId, $newUserId, 'member');
        $data['assigned_to_user_id'] = $newUserId;
    }

    TaskManagement::updateTask($ctx, $taskId, $data);

    // Reassigned to someone new? Notify them with the group's assignment
    // email template. Best-effort: a mail problem must not block the save.
    $newAssigneeId = (int)($data['assigned_to_user_id'] ?? 0);
    if ($newAssigneeId && $newAssigneeId !== (int)($task['assigned_to_user_id'] ?? 0)) {
        try {
            TaskNotificationManagement::sendAssignmentEmail($taskId, $ctx);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $_SESSION['success'] = 'Task saved.';
    header('Location: /tasks/view.php?id=' . $taskId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /tasks/edit.php?id=' . $taskId);
    exit;
}
