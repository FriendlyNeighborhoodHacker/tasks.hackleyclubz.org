<?php
// A group's tasks: by-week view (default) with an alternate by-owner view,
// plus an "only my tasks" toggle. Group admins default to all tasks; regular
// members default to just their own (see docs/app-spec.md).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/TaskNotificationManagement.php';
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

$view = ($_GET['view'] ?? 'week') === 'owner' ? 'owner' : 'week';
$mine = isset($_GET['mine']) ? !empty($_GET['mine']) : !$isGroupAdmin;
$showDone = !empty($_GET['show_done']);
$search = trim((string)($_GET['q'] ?? ''));

$filters = ['include_done' => $showDone];
if ($search !== '') $filters['search'] = $search;
if ($mine) $filters['assigned_to_user_id'] = $ctx->id;

$tasks = TaskManagement::listTasks($groupId, $filters);
$today = date('Y-m-d');
$groups = $view === 'owner'
    ? TaskManagement::groupTasksByOwner($tasks)
    : TaskManagement::groupTasksByWeek($tasks, $today);

// Email-schedule data for the admin columns (next send / already sent).
$taskIds = array_map('intval', array_column($tasks, 'id'));
$remindersByTask = $isGroupAdmin ? TaskManagement::remindersByTask($taskIds) : [];
$lastSentByTask = $isGroupAdmin ? TaskNotificationManagement::lastReminderSentByTask($taskIds) : [];

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
// fixed buckets, a rotating palette for week/owner groups.
function board_group_color(string $label, int $cycleIndex): string {
    if ($label === 'Overdue') return 'var(--board-red)';
    if ($label === 'Completed') return 'var(--board-green)';
    if ($label === 'No due date' || $label === 'Unassigned') return 'var(--board-gray)';
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

// Due/status column as a monday.com-style colored pill. For completed tasks
// the pill is a button: clicking it (with a confirm) marks the task
// incomplete again via reopen_eval.php.
function board_status_html(array $t, string $today): string {
    if (!empty($t['is_done'])) {
        $when = $t['completion_date'] ? ' ' . date('M j', strtotime($t['completion_date'])) : '';
        return '<form method="post" action="/tasks/reopen_eval.php" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="task_id" value="' . (int)$t['id'] . '">'
            . '<input type="hidden" name="return" value="' . h($_SERVER['REQUEST_URI'] ?? '/tasks/index.php') . '">'
            . '<button type="submit" class="pill pill-done pill-btn" data-confirm="Mark this task as incomplete? It will go back to the open tasks."'
            . ' title="Click to mark as incomplete">✓ Done' . h($when) . '</button>'
            . '</form>';
    }
    $due = $t['due_date'] ?? null;
    if (!$due) return '<span class="pill pill-none">No due date</span>';

    $days = (int)round((strtotime($due) - strtotime($today)) / 86400);
    $dateLabel = date('M j', strtotime($due));
    if ($days < 0) {
        $n = -$days;
        return '<span class="pill pill-overdue">' . h($dateLabel) . ' · ' . $n . 'd overdue</span>';
    }
    if ($days === 0) return '<span class="pill pill-today">Due today</span>';
    if ($days <= 30) return '<span class="pill pill-soon">Due ' . h($dateLabel) . '</span>';
    return '<span class="pill pill-later">Due ' . h($dateLabel) . '</span>';
}

// "Email sends" cell: when the task's next reminder email goes out. Group
// admins can click it to set an exact date & time (or clear back to the
// automatic schedule).
function board_schedule_html(array $t, string $today, array $reminderDays, bool $isGroupAdmin): string {
    $sched = TaskNotificationManagement::nextScheduledSend($t, $reminderDays, $today);

    if ($sched === null) {
        $label = '<span class="small">' . (empty($t['is_done']) ? 'No due date — not scheduled' : '—') . '</span>';
        if (!empty($t['is_done'])) return $label;
    } else {
        $ts = strtotime($sched['at']);
        $label = '<span class="sched-when' . ($sched['is_custom'] ? ' custom' : '') . '">'
            . ($sched['daily'] ? 'Daily at ' . date('g:i A', $ts) . ' <span class="small">(overdue)</span>'
                               : h(date('M j', $ts)) . ' · ' . date('g:i A', $ts))
            . ($sched['is_custom'] ? ' <span class="sched-custom-badge">custom</span>' : '')
            . '</span>';
    }

    if (!$isGroupAdmin) return $label;

    $value = ($sched && $sched['is_custom']) ? date('Y-m-d\TH:i', strtotime($sched['at'])) : '';
    return '<details class="sched-edit"><summary>' . $label . '</summary>'
        . '<form method="post" action="/tasks/schedule_eval.php" class="sched-form">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="task_id" value="' . (int)$t['id'] . '">'
        . '<input type="hidden" name="return" value="' . h($_SERVER['REQUEST_URI'] ?? '/tasks/index.php') . '">'
        . '<input type="datetime-local" name="send_at" value="' . h($value) . '">'
        . '<button class="button primary" type="submit">Set</button>'
        . ($value !== '' ? '<button class="button" type="submit" name="send_at" value="">Auto</button>' : '')
        . '</form></details>';
}

// "Sent" cell: has this task's reminder email already gone out?
function board_sent_html(array $t, array $lastSentByTask): string {
    $last = $lastSentByTask[(int)$t['id']] ?? null;
    if ($last) {
        return '<span class="sent-yes" title="Last sent ' . h(date('M j, g:i A', strtotime($last))) . '">✓ Sent ' . h(date('M j', strtotime($last))) . '</span>';
    }
    return empty($t['is_done']) ? '<span class="small">Not yet</span>' : '<span class="small">—</span>';
}

function tasks_table(array $rows, string $today, bool $isGroupAdmin, array $remindersByTask, array $lastSentByTask): void {
    ?>
    <table class="board-table">
      <thead><tr>
        <th>Task</th>
        <th class="col-assignee">Owner</th>
        <th class="col-due">Status</th>
        <?php if ($isGroupAdmin): ?>
        <th class="col-sched">Email sends</th>
        <th class="col-sent">Sent</th>
        <th class="col-email">Email</th>
        <?php endif; ?>
        <th class="col-actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t): $tid = (int)$t['id']; ?>
        <tr>
          <td><a class="board-task-link" href="/tasks/view.php?id=<?=$tid?>"><?=h($t['title'])?></a></td>
          <td><?=board_assignee_html($t)?></td>
          <td><?=board_status_html($t, $today)?></td>
          <?php if ($isGroupAdmin): ?>
          <td><?=board_schedule_html($t, $today, $remindersByTask[$tid] ?? [], true)?></td>
          <td><?=board_sent_html($t, $lastSentByTask)?></td>
          <td>
            <?php if (empty($t['is_done'])): ?>
            <button type="button" class="email-preview-btn" data-task-id="<?=$tid?>">✉ Email preview</button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <?php if (empty($t['is_done'])): ?>
            <form method="post" action="/tasks/complete_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="task_id" value="<?=$tid?>">
              <input type="hidden" name="return" value="<?=h($_SERVER['REQUEST_URI'] ?? '/tasks/index.php')?>">
              <button class="done-btn" type="submit">Mark as complete</button>
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
    <a class="<?=$view==='owner'?'active':''?>" href="<?=h(tasks_view_url($groupId, 'owner', $mine, $showDone, $search))?>">By Owner</a>
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
        <?php tasks_table($section['tasks'], $today, $isGroupAdmin, $remindersByTask, $lastSentByTask); ?>
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
    <p class="email-compose-hint small">Text shown in <strong class="hint-blue">blue</strong> is filled in automatically
      by the site — each email re-fills it at the moment it is sent, so it stays up to date when the task changes (hover
      blue text to see which detail it is). Everything else is sent as written. Type <code>[task_due_date]</code>-style
      brackets to add another automatic value.</p>
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
