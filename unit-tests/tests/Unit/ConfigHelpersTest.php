<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigHelpersTest extends TestCase
{
    public function testValidNextPathsPassThrough(): void
    {
        $this->assertSame('/index.php', validate_relative_next_path('/index.php'));
        $this->assertSame('/profile/edit.php?x=1', validate_relative_next_path('/profile/edit.php?x=1'));
        $this->assertSame('/admin/users.php', validate_relative_next_path('  /admin/users.php  '));
    }

    public function testInvalidNextPathsAreRejected(): void
    {
        $this->assertSame('', validate_relative_next_path(''));
        $this->assertSame('', validate_relative_next_path('index.php')); // not absolute
        $this->assertSame('', validate_relative_next_path('https://evil.example.com'));
        $this->assertSame('', validate_relative_next_path('//evil.example.com')); // protocol-relative
        $this->assertSame('', validate_relative_next_path('/login.php?next=/x')); // login loop
        $this->assertSame('', validate_relative_next_path(null));
        $this->assertSame('', validate_relative_next_path(['array']));
    }
}
