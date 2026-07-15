<?php
// Deletes a group (POST from groups/settings.php). Owner or app admin only.
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
    GroupManagement::deleteGroup($ctx, $groupId);
    $_SESSION['success'] = 'Group deleted.';
    header('Location: /index.php');
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: /groups/settings.php?group_id=' . $groupId);
    exit;
}
