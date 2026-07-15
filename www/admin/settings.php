<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../settings.php';
Application::init();
require_admin();

$msg = null;
$err = null;

// Settings definitions for the Tasks application
$SETTINGS_DEF = [
  'site_title' => [
    'label' => 'Site Title',
    'hint'  => 'Shown in the header and page titles. Defaults to "Tasks" if empty.',
    'type'  => 'text',
  ],
  'announcement' => [
    'label' => 'Announcement',
    'hint'  => 'Shown on the Home page when non-empty.',
    'type'  => 'textarea',
  ],
  'timezone' => [
    'label' => 'Time zone',
    'hint'  => 'Times are displayed in this time zone.',
    'type'  => 'timezone',
  ],
  'login_image_file_id' => [
    'label' => 'Login Image',
    'hint'  => 'Image displayed on login and authentication pages. Recommended size: 200px wide.',
    'type'  => 'file',
  ],
  'site_base_url' => [
    'label' => 'Site URL',
    'hint'  => 'Used for links in reminder emails, e.g. https://tasks.hackleyclubz.org',
    'type'  => 'text',
  ],
  'task_token_expiry_days' => [
    'label' => 'Task email link expiry (days)',
    'hint'  => 'How long the act-on-a-task links in reminder emails stay valid.',
    'type'  => 'text',
  ],
];

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  try {
    require_once __DIR__ . '/../lib/Files.php';
    $currentUser = current_user();
    
    foreach ($SETTINGS_DEF as $key => $meta) {
      $typ = $meta['type'] ?? 'text';
      
      if ($typ === 'file') {
        // Handle file upload
        $currentVal = $_POST['s'][$key] ?? '';
        $removeFile = !empty($_POST['remove_' . $key]);
        
        if ($removeFile) {
          // Remove current image
          Settings::set($key, '');
        } elseif (isset($_FILES['file_' . $key]) && $_FILES['file_' . $key]['error'] === UPLOAD_ERR_OK) {
          // Upload new image
          $file = $_FILES['file_' . $key];
          $tmpPath = $file['tmp_name'];
          $originalName = $file['name'];
          $size = $file['size'];
          
          // Validate file
          if ($size > 8 * 1024 * 1024) { // 8MB limit
            throw new Exception('Image file too large (max 8MB).');
          }
          
          // Check mime type
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime = $finfo->file($tmpPath);
          $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
          
          if (!in_array($mime, $allowedTypes)) {
            throw new Exception('Invalid image type. Please upload JPEG, PNG, WebP, or GIF.');
          }
          
          // Verify it's actually an image
          if (!@getimagesize($tmpPath)) {
            throw new Exception('Invalid image file.');
          }
          
          // Read file data
          $data = file_get_contents($tmpPath);
          if ($data === false) {
            throw new Exception('Failed to read uploaded file.');
          }
          
          // Store in public files
          $publicFileId = Files::insertPublicFile($data, $mime, $originalName, (int)$currentUser['id']);
          Settings::set($key, (string)$publicFileId);
        } else {
          // Keep current value
          Settings::set($key, $currentVal);
        }
      } elseif ($typ === 'checkbox') {
        Settings::set($key, !empty($_POST['s'][$key]) ? '1' : '0');
      } else {
        // Handle regular settings
        $val = $_POST['s'][$key] ?? '';
        Settings::set($key, $val);
      }
    }
    $msg = 'Settings saved.';
  } catch (Throwable $e) {
    $err = 'Failed to save settings: ' . $e->getMessage();
  }
}

// Gather current values
$current = [];
foreach ($SETTINGS_DEF as $key => $_meta) {
  // Provide sensible defaults
  if ($key === 'site_title') {
    $default = defined('APP_NAME') ? APP_NAME : 'Tasks';
  } elseif ($key === 'timezone') {
    $default = date_default_timezone_get();
  } else {
    $default = '';
  }
  $val = Settings::get($key, $default);
  $current[$key] = $val;
}

header_html('Manage Settings');
?>
<h2>Manage Settings</h2>
<?php if($msg):?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if($err):?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php foreach ($SETTINGS_DEF as $key => $meta): ?>
      <label>
        <?=h($meta['label'])?>
        <?php $typ = $meta['type'] ?? 'text'; ?>
        <?php if ($typ === 'textarea'): ?>
          <textarea name="s[<?=h($key)?>]" rows="4"><?=h($current[$key])?></textarea>
        <?php elseif ($typ === 'timezone'): ?>
          <?php $zones = DateTimeZone::listIdentifiers(); ?>
          <select name="s[<?=h($key)?>]">
            <?php foreach ($zones as $z): ?>
              <option value="<?=h($z)?>" <?= $current[$key] === $z ? 'selected' : '' ?>><?=h($z)?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($typ === 'checkbox'): ?>
          <input type="checkbox" name="s[<?=h($key)?>]" value="1" <?= $current[$key] === '1' ? 'checked' : '' ?> style="width:auto;align-self:flex-start;">
        <?php elseif ($typ === 'file'): ?>
          <?php 
            // Show current image if set
            $currentImageUrl = '';
            if ($key === 'login_image_file_id' && $current[$key] !== '') {
              $currentImageUrl = Settings::loginImageUrl();
            }
          ?>
          <?php if ($currentImageUrl !== ''): ?>
            <div style="margin-bottom: 8px;">
              <img src="<?=h($currentImageUrl)?>" alt="Current login image" style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
          <?php endif; ?>
          <input type="file" name="file_<?=h($key)?>" accept="image/*">
          <input type="hidden" name="s[<?=h($key)?>]" value="<?=h($current[$key])?>">
          <?php if ($currentImageUrl !== ''): ?>
            <label class="inline" style="margin-top: 8px;">
              <input type="checkbox" name="remove_<?=h($key)?>" value="1"> Remove current image
            </label>
          <?php endif; ?>
        <?php else: ?>
          <input type="text" name="s[<?=h($key)?>]" value="<?=h($current[$key])?>">
        <?php endif; ?>
        <?php if (!empty($meta['hint'])): ?>
          <small class="small"><?=h($meta['hint'])?></small>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/index.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
