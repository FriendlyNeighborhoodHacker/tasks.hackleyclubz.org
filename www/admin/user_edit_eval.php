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

// Get user ID
$userId = (int)($_POST['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /admin/users.php?err=' . urlencode('Invalid user ID.'));
    exit;
}

// Load user to verify it exists
$user = UserManagement::findById($userId);
if (!$user) {
    header('Location: /admin/users.php?err=' . urlencode('User not found.'));
    exit;
}

$me = current_user();
$canEditAdmin = ((int)$user['id'] !== (int)$me['id']); // Can't change own admin status
$hasLogin = ($user['password_hash'] !== ''); // No-login users have no password set

// Handle admin actions
$action = $_POST['action'] ?? 'update';

if ($action === 'delete') {
    if (!$canEditAdmin) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Cannot delete your own account.'));
        exit;
    }
    
    try {
        $ctx = UserContext::getLoggedInUserContext();
        $deleted = UserManagement::deleteUser($ctx, $userId);
        if ($deleted) {
            header('Location: /admin/users.php?msg=' . urlencode('User deleted successfully.'));
            exit;
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to delete user.'));
            exit;
        }
    } catch (Exception $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error deleting user: ' . $e->getMessage()));
        exit;
    }
}

if ($action === 'send_verification') {
    if (!$canEditAdmin) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Access denied.'));
        exit;
    }
    if (empty($user['email'])) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Add an email address before sending a verification email.'));
        exit;
    }

    try {
        // Generate new verification token
        $ctx = UserContext::getLoggedInUserContext();
        $token = UserManagement::setEmailVerificationToken($ctx, $userId);
        
        // Send verification email
        require_once __DIR__ . '/../mailer.php';
        $sent = send_verification_email($user['email'], $token, $user['first_name']);
        
        if ($sent) {
            header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('Email verification sent successfully.'));
            exit;
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to send verification email.'));
            exit;
        }
    } catch (Exception $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error sending verification email: ' . $e->getMessage()));
        exit;
    }
}

if ($action === 'send_reset') {
    if (!$canEditAdmin) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Access denied.'));
        exit;
    }
    if (empty($user['email']) || !$hasLogin) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Password reset requires an activated account with an email address.'));
        exit;
    }

    try {
        // Use the existing password reset functionality
        $token = UserManagement::setPasswordResetToken($user['email']);
        
        if ($token) {
            header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('Password reset email sent successfully.'));
            exit;
        } else {
            header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Failed to send password reset email.'));
            exit;
        }
    } catch (Exception $e) {
        header('Location: /admin/user_edit.php?id=' . $userId . '&err=' . urlencode('Error sending password reset email: ' . $e->getMessage()));
        exit;
    }
}

// Handle update action
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$canGrantAdmin = $canEditAdmin && $hasLogin; // no-login users cannot be admins
$is_admin = $canGrantAdmin && !empty($_POST['is_admin']) ? 1 : ($canGrantAdmin ? 0 : (int)$user['is_admin']);

// Validation
$errors = [];
if ($first_name === '') {
    $errors[] = 'First name is required.';
}
if ($last_name === '') {
    $errors[] = 'Last name is required.';
}
if ($email === '' && $hasLogin) {
    $errors[] = 'Email is required for users who can sign in.';
} elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
}

// Check if email already exists (but allow keeping the same email)
if (empty($errors) && $email !== '' && strtolower($email) !== strtolower((string)$user['email']) && UserManagement::emailExists($email)) {
    $errors[] = 'Email already exists.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $userId,
        'err' => implode(' ', $errors),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin
    ];
    $query = http_build_query($params);
    header('Location: /admin/user_edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Update user profile
    $updateData = [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email
    ];
    
    $updated = UserManagement::updateProfile($ctx, $userId, $updateData);
    
    // Update admin flag if allowed
    if ($canGrantAdmin) {
        UserManagement::setAdminFlag($ctx, $userId, (bool)$is_admin);
    }

    if ($updated || $canGrantAdmin) {
        // Success - redirect back to edit page with success message
        header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('User updated successfully.'));
        exit;
    } else {
        // No changes made
        header('Location: /admin/user_edit.php?id=' . $userId . '&msg=' . urlencode('No changes were made.'));
        exit;
    }
    
} catch (Exception $e) {
    // Error updating user - redirect back to form
    $params = [
        'id' => $userId,
        'err' => 'Error updating user: ' . $e->getMessage(),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'is_admin' => $is_admin
    ];
    $query = http_build_query($params);
    header('Location: /admin/user_edit.php?' . $query);
    exit;
}
