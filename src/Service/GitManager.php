<?php

namespace Drupal\omnia_dev\Service;

class GitManager
{
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
    }

    /**
     * Ensures the given directory is ignored in .gitignore.
     *
     * @param string $dirName
     * @return bool True if added, false if already present.
     */
    public function ensureIgnored(string $dirName): bool
    {
        $gitignoreFile = $this->rootPath . '/.gitignore';
        $normalizedDir = trim($dirName, '/');

        if (file_exists($gitignoreFile)) {
            $content = file_get_contents($gitignoreFile);
            $lines = preg_split('/\r\n|\r|\n/', $content);

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (trim($trimmed, '/') === $normalizedDir) {
                    return false;
                }
            }
        } else {
            $content = '';
        }

        $entry = sprintf("/%s/\n", $normalizedDir);
        $snippet = "\n# Omnia Dev test environment\n" . $entry;

        if ($content === '') {
            $newContent = ltrim($snippet, "\n");
        } else {
            $newContent = rtrim($content) . "\n" . $snippet;
        }

        file_put_contents($gitignoreFile, $newContent);
        return true;
    }
}
