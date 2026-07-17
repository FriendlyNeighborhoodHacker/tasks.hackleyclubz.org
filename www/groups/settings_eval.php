<?php
// Evaluates the General group settings forms (POST from groups/settings.php):
// name/description update and ownership transfer.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
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

    if (($_POST['action'] ?? '') === 'transfer_ownership') {
        GroupManagement::transferOwnership($ctx, $groupId, (int)($_POST['new_owner_user_id'] ?? 0));
        $_SESSION['success'] = 'Ownership transferred.';
    } else {
        GroupManagement::updateGroup($ctx, $groupId, [
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
        ]);
        $_SESSION['success'] = 'Group settings saved.';
    }

    header('Location: /groups/settings.php?group_id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/settings.php?group_id=' . $groupId);
    exit;
}
