<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EmailTemplatesTest extends TestCase
{
    private UserContext $ownerCtx;
    private int $ownerId;
    private int $memberId;
    private int $groupId;

    protected function setUp(): void
    {
        test_reset_all();

        $st = pdo()->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
             VALUES (?, 'Test', ?, 'hash', 0, NOW())"
        );
        $st->execute(['Owner', 'owner@example.com']);
        $this->ownerId = (int)pdo()->lastInsertId();
        $st->execute(['Member', 'member@example.com']);
        $this->memberId = (int)pdo()->lastInsertId();

        $this->ownerCtx = new UserContext($this->ownerId, false);
        UserContext::set($this->ownerCtx);

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Casa']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $this->memberId, 'member');
    }

    public function testDefaultsAreReturnedUntilCustomized(): void
    {
        foreach (EmailTemplates::TYPES as $type) {
            $tpl = EmailTemplates::getTemplate($this->groupId, $type);
            $this->assertFalse($tpl['is_custom'], $type);
            $this->assertNotSame('', $tpl['subject'], $type);
            $this->assertStringContainsString('Hello [first_name],', $tpl['body'], $type);
            $this->assertStringContainsString('- the [group_name] Team!', $tpl['body'], $type);
        }
        $this->assertStringContainsString('[task_list]', EmailTemplates::getTemplate($this->groupId, EmailTemplates::TYPE_REMINDER_MULTI)['body']);
    }

    public function testSaveAndResetTemplate(): void
    {
        EmailTemplates::saveTemplate($this->ownerCtx, $this->groupId, EmailTemplates::TYPE_ASSIGNMENT, 'Hi [first_name]', 'Custom body');

        $tpl = EmailTemplates::getTemplate($this->groupId, EmailTemplates::TYPE_ASSIGNMENT);
        $this->assertTrue($tpl['is_custom']);
        $this->assertSame('Hi [first_name]', $tpl['subject']);
        $this->assertSame('Custom body', $tpl['body']);

        // Other groups are unaffected
        $otherGroup = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Other']);
        $this->assertFalse(EmailTemplates::getTemplate($otherGroup, EmailTemplates::TYPE_ASSIGNMENT)['is_custom']);

        // Saving again overwrites in place
        EmailTemplates::saveTemplate($this->ownerCtx, $this->groupId, EmailTemplates::TYPE_ASSIGNMENT, 'Hi again', 'Second body');
        $this->assertSame('Hi again', EmailTemplates::getTemplate($this->groupId, EmailTemplates::TYPE_ASSIGNMENT)['subject']);
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM group_email_templates')->fetchColumn());

        EmailTemplates::resetTemplate($this->ownerCtx, $this->groupId, EmailTemplates::TYPE_ASSIGNMENT);
        $this->assertFalse(EmailTemplates::getTemplate($this->groupId, EmailTemplates::TYPE_ASSIGNMENT)['is_custom']);
    }

    public function testRegularMembersCannotEditTemplates(): void
    {
        $this->expectException(RuntimeException::class);
        EmailTemplates::saveTemplate(new UserContext($this->memberId, false), $this->groupId, EmailTemplates::TYPE_ASSIGNMENT, 'S', 'B');
    }

    public function testSaveRejectsEmptySubjectOrBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailTemplates::saveTemplate($this->ownerCtx, $this->groupId, EmailTemplates::TYPE_ASSIGNMENT, '  ', 'Body');
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailTemplates::getTemplate($this->groupId, 'weekly_digest');
    }

    public function testRenderTextSubstitutesTokens(): void
    {
        $out = EmailTemplates::renderText('Hello [first_name], [task_name] is due on [task_due_date].', [
            'first_name' => 'Ada',
            'task_name' => 'Fix the door',
            'task_due_date' => 'July 22, 2026',
        ]);
        $this->assertSame('Hello Ada, Fix the door is due on July 22, 2026.', $out);
    }

    public function testRenderHtmlEscapesTextButKeepsHtmlTokens(): void
    {
        $html = EmailTemplates::renderHtml("Hello [first_name] <b>& co</b>,\nsee [task_name].", [
            'first_name' => 'Ada <script>',
        ], [
            'task_name' => '<a href="https://x.test">Fix</a>',
        ]);
        $this->assertStringContainsString('Ada &lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;&amp; co&lt;/b&gt;', $html);
        $this->assertStringContainsString('<a href="https://x.test">Fix</a>', $html);
        $this->assertStringContainsString('<br>', $html);
    }

    public function testTaskListRendering(): void
    {
        $items = [
            ['title' => 'First task', 'due_label' => 'July 20, 2026', 'description' => 'Bring gloves'],
            ['title' => 'Second task', 'due_label' => 'July 21, 2026', 'description' => ''],
        ];

        $text = EmailTemplates::taskListText($items);
        $this->assertStringContainsString("1. First task which is due on July 20, 2026.\nBring gloves", $text);
        $this->assertStringContainsString('2. Second task which is due on July 21, 2026.', $text);

        $items[0]['title_html'] = '<a href="https://x.test">First task</a>';
        $html = EmailTemplates::taskListHtml($items);
        $this->assertStringContainsString('1. <a href="https://x.test">First task</a> which is due on', $html);
        $this->assertStringContainsString('<br>Bring gloves', $html);
    }

    public function testDueDateLabel(): void
    {
        $this->assertSame('July 22, 2026', EmailTemplates::dueDateLabel('2026-07-22'));
        $this->assertSame('no set due date', EmailTemplates::dueDateLabel(null));
    }
}
