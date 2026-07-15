<?php
// People in a group: members list + add person. Group admins only.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
Application::init();
require_login();

$groupId = (int)($_GET['group_id'] ?? 0);
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
$ctx = UserContext::getLoggedInUserContext();

if (!$group || !GroupManagement::canManageGroup($ctx, $groupId)) {
    http_response_code(403);
    die('Only the group owner or a group admin can manage people.');
}

$isOwner = $ctx->admin || GroupManagement::isOwner($ctx->id, $groupId);

$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

$members = GroupManagement::listMembers($groupId);

header_html('People - ' . $group['name']);
?>
<h2><?=h($group['name'])?> — People</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <table class="list">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <?php
        $mid = (int)$m['id'];
        if (!empty($m['has_password'])) {
            $status = 'Active';
        } elseif (!empty($m['invite_pending'])) {
            $status = 'Invited';
        } elseif (!empty($m['email'])) {
            $status = 'Email only';
        } else {
            $status = 'No login';
        }
      ?>
      <tr>
        <td><?=h(trim($m['first_name'] . ' ' . $m['last_name']))?><?php if (!empty($m['is_owner'])): ?> <span class="badge">Owner</span><?php endif; ?></td>
        <td><?=h($m['email'] ?? '')?></td>
        <td>
          <?php if (!empty($m['is_owner'])): ?>
            Admin
          <?php elseif ($isOwner): ?>
            <form method="post" action="/groups/people_role_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="group_id" value="<?=$groupId?>">
              <input type="hidden" name="user_id" value="<?=$mid?>">
              <select name="role" onchange="this.form.submit()">
                <option value="member" <?=$m['role']==='member'?'selected':''?>>Member</option>
                <option value="admin" <?=$m['role']==='admin'?'selected':''?>>Admin</option>
              </select>
            </form>
          <?php else: ?>
            <?=h(ucfirst((string)$m['role']))?>
          <?php endif; ?>
        </td>
        <td><span class="small"><?=h($status)?></span></td>
        <td class="actions">
          <?php if ($status === 'Email only' || $status === 'Invited'): ?>
            <form method="post" action="/groups/people_invite_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="group_id" value="<?=$groupId?>">
              <input type="hidden" name="user_id" value="<?=$mid?>">
              <button class="button" type="submit"><?=$status === 'Invited' ? 'Re-send invite' : 'Send invite'?></button>
            </form>
          <?php endif; ?>
          <?php if (empty($m['is_owner']) && ($isOwner || $m['role'] !== 'admin')): ?>
            <form method="post" action="/groups/people_remove_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="group_id" value="<?=$groupId?>">
              <input type="hidden" name="user_id" value="<?=$mid?>">
              <button class="button danger" type="submit" data-confirm="Remove <?=h($m['first_name'])?> from this group?">Remove</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add a Person</h3>
  <p><span class="prompt-em">Enter their name and email.</span> If they are new here, an account is created for them — sending an invitation is optional; they will get task emails either way.</p>
  <form method="post" action="/groups/people_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <div class="grid">
      <label>First name
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($form['email'] ?? '')?>" required>
      </label>
      <?php if ($isOwner): ?>
      <label>Role
        <select name="role">
          <option value="member">Member</option>
          <option value="admin" <?=($form['role'] ?? '')==='admin'?'selected':''?>>Admin</option>
        </select>
      </label>
      <?php endif; ?>
    </div>
    <label class="inline"><input type="checkbox" name="send_invite" value="1" <?=!empty($form['send_invite'])?'checked':''?>> Email them an invitation to set a password</label>
    <div class="actions">
      <button class="primary" type="submit">Add Person</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
