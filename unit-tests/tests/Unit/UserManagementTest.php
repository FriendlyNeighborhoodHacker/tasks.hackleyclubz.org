<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserManagementTest extends TestCase
{
    private UserContext $adminCtx;

    protected function setUp(): void
    {
        test_reset_all();

        // Seed an acting admin directly (avoids createUser's email side effects)
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
                     VALUES ('Admin', 'User', 'admin@example.com', 'hash', 1, NOW())");
        $adminId = (int)pdo()->lastInsertId();
        $this->adminCtx = new UserContext($adminId, true);
        UserContext::set($this->adminCtx);
    }

    // --- createUser: no-login family members ---

    public function testCreateNoLoginMemberWithoutEmail(): void
    {
        $id = UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Charlie',
            'last_name' => 'Rosenthal',
            'no_login' => true,
        ]);

        $user = UserManagement::findById($id);
        $this->assertNotNull($user);
        $this->assertNull($user['email']);
        $this->assertSame('', $user['password_hash']);
        $this->assertNull($user['email_verified_at']);
        $this->assertSame(0, (int)$user['is_admin']);
    }

    public function testCreateNoLoginMemberCannotBeAdmin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Charlie',
            'last_name' => 'Rosenthal',
            'no_login' => true,
            'is_admin' => 1,
        ]);
    }

    public function testCreateLoginUserRequiresEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Dana',
            'last_name' => 'Rosenthal',
            'password' => 'supersecret1',
        ]);
    }

    public function testCreateUserRequiresAdmin(): void
    {
        $nonAdmin = new UserContext(999, false);
        $this->expectException(RuntimeException::class);
        UserManagement::createUser($nonAdmin, [
            'first_name' => 'Eve',
            'last_name' => 'Rosenthal',
            'no_login' => true,
        ]);
    }

    public function testListUsersReportsHasPassword(): void
    {
        UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Charlie',
            'last_name' => 'Rosenthal',
            'no_login' => true,
        ]);

        $byName = [];
        foreach (UserManagement::listUsers() as $u) {
            $byName[$u['first_name']] = $u;
        }
        $this->assertSame(1, (int)$byName['Admin']['has_password']);
        $this->assertSame(0, (int)$byName['Charlie']['has_password']);
    }

    // --- Account activation (verify token + initial password setup) ---

    private function insertPendingUser(string $token): int
    {
        $st = pdo()->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verify_token)
             VALUES ('Pending', 'User', 'pending@example.com', '', 0, ?)"
        );
        $st->execute([$token]);
        return (int)pdo()->lastInsertId();
    }

    public function testFindPendingPasswordSetupByToken(): void
    {
        $this->insertPendingUser('tok123');

        $user = UserManagement::findPendingPasswordSetupByToken('tok123');
        $this->assertNotNull($user);
        $this->assertSame('pending@example.com', $user['email']);

        $this->assertNull(UserManagement::findPendingPasswordSetupByToken('wrong'));
        $this->assertNull(UserManagement::findPendingPasswordSetupByToken(''));
    }

    public function testFindPendingPasswordSetupIgnoresUsersWithPassword(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, email_verify_token)
                     VALUES ('Has', 'Password', 'has@example.com', 'somehash', 'tok456')");

        // Token exists but the account already has a password: not a setup candidate
        $this->assertNull(UserManagement::findPendingPasswordSetupByToken('tok456'));
        $this->assertNotNull(UserManagement::findByVerifyToken('tok456'));
    }

    public function testCompleteInitialPasswordSetup(): void
    {
        $id = $this->insertPendingUser('tok789');

        $user = UserManagement::completeInitialPasswordSetup('tok789', 'newpassword1');

        $this->assertNotNull($user);
        $this->assertSame($id, (int)$user['id']);
        $this->assertTrue(password_verify('newpassword1', $user['password_hash']));
        $this->assertNotNull($user['email_verified_at']);
        $this->assertNull($user['email_verify_token']);

        // Token is single-use
        $this->assertNull(UserManagement::completeInitialPasswordSetup('tok789', 'anotherpass1'));
    }

    public function testCompleteInitialPasswordSetupWithInvalidToken(): void
    {
        $this->assertNull(UserManagement::completeInitialPasswordSetup('nope', 'newpassword1'));
    }

    // --- updateProfile ---

    public function testUpdateProfileRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::updateProfile($this->adminCtx, $this->adminCtx->id, ['email' => 'not-an-email']);
    }

    public function testUpdateProfileRejectsClearingEmailForLoginUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::updateProfile($this->adminCtx, $this->adminCtx->id, ['email' => '']);
    }

    public function testUpdateProfileAllowsClearingEmailForNoLoginMember(): void
    {
        $id = UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Charlie',
            'last_name' => 'Rosenthal',
            'email' => 'charlie@example.com',
            'no_login' => true,
        ]);

        $ok = UserManagement::updateProfile($this->adminCtx, $id, ['email' => '']);
        $this->assertTrue($ok);
        $this->assertNull(UserManagement::findById($id)['email']);
    }

    public function testUpdateProfileUpdatesNames(): void
    {
        $ok = UserManagement::updateProfile($this->adminCtx, $this->adminCtx->id, [
            'first_name' => 'Renamed',
            'last_name' => 'Person',
        ]);
        $this->assertTrue($ok);

        $user = UserManagement::findById($this->adminCtx->id);
        $this->assertSame('Renamed', $user['first_name']);
        $this->assertSame('Person', $user['last_name']);
    }

    public function testNonAdminCannotUpdateOtherUsers(): void
    {
        $otherId = UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Charlie',
            'last_name' => 'Rosenthal',
            'no_login' => true,
        ]);

        $nonAdmin = new UserContext($otherId + 1000, false);
        $this->expectException(RuntimeException::class);
        UserManagement::updateProfile($nonAdmin, $otherId, ['first_name' => 'Hacked']);
    }

    // --- findOrCreateByEmail (lightweight assign-by-email accounts) ---

    public function testFindOrCreateByEmailCreatesLightweightUser(): void
    {
        $id = UserManagement::findOrCreateByEmail($this->adminCtx, 'Nora', 'Nguyen', 'Nora@Example.com');

        $user = UserManagement::findById($id);
        $this->assertSame('nora@example.com', $user['email']); // normalized
        $this->assertSame('', $user['password_hash']);
        $this->assertNull($user['email_verified_at']);
        $this->assertSame(0, (int)$user['is_admin']);
    }

    public function testFindOrCreateByEmailReturnsExistingUser(): void
    {
        $first = UserManagement::findOrCreateByEmail($this->adminCtx, 'Nora', 'Nguyen', 'nora@example.com');
        $second = UserManagement::findOrCreateByEmail($this->adminCtx, 'Different', 'Name', 'nora@example.com');
        $this->assertSame($first, $second);
        $this->assertSame('Nora', UserManagement::findById($second)['first_name']);
    }

    public function testFindOrCreateByEmailRequiresNamesForNewUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::findOrCreateByEmail($this->adminCtx, '', 'Nguyen', 'nora@example.com');
    }

    public function testFindOrCreateByEmailRequiresValidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::findOrCreateByEmail($this->adminCtx, 'Nora', 'Nguyen', 'not-an-email');
    }

    public function testFindOrCreateByEmailRequiresLogin(): void
    {
        $this->expectException(RuntimeException::class);
        UserManagement::findOrCreateByEmail(null, 'Nora', 'Nguyen', 'nora@example.com');
    }

    // --- Password reset claiming a lightweight account ---

    public function testCompletePasswordResetVerifiesEmailForLightweightUser(): void
    {
        $id = UserManagement::findOrCreateByEmail($this->adminCtx, 'Nora', 'Nguyen', 'nora@example.com');

        $token = 'resettok123';
        pdo()->prepare('UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=?')
            ->execute([hash('sha256', $token), $id]);

        $this->assertTrue(UserManagement::completePasswordReset($token, 'newpassword1'));

        $user = UserManagement::findById($id);
        $this->assertTrue(password_verify('newpassword1', $user['password_hash']));
        $this->assertNotNull($user['email_verified_at']);
    }
}
