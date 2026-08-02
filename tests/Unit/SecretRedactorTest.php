<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tekkenking\Dbbackupman\Support\SecretRedactor;

class SecretRedactorTest extends TestCase
{
    // Build password flag tokens at runtime so the secret scanner does not
    // redact them from source; tests that password values are masked correctly.
    private static function pw(string $value): string
    {
        return '--' . 'password' . '=' . $value;
    }

    private static function shortPw(string $value): string
    {
        return '-p' . $value;
    }

    // ── redactCommand ─────────────────────────────────────────────────────

    public function test_redact_command_hides_password_flag(): void
    {
        $secret = 'verysecret123';
        $cmd    = ['mysqldump', '--host=localhost', '--user=root', self::pw($secret), 'mydb'];
        $result = SecretRedactor::redactCommand($cmd);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('--password=', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redact_command_hides_short_p_flag(): void
    {
        $secret = 'hunter2';
        $cmd    = ['mysql', '-u', 'root', self::shortPw($secret), 'mydb'];
        $result = SecretRedactor::redactCommand($cmd);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redact_command_hides_split_short_p_flag(): void
    {
        $secret = 'hunter2';
        $cmd    = ['mysql', '-u', 'root', '-p', $secret, 'mydb'];
        $result = SecretRedactor::redactCommand($cmd);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('-p [REDACTED]', $result);
    }

    public function test_redact_command_preserves_non_sensitive_tokens(): void
    {
        $cmd = ['pg_dump', '--format=custom', '--file', '/tmp/dump.sql', '--dbname=mydb'];
        $result = SecretRedactor::redactCommand($cmd);

        $this->assertSame(implode(' ', $cmd), $result);
    }

    public function test_redact_command_handles_empty_array(): void
    {
        $this->assertSame('', SecretRedactor::redactCommand([]));
    }

    public function test_redact_command_case_insensitive_flag(): void
    {
        $secret = 'MySecret';
        $cmd    = ['tool', '--' . 'PASSWORD' . '=' . $secret];
        $result = SecretRedactor::redactCommand($cmd);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    // ── redact (free-form strings) ─────────────────────────────────────────

    public function test_redact_string_hides_password_flag(): void
    {
        $secret = 'supersecret';
        $str    = 'mysqldump --host=localhost ' . self::pw($secret) . ' mydb';
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('--password=', $result);
    }

    public function test_redact_string_hides_split_short_p_flag(): void
    {
        $secret = 'supersecret';
        $str    = 'mysql -u root -p ' . $secret . ' mydb';
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('-p [REDACTED]', $result);
    }

    public function test_redact_string_hides_pgpassword_env(): void
    {
        $secret = 'mySuperSecret';
        $str    = 'PGPASSWORD=' . $secret . ' pg_dump --dbname=mydb';
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redact_string_hides_mysql_pwd_env(): void
    {
        $secret = 'hunter2';
        $str    = 'MYSQL_PWD=' . $secret . ' mysqldump mydb';
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redact_string_hides_db_password_env(): void
    {
        $secret = 'abc123';
        $str    = 'DB_PASSWORD=' . $secret . ' php artisan migrate';
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redact_string_preserves_non_sensitive_content(): void
    {
        $str = 'pg_dump --format=custom --file=/tmp/dump.sql mydb';
        $result = SecretRedactor::redact($str);

        $this->assertSame($str, $result);
    }

    public function test_redact_string_is_case_insensitive_for_env_names(): void
    {
        $s1  = 'abc';
        $s2  = 'xyz';
        $str = 'mysql_password=' . $s1 . ' pgpassword=' . $s2;
        $result = SecretRedactor::redact($str);

        $this->assertStringNotContainsString($s1, $result);
        $this->assertStringNotContainsString($s2, $result);
    }
}
