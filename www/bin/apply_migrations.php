<?php
// Applies any pending files from www/db_migrations/ to the configured
// database, in order, skipping ones already applied (detected by a sentinel
// table/column each migration creates). Run on the server after a git pull:
//
//   php www/bin/apply_migrations.php [--dry-run] [--skip-backup]
//
// Before changing anything it writes a full mysqldump backup to
// db_backups/<dbname>-<timestamp>.sql at the project root.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';

$options = getopt('', ['dry-run', 'skip-backup']);
$dryRun = array_key_exists('dry-run', $options);
$skipBackup = array_key_exists('skip-backup', $options);

$migrationsDir = realpath(__DIR__ . '/../db_migrations');

// Each migration and the schema object whose existence proves it was applied.
// Ordered; a new migration file must be added here with its sentinel.
$migrations = [
    '2026-07-15_initial_schema.sql'         => ['table', 'tasks'],
    '2026-07-15_group_email_templates.sql'  => ['table', 'group_email_templates'],
    '2026-07-15_email_schedule_override.sql'=> ['column', 'tasks', 'custom_email_send_at'],
    '2026-07-16_group_reply_to_email.sql'   => ['column', 'task_groups', 'reply_to_email'],
    '2026-07-16_group_smtp_overrides.sql'   => ['table', 'group_smtp_overrides'],
    '2026-07-18_task_multi_assignees.sql'   => ['table', 'task_assignees'],
];

// Any .sql file on disk that this list doesn't know about is a hard error —
// better to stop than to silently skip a schema change.
$onDisk = array_filter(scandir($migrationsDir), fn($f) => str_ends_with($f, '.sql'));
$unknown = array_diff($onDisk, array_keys($migrations));
if ($unknown) {
    fwrite(STDERR, "Unknown migration file(s) with no sentinel entry: " . implode(', ', $unknown) . "\n");
    exit(1);
}

$db = pdo();

function schema_object_exists(PDO $db, array $sentinel): bool {
    if ($sentinel[0] === 'table') {
        $st = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$sentinel[1]]);
    } else { // column
        $st = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$sentinel[1], $sentinel[2]]);
    }
    return (bool)$st->fetchColumn();
}

$pending = [];
foreach ($migrations as $file => $sentinel) {
    if (!schema_object_exists($db, $sentinel)) {
        $pending[] = $file;
    }
}

if (!$pending) {
    echo "Database is up to date — nothing to apply.\n";
    exit(0);
}

echo "Pending migration(s):\n";
foreach ($pending as $file) {
    echo "  - $file\n";
}
if ($dryRun) {
    echo "Dry run — nothing applied.\n";
    exit(0);
}

// mysql CLI is used to apply files (they contain multiple statements) and
// mysqldump for the backup; both read the password from the environment so
// it never appears in the process list.
$env = ['MYSQL_PWD' => DB_PASS];
$baseArgs = '--host=' . escapeshellarg(DB_HOST ?: 'localhost') . ' --user=' . escapeshellarg(DB_USER) . ' ' . escapeshellarg(DB_NAME);

function run_cmd(string $cmd, array $env): int {
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env + $_ENV + ['PATH' => getenv('PATH')]);
    fclose($pipes[0]);
    return proc_close($proc);
}

if (!$skipBackup) {
    $backupDir = dirname(__DIR__, 2) . '/db_backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0700, true);
    $backupFile = $backupDir . '/' . DB_NAME . '-' . date('Ymd-His') . '.sql';
    echo "Backing up to $backupFile …\n";
    $code = run_cmd('mysqldump --single-transaction --routines ' . $baseArgs . ' > ' . escapeshellarg($backupFile), $env);
    if ($code !== 0 || !filesize($backupFile)) {
        fwrite(STDERR, "Backup FAILED — aborting, nothing was applied.\n");
        exit(1);
    }
}

foreach ($pending as $file) {
    echo "Applying $file …\n";
    $code = run_cmd('mysql ' . $baseArgs . ' < ' . escapeshellarg($migrationsDir . '/' . $file), $env);
    if ($code !== 0) {
        fwrite(STDERR, "FAILED on $file — stopping. Restore from the backup if needed.\n");
        exit(1);
    }
}

echo "Done — " . count($pending) . " migration(s) applied.\n";
