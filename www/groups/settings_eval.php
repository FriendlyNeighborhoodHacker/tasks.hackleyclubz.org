<?php
// Evaluates the group settings forms (POST from groups/settings.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
require_once __DIR__ . '/../lib/GroupSmtpSettings.php';
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
    } elseif (($_POST['action'] ?? '') === 'save_email_template') {
        EmailTemplates::saveTemplate($ctx, $groupId, (string)($_POST['template_type'] ?? ''), (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''));
        $_SESSION['success'] = 'Email template saved.';
    } elseif (($_POST['action'] ?? '') === 'reset_email_template') {
        EmailTemplates::resetTemplate($ctx, $groupId, (string)($_POST['template_type'] ?? ''));
        $_SESSION['success'] = 'Email template reset to the default.';
    } elseif (($_POST['action'] ?? '') === 'save_smtp_override') {
        GroupSmtpSettings::save($ctx, $groupId, [
            'smtp_host' => $_POST['smtp_host'] ?? '',
            'smtp_port' => $_POST['smtp_port'] ?? '',
            'smtp_username' => $_POST['smtp_username'] ?? '',
            'smtp_password' => $_POST['smtp_password'] ?? '',
            'smtp_secure' => $_POST['smtp_secure'] ?? '',
            'from_email' => $_POST['from_email'] ?? '',
            'from_name' => $_POST['from_name'] ?? '',
        ]);
        $_SESSION['success'] = 'Group SMTP settings saved. Use "Send Test Email to Me" to verify them.';
    } elseif (($_POST['action'] ?? '') === 'remove_smtp_override') {
        GroupSmtpSettings::remove($ctx, $groupId);
        $_SESSION['success'] = 'Group SMTP settings removed; emails use the site-wide sender again.';
    } elseif (($_POST['action'] ?? '') === 'test_smtp_override') {
        if (!$ctx || !GroupManagement::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can send a test email.');
        }
        $smtp = GroupSmtpSettings::getForSending($groupId);
        if (!$smtp) {
            throw new RuntimeException('Save your SMTP settings first, then send a test email.');
        }
        $u = current_user();
        if (empty($u['email'])) {
            throw new RuntimeException('Your account has no email address to send the test to.');
        }
        $group = GroupManagement::getGroup($groupId);
        $toName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $html = '<p>This is a test email from the group "' . htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') . '".</p>'
              . '<p>If you received it, the group\'s SMTP settings are working.</p>';
        require_once __DIR__ . '/../mailer.php';
        $smtpError = '';
        $ok = send_email_with_error((string)$u['email'], 'Test email from ' . $group['name'], $html, $toName, $smtpError, $smtp);
        if ($ok) {
            $_SESSION['success'] = 'Test email sent to ' . $u['email'] . '. Check your inbox (including spam).';
        } else {
            $_SESSION['error'] = 'Test email failed: ' . $smtpError;
        }
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
    unset($_POST['smtp_password']); // never round-trip the password through the session
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/settings.php?group_id=' . $groupId);
    exit;
}
