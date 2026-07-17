<?php
// Group settings — General tab: rename/describe (group admins); transfer
// ownership and delete (owner only). Evaluates to groups/settings_eval.php.
// Email templates and SMTP live on their own tabs (settings_templates.php,
// settings_smtp.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
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
<?=group_settings_tabs_html($groupId, 'general')?>
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
