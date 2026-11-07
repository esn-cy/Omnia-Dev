<?php

namespace Drupal\omnia_dev\Service;

use Symfony\Component\Filesystem\Filesystem;

class SymlinkManager
{
    private ModuleDetector $detector;
    private string $envPath;
    private Filesystem $filesystem;

    public function __construct(ModuleDetector $detector, string $envPath)
    {
        $this->detector = $detector;
        $this->envPath = rtrim($envPath, '/');
        $this->filesystem = new Filesystem();
    }

    /**
     * Gets the target directory where the custom module will live in Drupal.
     */
    public function getModuleDestination(): string
    {
        $machineName = $this->detector->getMachineName();
        return $this->envPath . '/web/modules/custom/' . $machineName;
    }

    /**
     * Creates all necessary symlinks for the module into the test environment.
     *
     * @return array List of created links.
     */
    public function createSymlinks(): array
    {
        $dest = $this->getModuleDestination();
        $this->filesystem->mkdir($dest);

        $moduleItems = $this->detector->getModuleFiles();
        $created = [];

        // 5 levels up: custom -> modules -> web -> env -> module repo
        $prefix = '../../../../..';

        // Link directories
        foreach ($moduleItems['dirs'] ?? [] as $dir) {
            $linkTarget = $prefix . '/' . $dir;
            $linkPath = $dest . '/' . $dir;
            $this->createRelativeSymlink($linkTarget, $linkPath);
            $created[] = $dir . '/';
        }

        // Link files
        foreach ($moduleItems['files'] ?? [] as $file) {
            $linkTarget = $prefix . '/' . $file;
            $linkPath = $dest . '/' . $file;
            $this->createRelativeSymlink($linkTarget, $linkPath);
            $created[] = $file;
        }

        return $created;
    }

    /**
     * Creates a relative symlink atomically.
     */
    private function createRelativeSymlink(string $target, string $link): void
    {
        if (is_link($link) || file_exists($link)) {
            $this->filesystem->remove($link);
        }
        symlink($target, $link);
    }
}
