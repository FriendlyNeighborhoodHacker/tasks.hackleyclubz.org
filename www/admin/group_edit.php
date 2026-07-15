<?php
// Admin: edit any group without restrictions — rename, transfer ownership,
// delete. Membership and roles are managed on the group's People page (app
// admins can use it for any group). Evaluates to admin/group_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();
require_admin();

$groupId = (int)($_GET['id'] ?? 0);
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
if (!$group) {
    http_response_code(404);
    die('Group not found');
}

$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

$values = $form + ['name' => $group['name'], 'description' => $group['description'] ?? ''];
$allUsers = UserManagement::listUsers();

header_html('Edit Group - ' . $group['name']);
?>
<h2>Edit Group: <?=h($group['name'])?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/group_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <label>Group name
      <input type="text" name="name" value="<?=h($values['name'])?>" required>
    </label>
    <label>Description
      <textarea name="description" rows="3"><?=h($values['description'])?></textarea>
    </label>
    <label>Owner
      <select name="owner_user_id">
        <?php foreach ($allUsers as $u): ?>
          <option value="<?=(int)$u['id']?>" <?=(int)$u['id'] === (int)$group['owner_user_id'] ? 'selected' : ''?>>
            <?=h(trim($u['first_name'] . ' ' . $u['last_name']))?><?=!empty($u['email']) ? ' (' . h($u['email']) . ')' : ''?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="actions">
      <button class="primary" type="submit">Save Group</button>
      <a class="button" href="/admin/groups.php">Back</a>
      <a class="button" href="/groups/people.php?group_id=<?=$groupId?>">Manage People</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Danger Zone</h3>
  <form method="post" action="/admin/group_edit_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <input type="hidden" name="action" value="delete">
    <button class="button danger" type="submit" data-confirm="Delete this group and ALL of its tasks? This cannot be undone.">Delete Group</button>
  </form>
</div>

<?php footer_html(); ?>
