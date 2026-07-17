<?php
// Group settings — rename/describe (group admins); transfer ownership and
// delete (owner only). Evaluates to groups/settings_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
require_once __DIR__ . '/../lib/GroupSmtpSettings.php';
Application::init();
require_login();

$groupId = (int)($_GET['group_id'] ?? 0);
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
$ctx = UserContext::getLoggedInUserContext();

if (!$group || !GroupManagement::canManageGroup($ctx, $groupId)) {
    http_response_code(403);
    die('Only the group owner or a group admin can edit group settings.');
}

$isOwner = $ctx->admin || GroupManagement::isOwner($ctx->id, $groupId);

$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

$values = $form + ['name' => $group['name'], 'description' => $group['description'] ?? ''];
$members = GroupManagement::listMembers($groupId);

// SMTP override form values: repopulate from a failed submit, else the stored
// override. The password is never rendered back into the form.
$smtp = GroupSmtpSettings::get($groupId);
$smtpValues = [
    'smtp_host' => $form['smtp_host'] ?? $smtp['smtp_host'] ?? '',
    'smtp_port' => $form['smtp_port'] ?? $smtp['smtp_port'] ?? 587,
    'smtp_secure' => $form['smtp_secure'] ?? $smtp['smtp_secure'] ?? 'tls',
    'smtp_username' => $form['smtp_username'] ?? $smtp['smtp_username'] ?? '',
    'from_email' => $form['from_email'] ?? $smtp['from_email'] ?? '',
    'from_name' => $form['from_name'] ?? $smtp['from_name'] ?? '',
];

header_html('Settings - ' . $group['name']);
?>
<h2><?=h($group['name'])?> — Settings</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/groups/settings_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <label>Group name
      <input type="text" name="name" value="<?=h($values['name'])?>" required>
    </label>
    <label>Description
      <textarea name="description" rows="3"><?=h($values['description'])?></textarea>
    </label>
    <div class="actions">
      <button class="primary" type="submit">Save Settings</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Email Templates</h3>
  <p class="small">These emails go to your group's members: when a task is assigned, and as scheduled
    reminders (one email when a single task is due, a combined email when several are). Placeholders in
    <code>[brackets]</code> are filled in per person and task when the email is sent.</p>
  <?php foreach (EmailTemplates::TYPES as $type): $tpl = EmailTemplates::getTemplate($groupId, $type); $info = EmailTemplates::TYPE_INFO[$type]; ?>
  <details class="email-template" <?=$tpl['is_custom'] ? 'open' : ''?>>
    <summary><?=h($info['label'])?><?php if ($tpl['is_custom']): ?> <span class="badge success">customized</span><?php endif; ?></summary>
    <p class="small"><?=h($info['help'])?> Available placeholders: <code><?=h($info['tokens'])?></code></p>
    <form method="post" action="/groups/settings_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="group_id" value="<?=$groupId?>">
      <input type="hidden" name="action" value="save_email_template">
      <input type="hidden" name="template_type" value="<?=h($type)?>">
      <label>Subject
        <input type="text" name="subject" value="<?=h($tpl['subject'])?>" required>
      </label>
      <label>Body
        <textarea name="body" rows="10" required><?=h($tpl['body'])?></textarea>
      </label>
      <div class="actions">
        <button class="primary" type="submit">Save Template</button>
        <?php if ($tpl['is_custom']): ?>
        <button class="button" type="submit" name="action" value="reset_email_template"
                data-confirm="Discard this customization and go back to the default template?">Reset to default</button>
        <?php endif; ?>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>Email Sending (SMTP)<?php if ($smtp): ?> <span class="badge success">customized</span><?php endif; ?></h3>
  <p class="small">By default this group's emails are sent from the site-wide address. Fill this in to send
    them from your group's own email account instead — for Gmail, use host <code>smtp.gmail.com</code>,
    port <code>587</code> with <code>tls</code> (or <code>465</code> with <code>ssl</code>), your Gmail
    address as the username, and a Google <strong>app password</strong> (not your regular password).</p>
  <form method="post" action="/groups/settings_eval.php" class="stack">
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

<?php if ($isOwner): ?>
<div class="card">
  <h3>Ownership</h3>
  <p class="small">Owner: <?=h(trim(($group['owner_first_name'] ?? '') . ' ' . ($group['owner_last_name'] ?? '')))?></p>
  <form method="post" action="/groups/settings_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <input type="hidden" name="action" value="transfer_ownership">
    <label>Transfer ownership to
      <select name="new_owner_user_id">
        <?php foreach ($members as $m): if (!empty($m['is_owner'])) continue; ?>
          <option value="<?=(int)$m['id']?>"><?=h(trim($m['first_name'] . ' ' . $m['last_name']))?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="actions">
      <button class="button" type="submit" data-confirm="Transfer ownership of this group? You will remain a group admin.">Transfer Ownership</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Danger Zone</h3>
  <form method="post" action="/groups/delete_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <button class="button danger" type="submit" data-confirm="Delete this group and ALL of its tasks? This cannot be undone.">Delete Group</button>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
