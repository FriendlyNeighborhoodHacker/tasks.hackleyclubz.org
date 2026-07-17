<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GroupSmtpSettingsTest extends TestCase
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

        $this->groupId = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Club Board']);
        GroupManagement::addMember($this->ownerCtx, $this->groupId, $this->memberId, 'member');
    }

    private function validData(array $overrides = []): array
    {
        return $overrides + [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => '587',
            'smtp_username' => 'club@gmail.com',
            'smtp_password' => 'app-password',
            'smtp_secure' => 'tls',
            'from_email' => '',
            'from_name' => '',
        ];
    }

    public function testNoOverrideByDefault(): void
    {
        $this->assertNull(GroupSmtpSettings::get($this->groupId));
        $this->assertNull(GroupSmtpSettings::getForSending($this->groupId));
    }

    public function testSaveAndGetRoundtripWithFallbacks(): void
    {
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());

        $row = GroupSmtpSettings::get($this->groupId);
        $this->assertSame('smtp.gmail.com', $row['smtp_host']);
        $this->assertSame(587, (int)$row['smtp_port']);
        $this->assertSame('tls', $row['smtp_secure']);
        $this->assertSame('club@gmail.com', $row['smtp_username']);
        $this->assertSame('app-password', $row['smtp_password']);
        $this->assertNull($row['from_email']);
        $this->assertNull($row['from_name']);

        // getForSending applies the fallbacks: username and group name.
        $smtp = GroupSmtpSettings::getForSending($this->groupId);
        $this->assertSame([
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'club@gmail.com',
            'password' => 'app-password',
            'secure' => 'tls',
            'from_email' => 'club@gmail.com',
            'from_name' => 'Club Board',
        ], $smtp);

        // Explicit from fields win over the fallbacks.
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData([
            'from_email' => 'noreply@club.org',
            'from_name' => 'The Club',
        ]));
        $smtp = GroupSmtpSettings::getForSending($this->groupId);
        $this->assertSame('noreply@club.org', $smtp['from_email']);
        $this->assertSame('The Club', $smtp['from_name']);
    }

    public function testSavingTwiceUpdatesInPlace(): void
    {
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '465',
            'smtp_secure' => 'ssl',
        ]));

        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM group_smtp_overrides')->fetchColumn());
        $row = GroupSmtpSettings::get($this->groupId);
        $this->assertSame('smtp.example.com', $row['smtp_host']);
        $this->assertSame(465, (int)$row['smtp_port']);
        $this->assertSame('ssl', $row['smtp_secure']);
    }

    public function testBlankPasswordKeepsStoredPassword(): void
    {
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData([
            'smtp_password' => '',
            'smtp_host' => 'smtp2.gmail.com',
        ]));

        $row = GroupSmtpSettings::get($this->groupId);
        $this->assertSame('smtp2.gmail.com', $row['smtp_host']);
        $this->assertSame('app-password', $row['smtp_password']);
    }

    public function testBlankPasswordOnFirstSaveThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData(['smtp_password' => '']));
    }

    public function testValidationRejectsBadValues(): void
    {
        foreach ([
            ['smtp_host' => '  '],
            ['smtp_username' => ''],
            ['smtp_port' => '99999'],
            ['smtp_port' => 'abc'],
            ['smtp_port' => ''],
            ['smtp_secure' => 'starttls'],
            ['from_email' => 'not-an-email'],
        ] as $bad) {
            try {
                GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData($bad));
                $this->fail('Expected InvalidArgumentException for ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull(GroupSmtpSettings::get($this->groupId));
    }

    public function testRegularMembersAndAnonymousCannotEdit(): void
    {
        $memberCtx = new UserContext($this->memberId, false);
        foreach ([$memberCtx, null] as $ctx) {
            try {
                GroupSmtpSettings::save($ctx, $this->groupId, $this->validData());
                $this->fail('Expected RuntimeException on save');
            } catch (RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
            try {
                GroupSmtpSettings::remove($ctx, $this->groupId);
                $this->fail('Expected RuntimeException on remove');
            } catch (RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRemoveRestoresSiteDefault(): void
    {
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());
        $this->assertNotNull(GroupSmtpSettings::getForSending($this->groupId));

        GroupSmtpSettings::remove($this->ownerCtx, $this->groupId);
        $this->assertNull(GroupSmtpSettings::get($this->groupId));
        $this->assertNull(GroupSmtpSettings::getForSending($this->groupId));
    }

    public function testOverridesAreScopedPerGroup(): void
    {
        $otherGroup = GroupManagement::createGroup($this->ownerCtx, ['name' => 'Other']);
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());

        $this->assertNotNull(GroupSmtpSettings::getForSending($this->groupId));
        $this->assertNull(GroupSmtpSettings::getForSending($otherGroup));
    }

    // --- Reply-To (independent of the override) ---

    public function testReplyToIsIndependentOfTheOverride(): void
    {
        // Settable with no SMTP override at all.
        $this->assertSame('', GroupSmtpSettings::getReplyTo($this->groupId));
        GroupSmtpSettings::saveReplyTo($this->ownerCtx, $this->groupId, ' leader@example.com ');
        $this->assertSame('leader@example.com', GroupSmtpSettings::getReplyTo($this->groupId));
        $this->assertNull(GroupSmtpSettings::getForSending($this->groupId));

        // Survives saving and removing an override.
        GroupSmtpSettings::save($this->ownerCtx, $this->groupId, $this->validData());
        GroupSmtpSettings::remove($this->ownerCtx, $this->groupId);
        $this->assertSame('leader@example.com', GroupSmtpSettings::getReplyTo($this->groupId));

        // Blank clears it.
        GroupSmtpSettings::saveReplyTo($this->ownerCtx, $this->groupId, '');
        $this->assertSame('', GroupSmtpSettings::getReplyTo($this->groupId));
    }

    public function testReplyToValidationAndPermissions(): void
    {
        try {
            GroupSmtpSettings::saveReplyTo($this->ownerCtx, $this->groupId, 'not-an-email');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        foreach ([new UserContext($this->memberId, false), null] as $ctx) {
            try {
                GroupSmtpSettings::saveReplyTo($ctx, $this->groupId, 'leader@example.com');
                $this->fail('Expected RuntimeException');
            } catch (RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('', GroupSmtpSettings::getReplyTo($this->groupId));
    }
}
