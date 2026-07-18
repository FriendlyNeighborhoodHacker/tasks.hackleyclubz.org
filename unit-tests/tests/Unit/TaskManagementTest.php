<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TaskManagementTest extends TestCase
{
    private UserContext $ownerCtx;
    private UserContext $memberCtx;
    private UserContext $otherMemberCtx;
    private UserContext $outsiderCtx;
    private int $groupId;

    private function insertUser(string $first, string $email): int
    {
        $st = pdo()->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
             VALUES (?, 'Test', ?, 'hash', 0, NOW())"
        );
        $st->execute([$first, $email]);
        return (int)pdo()->lastInsertId();
    }

    protected function setUp(): void
    {
        test_reset_all();

        $ownerId = $this->insertUser('Owner', 'owner@example.com');
        $memberId = $this->insertUser('Member', 'member@example.com');
        $otherId = $this->insertUser('Other', 'other@example.com');
        $outsiderId = $this->insertUser('Outsider', 'outsider@example.com');

        $this->ownerCtx = new UserContext($ownerId, false);
        $this->memberCtx = new UserContext($memberId, false);
        $this->otherMemberCtx = new UserContext($otherId, false);
        $this->outsiderCtx = new UserContext($outsiderId, false);
        UserContext::set($this->ownerCtx);

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Test Group']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $memberId, 'member');
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $otherId, 'member');
    }

    // --- createTask ---

    public function testMemberCanCreateTaskWithDefaults(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Bring snacks',
            'due_date' => '2026-08-01',
            'assigned_user_ids' => [$this->otherMemberCtx->id],
        ]);

        $task = TaskManagement::getTask($id);
        $this->assertSame('Bring snacks', $task['title']);
        $this->assertSame('2026-08-01', $task['due_date']);
        $this->assertSame([$this->otherMemberCtx->id], array_column($task['assignees'], 'user_id'));
        $this->assertSame($this->memberCtx->id, (int)$task['created_by_user_id']);
        $this->assertSame(0, (int)$task['is_done']);

        // A due date with no explicit reminders gets the default reminder
        $reminders = TaskManagement::listReminders($id);
        $this->assertCount(1, $reminders);
        $this->assertSame(TaskManagement::DEFAULT_REMINDER_DAYS, (int)$reminders[0]['days_in_advance']);
    }

    public function testCreateTaskWithExplicitReminders(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Plan gala',
            'due_date' => '2026-09-01',
            'reminders' => [7, 1, 7], // duplicates collapse
        ]);
        $days = array_map('intval', array_column(TaskManagement::listReminders($id), 'days_in_advance'));
        $this->assertSame([1, 7], $days);
    }

    public function testCreateTaskWithoutDueDateGetsNoDefaultReminder(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, ['title' => 'Someday']);
        $this->assertCount(0, TaskManagement::listReminders($id));
    }

    public function testOutsiderCannotCreateTask(): void
    {
        $this->expectException(RuntimeException::class);
        TaskManagement::createTask($this->outsiderCtx, $this->groupId, ['title' => 'Nope']);
    }

    public function testAssigneeMustBeGroupMember(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Bad assignee',
            'assigned_user_ids' => [$this->outsiderCtx->id],
        ]);
    }

    public function testCreateTaskRequiresTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskManagement::createTask($this->memberCtx, $this->groupId, ['title' => '  ']);
    }

    // --- edit permissions ---

    private function createTaskAssignedToOther(): int
    {
        return TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Assigned task',
            'assigned_user_ids' => [$this->otherMemberCtx->id],
        ]);
    }

    public function testCreatorAssigneeAndAdminCanEdit(): void
    {
        $id = $this->createTaskAssignedToOther();

        // updateTask has full-replace semantics (forms post every field), so
        // keep the assignment while editing the title.
        $edit = fn(string $title) => ['title' => $title, 'assigned_user_ids' => [$this->otherMemberCtx->id]];
        $this->assertTrue(TaskManagement::updateTask($this->memberCtx, $id, $edit('By creator')));
        $this->assertTrue(TaskManagement::updateTask($this->otherMemberCtx, $id, $edit('By assignee')));
        $this->assertTrue(TaskManagement::updateTask($this->ownerCtx, $id, $edit('By owner')));
        $this->assertSame('By owner', TaskManagement::getTask($id)['title']);
    }

    public function testUnrelatedMemberCannotEdit(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, ['title' => 'Private-ish']);

        $this->expectException(RuntimeException::class);
        TaskManagement::updateTask($this->otherMemberCtx, $id, ['title' => 'Hijack']);
    }

    public function testUnrelatedMemberCannotDelete(): void
    {
        $id = $this->createTaskAssignedToOther();

        // Assignee is not the creator/admin, so cannot delete either
        $this->expectException(RuntimeException::class);
        TaskManagement::deleteTask($this->otherMemberCtx, $id);
    }

    public function testCreatorCanDelete(): void
    {
        $id = $this->createTaskAssignedToOther();
        $this->assertTrue(TaskManagement::deleteTask($this->memberCtx, $id));
        $this->assertNull(TaskManagement::getTask($id));
    }

    // --- completion ---

    public function testMarkCompleteSetsFieldsAndRecordsHistory(): void
    {
        $id = $this->createTaskAssignedToOther();
        TaskManagement::markComplete($this->otherMemberCtx, $id, '2026-07-10', 'Done at the meeting');

        $task = TaskManagement::getTask($id);
        $this->assertSame(1, (int)$task['is_done']);
        $this->assertSame('2026-07-10', $task['completion_date']);
        $this->assertSame($this->otherMemberCtx->id, (int)$task['completed_by_user_id']);

        $comments = TaskManagement::listComments($id);
        $this->assertCount(1, $comments);
        $this->assertSame(1, (int)$comments[0]['marked_complete']);
        $this->assertSame('Done at the meeting', $comments[0]['comment']);
    }

    public function testReopenClearsCompletion(): void
    {
        $id = $this->createTaskAssignedToOther();
        TaskManagement::markComplete($this->otherMemberCtx, $id);
        TaskManagement::reopenTask($this->memberCtx, $id);

        $task = TaskManagement::getTask($id);
        $this->assertSame(0, (int)$task['is_done']);
        $this->assertNull($task['completion_date']);
        $this->assertNull($task['completed_by_user_id']);
    }

    // --- comments ---

    public function testAnyMemberCanComment(): void
    {
        $id = $this->createTaskAssignedToOther();
        TaskManagement::addComment($this->otherMemberCtx, $id, 'On it!');
        $this->assertCount(1, TaskManagement::listComments($id));
    }

    public function testOutsiderCannotComment(): void
    {
        $id = $this->createTaskAssignedToOther();
        $this->expectException(RuntimeException::class);
        TaskManagement::addComment($this->outsiderCtx, $id, 'Sneaky');
    }

    public function testEmptyCommentRejected(): void
    {
        $id = $this->createTaskAssignedToOther();
        $this->expectException(InvalidArgumentException::class);
        TaskManagement::addComment($this->memberCtx, $id, '   ');
    }

    public function testOnlyAuthorOrAdminCanDeleteComment(): void
    {
        $id = $this->createTaskAssignedToOther();
        $commentId = TaskManagement::addComment($this->otherMemberCtx, $id, 'Mine');

        try {
            TaskManagement::deleteComment($this->memberCtx, $commentId);
            $this->fail('Non-author member deleted a comment');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertTrue(TaskManagement::deleteComment($this->otherMemberCtx, $commentId));
    }

    // --- listTasks filters ---

    public function testListTasksMineFilterAndDoneFilter(): void
    {
        $mine = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Mine', 'assigned_user_ids' => [$this->memberCtx->id],
        ]);
        TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Theirs', 'assigned_user_ids' => [$this->otherMemberCtx->id],
        ]);
        $done = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Done one', 'assigned_user_ids' => [$this->memberCtx->id],
        ]);
        TaskManagement::markComplete($this->memberCtx, $done);

        $mineOpen = TaskManagement::listTasks($this->groupId, ['assigned_to_user_id' => $this->memberCtx->id]);
        $this->assertSame([$mine], array_map(fn($t) => (int)$t['id'], $mineOpen));

        $all = TaskManagement::listTasks($this->groupId, ['include_done' => true]);
        $this->assertCount(3, $all);
    }

    // --- pure grouping helpers ---

    public function testGroupTasksByWeek(): void
    {
        $today = '2026-07-15'; // a Wednesday; Monday = 2026-07-13
        $tasks = [
            ['id' => 1, 'title' => 'Overdue', 'due_date' => '2026-07-01', 'is_done' => 0],
            ['id' => 2, 'title' => 'This week', 'due_date' => '2026-07-17', 'is_done' => 0],
            ['id' => 3, 'title' => 'Next week', 'due_date' => '2026-07-21', 'is_done' => 0],
            ['id' => 4, 'title' => 'No date', 'due_date' => null, 'is_done' => 0],
            ['id' => 5, 'title' => 'Next year', 'due_date' => '2027-01-05', 'is_done' => 0],
            ['id' => 6, 'title' => 'Finished', 'due_date' => '2026-07-10', 'is_done' => 1, 'completion_date' => '2026-07-10'],
        ];

        $groups = TaskManagement::groupTasksByWeek($tasks, $today);
        $labels = array_column($groups, 'label');

        $this->assertSame('Overdue', $labels[0]);
        $this->assertContains('This Week', $labels);
        $this->assertContains('Week of Mon Jul 20', $labels);
        $this->assertContains('Week of Mon Jan 4', $labels);
        $this->assertContains('No due date', $labels);
        $this->assertSame('Completed', $labels[count($labels) - 1]);

        // "This Week" contains only the July 17 task
        foreach ($groups as $g) {
            if ($g['label'] === 'This Week') {
                $this->assertSame([2], array_map(fn($t) => (int)$t['id'], $g['tasks']));
            }
        }
    }

    public function testGroupTasksByWeekOverdueExcludesEarlierThisWeek(): void
    {
        // Monday of the week of 2026-07-15 is 2026-07-13; a task due the 13th
        // is overdue relative to today (the 15th), not "this week".
        $groups = TaskManagement::groupTasksByWeek([
            ['id' => 1, 'title' => 'Monday task', 'due_date' => '2026-07-13', 'is_done' => 0],
        ], '2026-07-15');
        $this->assertSame('Overdue', $groups[0]['label']);
    }

    public function testGroupTasksByOwner(): void
    {
        $zoe = ['user_id' => 1, 'first_name' => 'Zoe', 'last_name' => 'Young'];
        $ada = ['user_id' => 2, 'first_name' => 'ada', 'last_name' => 'Byron'];
        $tasks = [
            ['id' => 1, 'title' => 'B', 'assignees' => [$zoe], 'is_done' => 0],
            ['id' => 2, 'title' => 'A', 'assignees' => [$ada], 'is_done' => 0],
            ['id' => 3, 'title' => 'C', 'assignees' => [], 'is_done' => 0],
            ['id' => 4, 'title' => 'E', 'assignees' => [$zoe], 'is_done' => 0],
            ['id' => 5, 'title' => 'D', 'assignees' => [$ada], 'is_done' => 1, 'completion_date' => '2026-07-10'],
        ];

        $groups = TaskManagement::groupTasksByOwner($tasks);
        $labels = array_column($groups, 'label');

        $this->assertSame(['ada Byron', 'Zoe Young', 'Unassigned', 'Completed'], $labels);
        $this->assertCount(2, $groups[1]['tasks']);
    }

    public function testGroupTasksByOwnerListsMultiAssigneeTaskUnderEachOwner(): void
    {
        $zoe = ['user_id' => 1, 'first_name' => 'Zoe', 'last_name' => 'Young'];
        $ada = ['user_id' => 2, 'first_name' => 'ada', 'last_name' => 'Byron'];
        $mel = ['user_id' => 3, 'first_name' => 'Mel', 'last_name' => 'Ott'];
        $tasks = [
            ['id' => 1, 'title' => 'Shared', 'assignees' => [$zoe, $ada, $mel], 'is_done' => 0],
            ['id' => 2, 'title' => 'Solo', 'assignees' => [$ada], 'is_done' => 0],
        ];

        $groups = TaskManagement::groupTasksByOwner($tasks);
        $labels = array_column($groups, 'label');

        $this->assertSame(['ada Byron', 'Mel Ott', 'Zoe Young'], $labels);
        $this->assertCount(2, $groups[0]['tasks']); // ada: Shared + Solo
        $this->assertCount(1, $groups[1]['tasks']);
        $this->assertCount(1, $groups[2]['tasks']);
    }

    // --- multi-assignee ---

    public function testMultiAssigneeRoundtripAndDedup(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Team task',
            'assigned_user_ids' => [$this->otherMemberCtx->id, $this->ownerCtx->id, $this->otherMemberCtx->id],
        ]);

        $task = TaskManagement::getTask($id);
        $ids = array_column($task['assignees'], 'user_id');
        sort($ids);
        $this->assertSame([$this->ownerCtx->id, $this->otherMemberCtx->id], $ids);
    }

    public function testUpdateReplacesAssigneeSet(): void
    {
        $id = TaskManagement::createTask($this->memberCtx, $this->groupId, [
            'title' => 'Rotating duty',
            'assigned_user_ids' => [$this->otherMemberCtx->id],
        ]);
        TaskManagement::updateTask($this->memberCtx, $id, [
            'title' => 'Rotating duty',
            'assigned_user_ids' => [$this->ownerCtx->id, $this->memberCtx->id],
        ]);

        $ids = array_column(TaskManagement::getTask($id)['assignees'], 'user_id');
        sort($ids);
        $this->assertSame([$this->ownerCtx->id, $this->memberCtx->id], $ids);
    }

    public function testAnyAssigneeCanEdit(): void
    {
        $id = TaskManagement::createTask($this->ownerCtx, $this->groupId, [
            'title' => 'Shared chore',
            'assigned_user_ids' => [$this->memberCtx->id, $this->otherMemberCtx->id],
        ]);

        $edit = fn(string $title) => ['title' => $title, 'assigned_user_ids' => [$this->memberCtx->id, $this->otherMemberCtx->id]];
        $this->assertTrue(TaskManagement::updateTask($this->memberCtx, $id, $edit('By first assignee')));
        $this->assertTrue(TaskManagement::updateTask($this->otherMemberCtx, $id, $edit('By second assignee')));
    }

    public function testMineFilterMatchesAnyAssignee(): void
    {
        TaskManagement::createTask($this->ownerCtx, $this->groupId, [
            'title' => 'Shared', 'assigned_user_ids' => [$this->memberCtx->id, $this->otherMemberCtx->id],
        ]);
        TaskManagement::createTask($this->ownerCtx, $this->groupId, [
            'title' => 'Nobody\'s', 'assigned_user_ids' => [],
        ]);

        foreach ([$this->memberCtx->id, $this->otherMemberCtx->id] as $uid) {
            $mine = TaskManagement::listTasks($this->groupId, ['assigned_to_user_id' => $uid]);
            $this->assertCount(1, $mine);
            $this->assertSame('Shared', $mine[0]['title']);
        }
    }
}
