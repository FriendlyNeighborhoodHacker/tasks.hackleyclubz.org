<?php
// My Profile — view page. Editing happens on profile/edit.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$me = current_user();

// One-shot flash from eval pages
$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

header_html('My Profile');

$meName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));
$meInitials = strtoupper((string)substr((string)($me['first_name'] ?? ''), 0, 1) . (string)substr((string)($me['last_name'] ?? ''), 0, 1));
$mePhotoUrl = Files::profilePhotoUrl($me['photo_public_file_id'] ?? null, 80);
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>My Profile</h2>
  <div style="display:flex;gap:8px;">
    <a class="button" href="/profile/edit.php">Edit Profile</a>
    <a class="button" href="/profile/edit_picture.php">Edit Picture</a>
    <a class="button" href="/profile/change_password.php">Change Password</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <?php if ($mePhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($mePhotoUrl) ?>" alt="<?= h($meName) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:#007bff;color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:500;"><?= h($meInitials) ?></div>
    <?php endif; ?>
    <div>
      <h3 style="margin:0 0 4px 0;"><?= h($meName) ?></h3>
      <p style="margin:0;" class="small"><?= h($me['email'] ?? '') ?></p>
      <?php if (!empty($me['is_admin'])): ?>
        <p style="margin:4px 0 0 0;" class="small">Administrator</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php footer_html(); ?>
