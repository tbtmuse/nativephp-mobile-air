# Unified Build Process Architecture (v2)

## The Problem

The build pipeline does the same conceptual work twice, in two different styles:
- **Android**: Uses `PreparesBuild` trait → `PlatformFileOperations`
- **iOS**: Inline rsync code → bypasses shared infrastructure

This makes it easy for iOS to drift and accidentally bypass shared logic. The rsync bug (missing trailing slashes) is proof.

---

## The Solution: 3-Stage Pipeline

```
PackageCommand
    ├── getPlatformConfig()  ← ALL differences in ONE place (data only)
    ├── prepare()           ← 100% shared, config-driven
    ├── configure()         ← delegates: configureIosProject() / configureAndroidProject()
    └── build()             ← delegates: buildIos() / buildAndroid()
```

**Mental model (top to bottom):**
1. Shared work is shared → `prepare()`
2. Platform work is isolated → `configure()`, `build()`
3. Platform differences are data, not branching → `getPlatformConfig()`

---

## Implementation

### 1. One Method for Platform Data

```php
protected function getPlatformConfig(): array
{
    return match ($this->platform) {
        'ios' => [
            'bundle_destination' => base_path('nativephp/ios/NativePHP/app.zip'),
            'asset_url' => 'php://127.0.0.1/_assets',
            'bootstrap_files' => [],
            'excluded_dirs' => [
                'vendor/',                    // trailing slash = root only
                'node_modules/',
                'nativephp/',
                'output/',
                'build/',
                'dist/',
                'artifacts/',
                '.git/',
                'storage/logs/',
                'storage/framework/cache/',
            ],
            'cleanup_patterns' => [
                '.git', 'vendor/bin', 'tests', 'storage/logs',
                'database/database.sqlite', '*.js', '*.md',
            ],
        ],
        'android' => [
            'bundle_destination' => base_path('nativephp/android/app/src/main/assets/laravel_bundle.zip'),
            'asset_url' => 'http://127.0.0.1/_assets',
            'bootstrap_files' => [
                'source' => base_path('vendor/nativephp/mobile/bootstrap/android/artisan.php'),
                'dest' => 'artisan.php',
            ],
            'excluded_dirs' => [
                'vendor/',                    // trailing slash = root only
                'node_modules/',
                'nativephp/',
                'output/',
                'build/',
                'dist/',
                'artifacts/',
                '.git/',
                'storage/logs/',
                'storage/framework/cache/',
            ],
            'cleanup_patterns' => [...],
        ],
    };
}
```

### 2. Prepare() - 100% Shared, Config-Driven

```php
protected function prepare(): void
{
    $config = $this->getPlatformConfig();
    $tempDir = $this->getTempDir();
    
    // 1. Clear and copy (uses PlatformFileOperations - SAME for both)
    $this->platformOptimizedCopy(base_path(), $tempDir, $config['excluded_dirs']);
    
    // 2. Configure ASSET_URL
    $this->configureAssetUrl($config['asset_url']);
    
    // 3. Composer install - SAME for both
    $this->installComposer($tempDir);
    
    // 4. Copy bootstrap files (empty for iOS, artisan.php for Android)
    $this->copyBootstrapFiles($tempDir, $config['bootstrap_files']);
    
    // 5. Create bundle - SAME for both
    $this->createBundle($tempDir, $config['bundle_destination']);
    
    // 6. Cleanup - uses config
    $this->cleanup($config['cleanup_patterns']);
}
```

**Critical**: iOS MUST use `PlatformFileOperations::platformOptimizedCopy()` - no inline rsync.

### 3. Configure/Build - Delegation Only

```php
protected function configure(): void
{
    $this->{"configure{$this->platform}Project"}();
}

protected function build(): void
{
    $this->{"build{$this->platform}"}();
}

// Isolated platform-specific implementations
private function configureIosProject(): void { /* Xcode */ }
private function configureAndroidProject(): void { /* Gradle */ }
private function buildIos(): void { /* xcodebuild */ }
private function buildAndroid(): void { /* gradle */ }
```

---

## Shared Infrastructure

### PlatformFileOperations Trait

```php
trait PlatformFileOperations
{
    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs = []): void
    {
        // NORMALIZE: Always add trailing slashes to prevent rsync glob bugs
        // This is idempotent - calling on already-normalized values gives same result:
        //   'vendor'   → 'vendor/'
        //   'vendor/'  → 'vendor/'  (no change)
        //   'vendor//' → 'vendor/'  (multiple slashes reduced to one)
        // Only needed for rsync (Unix); robocopy (Windows) uses literal path matching
        if (PHP_OS_FAMILY !== 'Windows') {
            $excludedDirs = array_map(fn($dir) => rtrim($dir, '/') . '/', $excludedDirs);
        }
        
        // Add nested exclusions (ALSO needs trailing slashes!)
        $excludedDirs[] = 'vendor/*/vendor/';
        $excludedDirs[] = 'vendor/nativephp/mobile/vendor/';
        
        if (PHP_OS_FAMILY === 'Windows') {
            // robocopy with exclusions
        } else {
            // rsync with exclusions - SAME for both platforms
        }
    }
}
```

**Key**: The normalization happens ONCE in the shared trait. Both platforms benefit.

---

## Migration Steps

### Phase 1: Fix Critical Bug (Now)
```php
// In BuildIosAppCommand::copyLaravelAppIntoIosApp()
$excludedDirs = ['vendor/', 'node_modules/', ...];  // trailing slashes!
```

### Phase 2: Make iOS Use PlatformFileOperations
- Replace inline rsync with `$this->platformOptimizedCopy()`

### Phase 3: Extract getPlatformConfig()
- Move all platform-specific values to one method

### Phase 4: Rename to 3-Stage
- `bundleLaravelApp()` → `prepare()`
- Configure/build methods stay but called by delegation

---

## Why This Works

| Problem | Solution |
|---------|----------|
| iOS bypasses shared code | Must use `PlatformFileOperations` |
| Exclusions can diverge | Normalized in ONE place |
| Platform differences scattered | All in `getPlatformConfig()` |
| Mental model unclear | Read top-to-bottom: prepare → configure → build |

---

## Success Criteria

- [ ] iOS uses `PlatformFileOperations::platformOptimizedCopy()`
- [ ] Both platforms use `prepare()`, `configure()`, `build()`
- [ ] All platform differences in `getPlatformConfig()`
- [ ] No inline platform-specific code in shared methods
- [ ] Bug fixes (like trailing slashes) apply to both automatically
