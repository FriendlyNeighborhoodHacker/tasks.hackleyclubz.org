<?php
// Removes a member from a group (POST from groups/people.php).
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
    GroupManagement::removeMember($ctx, $groupId, (int)($_POST['user_id'] ?? 0));
    $_SESSION['success'] = 'Person removed from the group.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /groups/people.php?group_id=' . $groupId);
exit;
