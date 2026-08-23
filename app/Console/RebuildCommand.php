<?php

declare(strict_types=1);

namespace App\Console;

use App\Config\StashdConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Console\Middleware\CautionMiddleware;
use Tempest\Console\Middleware\ForceMiddleware;
use Tempest\Core\Environment;
use Tempest\Framework\Commands\MigrateFreshCommand;

final readonly class RebuildCommand
{
    use HasConsole;

    // Must match docker/supervisord.conf.template's [program:...] names exactly.
    // A stale/mismatched name here makes `supervisorctl stop ...` exit non-zero
    // (the whole invocation fails if any one name doesn't resolve), which reads
    // as "supervisorctl unreachable" and skips the restart below -- even though
    // the names that DID match were, as a side effect of that same stop call,
    // genuinely stopped and then never restarted.
    private const array SUPERVISED_ROLES = ['worker-interactive', 'worker-discovery', 'worker-bulk', 'scheduler', 'frankenphp'];

    public function __construct(
        private StashdConfig $config,
        private Environment $environment,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:rebuild',
        description: 'Dev convenience: wipe the database and on-disk vault/broadcast files, re-run stashd:boot, and restart the supervised roles for a clean, repeatable reset. Destructive.',
        middleware: [ForceMiddleware::class, CautionMiddleware::class],
    )]
    public function __invoke(): ExitCode
    {
        // CautionMiddleware already gates the whole command behind a generic
        // "might be destructive" confirm in staging/production. File deletion
        // is a step up from that (a DB wipe is at least re-downloadable; real
        // media on disk is not), so it gets its own explicit, specific
        // confirmation on top -- same requiresCaution()/isForced policy as
        // the middleware, just louder about what's actually being destroyed.
        if ($this->environment->requiresCaution() && ! $this->console->isForced) {
            $confirmed = $this->console->confirm(
                "This will PERMANENTLY DELETE all vault and broadcast files on disk in the '{$this->environment->value}' environment, in addition to wiping the database. This cannot be undone. Continue?",
            );

            if (! $confirmed) {
                return ExitCode::CANCELLED;
            }
        }

        // Stop the roles first so rebuild starts from a quiescent application.
        // across the schema drop below. Gracefully skipped (and the restart
        // below skipped too) when supervisorctl isn't reachable -- plain
        // `php tempest serve` dev setups without supervisord are unaffected.
        $supervised = $this->console->task('Stop worker/scheduler/frankenphp', $this->supervisorctl('stop'));

        // The DB wipe below deletes every asset/broadcast row, but never touches
        // disk -- without this, downloaded media and generated broadcast files
        // from the previous run are orphaned, invisible to the fresh DB. This is
        // a dev-only full reset (already destructive by design), not the runtime
        // broadcast-prune path, so wiping vault files here is intentional.
        $this->console->task('Wipe vault and broadcast files', function (): void {
            foreach ([$this->config->vaultPath(), $this->config->broadcastsPath(), $this->config->tempPath(), $this->config->cachePath()] as $root) {
                $this->clearDirectory($root);
            }
        });

        $this->console->call(MigrateFreshCommand::class);
        $this->console->call(BootCommand::class);

        if ($supervised) {
            $this->console->task('Restart worker/scheduler/frankenphp', $this->supervisorctl('start'));
        } else {
            $this->console->warning('supervisorctl unreachable -- worker/scheduler/frankenphp were not restarted.');
        }

        return ExitCode::SUCCESS;
    }

    private function supervisorctl(string $action): Process
    {
        return new Process(['supervisorctl', $action, ...self::SUPERVISED_ROLES]);
    }

    private function clearDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() ? @rmdir($path) : @unlink($path);
        }
    }
}
