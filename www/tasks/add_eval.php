<?php
// Evaluates the add-task form (POST from tasks/add.php). Supports assigning to
// a brand-new person by email: a lightweight account is created and added to
// the group (group admins only).
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

$groupId = (int)($_POST['group_id'] ?? 0);

try {
    $ctx = UserContext::getLoggedInUserContext();
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

    $id = TaskManagement::createTask($ctx, $groupId, $data);

    // Notify the assignee with the group's assignment email template.
    // Best-effort: a mail problem must not block the save.
    if (!empty($data['assigned_to_user_id'])) {
        try {
            TaskNotificationManagement::sendAssignmentEmail($id, $ctx);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $_SESSION['success'] = 'Task created.';
    header('Location: /tasks/view.php?id=' . $id);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /tasks/add.php?group_id=' . $groupId);
    exit;
}
