<?php
// Evaluates the create-group form (POST from groups/add.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /groups/add.php');
    exit;
}

require_csrf();

try {
    $ctx = UserContext::getLoggedInUserContext();
    $groupId = GroupManagement::createGroup($ctx, [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
    ]);
    $_SESSION['success'] = 'Group created. Add people so you can assign them tasks.';
    header('Location: /groups/people.php?group_id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/add.php');
    exit;
}
