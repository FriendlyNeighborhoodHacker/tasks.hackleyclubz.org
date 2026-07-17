<?php
// Evaluates the email template forms (POST from groups/settings_templates.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
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

    if (($_POST['action'] ?? '') === 'reset_email_template') {
        EmailTemplates::resetTemplate($ctx, $groupId, (string)($_POST['template_type'] ?? ''));
        $_SESSION['success'] = 'Email template reset to the default.';
    } else {
        EmailTemplates::saveTemplate($ctx, $groupId, (string)($_POST['template_type'] ?? ''), (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''));
        $_SESSION['success'] = 'Email template saved.';
    }

    header('Location: /groups/settings_templates.php?group_id=' . $groupId);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
    $_SESSION['form_data'] = $_POST;
    header('Location: /groups/settings_templates.php?group_id=' . $groupId);
    exit;
}
