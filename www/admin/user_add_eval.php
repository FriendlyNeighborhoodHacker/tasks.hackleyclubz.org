<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

require_csrf();

// Get form data
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$no_login = !empty($_POST['no_login']) ? 1 : 0;
$is_admin = (!$no_login && !empty($_POST['is_admin'])) ? 1 : 0;

$formParams = [
    'first_name' => $first_name,
    'last_name' => $last_name,
    'email' => $email,
    'is_admin' => $is_admin,
    'no_login' => $no_login
];

// Validation
$errors = [];
if ($first_name === '') {
    $errors[] = 'First name is required.';
}
if ($last_name === '') {
    $errors[] = 'Last name is required.';
}
if (!$no_login && $email === '') {
    $errors[] = 'Email is required for users who will sign in.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
}

// Check if email already exists
if (empty($errors) && $email !== '' && UserManagement::emailExists($email)) {
    $errors[] = 'Email already exists.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $query = http_build_query(['err' => implode(' ', $errors)] + $formParams);
    header('Location: /admin/user_add.php?' . $query);
    exit;
}

try {
    $data = [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin,
        'no_login' => $no_login === 1,
        'require_password_setup' => $no_login !== 1
    ];

    $ctx = UserContext::getLoggedInUserContext();
    $userId = UserManagement::createUser($ctx, $data);

    $successMsg = $no_login
        ? 'Family member created (no login).'
        : 'User created successfully. An activation email has been sent.';
    header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode($successMsg));
    exit;

} catch (Exception $e) {
    // Error creating user - redirect back to form
    $query = http_build_query(['err' => 'Error creating user: ' . $e->getMessage()] + $formParams);
    header('Location: /admin/user_add.php?' . $query);
    exit;
}
