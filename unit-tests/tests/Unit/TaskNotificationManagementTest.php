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

        // exactly 7 days out: reminder (single-task template)
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(1, $stats['emails_sent']);
        $this->assertSame('assignee@example.com', $this->sentEmails[0]['to']);
        $this->assertStringContainsString('Prepare agenda', $this->sentEmails[0]['html']);
        $this->assertStringContainsString('due on July 22, 2026', $this->sentEmails[0]['html']);
        $this->assertSame('Reminder: Prepare agenda is due on July 22, 2026', $this->sentEmails[0]['subject']);

        // 6 days out: quiet again
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-16', $this->fakeSender());
        $this->assertSame(0, $stats['emails_sent']);
    }

    public function testDueTodayAndOverdueTrigger(): void
    {
        $this->createTask('Due today task', '2026-07-15', $this->assigneeId);
        $this->createTask('Overdue task', '2026-07-10', $this->assigneeId);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        // One multi-task email to the assignee containing both, numbered
        $this->assertSame(1, $stats['emails_sent']);
        $html = $this->sentEmails[0]['html'];
        $this->assertStringContainsString('to complete these tasks', $html);
        $this->assertStringContainsString('Due today task', $html);
        $this->assertStringContainsString('Overdue task', $html);
        $this->assertStringContainsString('1. ', strip_tags($html));
        $this->assertStringContainsString('2. ', strip_tags($html));
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

    public function testOneEmailPerGroupPerRecipient(): void
    {
        $otherGroup = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Second Group']);
        GroupManagement::addMember($this->ownerCtx, $otherGroup, $this->assigneeId, 'member');

        $this->createTask('Group one task', '2026-07-15', $this->assigneeId);
        TaskManagement::createTask($this->ownerCtx, $otherGroup, [
            'title' => 'Group two task',
            'due_date' => '2026-07-15',
            'assigned_to_user_id' => $this->assigneeId,
        ]);

        // Templates are per group, so each group sends its own email.
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(2, $stats['emails_sent']);

        $bodies = array_column($this->sentEmails, 'html');
        sort($bodies);
        $combined = implode("\n---\n", $bodies);
        $this->assertStringContainsString('Group one task', $combined);
        $this->assertStringContainsString('Club Board Team', $combined);
        $this->assertStringContainsString('Group two task', $combined);
        $this->assertStringContainsString('Second Group Team', $combined);
        foreach ($bodies as $html) {
            // Each email covers exactly one group's task
            $this->assertNotSame(
                str_contains($html, 'Group one task'),
                str_contains($html, 'Group two task')
            );
        }
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

    public function testMultiTaskSubjectCountsTasks(): void
    {
        $this->createTask('One', '2026-07-15', $this->assigneeId);
        $this->createTask('Two', '2026-07-10', $this->assigneeId);

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame('Reminder: you have 2 tasks in Club Board', $this->sentEmails[0]['subject']);
    }

    // --- customizable templates ---

    public function testCustomizedGroupTemplateIsUsed(): void
    {
        EmailTemplates::saveTemplate($this->ownerCtx, $this->groupId, EmailTemplates::TYPE_REMINDER_SINGLE,
            'Nudge about [task_name]',
            "Dear [first_name], please finish [task_name] by [task_due_date]. - the Casa Team!");

        $this->createTask('Water the plants', '2026-07-15', $this->assigneeId);
        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        $this->assertSame('Nudge about Water the plants', $this->sentEmails[0]['subject']);
        $this->assertStringContainsString('Dear Assignee, please finish', $this->sentEmails[0]['html']);
        $this->assertStringContainsString('July 15, 2026', $this->sentEmails[0]['html']);
        $this->assertStringContainsString('the Casa Team!', $this->sentEmails[0]['html']);
    }

    public function testPerTaskCustomEmailIsSentSeparately(): void
    {
        $customId = $this->createTask('Custom-email task', '2026-07-15', $this->assigneeId);
        $this->createTask('Plain task', '2026-07-15', $this->assigneeId);

        pdo()->prepare('UPDATE tasks SET custom_email_subject=?, custom_email_body=? WHERE id=?')
            ->execute(['A personal note about [task_name]', "Hi [first_name] — special instructions here.", $customId]);

        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());

        // The customized task gets its own email; the other uses the single template.
        $this->assertSame(2, $stats['emails_sent']);
        $subjects = array_column($this->sentEmails, 'subject');
        sort($subjects);
        $this->assertSame('A personal note about Custom-email task', $subjects[0]);
        $this->assertSame('Reminder: Plain task is due on July 15, 2026', $subjects[1]);

        $custom = null;
        foreach ($this->sentEmails as $email) {
            if (str_starts_with($email['subject'], 'A personal note')) $custom = $email;
        }
        $this->assertStringContainsString('Hi Assignee — special instructions here.', $custom['html']);
        // The edited text may lack a link, so one is appended.
        $this->assertStringContainsString('View or complete this task', $custom['html']);

        // Both are recorded and neither re-sends.
        $second = TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $this->assertSame(0, $second['emails_sent']);
    }

    // --- admin-set send time ---

    public function testCustomSendDateReplacesAdvanceReminders(): void
    {
        $id = $this->createTask('Rescheduled email', '2026-07-30', $this->assigneeId, [7]);
        pdo()->prepare('UPDATE tasks SET custom_email_send_at=? WHERE id=?')
            ->execute(['2026-07-20 09:30:00', $id]);

        // The default 7-days-in-advance reminder (Jul 23) no longer fires...
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-23', $this->fakeSender());
        $this->assertSame(0, $stats['emails_sent']);

        // ...the admin-chosen date does.
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-20', $this->fakeSender());
        $this->assertSame(1, $stats['emails_sent']);
        $this->assertStringContainsString('Rescheduled email', $this->sentEmails[0]['html']);

        // Due-today still applies regardless of the custom date.
        $stats = TaskNotificationManagement::runDailyNotifications('2026-07-30', $this->fakeSender());
        $this->assertSame(1, $stats['emails_sent']);
    }

    public function testNextScheduledSendComputation(): void
    {
        $task = ['is_done' => 0, 'due_date' => '2026-07-30', 'custom_email_send_at' => null];

        // Earliest of (due - reminder days >= today) and the due date itself
        $next = TaskNotificationManagement::nextScheduledSend($task, [7, 3], '2026-07-15');
        $this->assertSame('2026-07-23', substr($next['at'], 0, 10));
        $this->assertFalse($next['is_custom']);
        $this->assertFalse($next['daily']);

        // Admin-set time wins
        $task['custom_email_send_at'] = '2026-07-20 09:30:00';
        $next = TaskNotificationManagement::nextScheduledSend($task, [7], '2026-07-15');
        $this->assertSame('2026-07-20 09:30:00', $next['at']);
        $this->assertTrue($next['is_custom']);

        // Overdue: daily
        $task['custom_email_send_at'] = null;
        $next = TaskNotificationManagement::nextScheduledSend($task, [], '2026-08-05');
        $this->assertTrue($next['daily']);
        $this->assertSame('2026-08-05', substr($next['at'], 0, 10));

        // Done or no due date: nothing scheduled
        $this->assertNull(TaskNotificationManagement::nextScheduledSend(['is_done' => 1, 'due_date' => '2026-07-30'], [], '2026-07-15'));
        $this->assertNull(TaskNotificationManagement::nextScheduledSend(['is_done' => 0, 'due_date' => null], [], '2026-07-15'));
    }

    public function testLastReminderSentByTask(): void
    {
        $id = $this->createTask('Tracked task', '2026-07-15', $this->assigneeId);
        $this->assertSame([], TaskNotificationManagement::lastReminderSentByTask([$id]));

        TaskNotificationManagement::runDailyNotifications('2026-07-15', $this->fakeSender());
        $sent = TaskNotificationManagement::lastReminderSentByTask([$id]);
        $this->assertArrayHasKey($id, $sent);
    }

    // --- assignment emails ---

    public function testAssignmentEmailUsesTemplateAndLogs(): void
    {
        $taskId = $this->createTask('Bake cookies', '2026-07-22', $this->assigneeId);

        $ok = TaskNotificationManagement::sendAssignmentEmail($taskId, $this->ownerCtx, $this->fakeSender());
        $this->assertTrue($ok);

        $this->assertCount(1, $this->sentEmails);
        $email = $this->sentEmails[0];
        $this->assertSame('assignee@example.com', $email['to']);
        $this->assertSame('New task for you: Bake cookies', $email['subject']);
        $this->assertStringContainsString('Owner Test has assigned you to', $email['html']);
        $this->assertStringContainsString('due on July 22, 2026', $email['html']);
        $this->assertStringContainsString('email reminders as the due date gets closer', $email['html']);
        $this->assertStringContainsString('the Club Board Team!', $email['html']);

        $row = pdo()->query("SELECT notification_type, recipient_user_id FROM notification_log")->fetch();
        $this->assertSame('assignment', $row['notification_type']);
        $this->assertSame($this->assigneeId, (int)$row['recipient_user_id']);
    }

    public function testAssignmentEmailSkipsSelfAssignmentAndMissingEmail(): void
    {
        $selfTask = $this->createTask('Self-assigned', '2026-07-22', $this->ownerId);
        $this->assertFalse(TaskNotificationManagement::sendAssignmentEmail($selfTask, $this->ownerCtx, $this->fakeSender()));

        $noEmailId = $this->insertUser('NoEmail', null);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $noEmailId, 'member');
        $noEmailTask = $this->createTask('No-email assignee', '2026-07-22', $noEmailId);
        $this->assertFalse(TaskNotificationManagement::sendAssignmentEmail($noEmailTask, $this->ownerCtx, $this->fakeSender()));

        $this->assertCount(0, $this->sentEmails);
    }
}
