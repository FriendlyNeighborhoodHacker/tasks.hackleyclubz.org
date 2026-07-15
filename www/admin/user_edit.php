<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_admin();

$msg = null;
$err = null;

// Get user ID
$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /admin/users.php');
    exit;
}

// Load user data
$user = UserManagement::findById($userId);
if (!$user) {
    header('Location: /admin/users.php?err=' . urlencode('User not found.'));
    exit;
}

// Handle messages from evaluation script
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// For repopulating form after errors - get from URL parameters or use current user data
$form = [];
$formFields = ['first_name', 'last_name', 'email', 'is_admin'];
foreach ($formFields as $field) {
    if (isset($_GET[$field])) {
        $form[$field] = $_GET[$field];
    } else {
        $form[$field] = $user[$field] ?? '';
    }
}

$me = current_user();
$canEditAdmin = ((int)$user['id'] !== (int)$me['id']); // Can't change own admin status
$hasLogin = ($user['password_hash'] !== ''); // No-login users have no password set
$hasEmail = !empty($user['email']);

$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
header_html('Edit ' . $userName);

// Handle messages from upload_photo redirect
if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
if (isset($_GET['photo_err'])) { $err = 'Photo upload failed.'; }
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Edit <?= h($userName) ?></h2>
  <div style="display:flex;align-items:center;gap:12px;">
    <a class="button" href="/admin/users.php">Back to Users</a>
    <?php if ($canEditAdmin): ?>
      <div class="nav-admin-wrap">
        <button type="button" id="adminActionsToggle" class="button nav-admin-link" aria-expanded="false">Admin Actions</button>
        <div id="adminActionsMenu" class="admin-menu hidden" role="menu" aria-hidden="true">
          <a href="#" role="menuitem" onclick="if(confirm('Delete this user? This cannot be undone.')) { document.getElementById('deleteForm').submit(); } return false;">Delete User</a>
          <?php if ($hasEmail): ?>
            <a href="#" role="menuitem" onclick="document.getElementById('sendVerificationForm').submit(); return false;"><?= $hasLogin ? 'Send Email Verification' : 'Send Account Activation' ?></a>
            <?php if ($hasLogin): ?>
              <a href="#" role="menuitem" onclick="document.getElementById('sendResetForm').submit(); return false;">Send Password Reset</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $userInitials = strtoupper(substr((string)($user['first_name'] ?? ''),0,1).substr((string)($user['last_name'] ?? ''),0,1));
      $userPhotoUrl = Files::profilePhotoUrl($user['photo_public_file_id'] ?? null);
    ?>
    <?php if ($userPhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($userPhotoUrl) ?>" alt="<?= h($userName) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:#007bff;color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:500;"><?= h($userInitials) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?user_id=<?= (int)$userId ?>&return_to=<?= urlencode('/admin/user_edit.php?id=' . $userId) ?>" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px" id="profilePhotoForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button" id="profilePhotoBtn">Upload Photo</button>
      </div>
    </form>
    <?php if (!empty($userPhotoUrl)): ?>
      <form method="post" action="/upload_photo.php?user_id=<?= (int)$userId ?>&return_to=<?= urlencode('/admin/user_edit.php?id=' . $userId) ?>" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form method="post" action="/admin/user_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$userId ?>">
    
    <h3>User Information</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($form['email'] ?? '')?>" <?= $hasLogin ? 'required' : '' ?>>
        <?php if (!$hasLogin): ?>
          <small class="small">Optional for people without a login. Add an email to enable account activation.</small>
        <?php endif; ?>
      </label>
      <?php if ($canEditAdmin && $hasLogin): ?>
        <label class="inline">
          <input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>>
          Admin user
        </label>
      <?php elseif (!$hasLogin): ?>
        <div class="small" style="color: #6c757d;">
          Admin status: No (people without a login cannot be admins)
        </div>
      <?php else: ?>
        <div class="small" style="color: #6c757d;">
          Admin status: <?= !empty($user['is_admin']) ? 'Yes' : 'No' ?> (cannot change your own admin status)
        </div>
      <?php endif; ?>
    </div>

    <h3>Account Status</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <div>
        <strong>Login:</strong>
        <?php if (!empty($user['email_verified_at'])): ?>
          <span class="status-verified">Active</span>
        <?php elseif (!$hasLogin && !$hasEmail): ?>
          <span class="small">No login (person only)</span>
        <?php elseif (!$hasLogin): ?>
          <span class="status-pending">Awaiting account activation</span>
        <?php else: ?>
          <span class="status-pending">Pending email verification</span>
        <?php endif; ?>
      </div>
      <div>
        <strong>Created:</strong> <?= h(date('M j, Y g:i A', strtotime($user['created_at']))) ?>
      </div>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Update User</button>
    </div>
  </form>
  
  <?php if ($canEditAdmin): ?>
    <form id="deleteForm" method="post" action="/admin/user_edit_eval.php" style="display: none;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= (int)$userId ?>">
      <input type="hidden" name="action" value="delete">
    </form>
    
    <form id="sendVerificationForm" method="post" action="/admin/user_edit_eval.php" style="display: none;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= (int)$userId ?>">
      <input type="hidden" name="action" value="send_verification">
    </form>
    
    <form id="sendResetForm" method="post" action="/admin/user_edit_eval.php" style="display: none;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= (int)$userId ?>">
      <input type="hidden" name="action" value="send_reset">
    </form>
  <?php endif; ?>
</div>

<script>
  (function(){
    // Admin Actions dropdown
    var adminToggle = document.getElementById('adminActionsToggle');
    var adminMenu = document.getElementById('adminActionsMenu');
    
    function hideAdminMenu() {
      if (adminMenu) {
        adminMenu.classList.add('hidden');
        adminMenu.setAttribute('aria-hidden', 'true');
      }
      if (adminToggle) {
        adminToggle.setAttribute('aria-expanded', 'false');
      }
    }
    
    function toggleAdminMenu(e) {
      e.preventDefault();
      if (!adminMenu) return;
      var isHidden = adminMenu.classList.contains('hidden');
      if (isHidden) {
        adminMenu.classList.remove('hidden');
        adminMenu.setAttribute('aria-hidden', 'false');
        if (adminToggle) adminToggle.setAttribute('aria-expanded', 'true');
      } else {
        hideAdminMenu();
      }
    }
    
    if (adminToggle) {
      adminToggle.addEventListener('click', toggleAdminMenu);
    }
    
    // Click outside to close
    document.addEventListener('click', function(e) {
      if (!adminMenu || !adminToggle) return;
      var adminWrap = adminToggle.closest('.nav-admin-wrap');
      if (adminWrap && adminWrap.contains(e.target)) return;
      hideAdminMenu();
    });
    
    // Escape key to close
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        hideAdminMenu();
      }
    });
    
    // Profile photo upload protection
    var profilePhotoForm = document.getElementById('profilePhotoForm');
    var profilePhotoBtn = document.getElementById('profilePhotoBtn');
    
    if (profilePhotoForm && profilePhotoBtn) {
      profilePhotoForm.addEventListener('submit', function(e) {
        if (profilePhotoBtn.disabled) {
          e.preventDefault();
          return;
        }
        profilePhotoBtn.disabled = true;
        profilePhotoBtn.textContent = 'Uploading...';
      });
    }
  })();
</script>

<?php footer_html(); ?>
