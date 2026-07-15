<?php
// Admin: all groups in the app, with unrestricted edit access.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
Application::init();
require_login();
require_admin();

$search = trim((string)($_GET['q'] ?? ''));
$ctx = UserContext::getLoggedInUserContext();
$groups = GroupManagement::listAllGroups($ctx, $search);

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

header_html('All Groups');
?>
<h2>All Groups</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="toolbar">
  <form method="get" action="/admin/groups.php" data-auto-submit>
    <input type="search" name="q" value="<?=h($search)?>" placeholder="Search groups…">
  </form>
</div>

<div class="card">
  <table class="list">
    <thead><tr><th>Group</th><th>Owner</th><th>Members</th><th>Tasks</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <tr>
        <td><a href="/admin/group_edit.php?id=<?=(int)$g['id']?>"><?=h($g['name'])?></a></td>
        <td><?=h(trim(($g['owner_first_name'] ?? '') . ' ' . ($g['owner_last_name'] ?? '')))?></td>
        <td><?=(int)$g['member_count']?></td>
        <td><?=(int)$g['task_count']?></td>
        <td><?=h(date('M j, Y', strtotime($g['created_at'])))?></td>
        <td class="actions">
          <a class="button" href="/tasks/index.php?group_id=<?=(int)$g['id']?>">Tasks</a>
          <a class="button" href="/groups/people.php?group_id=<?=(int)$g['id']?>">People</a>
          <a class="button" href="/admin/group_edit.php?id=<?=(int)$g['id']?>">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$groups): ?><p class="small">No groups<?=$search !== '' ? ' match your search' : ' yet'?>.</p><?php endif; ?>
</div>

<?php footer_html(); ?>
