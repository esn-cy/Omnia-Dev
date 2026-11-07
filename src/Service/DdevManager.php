<?php

namespace Drupal\omnia_dev\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class DdevManager
{
    private string $envPath;
    private string $phpVersion;
    private ?string $projectName;

    public function __construct(string $envPath, string $phpVersion = '8.3', ?string $projectName = null)
    {
        $this->envPath = rtrim($envPath, '/');
        $this->phpVersion = $phpVersion;
        $this->projectName = $projectName;
    }

    /**
     * Gets the DDEV project name.
     */
    public function getProjectName(): string
    {
        if ($this->projectName !== null && $this->projectName !== '') {
            return $this->projectName;
        }

        $configFile = $this->envPath . '/.ddev/config.yaml';
        if (file_exists($configFile)) {
            $config = Yaml::parseFile($configFile);
            if (!empty($config['name'])) {
                $this->projectName = $config['name'];
                return $this->projectName;
            }
        }

        return basename($this->envPath);
    }

    /**
     * Runs ddev config for Drupal 10 with web docroot.
     */
    public function configure(?callable $outputCallback = null): bool
    {
        $projectName = $this->getProjectName();
        $command = [
            'ddev',
            'config',
            '--project-type=drupal10',
            '--docroot=web',
            '--php-version=' . $this->phpVersion,
            '--project-name=' . $projectName,
        ];

        $process = new Process($command, $this->envPath);
        $process->setTimeout(120);
        $process->run($outputCallback);

        if (!$process->isSuccessful()) {
            return false;
        }

        $this->writeDockerComposeOverride();
        $this->configureHooks();

        return true;
    }

    /**
     * Creates docker-compose.module.yaml to mount parent repository into container.
     */
    public function writeDockerComposeOverride(): void
    {
        $ddevDir = $this->envPath . '/.ddev';
        if (!is_dir($ddevDir)) {
            mkdir($ddevDir, 0755, true);
        }

        $overrideFile = $ddevDir . '/docker-compose.module.yaml';
        $content = <<<YAML
        services:
          web:
            volumes:
              - "../..:/var/dev_module"
        YAML;

        file_put_contents($overrideFile, $content);
    }

    /**
     * Configures post-start hook in .ddev/config.yaml.
     */
    public function configureHooks(): void
    {
        $configFile = $this->envPath . '/.ddev/config.yaml';
        if (!file_exists($configFile)) {
            return;
        }

        $hookCommand = 'bash -c "ln -sfn /var/dev_module/src /var/www/src 2>/dev/null || true; ln -sfn /var/dev_module/assets /var/www/assets 2>/dev/null || true; for f in /var/dev_module/*.yml; do [ -f \"\$f\" ] && ln -sfn \"\$f\" /var/www/\$(basename \"\$f\"); done; true"';

        $config = Yaml::parseFile($configFile) ?? [];
        $config['hooks']['post-start'] = [
            ['exec' => $hookCommand],
        ];

        file_put_contents($configFile, Yaml::dump($config, 4, 2));
    }

    /**
     * Starts DDEV.
     */
    public function start(?callable $outputCallback = null): bool
    {
        $process = new Process(['ddev', 'start'], $this->envPath);
        $process->setTimeout(300);
        $process->run($outputCallback);
        return $process->isSuccessful();
    }

    /**
     * Runs a drush command inside DDEV.
     */
    public function drush(array $args, ?callable $outputCallback = null): Process
    {
        $command = array_merge(['ddev', 'drush'], $args);
        $process = new Process($command, $this->envPath);
        $process->setTimeout(600);
        $process->run($outputCallback);
        return $process;
    }

    /**
     * Gets a one-time login link for user 1.
     */
    public function getUli(): ?string
    {
        $process = $this->drush(['uli']);
        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            // Extract URL from output
            if (preg_match('#https?://\S+#', $output, $matches)) {
                return $matches[0];
            }
            return $output;
        }
        return null;
    }

    /**
     * Gets project primary URL.
     */
    public function getPrimaryUrl(): string
    {
        return 'https://' . $this->getProjectName() . '.ddev.site';
    }
}
