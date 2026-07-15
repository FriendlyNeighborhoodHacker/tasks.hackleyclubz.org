<?php
// Sends (or re-sends) an account invitation to a member without a password
// (POST from groups/people.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

require_csrf();

$groupId = (int)($_POST['group_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);

try {
    $ctx = UserContext::getLoggedInUserContext();
    if (!GroupManagement::canManageGroup($ctx, $groupId)) {
        throw new RuntimeException('Only the group owner or a group admin can send invitations.');
    }
    if (!GroupManagement::isMember($userId, $groupId)) {
        throw new RuntimeException('That person is not a member of this group.');
    }
    UserManagement::sendAccountInvite($ctx, $userId);
    $_SESSION['success'] = 'Invitation sent.';
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /groups/people.php?group_id=' . $groupId);
exit;
