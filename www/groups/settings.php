<?php
// Group settings — rename/describe (group admins); transfer ownership and
// delete (owner only). Evaluates to groups/settings_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
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
