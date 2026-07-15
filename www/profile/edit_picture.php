<?php
// Edit my profile picture. Uploads evaluate to /upload_photo.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$me = current_user();

$msg = null;
$err = null;
if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }

header_html('Edit Picture');

$meName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));
$meInitials = strtoupper((string)substr((string)($me['first_name'] ?? ''), 0, 1) . (string)substr((string)($me['last_name'] ?? ''), 0, 1));
$mePhotoUrl = Files::profilePhotoUrl($me['photo_public_file_id'] ?? null, 80);
?>
<h2>Edit Picture</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php if ($mePhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($mePhotoUrl) ?>" alt="<?= h($meName) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:#007bff;color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:500;"><?= h($meInitials) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?user_id=<?= (int)$me['id'] ?>&return_to=<?= urlencode('/profile/edit_picture.php') ?>" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px" id="profilePhotoForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button" id="profilePhotoBtn">Upload Photo</button>
      </div>
    </form>
    <?php if (!empty($mePhotoUrl)): ?>
      <form method="post" action="/upload_photo.php?user_id=<?= (int)$me['id'] ?>&return_to=<?= urlencode('/profile/edit_picture.php') ?>" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="actions" style="margin-top:12px;">
    <a class="button" href="/profile/">Back to Profile</a>
  </div>
</div>

<script>
  (function(){
    // Double-click protection for the upload button
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
