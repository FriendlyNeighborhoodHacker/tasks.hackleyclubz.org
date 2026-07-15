<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class UserManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    private static function normEmail(?string $email): ?string {
        if ($email === null) return null;
        $email = strtolower(trim($email));
        return $email === '' ? null : $email;
    }

    private static function boolInt($v): int {
        return !empty($v) ? 1 : 0;
    }

    // Activity logging - do not perform extra queries, just log what's provided.
    private static function log(string $action, ?int $targetUserId, array $details = []): void {
        try {
            // Actor is the currently logged in user (if any). May be null for some flows.
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($targetUserId !== null && !array_key_exists('target_user_id', $meta)) {
                $meta['target_user_id'] = (int)$targetUserId;
            }
            ActivityLog::log($ctx, (string)$action, (array)$meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) { 
            throw new RuntimeException('Admins only'); 
        }
    }

    private static function assertCanUpdate(?UserContext $ctx, int $targetUserId): void {
        if (!$ctx) { 
            throw new RuntimeException('Login required'); 
        }
        if (!$ctx->admin && $ctx->id !== $targetUserId) { 
            throw new RuntimeException('Forbidden (assertCanUpdate)'); 
        }
    }

    // Find user by email for authentication
    public static function findAuthByEmail(string $email): ?array {
        $email = self::normEmail($email);
        if (!$email) return null;
        $st = self::pdo()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Find an existing user by email, or create a lightweight account (no
    // password, no verification email) so a group admin can assign tasks to
    // someone who has never been formally invited. The person can act on their
    // tasks via emailed token links, and may claim the account at any time
    // through the forgot-password flow or an explicit invite.
    public static function findOrCreateByEmail(?UserContext $ctx, string $firstName, string $lastName, string $email): int {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $email = self::normEmail($email);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        $existing = self::findAuthByEmail($email);
        if ($existing) {
            return (int)$existing['id'];
        }

        $first = self::str($firstName);
        $last = self::str($lastName);
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('First and last name are required when adding a new person.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
             VALUES (?,?,?,'',0,NULL,NULL)"
        );
        $st->execute([$first, $last, $email]);
        $id = (int)self::pdo()->lastInsertId();

        self::log('user.create', $id, ['email' => $email, 'lightweight' => true]);
        return $id;
    }

    // Send (or re-send) an account invitation to a user who cannot sign in yet:
    // generates a verification token and emails a set-your-password link.
    // Callers are responsible for authorization (e.g. the People page verifies
    // the actor administers a group the person belongs to).
    public static function sendAccountInvite(?UserContext $ctx, int $userId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        $user = self::findById($userId);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }
        if (empty($user['email'])) {
            throw new InvalidArgumentException('That person has no email address.');
        }
        if ($user['password_hash'] !== '') {
            throw new InvalidArgumentException('That person already has an account with a password.');
        }

        $token = bin2hex(random_bytes(32));
        self::pdo()->prepare('UPDATE users SET email_verify_token = ? WHERE id = ?')
            ->execute([$token, $userId]);

        send_verification_email((string)$user['email'], $token, (string)$user['first_name']);

        self::log('user.invite_sent', $userId, ['email' => $user['email']]);
    }

    // Find user by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Create a new user (admin-created).
    // Three modes:
    //  - no_login: a person without an account (no email required, cannot sign in
    //    until an admin later adds an email and sends an activation link)
    //  - require_password_setup: sends a verification email; the user sets their own password
    //  - password: created directly with the given password, email pre-verified
    public static function createUser(UserContext $ctx, array $data): int {
        self::assertAdmin($ctx);

        $first = self::str($data['first_name'] ?? '');
        $last = self::str($data['last_name'] ?? '');
        $email = self::normEmail($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $isAdmin = self::boolInt($data['is_admin'] ?? 0);
        $requirePasswordSetup = !empty($data['require_password_setup']);
        $noLogin = !empty($data['no_login']);

        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Missing required fields for user creation.');
        }
        if (!$noLogin && !$email) {
            throw new InvalidArgumentException('Email is required for users who will sign in.');
        }

        // Check if email already exists
        if ($email && self::emailExists($email)) {
            throw new InvalidArgumentException('Email already exists.');
        }

        if ($noLogin) {
            if ($isAdmin) {
                throw new InvalidArgumentException('A person without a login cannot be an admin.');
            }
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,'',0,NULL,NULL)"
            );
            $st->execute([$first, $last, $email]);
            $id = (int)self::pdo()->lastInsertId();

            self::log('user.create', $id, ['email' => $email, 'no_login' => true]);
        } elseif ($requirePasswordSetup) {
            // Create user with empty password hash and verification token for password setup
            $hash = '';
            $token = bin2hex(random_bytes(32));
            $emailVerifiedAt = null;
            
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $st->execute([$first, $last, $email, $hash, $isAdmin, $token, $emailVerifiedAt]);
            $id = (int)self::pdo()->lastInsertId();
            
            // Send verification email that will lead to password setup
            send_verification_email($email, $token, $first);
            
            self::log('user.create', $id, ['email' => $email, 'is_admin' => $isAdmin, 'requires_password_setup' => true]);
        } else {
            // Traditional user creation with password
            if ($password === '') {
                throw new InvalidArgumentException('Password is required when not using password setup flow.');
            }
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,?,?,NULL,NOW())"
            );
            $st->execute([$first, $last, $email, $hash, $isAdmin]);
            $id = (int)self::pdo()->lastInsertId();
            
            self::log('user.create', $id, ['email' => $email, 'is_admin' => $isAdmin]);
        }
        
        return $id;
    }

    // Find a user by their email verification token
    public static function findByVerifyToken(string $token): ?array {
        if ($token === '') return null;
        $st = self::pdo()->prepare('SELECT * FROM users WHERE email_verify_token = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Find a user who verified via token but still needs to set their initial password
    public static function findPendingPasswordSetupByToken(string $token): ?array {
        $user = self::findByVerifyToken($token);
        if (!$user || $user['password_hash'] !== '') return null;
        return $user;
    }

    // Complete the account-activation flow: set the initial password, mark the email
    // verified, and clear the token. Returns the updated user row, or null if the
    // token is invalid or the account already has a password.
    public static function completeInitialPasswordSetup(string $token, string $newPassword): ?array {
        $user = self::findPendingPasswordSetupByToken($token);
        if (!$user) return null;

        if ($newPassword === '') {
            throw new InvalidArgumentException('Password is required.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare(
            'UPDATE users SET password_hash = ?, email_verify_token = NULL, email_verified_at = NOW() WHERE id = ?'
        );
        $ok = $st->execute([$hash, (int)$user['id']]);
        if (!$ok) return null;

        ActivityLog::log(new UserContext((int)$user['id'], !empty($user['is_admin'])), 'user.initial_password_set', []);

        return self::findById((int)$user['id']);
    }

    // Verify email by token
    public static function verifyByToken(string $token): bool {
        if ($token === '') return false;
        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT id FROM users WHERE email_verify_token = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        if (!$row) return false;

        $upd = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?');
        return $upd->execute([(int)$row['id']]);
    }

    // Set password reset token
    public static function setPasswordResetToken(string $email): ?string {
        $email = self::normEmail($email);
        if (!$email) return null;

        $user = self::findAuthByEmail($email);
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes

        $st = self::pdo()->prepare(
            "UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=? WHERE id=?"
        );
        $st->execute([$tokenHash, $expiresAt, (int)$user['id']]);

        // Send reset email
        send_password_reset_email($email, $token, $user['first_name'] ?? '');

        return $token;
    }

    // Verify password reset token and get user
    public static function getUserByResetToken(string $token): ?array {
        if ($token === '') return null;
        
        $tokenHash = hash('sha256', $token);
        $st = self::pdo()->prepare(
            'SELECT * FROM users WHERE password_reset_token_hash = ? AND password_reset_expires_at > NOW() LIMIT 1'
        );
        $st->execute([$tokenHash]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Complete password reset
    public static function completePasswordReset(string $token, string $newPassword): bool {
        $user = self::getUserByResetToken($token);
        if (!$user) return false;

        // Completing a reset proves control of the email address, so also mark
        // it verified — this is how a lightweight (assigned-by-email) user
        // claims a full login account.
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare(
            'UPDATE users SET password_hash=?, password_reset_token_hash=NULL, password_reset_expires_at=NULL,
                    email_verified_at=COALESCE(email_verified_at, NOW()), email_verify_token=NULL WHERE id=?'
        );
        $ok = $st->execute([$hash, (int)$user['id']]);
        
        if ($ok) {
            self::log('user.password_reset', (int)$user['id']);
        }
        
        return $ok;
    }

    // Check if email exists
    public static function emailExists(string $email): bool {
        $norm = self::normEmail($email);
        if (!$norm) return false;
        $st = self::pdo()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $st->execute([$norm]);
        return (bool)$st->fetchColumn();
    }

    // List all users (admin only)
    public static function listUsers(string $search = ''): array {
        $sql = 'SELECT id, first_name, last_name, email, is_admin, email_verified_at, created_at,
                       (password_hash <> \'\') AS has_password FROM users';
        $params = [];

        if ($search !== '') {
            $sql .= ' WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?';
            $searchTerm = '%' . $search . '%';
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        $sql .= ' ORDER BY last_name, first_name';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // Update user profile (name/email). Email may be empty only for users who
    // cannot sign in (no password set); a non-empty email must be valid and unused.
    public static function updateProfile(UserContext $ctx, int $id, array $fields): bool {
        self::assertCanUpdate($ctx, $id);

        $allowed = ['first_name', 'last_name', 'email'];
        $set = [];
        $params = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) continue;

            if ($key === 'email') {
                $val = self::normEmail($fields['email']);
                if ($val !== null && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Valid email is required.');
                }
                if ($val === null) {
                    $existing = self::findById($id);
                    if ($existing && $existing['password_hash'] !== '') {
                        throw new InvalidArgumentException('Email is required for users who can sign in.');
                    }
                }
                $set[] = 'email = ?';
                $params[] = $val;
            } else {
                $val = self::str($fields[$key]);
                if ($val === '') {
                    throw new InvalidArgumentException("$key cannot be empty");
                }
                $set[] = "$key = ?";
                $params[] = $val;
            }
        }

        if (empty($set)) return false;
        $params[] = $id;

        $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
        $st = self::pdo()->prepare($sql);
        $ok = $st->execute($params);
        
        if ($ok) {
            $updatedFields = array_intersect_key($fields, array_flip($allowed));
            self::log('user.update', $id, $updatedFields);
        }
        
        return $ok;
    }

    // Delete user
    public static function deleteUser(UserContext $ctx, int $id): bool {
        self::assertAdmin($ctx);
        if ($id === $ctx->id) { 
            throw new RuntimeException('You cannot delete your own account.'); 
        }
        
        $st = self::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok) {
            self::log('user.delete', $id);
        }
        
        return $ok;
    }

    // Set admin flag
    public static function setAdminFlag(UserContext $ctx, int $id, bool $isAdmin): bool {
        self::assertAdmin($ctx);
        
        $st = self::pdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $ok = $st->execute([$isAdmin ? 1 : 0, $id]);
        
        if ($ok) {
            self::log('user.set_admin', $id, ['is_admin' => $isAdmin ? 1 : 0]);
        }
        
        return $ok;
    }

    // Change password
    public static function changePassword(UserContext $ctx, int $id, string $newPassword): bool {
        self::assertCanUpdate($ctx, $id);
        
        if ($newPassword === '') {
            throw new InvalidArgumentException('New password is required.');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $ok = $st->execute([$hash, $id]);
        
        if ($ok) {
            self::log('user.change_password', $id);
        }
        
        return $ok;
    }

    // Set email verification token (for admin use)
    public static function setEmailVerificationToken(UserContext $ctx, int $id): string {
        self::assertAdmin($ctx);
        
        $token = bin2hex(random_bytes(32));
        $st = self::pdo()->prepare('UPDATE users SET email_verify_token = ?, email_verified_at = NULL WHERE id = ?');
        $ok = $st->execute([$token, $id]);
        
        if (!$ok) {
            throw new RuntimeException('Failed to set email verification token.');
        }
        
        self::log('user.email_verification_token_set', $id);
        return $token;
    }

    // Update user photo
    public static function updateUserPhoto(UserContext $ctx, int $id, ?int $photoPublicFileId): bool {
        self::assertCanUpdate($ctx, $id);
        
        $st = self::pdo()->prepare("UPDATE users SET photo_public_file_id = ? WHERE id = ?");
        $ok = $st->execute([$photoPublicFileId, $id]);
        
        if ($ok) {
            $action = $photoPublicFileId ? 'user.photo_update' : 'user.photo_remove';
            self::log($action, $id, ['photo_public_file_id' => $photoPublicFileId]);
        }
        
        return $ok;
    }
}
