<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\select;

class TailCommand extends Command
{
    protected $signature = 'native:tail
        {platform? : Platform to tail (android/a or ios/i)}
        {device? : Device UDID (optional, auto-detects if omitted)}
        {--ios : Target iOS platform (shorthand for platform=ios)}
        {--android : Target Android platform (shorthand for platform=android)}
        {--lines=50 : Number of lines to show}
        {--no-follow : Disable follow mode (follow is on by default)}';

    protected $description = 'Tail Laravel logs from the mobile app';

    public function handle(): void
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {
            $this->error('NATIVEPHP_APP_ID is not set');
            $this->line('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');

            return;
        }

        if ($this->option('ios')) {
            $platform = 'ios';
        } elseif ($this->option('android')) {
            $platform = 'android';
        } else {
            $platform = $this->argument('platform');

            if (! $platform) {
                $platform = select(
                    label: 'Select platform to tail',
                    options: [
                        'android' => 'Android',
                        'ios' => 'iOS',
                    ]
                );
            } else {
                $platform = match (strtolower($platform)) {
                    'android', 'a' => 'android',
                    'ios', 'i' => 'ios',
                    default => $platform,
                };
            }
        }

        $deviceId = $this->argument('device');

        $lines = (int) $this->option('lines');
        $follow = ! $this->option('no-follow');

        if ($platform === 'ios') {
            $this->tailIos($appId, $lines, $follow, $deviceId);
        } else {
            $this->tailAndroid($appId, $lines, $follow);
        }
    }

    private function tailAndroid(string $appId, int $lines, bool $follow): void
    {
        $this->info("Tailing Android logs for app: $appId");

        $command = ['adb', 'shell', 'run-as', $appId, 'tail'];

        if ($follow) {
            $command[] = '-f';
        }

        $command[] = '-n';
        $command[] = (string) $lines;
        $command[] = 'app_storage/persisted_data/storage/logs/laravel.log';

        $this->runProcess($command);
    }

    private function tailIos(string $appId, int $lines, bool $follow, ?string $deviceId = null): void
    {
        $this->info('Tailing iOS logs for app: ' . $appId);

        $deviceId = $deviceId ?? $this->findBootedSimulator();

        if (! $deviceId) {
            $this->error('No iOS simulator is running.');
            $this->line('Start a simulator with: xcrun simctl boot <device>');

            return;
        }

        $result = Process::run("xcrun simctl get_app_container {$deviceId} {$appId} data");

        if (! $result->successful()) {
            $this->error('Failed to get app container. Is the app installed?');
            $this->line("Run: php artisan native:run ios");

            return;
        }

        $container = $result->output();

        if (empty($container)) {
            $this->error("App container not found. Is the app installed?");
            return;
        }

        $logPath = trim($container) . '/Library/Application Support/storage/logs/laravel.log';

        $command = ['tail'];

        if ($follow) {
            $command[] = '-f';
        }

        $command[] = '-n';
        $command[] = (string) $lines;
        $command[] = $logPath;

        $this->runProcess($command);
    }

    private function findBootedSimulator(): ?string
    {
        $result = Process::run('xcrun simctl list devices booted');

        if (! $result->successful()) {
            $this->error('Failed to list iOS simulators. Is Xcode installed?');
            $this->line('Run: xcrun simctl list devices to verify Xcode is working.');

            return null;
        }

        $output = $result->output();

        preg_match_all('/([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})/', $output, $matches);

        $bootedDevices = $matches[1] ?? [];

        if (count($bootedDevices) === 0) {
            $this->error('No iOS simulators are running.');
            $this->line('To boot a simulator: xcrun simctl boot <device-udid>');
            $this->line('To list available devices: xcrun simctl list devices');

            return null;
        }

        if (count($bootedDevices) === 1) {
            return $bootedDevices[0];
        }

        return select(
            label: 'Select booted simulator',
            options: array_combine($bootedDevices, $bootedDevices)
        );
    }

    private function runProcess(array $command): void
    {
        $follow = ! $this->option('no-follow');

        if (! $follow) {
            $this->line("Command: " . implode(' ', $command) . "\n");
        }

        $process = new SymfonyProcess($command);

        if ($follow) {
            $process->setTimeout(null);
        }

        try {
            $process->start();

            foreach ($process as $type => $data) {
                if ($process::OUT === $type) {
                    $this->line($data, null, null, false);
                } else {
                    $this->error($data, null, null, false);
                }
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }
}
