<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Console;

use Illuminate\Console\Command;
use Vorgio\Laravel\ConnectionDoctor;

/**
 * `php artisan vorgio:doctor` — prints a checklist confirming this app is
 * correctly connected to Vorgio (token, mode, abilities, webhook, mirror
 * tables). Pass `--test-webhook` to also send a signed test event to your
 * registered endpoint and confirm the secret + delivery path round-trips.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'vorgio:doctor {--test-webhook : Also send a live signed test event to your registered webhook endpoint}';

    protected $description = 'Check that this app is correctly connected to Vorgio.';

    public function handle(ConnectionDoctor $doctor): int
    {
        $report = $doctor->run((bool) $this->option('test-webhook'));

        foreach ($report['checks'] as $check) {
            $icon = match ($check['status']) {
                ConnectionDoctor::OK => '<info>✓</info>',
                ConnectionDoctor::WARN => '<comment>!</comment>',
                default => '<error>✗</error>',
            };

            $this->line($icon.' '.$check['label'].' — '.$check['detail']);
        }

        $connection = $report['connection'];
        if (is_array($connection)) {
            $this->newLine();
            $this->line('Team: <comment>'.($connection['team']['slug'] ?? '?').'</comment>  Mode: <comment>'.($connection['mode'] ?? '?').'</comment>');
        }

        $this->newLine();

        if ($report['ok']) {
            $this->info('Vorgio connection looks good.');

            return self::SUCCESS;
        }

        $this->error('Vorgio connection has problems — see the failing checks above.');

        return self::FAILURE;
    }
}
