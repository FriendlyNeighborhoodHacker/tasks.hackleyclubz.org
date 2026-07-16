<?php
// A group's tasks: by-week view (default) with an alternate by-category view,
// plus an "only my tasks" toggle. Group admins default to all tasks; regular
// members default to just their own (see docs/app-spec.md).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();

$groupId = (int)($_GET['group_id'] ?? 0);
if (!$groupId) {
    $groupId = (int)(GroupManagement::resolveCurrentGroupId($ctx) ?? 0);
    if (!$groupId) {
        header('Location: /index.php');
        exit;
    }
}

$group = GroupManagement::getGroup($groupId);
if (!$group || !GroupManagement::canViewGroup($ctx, $groupId)) {
    http_response_code(403);
    die('You are not a member of this group.');
}

// Visiting a group makes it the current one (first slot in the sidebar).
if (GroupManagement::isMember($ctx->id, $groupId)) {
    GroupManagement::setCurrentGroup($ctx, $groupId);
}

$isGroupAdmin = $ctx->admin || GroupManagement::isGroupAdmin($ctx->id, $groupId);

$view = ($_GET['view'] ?? 'week') === 'category' ? 'category' : 'week';
$mine = isset($_GET['mine']) ? !empty($_GET['mine']) : !$isGroupAdmin;
$showDone = !empty($_GET['show_done']);
$search = trim((string)($_GET['q'] ?? ''));

$filters = ['include_done' => $showDone];
if ($search !== '') $filters['search'] = $search;
if ($mine) $filters['assigned_to_user_id'] = $ctx->id;

$tasks = TaskManagement::listTasks($groupId, $filters);
$today = date('Y-m-d');
$groups = $view === 'category'
    ? TaskManagement::groupTasksByCategory($tasks)
    : TaskManagement::groupTasksByWeek($tasks, $today);

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

function tasks_view_url(int $groupId, string $view, bool $mine, bool $showDone, string $search): string {
    $params = ['group_id' => $groupId, 'view' => $view, 'mine' => $mine ? 1 : 0];
    if ($showDone) $params['show_done'] = 1;
    if ($search !== '') $params['q'] = $search;
    return '/tasks/index.php?' . http_build_query($params);
}

// Board group rail/title colors, monday.com-style: semantic colors for the
// fixed buckets, a rotating palette for week/category groups.
function board_group_color(string $label, int $cycleIndex): string {
    if ($label === 'Overdue') return 'var(--board-red)';
    if ($label === 'Completed' || $label === 'No due date') return 'var(--board-gray)';
    $cycle = ['var(--board-blue)', 'var(--board-green)', 'var(--board-purple)', 'var(--board-orange)'];
    return $cycle[$cycleIndex % count($cycle)];
}

// Stable avatar color per assignee name.
function board_avatar_color(string $name): string {
    $palette = ['#579bfc', '#00c875', '#a25ddc', '#fdab3d', '#e2445c', '#0086c0', '#9d99b9', '#037f4c'];
    return $palette[crc32($name) % count($palette)];
}

function board_assignee_html(array $t): string {
    $name = trim(($t['assignee_first_name'] ?? '') . ' ' . ($t['assignee_last_name'] ?? ''));
    if ($name === '') return '<span class="unassigned">Unassigned</span>';
    $initials = strtoupper(mb_substr($t['assignee_first_name'] ?? '', 0, 1) . mb_substr($t['assignee_last_name'] ?? '', 0, 1));
    if ($initials === '') $initials = strtoupper(mb_substr($name, 0, 1));
    return '<span class="assignee"><span class="assignee-avatar" style="background:' . h(board_avatar_color($name)) . '">' . h($initials)
        . '</span><span class="assignee-name">' . h($name) . '</span></span>';
}

// Due/status column as a monday.com-style colored pill.
function board_status_html(array $t, string $today): string {
    if (!empty($t['is_done'])) {
        $when = $t['completion_date'] ? ' ' . date('M j', strtotime($t['completion_date'])) : '';
        return '<span class="pill pill-done">✓ Done' . h($when) . '</span>';
    }
    $due = $t['due_date'] ?? null;
    if (!$due) return '<span class="pill pill-none">No due date</span>';

    $days = (int)round((strtotime($due) - strtotime($today)) / 86400);
    $dateLabel = date('M j', strtotime($due));
    if (date('Y', strtotime($due)) !== date('Y', strtotime($today))) {
        $dateLabel = date('M j, Y', strtotime($due));
    }
    if ($days < 0) {
        $n = -$days;
        return '<span class="pill pill-overdue">' . h($dateLabel) . ' · ' . $n . 'd overdue</span>';
    }
    if ($days === 0) return '<span class="pill pill-today">Due today</span>';
    if ($days <= 30) return '<span class="pill pill-soon">Due ' . h($dateLabel) . '</span>';
    return '<span class="pill pill-later">Due ' . h($dateLabel) . '</span>';
}

