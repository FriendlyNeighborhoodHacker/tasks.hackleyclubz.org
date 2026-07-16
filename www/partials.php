<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/ApplicationUI.php';

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function header_html(string $title): void {
    ApplicationUI::headerHtml($title);
}

function footer_html(): void {
    ApplicationUI::footerHtml();
}

// Stable avatar color for a person's name (board views, comment feeds).
function person_avatar_color(string $name): string {
    $palette = ['#579bfc', '#00c875', '#a25ddc', '#fdab3d', '#e2445c', '#0086c0', '#9d99b9', '#037f4c'];
    return $palette[crc32($name) % count($palette)];
}

// Colored initials circle + name. Empty name renders the $emptyLabel fallback.
function person_chip_html(?string $first, ?string $last, string $emptyLabel = 'Unassigned'): string {
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    if ($name === '') return '<span class="unassigned">' . h($emptyLabel) . '</span>';
    $initials = strtoupper(mb_substr($first ?? '', 0, 1) . mb_substr($last ?? '', 0, 1));
    if ($initials === '') $initials = strtoupper(mb_substr($name, 0, 1));
    return '<span class="assignee"><span class="assignee-avatar" style="background:' . h(person_avatar_color($name)) . '">' . h($initials)
        . '</span><span class="assignee-name">' . h($name) . '</span></span>';
}

// Shared presentation for a task's due date: "3 days overdue" /
// "Due today" / "Due in 5 days" / "Due Mar 15, 2027", colored appropriately.
function task_due_html(?string $dueDate, ?string $today = null): string {
    if (!$dueDate) return '<span class="small">—</span>';
    $today = $today ?? date('Y-m-d');

    $days = (int)round(((new DateTimeImmutable($dueDate))->getTimestamp() - (new DateTimeImmutable($today))->getTimestamp()) / 86400);
    $dateLabel = date('M j, Y', strtotime($dueDate));

    if ($days < 0) {
        $n = -$days;
        return '<span class="due-overdue">' . $n . ' day' . ($n === 1 ? '' : 's') . ' overdue</span> <span class="small">(' . h($dateLabel) . ')</span>';
    }
    if ($days === 0) {
        return '<span class="due-today">Due today</span>';
    }
    if ($days <= 30) {
        return '<span class="due-soon">Due in ' . $days . ' day' . ($days === 1 ? '' : 's') . '</span> <span class="small">(' . h($dateLabel) . ')</span>';
    }
    return 'Due ' . h($dateLabel);
}
