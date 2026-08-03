<?php

declare(strict_types=1);

namespace Tekkenking\Dbbackupman\Application;

/**
 * Routes a BackupContext to the appropriate registered driver handler
 * and returns the handler's result.
 *
 * Drivers are keyed by their normalised name ('pgsql' or 'mysql') and
 * are plain PHP callables so they are easy to swap in tests.
 *
 * Usage:
 *   $orchestrator = new BackupOrchestrator([
 *       'pgsql' => fn(BackupContext $ctx) => $pgService->run($ctx),
 *       'mysql' => fn(BackupContext $ctx) => $myService->run($ctx),
 *   ]);
 *   $result = $orchestrator->run($context);
 */
final class BackupOrchestrator
{
    /**
     * @param array<string, callable(BackupContext): array<string,mixed>> $drivers
     *   Map of driver name → callable that executes the backup and returns a
     *   result array (e.g. ['artifacts' => [...], 'manifest' => [...]]).
     */
    public function __construct(private readonly array $drivers) {}

    /**
     * Dispatch the backup to the correct driver and return its result.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when no driver is registered for the context's driver name.
     */
    public function run(BackupContext $context): array
    {
        if (!array_key_exists($context->driver, $this->drivers)) {
            throw new \InvalidArgumentException(
                "Unsupported driver: {$context->driver}. Supported: " . implode(', ', array_keys($this->drivers))
            );
        }

        return ($this->drivers[$context->driver])($context);
    }
}