function tasks_table(array $rows, string $today, bool $showCategory, bool $showEmailCol): void {
    ?>
    <table class="board-table">
      <thead><tr>
        <th>Task</th>
        <th class="col-assignee">Owner</th>
        <?php if ($showCategory): ?><th class="col-category">Category</th><?php endif; ?>
        <th class="col-due">Status</th>
        <?php if ($showEmailCol): ?><th class="col-email">Email</th><?php endif; ?>
        <th class="col-actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t): ?>
        <tr>
          <td><a class="board-task-link" href="/tasks/view.php?id=<?=(int)$t['id']?>"><?=h($t['title'])?></a></td>
          <td><?=board_assignee_html($t)?></td>
          <?php if ($showCategory): ?><td class="small"><?=h($t['category'] ?? '')?></td><?php endif; ?>
          <td><?=board_status_html($t, $today)?></td>
          <?php if ($showEmailCol): ?>
          <td>
            <?php if (empty($t['is_done'])): ?>
            <button type="button" class="email-preview-btn" data-task-id="<?=(int)$t['id']?>">✉ Email preview</button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <?php if (empty($t['is_done'])): ?>
            <form method="post" action="/tasks/complete_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="task_id" value="<?=(int)$t['id']?>">
              <input type="hidden" name="return" value="<?=h($_SERVER['REQUEST_URI'] ?? '/tasks/index.php')?>">
              <button class="done-btn" type="submit">Done</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

header_html($group['name']);
?>
<div class="page-head">
  <h1><?=h($group['name'])?></h1>
  <div class="actions">
    <a class="button primary" href="/tasks/add.php?group_id=<?=$groupId?>">Add Task</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="board-toolbar">
  <div class="seg">
    <a class="<?=$view==='week'?'active':''?>" href="<?=h(tasks_view_url($groupId, 'week', $mine, $showDone, $search))?>">By Week</a>
    <a class="<?=$view==='category'?'active':''?>" href="<?=h(tasks_view_url($groupId, 'category', $mine, $showDone, $search))?>">By Category</a>
  </div>
  <a class="chip <?=$mine?'active':''?>" href="<?=h(tasks_view_url($groupId, $view, !$mine, $showDone, $search))?>">Only my tasks</a>
  <a class="chip <?=$showDone?'active':''?>" href="<?=h(tasks_view_url($groupId, $view, $mine, !$showDone, $search))?>">Show completed</a>
  <form method="get" action="/tasks/index.php" data-auto-submit>
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <input type="hidden" name="view" value="<?=h($view)?>">
    <input type="hidden" name="mine" value="<?=$mine?1:0?>">
    <?php if ($showDone): ?><input type="hidden" name="show_done" value="1"><?php endif; ?>
    <input type="search" class="board-search" name="q" value="<?=h($search)?>" placeholder="Search tasks…">
  </form>
</div>

<?php if (!$groups): ?>
  <div class="card">
    <p>All caught up 🎉<?php if ($mine): ?> — no tasks assigned to you.<?php endif; ?></p>
  </div>
<?php else: ?>
  <?php $cycleIndex = 0; ?>
  <?php foreach ($groups as $section): ?>
    <?php
      $color = board_group_color($section['label'], $cycleIndex);
      if (!in_array($section['label'], ['Overdue', 'Completed', 'No due date'], true)) $cycleIndex++;
    ?>
    <div class="board-group" style="--group-color: <?=h($color)?>">
      <h3 class="board-group-title"><?=h($section['label'])?> <span class="count"><?=count($section['tasks'])?> task<?=count($section['tasks'])===1?'':'s'?></span></h3>
      <div class="board-card">
        <?php tasks_table($section['tasks'], $today, $view !== 'category', $isGroupAdmin); ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($isGroupAdmin): ?>
<!-- Gmail-style preview of a task's scheduled reminder email. The owner can
     edit it; "Save for scheduled send" stores it as the task's custom email. -->
<div class="modal hidden" id="email-modal" data-csrf="<?=h(csrf_token())?>">
  <div class="email-compose">
    <div class="email-compose-titlebar">
      <span id="em-title">Scheduled reminder</span>
      <button type="button" class="email-compose-close" id="em-close" aria-label="Close">×</button>
    </div>
    <div class="email-compose-field"><span class="email-compose-label">To</span><span id="em-to"></span></div>
    <div class="email-compose-field"><span class="email-compose-label">From</span><span id="em-from"></span></div>
    <div class="email-compose-field"><div contenteditable="true" id="em-subject" class="email-compose-subject" aria-label="Subject"></div></div>
    <div contenteditable="true" id="em-body" class="email-compose-body" aria-label="Email body"></div>
    <p class="email-compose-hint small">Blue tags are <strong>variables</strong>, shown with today's values — each email
      re-fills them at the moment it is sent, so they stay up to date when the task changes (hover a tag to see which
      variable it is). Everything else is sent as written. Type <code>[task_due_date]</code>-style brackets to add one.</p>
    <div class="email-compose-footer">
      <button type="button" class="email-send-btn" id="em-save">Save for scheduled send</button>
      <button type="button" class="button email-reset-btn hidden" id="em-reset">Reset to template</button>
      <span class="small" id="em-status"></span>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('email-modal');
  var csrf = modal.getAttribute('data-csrf');
  var taskId = null;
  var tokenValues = {};
  var el = function (id) { return document.getElementById(id); };

  function setStatus(text, isError) {
    el('em-status').textContent = text;
    el('em-status').style.color = isError ? '#b91c1c' : '#0f5d2f';
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // Known [tokens] become blue variable pills showing the task's CURRENT
  // value (the tooltip names the variable); anything else, bracketed or not,
  // is literal text. data-token is what gets saved, so it stays a variable.
  function highlightTokens(text) {
    return esc(text).replace(/\[([a-z_]+)\]/g, function (match, name) {
      if (!(name in tokenValues)) return match;
      var value = String(tokenValues[name]);
      return '<span class="token-pill" data-token="' + match + '"'
        + ' title="Variable ' + match + ' — updates automatically when the task changes"'
        + ' contenteditable="false">' + (value !== '' ? esc(value) : match) + '</span>';
    });
  }

  function setEditor(id, text, multiline) {
    var html = highlightTokens(text);
    el(id).innerHTML = multiline ? html.replace(/\n/g, '<br>') : html;
  }

  // Serialize an editor back to plain text: pills save as their [token], so
  // what is stored stays a variable even though the pill displayed the value.
  function editorText(node) {
    var out = '';
    node.childNodes.forEach(function (child) {
      if (child.nodeType === Node.TEXT_NODE) {
        out += child.textContent;
      } else if (child.nodeName === 'BR') {
        out += '\n';
      } else if (child.nodeType === Node.ELEMENT_NODE) {
        if (child.dataset && child.dataset.token) {
          out += child.dataset.token;
          return;
        }
        // Block elements the browser inserts on Enter start a new line
        if (/^(DIV|P)$/.test(child.nodeName) && out !== '' && !out.endsWith('\n')) out += '\n';
        out += editorText(child);
      }
    });
    return out;
  }

  function fill(data) {
    taskId = data.task_id;
    tokenValues = data.token_values || {};
    el('em-title').textContent = 'Scheduled reminder — ' + data.task_title;
    el('em-to').textContent = data.to;
    el('em-from').textContent = data.from;
    setEditor('em-subject', data.subject, false);
    setEditor('em-body', data.body, true);
    el('em-reset').classList.toggle('hidden', !data.is_custom);
    setStatus(data.is_custom ? 'Customized email — the group template no longer applies to this task.' : '');
  }

  function openPreview(id) {
    fetch('/tasks/email_preview.php?task_id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { alert(data.error); return; }
        fill(data);
        modal.classList.remove('hidden');
        el('em-subject').focus();
      })
      .catch(function () { alert('Could not load the email preview.'); });
  }

  function post(params) {
    params.set('csrf', csrf);
    params.set('task_id', taskId);
    return fetch('/tasks/email_save_eval.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    }).then(function (r) { return r.json(); });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.email-preview-btn');
    if (btn) openPreview(btn.getAttribute('data-task-id'));
  });

  el('em-save').addEventListener('click', function () {
    var params = new URLSearchParams();
    params.set('subject', editorText(el('em-subject')).replace(/\n/g, ' ').trim());
    params.set('body', editorText(el('em-body')).trim());
    post(params).then(function (data) {
      if (data.error) { setStatus(data.error, true); return; }
      el('em-reset').classList.remove('hidden');
      setStatus('Saved — this email will be used for this task’s scheduled reminders.');
    }).catch(function () { setStatus('Save failed.', true); });
  });

  // Single-line subject; typed [tokens] turn into pills once you leave a field.
  el('em-subject').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') e.preventDefault();
  });
  ['em-subject', 'em-body'].forEach(function (id) {
    el(id).addEventListener('blur', function () {
      setEditor(id, editorText(el(id)), id === 'em-body');
    });
  });

  el('em-reset').addEventListener('click', function () {
    var params = new URLSearchParams();
    params.set('action', 'reset');
    post(params).then(function (data) {
      if (data.error) { setStatus(data.error, true); return; }
      openPreview(taskId);
    }).catch(function () { setStatus('Reset failed.', true); });
  });

  el('em-close').addEventListener('click', function () { modal.classList.add('hidden'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.add('hidden'); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') modal.classList.add('hidden');
  });
})();
</script>
<?php endif; ?>

<?php footer_html(); ?>
