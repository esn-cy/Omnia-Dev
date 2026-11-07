<?php

namespace Drupal\omnia_dev\Service;

use Symfony\Component\Process\Process;

class SatelliteInstaller
{
    private string $envPath;
    private string $envDirName;

    public function __construct(string $envPath)
    {
        $this->envPath = rtrim($envPath, '/');
        $this->envDirName = basename($this->envPath);
    }

    /**
     * Creates the satellite project in the specified directory.
     *
     * @param callable|null $outputCallback
     * @param array<string, bool> $extraAllowPlugins
     * @return bool
     */
    public function createProject(?callable $outputCallback = null, array $extraAllowPlugins = []): bool
    {
        $parentDir = dirname($this->envPath);
        $command = [
            'composer',
            'create-project',
            'esn/satellite-project:^5.0',
            $this->envDirName,
            '--repository-url=https://packages.esn.org',
            '--no-install',
            '--no-interaction',
        ];

        $process = new Process($command, $parentDir);
        $process->setTimeout(600);
        $process->run($outputCallback);

        if (!$process->isSuccessful()) {
            return false;
        }

        if ($outputCallback) {
            $outputCallback(Process::OUT, "Configuring Composer allow-plugins...\n");
        }

        // Satellite 5 and Drupal require these core plugins:
        $essentialPlugins = [
            'composer/installers' => true,
            'cweagans/composer-patches' => true,
            'drupal/core-composer-scaffold' => true,
            'mglaman/composer-drupal-lenient' => true,
        ];

        $filteredExtra = array_filter($extraAllowPlugins, fn($allowed) => (bool)$allowed);
        $pluginsToAllow = array_merge($filteredExtra, $essentialPlugins);

        $this->allowPlugins($pluginsToAllow);

        if ($outputCallback) {
            $outputCallback(Process::OUT, "Installing Satellite dependencies via Composer...\n");
        }

        // Complete project installation with allowed plugins.
        $installCommand = [
            'composer',
            'install',
            '--no-interaction',
        ];

        $installProcess = new Process($installCommand, $this->envPath);
        $installProcess->setTimeout(600);
        $installProcess->run($outputCallback);

        return $installProcess->isSuccessful();
    }

    /**
     * Configures allow-plugins in the environment's composer.json.
     *
     * @param array<string, bool> $plugins
     */
    public function allowPlugins(array $plugins): void
    {
        $composerJsonPath = $this->envPath . '/composer.json';
        if (file_exists($composerJsonPath)) {
            $data = json_decode(file_get_contents($composerJsonPath), true);
            if (is_array($data)) {
                $data['config'] ??= [];
                $data['config']['allow-plugins'] ??= [];

                foreach ($plugins as $plugin => $allowed) {
                    $data['config']['allow-plugins'][$plugin] = $allowed;
                }

                file_put_contents(
                    $composerJsonPath,
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                );
            }
        }

        foreach ($plugins as $plugin => $allowed) {
            $command = [
                'composer',
                'config',
                '--no-plugins',
                'allow-plugins.' . $plugin,
                $allowed ? 'true' : 'false',
            ];
            $process = new Process($command, $this->envPath);
            $process->run();
        }
    }

    /**
     * Installs additional composer dependencies in the test environment.
     */
    public function requirePackages(array $packages, ?callable $outputCallback = null): bool
    {
        if (empty($packages)) {
            return true;
        }

        $command = array_merge(['composer', 'require', '--no-interaction'], $packages);
        $process = new Process($command, $this->envPath);
        $process->setTimeout(600);
        $process->run($outputCallback);

        return $process->isSuccessful();
    }

    /**
     * Prevents Drupal's extension scanner from recursing into the test environment.
     */
    public function configureScanIgnore(): void
    {
        $snippet = <<<PHP
        
        // Added by omnia-dev: Ignore the test environment directory during extension scans.
        \$settings['file_scan_ignore_directories'] = array_merge(
          \$settings['file_scan_ignore_directories'] ?? ['node_modules', 'bower_components'],
          ['$this->envDirName']
        );
        PHP;

        $settingsFiles = [
            $this->envPath . '/web/sites/default/settings.php',
            $this->envPath . '/web/sites/default/default.settings.php',
        ];

        foreach ($settingsFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (!str_contains($content, 'omnia-dev: Ignore the test environment')) {
                    file_put_contents($file, $content . "\n" . $snippet . "\n");
                }
            }
        }
    }
}
