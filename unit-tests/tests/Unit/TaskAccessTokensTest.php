<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TaskAccessTokensTest extends TestCase
{
    private UserContext $ownerCtx;
    private int $groupId;
    private int $taskId;
    private int $assigneeId;

    protected function setUp(): void
    {
        test_reset_all();

        // Clear the per-run token reuse cache between tests
        $ref = new ReflectionProperty(TaskAccessTokens::class, 'issuedThisRun');
        $ref->setValue(null, []);

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
                     VALUES ('Owner', 'Test', 'owner@example.com', 'hash', 0, NOW())");
        $ownerId = (int)pdo()->lastInsertId();
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash)
                     VALUES ('Assignee', 'Test', 'assignee@example.com', '')");
        $this->assigneeId = (int)pdo()->lastInsertId();

        $this->ownerCtx = new UserContext($ownerId, false);
        UserContext::set($this->ownerCtx);

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'G']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $this->assigneeId, 'member');
        $this->taskId = TaskManagement::createTask($this->ownerCtx, $this->groupId, [
            'title' => 'Tokened task',
            'assigned_user_ids' => [$this->assigneeId],
        ]);
    }

    public function testIssueAndVerifyRoundtrip(): void
    {
        $raw = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);

        $auth = TaskAccessTokens::verify($raw);
        $this->assertNotNull($auth);
        $this->assertSame($this->taskId, $auth['task_id']);
        $this->assertSame($this->assigneeId, $auth['user_id']);
    }

    public function testRawTokenIsNotStored(): void
    {
        $raw = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);

        $rows = pdo()->query('SELECT token_hash FROM task_access_tokens')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertNotSame($raw, $rows[0]['token_hash']);
        $this->assertSame(hash('sha256', $raw), $rows[0]['token_hash']);
    }

    public function testVerifyRejectsGarbage(): void
    {
        $this->assertNull(TaskAccessTokens::verify(''));
        $this->assertNull(TaskAccessTokens::verify('not-a-token'));
        $this->assertNull(TaskAccessTokens::verify(str_repeat('a', 64))); // valid shape, unknown
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $raw = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        pdo()->exec("UPDATE task_access_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $this->assertNull(TaskAccessTokens::verify($raw));
    }

    public function testVerifyRejectsRevokedToken(): void
    {
        $raw = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        TaskAccessTokens::revokeForTask($this->taskId);
        $this->assertNull(TaskAccessTokens::verify($raw));
    }

    public function testTokenReusedWithinRun(): void
    {
        $a = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        $b = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        $this->assertSame($a, $b);
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM task_access_tokens')->fetchColumn());
    }

    public function testTokenScopedToItsTaskAndUser(): void
    {
        $otherTask = TaskManagement::createTask($this->ownerCtx, $this->groupId, ['title' => 'Other']);
        $raw = TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);

        $auth = TaskAccessTokens::verify($raw);
        $this->assertNotSame($otherTask, $auth['task_id']);
        $this->assertSame($this->taskId, $auth['task_id']);
    }

    public function testTokensDeletedWithTask(): void
    {
        TaskAccessTokens::issueForTaskRecipient($this->taskId, $this->assigneeId);
        TaskManagement::deleteTask($this->ownerCtx, $this->taskId);
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM task_access_tokens')->fetchColumn());
    }

    public function testTaskActionUrlUsesBaseUrlSetting(): void
    {
        Settings::set('site_base_url', 'https://tasks.example.org/');
        $url = TaskAccessTokens::taskActionUrl('abc123');
        $this->assertSame('https://tasks.example.org/t/index.php?token=abc123', $url);
    }
}
