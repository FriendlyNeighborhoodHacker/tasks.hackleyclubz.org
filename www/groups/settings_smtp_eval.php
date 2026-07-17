<?php
// Evaluates the group SMTP override form (POST from groups/settings_smtp.php):
// save, remove, or send a test email through the saved override.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
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

    if (($_POST['action'] ?? '') === 'remove_smtp_override') {
        GroupSmtpSettings::remove($ctx, $groupId);
        $_SESSION['success'] = 'Group SMTP settings removed; emails use the site-wide sender again.';
    } elseif (($_POST['action'] ?? '') === 'save_reply_to') {
        GroupSmtpSettings::saveReplyTo($ctx, $groupId, (string)($_POST['reply_to_email'] ?? ''));
        $_SESSION['success'] = trim((string)($_POST['reply_to_email'] ?? '')) !== ''
            ? 'Reply-to address saved.'
            : 'Reply-to address cleared; replies go back to the sending address.';
    } elseif (($_POST['action'] ?? '') === 'test_smtp_override') {
        if (!$ctx || !GroupManagement::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can send a test email.');
        }
        $u = current_user();
        if (empty($u['email'])) {
            throw new RuntimeException('Your account has no email address to send the test to.');
        }
        // The group's effective sending config: its SMTP override if saved,
        // else the site-wide sender — plus its Reply-To, exactly as the real
        // assignment/reminder emails send.
        $smtp = GroupSmtpSettings::getForSending($groupId);
        $replyTo = GroupSmtpSettings::getReplyTo($groupId);
        $group = GroupManagement::getGroup($groupId);
        $toName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        $html = '<p>This is a test email from the group "' . htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') . '".</p>'
              . '<p>Sent via ' . ($smtp ? 'the group\'s own SMTP settings' : 'the site-wide sender')
              . ($replyTo !== '' ? ', with replies directed to ' . htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8') : '') . '.</p>';
        require_once __DIR__ . '/../mailer.php';
        $smtpError = '';
        $ok = send_email_with_error((string)$u['email'], 'Test email from ' . $group['name'], $html, $toName, $smtpError, $smtp, $replyTo);
        if ($ok) {
            $_SESSION['success'] = 'Test email sent to ' . $u['email'] . '. Check your inbox (including spam).';
        } else {
            $_SESSION['error'] = 'Test email failed: ' . $smtpError;
        }
    } else {
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
    }

    header('Location: /groups/settings_smtp.php?group_id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    unset($_POST['smtp_password']); // never round-trip the password through the session
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/settings_smtp.php?group_id=' . $groupId);
    exit;
}
