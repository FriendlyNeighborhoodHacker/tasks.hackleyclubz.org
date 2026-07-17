<?php
// Group settings — Email Sending tab: the group's SMTP override, so its
// emails come from the group's own address (e.g. Gmail with an app password)
// instead of the site-wide sender. Group admins only. Evaluates to
// groups/settings_smtp_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/GroupSmtpSettings.php';
Application::init();
require_login();

$groupId = (int)($_GET['group_id'] ?? 0);
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
$ctx = UserContext::getLoggedInUserContext();

if (!$group || !GroupManagement::canManageGroup($ctx, $groupId)) {
    http_response_code(403);
    die('Only the group owner or a group admin can edit SMTP settings.');
}

$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

// Repopulate from a failed submit, else the stored override. The password is
// never rendered back into the form.
$smtp = GroupSmtpSettings::get($groupId);
$smtpValues = [
    'smtp_host' => $form['smtp_host'] ?? $smtp['smtp_host'] ?? '',
    'smtp_port' => $form['smtp_port'] ?? $smtp['smtp_port'] ?? 587,
    'smtp_secure' => $form['smtp_secure'] ?? $smtp['smtp_secure'] ?? 'tls',
    'smtp_username' => $form['smtp_username'] ?? $smtp['smtp_username'] ?? '',
    'from_email' => $form['from_email'] ?? $smtp['from_email'] ?? '',
    'from_name' => $form['from_name'] ?? $smtp['from_name'] ?? '',
];

header_html('Email Sending - ' . $group['name']);
?>
<h2><?=h($group['name'])?> — Settings</h2>
<?=group_settings_tabs_html($groupId, 'smtp')?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Email Sending (SMTP)<?php if ($smtp): ?> <span class="badge success">customized</span><?php endif; ?></h3>
  <p class="small">By default this group's emails are sent from the site-wide address. Fill this in to send
    them from your group's own email account instead — for Gmail, use host <code>smtp.gmail.com</code>,
    port <code>587</code> with <code>tls</code> (or <code>465</code> with <code>ssl</code>), your Gmail
    address as the username, and a Google <strong>app password</strong> (not your regular password).</p>
  <form method="post" action="/groups/settings_smtp_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <input type="hidden" name="action" value="save_smtp_override">
    <label>SMTP host
      <input type="text" name="smtp_host" value="<?=h($smtpValues['smtp_host'])?>" placeholder="smtp.gmail.com">
    </label>
    <label>SMTP port
      <input type="number" name="smtp_port" value="<?=h((string)$smtpValues['smtp_port'])?>" min="1" max="65535">
    </label>
    <label>Security
      <select name="smtp_secure">
        <?php foreach (GroupSmtpSettings::SECURE_OPTIONS as $opt): ?>
          <option value="<?=h($opt)?>" <?=$smtpValues['smtp_secure'] === $opt ? 'selected' : ''?>><?=h(strtoupper($opt))?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Username
      <input type="text" name="smtp_username" value="<?=h($smtpValues['smtp_username'])?>" placeholder="your Gmail address" autocomplete="off">
    </label>
    <label>Password
      <input type="password" name="smtp_password" value=""
             placeholder="<?=$smtp ? 'Leave blank to keep the current password' : 'App password'?>" autocomplete="new-password">
    </label>
    <label>From email <span class="small">(optional — defaults to the username)</span>
      <input type="text" name="from_email" value="<?=h($smtpValues['from_email'])?>">
    </label>
    <label>From name <span class="small">(optional — defaults to the group name)</span>
      <input type="text" name="from_name" value="<?=h($smtpValues['from_name'])?>">
    </label>
    <div class="actions">
      <button class="primary" type="submit">Save SMTP Settings</button>
      <?php if ($smtp): ?>
      <button class="button" type="submit" name="action" value="test_smtp_override">Send Test Email to Me</button>
      <button class="button" type="submit" name="action" value="remove_smtp_override"
              data-confirm="Remove this group's SMTP settings and go back to the site-wide sender?">Use site default</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php footer_html(); ?>
