<?php
// Evaluates the admin group editor (POST from admin/group_edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
Application::init();
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/groups.php');
    exit;
}

require_csrf();

$groupId = (int)($_POST['group_id'] ?? 0);

try {
    $ctx = UserContext::getLoggedInUserContext();

    if (($_POST['action'] ?? '') === 'delete') {
        GroupManagement::deleteGroup($ctx, $groupId);
        $_SESSION['success'] = 'Group deleted.';
        header('Location: /admin/groups.php');
        exit;
    }

    GroupManagement::updateGroup($ctx, $groupId, [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
    ]);

    $newOwnerId = (int)($_POST['owner_user_id'] ?? 0);
    $group = GroupManagement::getGroup($groupId);
    if ($newOwnerId && $group && $newOwnerId !== (int)$group['owner_user_id']) {
        // App admins may hand ownership to anyone; ensure membership first.
        GroupManagement::addMember($ctx, $groupId, $newOwnerId, 'admin');
        GroupManagement::transferOwnership($ctx, $groupId, $newOwnerId);
    }

    $_SESSION['success'] = 'Group saved.';
    header('Location: /admin/group_edit.php?id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /admin/group_edit.php?id=' . $groupId);
    exit;
}
