<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Support;

/**
 * Redacts sensitive values (passwords, tokens, env secrets) from command
 * argument arrays and free-form strings before they are written to logs or
 * error messages.
 *
 * Call SecretRedactor::redactCommand($cmd) before logging any process
 * command and SecretRedactor::redact($str) for free-form strings.
 */
final class SecretRedactor
{
    private const REDACTED = '[REDACTED]';

    /**
     * Patterns matched against individual command tokens.
     * Each pattern is checked with a case-insensitive prefix match.
     *
     * @var array<string>
     */
    private const TOKEN_PREFIXES = [
        '--password=',
        '-p',
    ];

    /**
     * Redact sensitive values from a command argument array and return a
     * safe string representation suitable for logging.
     *
     * @param  array<string|int, string|int> $cmd
     */
    public static function redactCommand(array $cmd): string
    {
        $safe = [];
        $redactNext = false;
        foreach ($cmd as $token) {
            $token = (string)$token;

            if ($redactNext) {
                $safe[] = self::REDACTED;
                $redactNext = false;
                continue;
            }

            if ($token === '-p') {
                $safe[] = $token;
                $redactNext = true;
                continue;
            }

            $safe[] = self::redactToken($token);
        }

        return implode(' ', $safe);
    }

    /**
     * Redact sensitive values from a free-form string (e.g. an error message
     * that already contains a serialised command or environment dump).
     */
    public static function redact(string $str): string
    {
        // Replace --****** with --******
        $str = preg_replace('/--(?:password|passwd|pass)=\S+/i', '--password=' . self::REDACTED, $str) ?? $str;

        // Replace -pVALUE with -p[REDACTED] (only when -p is not part of --password)
        $str = preg_replace('/(?<!-)-p(?=[^-\s\r\n])\S+/', '-p' . self::REDACTED, $str) ?? $str;

        // Replace "-p secret" with "-p [REDACTED]"
        $str = preg_replace('/(?<!\S)-p\s+\S+/', '-p ' . self::REDACTED, $str) ?? $str;

        // Replace ENV_VAR=VALUE with ENV_VAR=[REDACTED]
        $str = preg_replace(
            '/\b(PGPASSWORD|MYSQL_PWD|DB_PASSWORD|DATABASE_PASSWORD|MYSQL_PASSWORD)=\S+/i',
            '$1=' . self::REDACTED,
            $str
        ) ?? $str;

        return $str;
    }

    // ── private ───────────────────────────────────────────────────────────

    private static function redactToken(string $token): string
    {
        foreach (self::TOKEN_PREFIXES as $prefix) {
            if (stripos($token, $prefix) === 0) {
                // Keep the flag name, replace the value
                if (str_contains($prefix, '=')) {
                    // e.g. --password=
                    return $prefix . self::REDACTED;
                }
                // e.g. -p (value follows immediately or token IS the flag)
                if (strlen($token) > strlen($prefix)) {
                    return $prefix . self::REDACTED;
                }
                // Token is exactly the prefix (value in next token – redact it)
                return $token;
            }
        }

        return $token;
    }
}
