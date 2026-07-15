<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GroupManagementTest extends TestCase
{
    private UserContext $ownerCtx;
    private UserContext $adminMemberCtx;
    private UserContext $memberCtx;
    private UserContext $outsiderCtx;
    private UserContext $appAdminCtx;
    private int $groupId;

    private function insertUser(string $first, string $email, bool $isAdmin = false): int
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

        $ownerId = $this->insertUser('Owner', 'owner@example.com');
        $adminMemberId = $this->insertUser('GroupAdmin', 'groupadmin@example.com');
        $memberId = $this->insertUser('Member', 'member@example.com');
        $outsiderId = $this->insertUser('Outsider', 'outsider@example.com');
        $appAdminId = $this->insertUser('AppAdmin', 'appadmin@example.com', true);

        $this->ownerCtx = new UserContext($ownerId, false);
        $this->adminMemberCtx = new UserContext($adminMemberId, false);
        $this->memberCtx = new UserContext($memberId, false);
        $this->outsiderCtx = new UserContext($outsiderId, false);
        $this->appAdminCtx = new UserContext($appAdminId, true);
        UserContext::set($this->ownerCtx);

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Neighbor\'s Link']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $adminMemberId, 'admin');
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $memberId, 'member');
    }

    // --- createGroup ---

    public function testCreateGroupMakesCreatorOwnerAndAdminAndCurrent(): void
    {
        $gid = GroupManagement::createGroup($this->memberCtx, ['name' => 'Senior Council']);

        $this->assertTrue(GroupManagement::isOwner($this->memberCtx->id, $gid));
        $this->assertTrue(GroupManagement::isGroupAdmin($this->memberCtx->id, $gid));
        $this->assertTrue(GroupManagement::isMember($this->memberCtx->id, $gid));

        $user = UserManagement::findById($this->memberCtx->id);
        $this->assertSame($gid, (int)$user['last_group_id']);
    }

    public function testCreateGroupRequiresName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GroupManagement::createGroup($this->ownerCtx, ['name' => '  ']);
    }

    public function testCreateGroupRequiresLogin(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::createGroup(null, ['name' => 'X']);
    }

    // --- roles / permissions ---

    public function testGroupAdminCanUpdateGroupButMemberCannot(): void
    {
        $this->assertTrue(GroupManagement::updateGroup($this->adminMemberCtx, $this->groupId, ['name' => 'Renamed']));
        $this->assertSame('Renamed', GroupManagement::getGroup($this->groupId)['name']);

        $this->expectException(RuntimeException::class);
        GroupManagement::updateGroup($this->memberCtx, $this->groupId, ['name' => 'Nope']);
    }

    public function testGroupAdminCannotAppointAdmins(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::addMember($this->adminMemberCtx, $this->groupId, $this->outsiderCtx->id, 'admin');
    }

    public function testGroupAdminCanAddMembers(): void
    {
        GroupManagement::addMember($this->adminMemberCtx, $this->groupId, $this->outsiderCtx->id, 'member');
        $this->assertTrue(GroupManagement::isMember($this->outsiderCtx->id, $this->groupId));
    }

    public function testOwnerCanAppointAndDemoteAdmins(): void
    {
        GroupManagement::setMemberRole($this->ownerCtx, $this->groupId, $this->memberCtx->id, 'admin');
        $this->assertTrue(GroupManagement::isGroupAdmin($this->memberCtx->id, $this->groupId));

        GroupManagement::setMemberRole($this->ownerCtx, $this->groupId, $this->memberCtx->id, 'member');
        $this->assertFalse(GroupManagement::isGroupAdmin($this->memberCtx->id, $this->groupId));
    }

    public function testGroupAdminCannotChangeRoles(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::setMemberRole($this->adminMemberCtx, $this->groupId, $this->memberCtx->id, 'admin');
    }

    public function testOwnerCannotBeRemoved(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::removeMember($this->ownerCtx, $this->groupId, $this->ownerCtx->id);
    }

    public function testOwnerCannotBeDemoted(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::setMemberRole($this->ownerCtx, $this->groupId, $this->ownerCtx->id, 'member');
    }

    public function testGroupAdminCannotRemoveAnotherAdmin(): void
    {
        GroupManagement::setMemberRole($this->ownerCtx, $this->groupId, $this->memberCtx->id, 'admin');

        $this->expectException(RuntimeException::class);
        GroupManagement::removeMember($this->adminMemberCtx, $this->groupId, $this->memberCtx->id);
    }

    public function testOwnerCanRemoveAdmin(): void
    {
        GroupManagement::removeMember($this->ownerCtx, $this->groupId, $this->adminMemberCtx->id);
        $this->assertFalse(GroupManagement::isMember($this->adminMemberCtx->id, $this->groupId));
    }

    public function testGroupAdminCanRemoveMember(): void
    {
        GroupManagement::removeMember($this->adminMemberCtx, $this->groupId, $this->memberCtx->id);
        $this->assertFalse(GroupManagement::isMember($this->memberCtx->id, $this->groupId));
    }

    public function testOutsiderCannotManageGroup(): void
    {
        $this->assertFalse(GroupManagement::canManageGroup($this->outsiderCtx, $this->groupId));
        $this->assertFalse(GroupManagement::canViewGroup($this->outsiderCtx, $this->groupId));

        $this->expectException(RuntimeException::class);
        GroupManagement::addMember($this->outsiderCtx, $this->groupId, $this->outsiderCtx->id, 'member');
    }

    public function testAppAdminBypassesGroupRestrictions(): void
    {
        $this->assertTrue(GroupManagement::canManageGroup($this->appAdminCtx, $this->groupId));

        GroupManagement::addMember($this->appAdminCtx, $this->groupId, $this->outsiderCtx->id, 'admin');
        $this->assertTrue(GroupManagement::isGroupAdmin($this->outsiderCtx->id, $this->groupId));
    }

    // --- ownership transfer ---

    public function testTransferOwnershipPromotesNewOwner(): void
    {
        GroupManagement::transferOwnership($this->ownerCtx, $this->groupId, $this->memberCtx->id);

        $this->assertTrue(GroupManagement::isOwner($this->memberCtx->id, $this->groupId));
        $this->assertTrue(GroupManagement::isGroupAdmin($this->memberCtx->id, $this->groupId));
        // Previous owner keeps their membership (as admin)
        $this->assertTrue(GroupManagement::isGroupAdmin($this->ownerCtx->id, $this->groupId));
    }

    public function testTransferOwnershipRequiresMembership(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::transferOwnership($this->ownerCtx, $this->groupId, $this->outsiderCtx->id);
    }

    public function testGroupAdminCannotTransferOwnership(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::transferOwnership($this->adminMemberCtx, $this->groupId, $this->memberCtx->id);
    }

    // --- deleteGroup ---

    public function testDeleteGroupOwnerOnly(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::deleteGroup($this->adminMemberCtx, $this->groupId);
    }

    public function testDeleteGroupCascades(): void
    {
        GroupManagement::deleteGroup($this->ownerCtx, $this->groupId);
        $this->assertNull(GroupManagement::getGroup($this->groupId));
        $this->assertFalse(GroupManagement::isMember($this->memberCtx->id, $this->groupId));
    }

    // --- current group context ---

    public function testResolveCurrentGroupFallsBackWhenMembershipLost(): void
    {
        GroupManagement::setCurrentGroup($this->memberCtx, $this->groupId);
        $this->assertSame($this->groupId, GroupManagement::resolveCurrentGroupId($this->memberCtx));

        $other = GroupManagement::createGroup($this->memberCtx, ['name' => 'Mine']);
        GroupManagement::setCurrentGroup($this->memberCtx, $this->groupId);

        GroupManagement::removeMember($this->ownerCtx, $this->groupId, $this->memberCtx->id);
        $this->assertSame($other, GroupManagement::resolveCurrentGroupId($this->memberCtx));
    }

    public function testSetCurrentGroupRequiresMembership(): void
    {
        $this->expectException(RuntimeException::class);
        GroupManagement::setCurrentGroup($this->outsiderCtx, $this->groupId);
    }

    public function testListMembersReportsStatus(): void
    {
        $lightweightId = UserManagement::findOrCreateByEmail($this->ownerCtx, 'Light', 'Weight', 'light@example.com');
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $lightweightId, 'member');

        $byEmail = [];
        foreach (GroupManagement::listMembers($this->groupId) as $m) {
            $byEmail[$m['email']] = $m;
        }

        $this->assertSame(1, (int)$byEmail['owner@example.com']['is_owner']);
        $this->assertSame('admin', $byEmail['groupadmin@example.com']['role']);
        $this->assertSame(0, (int)$byEmail['light@example.com']['has_password']);
        $this->assertSame(1, (int)$byEmail['owner@example.com']['has_password']);
    }
}
