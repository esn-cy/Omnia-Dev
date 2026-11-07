<?php

namespace Drupal\omnia_dev\Service;

use Symfony\Component\Yaml\Yaml;

class ModuleDetector
{
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
    }

    /**
     * Finds the primary Drupal module info file.
     */
    public function findInfoFile(): ?string
    {
        $files = glob($this->rootPath . '/*.info.yml');
        if (!empty($files)) {
            return $files[0];
        }
        return null;
    }

    /**
     * Returns the module machine name.
     */
    public function getMachineName(): ?string
    {
        $infoFile = $this->findInfoFile();
        if ($infoFile) {
            return basename($infoFile, '.info.yml');
        }
        return null;
    }

    /**
     * Parses the .info.yml file metadata.
     */
    public function getInfoData(): array
    {
        $infoFile = $this->findInfoFile();
        if ($infoFile && file_exists($infoFile)) {
            return Yaml::parseFile($infoFile) ?? [];
        }
        return [];
    }

    /**
     * Returns the human-readable module name.
     */
    public function getModuleName(): string
    {
        $info = $this->getInfoData();
        return $info['name'] ?? $this->getMachineName() ?? 'Unknown Module';
    }

    /**
     * Returns all Drupal dependencies declared in .info.yml (core and contrib).
     */
    public function getAllDrupalDependencies(): array
    {
        $info = $this->getInfoData();
        $deps = $info['dependencies'] ?? [];
        $result = [];

        foreach ($deps as $dep) {
            $cleanDep = preg_replace('/\s*\(.*?\)/', '', (string)$dep);
            $parts = explode(':', $cleanDep);
            $moduleName = trim(end($parts));

            if ($moduleName === '' || $moduleName === $this->getMachineName()) {
                continue;
            }

            $result[] = $moduleName;
        }

        return array_values(array_unique($result));
    }

    /**
     * Detects contrib Drupal module dependencies from .info.yml (excluding core).
     */
    public function getDrupalDependencies(): array
    {
        $all = $this->getAllDrupalDependencies();

        // Core Drupal 10/11 modules to exclude from Composer requirements
        $coreModules = [
            'action', 'announcements_feed', 'automated_cron', 'ban', 'basic_auth','big_pipe', 'block', 'block_content',
            'book', 'breakpoint', 'ckeditor5', 'claro', 'comment', 'config', 'contact', 'content_moderation',
            'content_translation', 'contextual', 'datetime', 'datetime_range', 'dblog', 'dynamic_page_cache', 'editor',
            'field', 'field_layout', 'field_ui', 'file', 'filter', 'forum', 'hal', 'help', 'history', 'image',
            'inline_form_errors', 'jsonapi', 'language', 'layout_builder', 'layout_discovery', 'link', 'locale',
            'media', 'media_library', 'menu_link_content', 'menu_ui', 'migrate', 'migrate_drupal', 'migrate_drupal_ui',
            'mysql', 'node', 'options', 'page_cache', 'path', 'path_alias', 'pgsql', 'quickedit', 'rdf',
            'responsive_image', 'rest', 'search', 'serialization', 'settings_tray', 'shortcut', 'sqlite', 'statistics',
            'syslog', 'system', 'taxonomy', 'telephone', 'text', 'toolbar', 'tour', 'tracker', 'update', 'user',
            'views', 'views_ui', 'workflows', 'workspaces',
        ];

        return array_values(array_filter($all, fn($mod) => !in_array($mod, $coreModules, true)));
    }

    /**
     * Parses the module root composer.json file.
     */
    public function getComposerData(): array
    {
        $composerJsonPath = $this->rootPath . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return [];
        }

        return json_decode(file_get_contents($composerJsonPath), true) ?? [];
    }

    /**
     * Returns the composer package name from composer.json.
     */
    public function getComposerName(): ?string
    {
        $data = $this->getComposerData();
        return $data['name'] ?? null;
    }

    /**
     * Detects Composer dependencies from module root composer.json.
     */
    public function getComposerDependencies(): array
    {
        $content = $this->getComposerData();
        $require = $content['require'] ?? [];
        $packages = [];

        foreach ($require as $pkg => $constraint) {
            if ($pkg === 'php' || str_starts_with($pkg, 'ext-') || $pkg === 'drupal/core') {
                continue;
            }
            $packages[$pkg] = $constraint;
        }

        return $packages;
    }

    /**
     * Detects Composer allow-plugins from module root composer.json.
     *
     * @return array<string, bool>
     */
    public function getAllowPlugins(): array
    {
        $content = $this->getComposerData();
        return $content['config']['allow-plugins'] ?? [];
    }

    /**
     * Returns all relative file and directory paths in the root that belong to this module.
     */
    public function getModuleFiles(): array
    {
        $items = [];

        // Common directories
        $dirs = ['src', 'assets', 'templates', 'config', 'css', 'js', 'images', 'html'];
        foreach ($dirs as $dir) {
            if (is_dir($this->rootPath . '/' . $dir)) {
                $items['dirs'][] = $dir;
            }
        }

        // Common files
        $patterns = [
            $this->rootPath . '/*.yml',
            $this->rootPath . '/*.module',
            $this->rootPath . '/*.install',
            $this->rootPath . '/*.theme',
            $this->rootPath . '/*.tokens.inc',
            $this->rootPath . '/*.post_update.php',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $file) {
                $items['files'][] = basename($file);
            }
        }

        return $items;
    }
}
