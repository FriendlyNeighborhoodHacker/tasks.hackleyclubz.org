<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

$me = current_user();

// Handle search
$search = trim($_GET['q'] ?? '');
$users = UserManagement::listUsers($search);

header_html('Users');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Users</h2>
  <a class="button" href="/admin/user_add.php">Add User</a>
</div>

<div class="card">
  <form method="get" class="stack">
    <div class="grid" style="grid-template-columns:1fr auto;gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?=h($search)?>" placeholder="Name or email">
      </label>
      <div style="align-self:end;">
        <button type="submit" class="button">Search</button>
      </div>
    </div>
  </form>
  
  <script>
    (function(){
      var form = document.querySelector('form[method="get"]');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var t;
      function submitNow() {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
      if (q) {
        q.addEventListener('input', function(){
          if (t) clearTimeout(t);
          t = setTimeout(submitNow, 600);
        });
      }
    })();
  </script>
</div>

<?php if (empty($users)): ?>
  <p class="small">No users found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Admin</th>
          <th>Status</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></td>
            <td><?= h($user['email'] ?? '') ?></td>
            <td><?= !empty($user['is_admin']) ? 'Yes' : 'No' ?></td>
            <td>
              <?php if (!empty($user['email_verified_at'])): ?>
                <span class="status-verified">Verified</span>
              <?php elseif (empty($user['has_password']) && empty($user['email'])): ?>
                <span class="small">No login</span>
              <?php else: ?>
                <span class="status-pending">Pending</span>
              <?php endif; ?>
            </td>
            <td><?= h(date('M j, Y', strtotime($user['created_at']))) ?></td>
            <td class="small">
              <a class="button small" href="/admin/user_edit.php?id=<?= (int)$user['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
