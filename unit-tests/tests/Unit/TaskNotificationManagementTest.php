<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TaskNotificationManagementTest extends TestCase
{
    private UserContext $ownerCtx;
    private int $ownerId;
    private int $assigneeId;
    private int $groupId;

    /** @var array<int, array{to:string,subject:string,html:string}> */
    private array $sentEmails = [];

    private function fakeSender(bool $succeed = true): callable
    {
        return function (string $to, string $toName, string $subject, string $html) use ($succeed): bool {
            $this->sentEmails[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            return $succeed;
        };
    }

    private function insertUser(string $first, ?string $email, bool $isAdmin = false): int
    {
        $st = pdo()->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
             VALUES (?, 'Test', ?, 'hash', ?, NOW())"
        );
        $st->execute([$first, $email, $isAdmin ? 1 : 0]);
        return (int)pdo()->lastInsertId();
    }

    protected function setUp(): void
    {
        test_reset_all();
        $this->sentEmails = [];

        $ref = new ReflectionProperty(TaskAccessTokens::class, 'issuedThisRun');
        $ref->setValue(null, []);

        $this->ownerId = $this->insertUser('Owner', 'owner@example.com');
        $this->assigneeId = $this->insertUser('Assignee', 'assignee@example.com');

        $this->ownerCtx = new UserContext($this->ownerId, false);
        UserContext::set($this->ownerCtx);

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Club Board']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $this->assigneeId, 'member');
    }

    private function createTask(string $title, ?string $dueDate, ?int $assigneeId, array $reminders = []): int
    {
        return TaskManagement::createTask($this->ownerCtx, $this->groupId, [
            'title' => $title,
            'due_date' => $dueDate ?? '',
            'assigned_to_user_id' => $assigneeId ?? '',
            'reminders' => $reminders,
        ]);
    }

    // --- trigger rules ---

    public function testReminderFiresExactlyOnItsDay(): void
    {
        $this->createTask('Prepare agenda', '2026-07-22', $this->assigneeId, [7]);

        // 8 days out: nothing
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-14', $this->fakeSender());
        $this->assertSame(0, $stats['emails_sent']);

        // exactly 7 days out: reminder
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(1, $stats['emails_sent']);
        $this->assertSame('assignee@example.com', $this->sentEmails[0]['to']);
        $this->assertStringContainsString('Prepare agenda', $this->sentEmails[0]['html']);
        $this->assertStringContainsString('due in 7 days', $this->sentEmails[0]['html']);

        // 6 days out: quiet again
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-16', $this->fakeSender());
        $this->assertSame(0, $stats['emails_sent']);
    }

    public function testDueTodayAndOverdueTrigger(): void
    {
        $this->createTask('Due today task', '2026-07-15', $this->assigneeId);
        $this->createTask('Overdue task', '2026-07-10', $this->assigneeId);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        // One digest to the assignee containing both
        $this->assertSame(1, $stats['emails_sent']);
        $html = $this->sentEmails[0]['html'];
        $this->assertStringContainsString('Due today task', $html);
        $this->assertStringContainsString('Overdue task', $html);
        $this->assertStringContainsString('5 days overdue', $html);
    }

    public function testOverdueRetriggersDaily(): void
    {
        $this->createTask('Overdue task', '2026-07-10', $this->assigneeId);

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        TaskNotificationManagement::runDailyNotifications('2026-07-16', $this->fakeSender());
        $this->assertCount(2, $this->sentEmails);
    }

    public function testDoneTasksAreSkipped(): void
    {
        $id = $this->createTask('Finished', '2026-07-15', $this->assigneeId);
        TaskManagement::markComplete($this->ownerCtx, $id);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(0, $stats['emails_sent']);
    }

    // --- recipients ---

    public function testUnassignedTaskNotifiesOwnerAndAdmins(): void
    {
        $adminId = $this->insertUser('GroupAdmin', 'groupadmin@example.com');
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $adminId, 'admin');

        $this->createTask('Unassigned chore', '2026-07-15', null);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(2, $stats['emails_sent']);

        $recipients = array_column($this->sentEmails, 'to');
        sort($recipients);
        $this->assertSame(['groupadmin@example.com', 'owner@example.com'], $recipients);
    }

    public function testAssigneeWithoutEmailFallsBackToAdmins(): void
    {
        $noEmailId = $this->insertUser('NoEmail', null);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $noEmailId, 'member');
        $this->createTask('Orphan reminder', '2026-07-15', $noEmailId);

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertCount(1, $this->sentEmails);
        $this->assertSame('owner@example.com', $this->sentEmails[0]['to']);
    }

    public function testOneDigestPerRecipientAcrossGroups(): void
    {
        $otherGroup = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Second Group']);
        GroupManagement::addMember($this->ownerCtx, $otherGroup, $this->assigneeId, 'member');

        $this->createTask('Group one task', '2026-07-15', $this->assigneeId);
        TaskManagement::createTask($this->ownerCtx, $otherGroup, [
            'title' => 'Group two task',
            'due_date' => '2026-07-15',
            'assigned_to_user_id' => $this->assigneeId,
        ]);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(1, $stats['emails_sent']);

        $html = $this->sentEmails[0]['html'];
        $this->assertStringContainsString('Group one task', $html);
        $this->assertStringContainsString('Group two task', $html);
        $this->assertStringContainsString('Club Board', $html);
        $this->assertStringContainsString('Second Group', $html);
    }

    // --- idempotency & failure handling ---

    public function testSecondRunSendsNothing(): void
    {
        $this->createTask('Due today task', '2026-07-15', $this->assigneeId);

        $first = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $second = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        $this->assertSame(1, $first['emails_sent']);
        $this->assertSame(0, $second['emails_sent']);
        $this->assertCount(1, $this->sentEmails);
    }

    public function testFailedDeliveryIsRecordedAndRetried(): void
    {
        $this->createTask('Flaky delivery', '2026-07-15', $this->assigneeId);

        $first = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender(false));
        $this->assertSame(1, $first['emails_failed']);

        $row = pdo()->query('SELECT delivery_status FROM notification_log')->fetch();
        $this->assertSame('failed', $row['delivery_status']);

        // A later run retries because dedup only counts delivery_status='sent'
        $second = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(1, $second['emails_sent']);
    }

    public function testDryRunSendsAndRecordsNothing(): void
    {
        $this->createTask('Due today task', '2026-07-15', $this->assigneeId);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender(), true);
        $this->assertSame(1, $stats['recipients_with_triggers']);
        $this->assertSame(0, $stats['emails_sent']);
        $this->assertCount(0, $this->sentEmails);
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM notification_log')->fetchColumn());
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM task_access_tokens')->fetchColumn());
    }

    // --- email content ---

    public function testDigestLinksThroughAccessTokens(): void
    {
        Settings::set('site_base_url', 'https://tasks.hackleyclubz.org');
        $taskId = $this->createTask('Tokened task', '2026-07-15', $this->assigneeId);

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        $html = $this->sentEmails[0]['html'];
        $this->assertMatchesRegularExpression(
            '#https://tasks\.hackleyclubz\.org/t/index\.php\?token=[0-9a-f]{64}#',
            $html
        );

        // The embedded token really authenticates the assignee for that task
        preg_match('#token=([0-9a-f]{64})#', $html, $m);
        $auth = TaskAccessTokens::verify($m[1]);
        $this->assertNotNull($auth);
        $this->assertSame($taskId, $auth['task_id']);
        $this->assertSame($this->assigneeId, $auth['user_id']);
    }

    public function testSubjectCountsReminders(): void
    {
        $this->createTask('One', '2026-07-15', $this->assigneeId);
        $this->createTask('Two', '2026-07-10', $this->assigneeId);

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertStringContainsString('2 reminders for today', $this->sentEmails[0]['subject']);
    }
}
