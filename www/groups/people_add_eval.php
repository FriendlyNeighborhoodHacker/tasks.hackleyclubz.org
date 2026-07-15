<?php
// Evaluates the add-person form (POST from groups/people.php). Creates a
// lightweight account when the email is new, adds the membership, and
// optionally sends a set-your-password invitation.
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

try {
    $ctx = UserContext::getLoggedInUserContext();
    if (!GroupManagement::canManageGroup($ctx, $groupId)) {
        throw new RuntimeException('Only the group owner or a group admin can add people.');
    }

    $userId = UserManagement::findOrCreateByEmail(
        $ctx,
        (string)($_POST['first_name'] ?? ''),
        (string)($_POST['last_name'] ?? ''),
        (string)($_POST['email'] ?? '')
    );

    $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    GroupManagement::addMember($ctx, $groupId, $userId, $role);

    if (!empty($_POST['send_invite'])) {
        $user = UserManagement::findById($userId);
        if ($user && $user['password_hash'] === '') {
            UserManagement::sendAccountInvite($ctx, $userId);
            $_SESSION['success'] = 'Person added and invitation sent.';
        } else {
            $_SESSION['success'] = 'Person added. They already have an account, so no invitation was needed.';
        }
    } else {
        $_SESSION['success'] = 'Person added.';
    }

    header('Location: /groups/people.php?group_id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/people.php?group_id=' . $groupId);
    exit;
}
