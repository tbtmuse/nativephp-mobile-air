<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait PlatformFileOperations
{
    /**
     * Platform-optimized file copy operation
     *
     * Normalizes exclusions to use trailing slashes for rsync to prevent matching subdirectories.
     * This is idempotent - calling on already-normalized values gives the same result.
     */
    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs = []): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Use robocopy on Windows (uses literal path matching, not glob)
            if (! empty($excludedDirs)) {
                $excludeArgs = '';
                foreach ($excludedDirs as $dir) {
                    $excludeArgs .= " /XD \"{$source}\\{$dir}\"";
                }
                $cmd = "robocopy \"{$source}\" \"{$destination}\" /MIR /NFL /NDL /NJH /NJS /NP /R:0 /W:0{$excludeArgs}";
            } else {
                $cmd = "xcopy \"{$source}\\*\" \"{$destination}\\\" /E /I /Y /Q";
            }

            exec($cmd, $output, $result);

            // Robocopy returns 0-7 as success codes
            if ($result >= 8 && strpos($cmd, 'robocopy') !== false) {
                $this->components->warn("robocopy failed with exit code $result");
            }
        } else {
            // Use rsync on Unix-like systems
            // IMPORTANT: Use LEADING slash to exclude only root directory, not subdirectories
            // '/vendor/' excludes root vendor/ but NOT public/vendor/
            // Without leading slash, 'vendor/' matches vendor at ANY level
            $excludedDirs = array_map(function($dir) {
                // Add leading slash if not present, ensure trailing slash
                $dir = ltrim($dir, '/');
                return '/' . rtrim($dir, '/') . '/';
            }, $excludedDirs);

            // Add specific exclusions for nested vendor directories that cause rsync cycles
            $excludedDirs[] = '/vendor/*/vendor/';
            $excludedDirs[] = '/vendor/nativephp/mobile/vendor/';

            $excludeFlags = implode(' ', array_map(fn ($d) => "--exclude='{$d}'", $excludedDirs));
            $cmd = "rsync -aL {$excludeFlags} \"{$source}/\" \"{$destination}/\"";

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception("rsync failed with exit code {$exitCode}");
            }
        }
    }

    /**
     * Platform-optimized directory removal
     */
    protected function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Try rmdir first, fallback to File::deleteDirectory
            $result = @exec("rmdir /s /q \"{$directory}\" 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                File::deleteDirectory($directory);
            }
            // Windows sometimes needs a moment
            usleep(100000); // 100ms
        } else {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Normalize line endings to LF
     */
    protected function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Replace file contents with proper line ending handling
     */
    protected function replaceFileContents(string $path, string $search, string $replace): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $newContents = str_replace($search, $replace, $contents);

        if ($contents !== $newContents) {
            File::put($path, $this->normalizeLineEndings($newContents));

            return true;
        }

        return false;
    }

    /**
     * Replace file contents using regex with proper line ending handling
     */
    protected function replaceFileContentsRegex(string $path, string $pattern, string $replacement): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $newContents = preg_replace($pattern, $replacement, $contents);

        if ($contents !== $newContents) {
            File::put($path, $this->normalizeLineEndings($newContents));

            return true;
        }

        return false;
    }

    /**
     * Check if running inside Windows Subsystem for Linux (WSL)
     */
    protected function isRunningInWSL(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $version = @file_get_contents('/proc/version') ?: '';

        return str_contains($version, 'Microsoft') || str_contains($version, 'WSL');
    }
}
