<?php
// Daily notification runner for tasks. Run once per day via cron:
//
//   0 7 * * * /usr/bin/php /path/to/www/bin/send_daily_notifications.php
//
// Idempotent — safe to run multiple times per day (sent reminders are recorded
// in notification_log and not re-sent).
//
// Options:
//   --date=YYYY-MM-DD    Run as-of a specific date (default: today in the app timezone)
//   --dry-run            Report what would be sent without sending or recording anything
//   --ignore-throttling  Send even if already sent (bypasses the notification_log
//                        dedup — for testing; combine with --date to replay any day)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../lib/TaskNotificationManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';

// "Today" must be evaluated in the app's timezone, not the server's
date_default_timezone_set(Settings::timezone());

$options = getopt('', ['date::', 'dry-run', 'ignore-throttling']);
$today = $options['date'] ?? date('Y-m-d');
$dryRun = array_key_exists('dry-run', $options);
$ignoreThrottling = array_key_exists('ignore-throttling', $options);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today)) {
    fwrite(STDERR, "Invalid --date (expected YYYY-MM-DD)\n");
    exit(1);
}

$startedAt = microtime(true);
echo 'Notification run for ' . $today
    . ($dryRun ? ' (dry run)' : '')
    . ($ignoreThrottling ? ' (ignoring throttling)' : '') . "\n";

try {
    $stats = TaskNotificationManagement::runDailyNotifications((string)$today, null, $dryRun, $ignoreThrottling);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
    ActivityLog::log(null, 'notifications.run_failed', ['date' => $today, 'error' => $e->getMessage()]);
    exit(1);
}

$stats['duration_seconds'] = round(microtime(true) - $startedAt, 2);

echo 'Tasks evaluated:          ' . $stats['tasks_evaluated'] . "\n";
echo 'Recipients w/ triggers:   ' . $stats['recipients_with_triggers'] . "\n";
echo 'Emails sent:              ' . $stats['emails_sent'] . "\n";
echo 'Delivery failures:        ' . $stats['emails_failed'] . "\n";
echo 'Notifications recorded:   ' . $stats['notifications_recorded'] . "\n";
echo 'Completed in ' . $stats['duration_seconds'] . "s\n";

if (!$dryRun) {
    ActivityLog::log(null, 'notifications.run', $stats);
}

exit($stats['emails_failed'] > 0 ? 1 : 0);
