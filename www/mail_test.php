<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/mailer.php';
Application::init();
require_admin();

$u = current_user();
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $subject = trim($_POST['subject'] ?? '');
  $body    = trim($_POST['body'] ?? '');
  if ($subject === '') {
    $err = 'Subject is required.';
  } else {
    $toEmail = $u['email'];
    $toName  = $u['first_name'].' '.$u['last_name'];
    // Send as simple HTML (preserve newlines)
    $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    
    // Clear any previous error logs for this test
    error_clear_last();
    
    // Capture detailed SMTP errors
    $smtpError = '';
    $ok = send_email_with_error($toEmail, $subject, $html, $toName, $smtpError);
    if ($ok) {
      $msg = "Email appears to have been sent to {$toEmail}. Check your inbox (including spam folder).";
    } else {
      $err = 'Failed to send email. Check SMTP settings in config.local.php.';
      if ($smtpError) {
        $err .= '<br><strong>SMTP Error:</strong> ' . htmlspecialchars($smtpError, ENT_QUOTES, 'UTF-8');
      }
    }
  }
}

$siteTitle = Settings::siteTitle();

header_html('Mail Test');
?>
<h2>Mail Test</h2>
<?php if($msg):?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if($err):?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p class="small">This sends an email to your account (<?=h($u['email'])?>) using the configured SMTP settings.</p>
  <ul class="small">
    <li>Host: <?= defined('SMTP_HOST') ? h(SMTP_HOST) : '<em>undefined</em>' ?></li>
    <li>Port: <?= defined('SMTP_PORT') ? h(SMTP_PORT) : '<em>undefined</em>' ?></li>
    <li>Security: <?= defined('SMTP_SECURE') ? h(SMTP_SECURE) : '<em>undefined</em>' ?></li>
    <li>From: <?= defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? h(SMTP_FROM_EMAIL) : (defined('SMTP_USER') ? h(SMTP_USER) : '<em>undefined</em>') ?></li>
  </ul>
  
  <?php if (!defined('SMTP_HOST') || !defined('SMTP_PORT')): ?>
    <div class="small" style="background: #f8d7da; padding: 8px; border-radius: 4px; margin-top: 8px; color: #721c24;">
      <strong>Missing Configuration:</strong> You're missing SMTP_HOST and/or SMTP_PORT definitions. Add these to your config.local.php:
      <ul style="margin: 4px 0;">
        <li><strong>Gmail:</strong> SMTP_HOST = 'smtp.gmail.com', SMTP_PORT = 465</li>
        <li><strong>Outlook:</strong> SMTP_HOST = 'smtp-mail.outlook.com', SMTP_PORT = 587</li>
        <li><strong>Yahoo:</strong> SMTP_HOST = 'smtp.mail.yahoo.com', SMTP_PORT = 465</li>
      </ul>
    </div>
  <?php elseif (defined('SMTP_SECURE') && SMTP_SECURE === 'tls'): ?>
    <div class="small" style="background: #fff3cd; padding: 8px; border-radius: 4px; margin-top: 8px;">
      <strong>STARTTLS Troubleshooting:</strong> If you're getting "STARTTLS failed" errors, try these common configurations:
      <ul style="margin: 4px 0;">
        <li><strong>Gmail:</strong> SMTP_SECURE = 'ssl', SMTP_PORT = 465</li>
        <li><strong>Outlook/Hotmail:</strong> SMTP_SECURE = 'tls', SMTP_PORT = 587</li>
        <li><strong>Yahoo:</strong> SMTP_SECURE = 'ssl', SMTP_PORT = 465</li>
        <li><strong>Generic:</strong> Try changing SMTP_SECURE from 'tls' to 'ssl'</li>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label>Subject
      <input name="subject" required value="<?=h($_POST['subject'] ?? 'Test email from '.h($siteTitle))?>">
    </label>
    <label>Body
      <textarea name="body" rows="6"><?=h($_POST['body'] ?? "Hello,\n\nThis is a test email from ".h($siteTitle).".\n\nIf you received this, SMTP is working.")?></textarea>
    </label>
    <div class="actions">
      <button class="primary" type="submit">Send Test Email</button>
      <a class="button" href="/index.php">Back</a>
    </div>
  </form>
</div>
<?php footer_html(); ?>
