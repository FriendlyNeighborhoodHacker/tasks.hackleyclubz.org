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
