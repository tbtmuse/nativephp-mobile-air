<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

class TailCommand extends Command
{
    protected $signature = 'native:tail
        {platform? : Platform to tail (android/a or ios/i)}
        {device? : Device ID (optional, auto-detects if omitted)}
        {--ios : Target iOS platform (shorthand for platform=ios)}
        {--android : Target Android platform (shorthand for platform=android)}
        {--lines=50 : Number of lines to show}
        {--no-follow : Disable follow mode (follow is on by default)}';

    protected $description = 'Tail Laravel logs from the mobile app';

    protected const ANDROID_LOG_PATH = 'app_storage/persisted_data/storage/logs/laravel.log';

    protected const IOS_LOG_PATH = 'Library/Application Support/storage/logs/laravel.log';

    public function handle(): int
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {

            error('NATIVEPHP_APP_ID is not set.');

            note('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');

            return self::FAILURE;
        }

        $platform = $this->resolvePlatform();

        if ($platform === null) {
            return self::FAILURE;
        }

        $deviceId = $this->argument('device');
        $lines = (int) $this->option('lines');
        $follow = ! $this->option('no-follow');

        return match ($platform) {
            'android' => $this->tailAndroid($appId, $lines, $follow, $deviceId),
            'ios' => $this->tailIos($appId, $lines, $follow, $deviceId),
        };
    }

    private function resolvePlatform(): ?string
    {
        if ($this->option('ios')) {
            return 'ios';
        }

        if ($this->option('android')) {
            return 'android';
        }

        $platform = $this->argument('platform');

        if (! $platform) {
            return select(
                label: 'Select platform to tail',
                options: [
                    'android' => 'Android',
                    'ios' => 'iOS',
                ]
            );
        }

        $resolved = match (strtolower($platform)) {
            'android', 'a' => 'android',
            'ios', 'i' => 'ios',
            default => null,
        };

        if ($resolved === null) {
            error("Invalid platform: {$platform}");
            note('Use: ios, android (or i, a as shortcuts).');

            return null;
        }

        return $resolved;
    }

    private function tailAndroid(string $appId, int $lines, bool $follow, ?string $deviceId): int
    {
        if (! $this->canRunCommand('adb version')) {
            error('ADB is not installed or not in your PATH.');

            return self::FAILURE;
        }

        $deviceId = $deviceId ?? $this->resolveAndroidDevice();

        if ($deviceId === null) {
            return self::FAILURE;
        }

        note("Tailing Android logs for {$appId} on {$deviceId}");

        if ($follow) {
            note('Press Ctrl+C to stop.');
        }

        $command = ['adb', '-s', $deviceId, 'shell', 'run-as', $appId, 'tail'];

        if ($follow) {
            $command[] = '-f';
        }

        $command[] = '-n';
        $command[] = (string) $lines;
        $command[] = static::ANDROID_LOG_PATH;

        return $this->runProcess($command, $follow);
    }

    private function resolveAndroidDevice(): ?string
    {
        $devices = $this->parseAdbDevices();

        if (empty($devices)) {
            error('No connected Android devices or emulators found.');
            note('Connect a device or start an emulator, then try again.');

            return null;
        }

        if (count($devices) === 1) {
            return array_key_first($devices);
        }

        return select(
            label: 'Select a device or emulator',
            options: $devices
        );
    }

    private function parseAdbDevices(): array
    {
        $output = shell_exec('adb devices') ?: '';

        return collect(explode("\n", $output))
            ->filter(fn (string $line) => Str::contains($line, "\tdevice"))
            ->mapWithKeys(fn (string $line) => [explode("\t", $line)[0] => explode("\t", $line)[0]])
            ->all();
    }

    private function tailIos(string $appId, int $lines, bool $follow, ?string $deviceId): int
    {
        $bootedSimulators = $this->getBootedSimulators();

        if ($deviceId !== null) {
            if (isset($bootedSimulators[$deviceId])) {
                return $this->tailIosSimulator($appId, $deviceId, $bootedSimulators[$deviceId], $lines, $follow);
            }

            return $this->tailIosRealDevice($appId, $deviceId, $lines, $follow);
        }

        if (empty($bootedSimulators)) {
            error('No booted iOS simulators found.');
            note('Start a simulator with: xcrun simctl boot <device>');
            note('For real devices, pass the device UDID: php artisan native:tail ios <device-udid>');

            return self::FAILURE;
        }

        if (count($bootedSimulators) === 1) {
            $deviceId = array_key_first($bootedSimulators);
        } else {
            $deviceId = select(
                label: 'Select a booted simulator',
                options: $bootedSimulators
            );
        }

        return $this->tailIosSimulator($appId, $deviceId, $bootedSimulators[$deviceId], $lines, $follow);
    }

    private function tailIosSimulator(string $appId, string $deviceId, string $deviceLabel, int $lines, bool $follow): int
    {
        $result = Process::run("xcrun simctl get_app_container {$deviceId} {$appId} data");

        if (! $result->successful()) {
            error('Failed to get app container. Is the app installed?');
            note('Run: php artisan native:run ios');

            return self::FAILURE;
        }

        $container = trim($result->output());

        if (empty($container)) {

            error('App container not found. Is the app installed?');

            return self::FAILURE;
        }

        $logPath = $container.'/'.static::IOS_LOG_PATH;

        if (! file_exists($logPath)) {
            error('Log file not found. Has the app been launched?');
            note("Expected: {$logPath}");

            return self::FAILURE;
        }

        note("Tailing iOS logs for {$appId} on {$deviceLabel}");

        if ($follow) {
            note('Press Ctrl+C to stop.');
        }

        $command = ['tail'];

        if ($follow) {
            $command[] = '-f';
        }

        $command[] = '-n';
        $command[] = (string) $lines;
        $command[] = $logPath;

        return $this->runProcess($command, $follow);
    }

    private function tailIosRealDevice(string $appId, string $deviceId, int $lines, bool $follow): int
    {
        if ($follow) {
            error('Follow mode is not supported for iOS real devices.');
            note('iOS real devices do not provide remote filesystem streaming.');
            note('Use --no-follow to fetch the current log contents:');
            note("  php artisan native:tail ios {$deviceId} --no-follow");

            return self::FAILURE;
        }

        $devicectlCheck = Process::run(['xcrun', 'devicectl', '--help']);

        if (! $devicectlCheck->successful()) {
            error('xcrun devicectl not found.');
            note('Real device log access requires Xcode 15 or later.');

            return self::FAILURE;
        }

        note("Fetching iOS logs for {$appId} on {$deviceId}");

        $tempFile = sys_get_temp_dir().'/nativephp-tail-'.time().'.log';

        $copyResult = Process::timeout(30)->run([
            'xcrun',
            'devicectl',
            'device',
            'copy',
            'from',
            '--domain-type',
            'appDataContainer',
            '--domain-identifier',
            $appId,
            '--source',
            static::IOS_LOG_PATH,
            '--device',
            $deviceId,
            $tempFile,
        ]);

        if (! $copyResult->successful()) {
            error('Failed to copy log file from device.');
            note($copyResult->errorOutput() ?: 'Is the app installed and has it been launched?');

            @unlink($tempFile);

            return self::FAILURE;
        }

        if (! file_exists($tempFile)) {
            error('Log file was not copied. Has the app been launched?');

            return self::FAILURE;
        }

        try {
            $process = new SymfonyProcess(['tail', '-n', (string) $lines, $tempFile]);
            $process->run();
            $this->output->write($process->getOutput());
        } finally {
            @unlink($tempFile);
        }

        return self::SUCCESS;
    }

    private function getBootedSimulators(): array
    {
        $result = Process::run('xcrun simctl list devices booted -j');

        if (! $result->successful()) {
            return [];
        }

        $json = json_decode($result->output(), true);

        if (! is_array($json) || ! isset($json['devices'])) {
            return [];
        }

        $simulators = [];

        foreach ($json['devices'] as $runtime => $devices) {

            $version = $this->parseRuntimeVersion($runtime);

            foreach ($devices as $device) {
                if (($device['state'] ?? '') !== 'Booted') {
                    continue;
                }

                $udid = $device['udid'] ?? '';
                $name = $device['name'] ?? 'Unknown';

                $label = $version
                    ? "{$name} (iOS {$version})"
                    : $name;

                $simulators[$udid] = $label;
            }
        }

        return $simulators;
    }

    private function parseRuntimeVersion(string $runtime): ?string
    {
        if (preg_match('/iOS[- ](\d+)[- ](\d+)/', $runtime, $matches)) {
            return "{$matches[1]}.{$matches[2]}";
        }

        return null;
    }

    private function canRunCommand(string $command): bool
    {
        try {

            $process = SymfonyProcess::fromShellCommandline($command);

            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function runProcess(array $command, bool $follow): int
    {
        $process = new SymfonyProcess($command);

        if ($follow) {
            $process->setTimeout(null);
        }

        try {
            $process->start();

            foreach ($process as $type => $data) {
                $this->output->write($data);
            }

            return $process->getExitCode() ?? self::SUCCESS;

        } catch (Throwable $e) {

            error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
