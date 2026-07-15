<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Groups ("task_groups" in the database — GROUPS is a reserved word in MySQL).
//
// Permission model (see docs/app-spec.md):
//   - Exactly one owner per group. The owner always has a group_members row
//     with role='admin'; it can never be removed or demoted.
//   - Only the owner may appoint or demote group admins.
//   - Group admins have broad permission on the group but cannot remove the
//     owner or manage other admins.
//   - App administrators (users.is_admin) bypass all group restrictions.
class GroupManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(string $action, array $meta = []): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function requireLogin(?UserContext $ctx): UserContext {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        return $ctx;
    }

    // ===== Role checks =====

    public static function isOwner(int $userId, int $groupId): bool {
        $st = self::pdo()->prepare('SELECT 1 FROM task_groups WHERE id=? AND owner_user_id=? LIMIT 1');
        $st->execute([$groupId, $userId]);
        return (bool)$st->fetchColumn();
    }

    // Owner or a member with role='admin'.
    public static function isGroupAdmin(int $userId, int $groupId): bool {
        if (self::isOwner($userId, $groupId)) return true;
        $st = self::pdo()->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? AND role='admin' LIMIT 1");
        $st->execute([$groupId, $userId]);
        return (bool)$st->fetchColumn();
    }

    public static function isMember(int $userId, int $groupId): bool {
        $st = self::pdo()->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
        $st->execute([$groupId, $userId]);
        return (bool)$st->fetchColumn();
    }

    // Can this context administer the group (edit settings, manage people)?
    public static function canManageGroup(?UserContext $ctx, int $groupId): bool {
        if (!$ctx) return false;
        if ($ctx->admin) return true;
        return self::isGroupAdmin($ctx->id, $groupId);
    }

    // Can this context see the group's tasks at all?
    public static function canViewGroup(?UserContext $ctx, int $groupId): bool {
        if (!$ctx) return false;
        if ($ctx->admin) return true;
        return self::isMember($ctx->id, $groupId);
    }

    private static function assertCanManage(?UserContext $ctx, int $groupId): UserContext {
        $ctx = self::requireLogin($ctx);
        if (!self::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can do this.');
        }
        return $ctx;
    }

    private static function assertOwnerOrAppAdmin(?UserContext $ctx, int $groupId): UserContext {
        $ctx = self::requireLogin($ctx);
        if (!$ctx->admin && !self::isOwner($ctx->id, $groupId)) {
            throw new RuntimeException('Only the group owner can do this.');
        }
        return $ctx;
    }

    // ===== Group CRUD =====

    // Any logged-in user may create a group. The creator becomes the owner,
    // gets an admin membership row, and the new group becomes their current group.
    public static function createGroup(?UserContext $ctx, array $data): int {
        $ctx = self::requireLogin($ctx);

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Group name is required.');
        }
        $description = trim((string)($data['description'] ?? ''));

        $pdo = self::pdo();
        $st = $pdo->prepare('INSERT INTO task_groups (name, description, owner_user_id) VALUES (?,?,?)');
        $st->execute([$name, $description !== '' ? $description : null, $ctx->id]);
        $groupId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?,?,'admin')");
        $st->execute([$groupId, $ctx->id]);

        $pdo->prepare('UPDATE users SET last_group_id=? WHERE id=?')->execute([$groupId, $ctx->id]);

        self::log('group.create', ['group_id' => $groupId, 'name' => $name]);
        return $groupId;
    }

    public static function updateGroup(?UserContext $ctx, int $groupId, array $data): bool {
        self::assertCanManage($ctx, $groupId);

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Group name is required.');
        }
        $description = trim((string)($data['description'] ?? ''));

        $st = self::pdo()->prepare('UPDATE task_groups SET name=?, description=? WHERE id=?');
        $ok = $st->execute([$name, $description !== '' ? $description : null, $groupId]);

        if ($ok) {
            self::log('group.update', ['group_id' => $groupId, 'name' => $name]);
        }
        return $ok;
    }

    public static function deleteGroup(?UserContext $ctx, int $groupId): bool {
        self::assertOwnerOrAppAdmin($ctx, $groupId);

        $group = self::getGroup($groupId);
        $st = self::pdo()->prepare('DELETE FROM task_groups WHERE id=?');
        $ok = $st->execute([$groupId]);

        if ($ok) {
            self::log('group.delete', ['group_id' => $groupId, 'name' => $group['name'] ?? null]);
        }
        return $ok;
    }

    public static function getGroup(int $groupId): ?array {
        $st = self::pdo()->prepare(
            'SELECT g.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name
             FROM task_groups g LEFT JOIN users u ON u.id = g.owner_user_id
             WHERE g.id=? LIMIT 1'
        );
        $st->execute([$groupId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Groups the user belongs to, with their role. Ordered by name; callers
    // put the current group first for navigation.
    public static function listGroupsForUser(int $userId): array {
        $st = self::pdo()->prepare(
            'SELECT g.*, gm.role, (g.owner_user_id = gm.user_id) AS is_owner
             FROM group_members gm
             JOIN task_groups g ON g.id = gm.group_id
             WHERE gm.user_id = ?
             ORDER BY g.name'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    // All groups in the app (app-admin only, for the admin area).
    public static function listAllGroups(?UserContext $ctx, string $search = ''): array {
        $ctx = self::requireLogin($ctx);
        if (!$ctx->admin) {
            throw new RuntimeException('Admins only');
        }

        $sql = 'SELECT g.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                       (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) AS member_count,
                       (SELECT COUNT(*) FROM tasks t WHERE t.group_id = g.id) AS task_count
                FROM task_groups g LEFT JOIN users u ON u.id = g.owner_user_id';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE g.name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY g.name';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // ===== Membership =====

    // role='member' requires group-admin permission; role='admin' may only be
    // granted by the owner (or an app admin).
    public static function addMember(?UserContext $ctx, int $groupId, int $userId, string $role = 'member'): void {
        if (!in_array($role, ['member', 'admin'], true)) {
            throw new InvalidArgumentException('Invalid role.');
        }
        if ($role === 'admin') {
            self::assertOwnerOrAppAdmin($ctx, $groupId);
        } else {
            self::assertCanManage($ctx, $groupId);
        }

        if (self::isMember($userId, $groupId)) {
            return; // already a member; adding again is a no-op
        }

        $st = self::pdo()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?,?,?)');
        $st->execute([$groupId, $userId, $role]);

        self::log('group.member_add', ['group_id' => $groupId, 'target_user_id' => $userId, 'role' => $role]);
    }

    public static function removeMember(?UserContext $ctx, int $groupId, int $userId): void {
        $ctx = self::assertCanManage($ctx, $groupId);

        if (self::isOwner($userId, $groupId)) {
            throw new RuntimeException('The group owner cannot be removed from the group.');
        }
        // Group admins cannot remove other admins — only the owner (or an app admin) can.
        if (!$ctx->admin && !self::isOwner($ctx->id, $groupId) && self::isGroupAdmin($userId, $groupId)) {
            throw new RuntimeException('Only the group owner can remove a group admin.');
        }

        $st = self::pdo()->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?');
        $st->execute([$groupId, $userId]);

        // If this was the removed user's current group, clear it.
        self::pdo()->prepare('UPDATE users SET last_group_id=NULL WHERE id=? AND last_group_id=?')
            ->execute([$userId, $groupId]);

        self::log('group.member_remove', ['group_id' => $groupId, 'target_user_id' => $userId]);
    }

    // Promote/demote a member. Owner (or app admin) only; the owner's own row
    // is protected.
    public static function setMemberRole(?UserContext $ctx, int $groupId, int $userId, string $role): void {
        self::assertOwnerOrAppAdmin($ctx, $groupId);

        if (!in_array($role, ['member', 'admin'], true)) {
            throw new InvalidArgumentException('Invalid role.');
        }
        if (self::isOwner($userId, $groupId)) {
            throw new RuntimeException('The group owner is always an admin.');
        }
        if (!self::isMember($userId, $groupId)) {
            throw new RuntimeException('That person is not a member of this group.');
        }

        $st = self::pdo()->prepare('UPDATE group_members SET role=? WHERE group_id=? AND user_id=?');
        $st->execute([$role, $groupId, $userId]);

        self::log('group.member_role', ['group_id' => $groupId, 'target_user_id' => $userId, 'role' => $role]);
    }

    public static function transferOwnership(?UserContext $ctx, int $groupId, int $newOwnerUserId): void {
        self::assertOwnerOrAppAdmin($ctx, $groupId);

        if (!self::isMember($newOwnerUserId, $groupId)) {
            throw new RuntimeException('The new owner must already be a member of the group.');
        }

        $pdo = self::pdo();
        $pdo->prepare('UPDATE task_groups SET owner_user_id=? WHERE id=?')->execute([$newOwnerUserId, $groupId]);
        // The owner is always an admin.
        $pdo->prepare("UPDATE group_members SET role='admin' WHERE group_id=? AND user_id=?")
            ->execute([$groupId, $newOwnerUserId]);

        self::log('group.transfer_ownership', ['group_id' => $groupId, 'new_owner_user_id' => $newOwnerUserId]);
    }

    public static function listMembers(int $groupId): array {
        $st = self::pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.photo_public_file_id,
                    gm.role, gm.created_at AS member_since,
                    (u.id = g.owner_user_id) AS is_owner,
                    (u.password_hash <> '') AS has_password,
                    (u.email_verify_token IS NOT NULL) AS invite_pending
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             JOIN task_groups g ON g.id = gm.group_id
             WHERE gm.group_id = ?
             ORDER BY (u.id = g.owner_user_id) DESC, gm.role = 'admin' DESC, u.first_name, u.last_name"
        );
        $st->execute([$groupId]);
        return $st->fetchAll();
    }

    // ===== Current group context =====

    // Remember the group the user is working in (renders first in the nav).
    public static function setCurrentGroup(?UserContext $ctx, int $groupId): void {
        $ctx = self::requireLogin($ctx);
        if (!self::canViewGroup($ctx, $groupId)) {
            throw new RuntimeException('You are not a member of that group.');
        }
        self::pdo()->prepare('UPDATE users SET last_group_id=? WHERE id=?')->execute([$groupId, $ctx->id]);
    }

    // The user's current group: last_group_id if they can still see it,
    // otherwise their first group, otherwise null.
    public static function resolveCurrentGroupId(?UserContext $ctx): ?int {
        if (!$ctx) return null;

        $st = self::pdo()->prepare('SELECT last_group_id FROM users WHERE id=? LIMIT 1');
        $st->execute([$ctx->id]);
        $lastGroupId = $st->fetchColumn();
        if ($lastGroupId && self::canViewGroup($ctx, (int)$lastGroupId)) {
            return (int)$lastGroupId;
        }

        $groups = self::listGroupsForUser($ctx->id);
        return $groups ? (int)$groups[0]['id'] : null;
    }
}
