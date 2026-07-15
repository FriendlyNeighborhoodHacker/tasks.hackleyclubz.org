<?php
// All of my groups. Opening a group makes it the current one, which puts it in
// the first slot of the sidebar navigation.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();
$groups = GroupManagement::listGroupsForUser($ctx->id);
$currentGroupId = GroupManagement::resolveCurrentGroupId($ctx);

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

header_html('My Groups');
?>
<h2>My Groups</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (!$groups): ?>
    <p><span class="prompt-em">Create your first group</span> to start managing tasks with other people.</p>
  <?php else: ?>
  <table class="list">
    <thead><tr><th>Group</th><th>My Role</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <?php
        $gid = (int)$g['id'];
        $role = !empty($g['is_owner']) ? 'Owner' : ucfirst((string)$g['role']);
      ?>
      <tr>
        <td>
          <a href="/tasks/index.php?group_id=<?=$gid?>"><?=h($g['name'])?></a>
          <?php if ($gid === $currentGroupId): ?><span class="badge">Current</span><?php endif; ?>
          <?php if (!empty($g['description'])): ?><div class="small"><?=h($g['description'])?></div><?php endif; ?>
        </td>
        <td><?=h($role)?></td>
        <td class="actions">
          <a class="button" href="/tasks/index.php?group_id=<?=$gid?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="actions" style="margin-top:12px;">
    <a class="button primary" href="/groups/add.php">+ New Group</a>
  </div>
</div>

<?php footer_html(); ?>
