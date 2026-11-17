<?php

namespace Drupal\omnia_dev\Service;

use DOMDocument;
use DOMElement;
use DOMException;

class JetBrainsManager
{
    private string $projectRoot;

    public function __construct(string $projectRoot, private readonly DdevManager $ddev)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    /**
     * Configures or updates JetBrains database data sources for the DDEV environment.
     * @throws DOMException
     */
    public function configureDatabase(): ?array
    {
        $dbInfo = $this->ddev->getDbInfo();
        if ($dbInfo === null) {
            return null;
        }

        $hash = md5('omnia-dev-ddev-db:' . $dbInfo['project_name']);
        $uuid = sprintf(
            '%s-%s-4%s-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            0x8000 | (hexdec(substr($hash, 16, 4)) & 0x3fff),
            substr($hash, 20, 12)
        );

        $name = sprintf('DDEV (%s)', $dbInfo['project_name']);
        $ideaDir = $this->projectRoot . '/.idea';
        if (!is_dir($ideaDir)) {
            mkdir($ideaDir, 0755, true);
        }

        $this->updateDataSourcesXml($ideaDir . '/dataSources.xml', $uuid, $name, $dbInfo);
        $this->updateDataSourcesLocalXml($ideaDir . '/dataSources.local.xml', $uuid, $name, $dbInfo);

        return array_merge($dbInfo, [
            'uuid' => $uuid,
            'data_source_name' => $name,
        ]);
    }

    /**
     * @throws DOMException
     */
    private function updateDataSourcesXml(string $filePath, string $uuid, string $name, array $dbInfo): void
    {
        [$dom, $project] = $this->loadOrCreateProjectDom($filePath);

        $component = $this->findOrCreateComponent($dom, $project, 'DataSourceManagerImpl', [
            'format' => 'xml',
            'multifile-model' => 'true',
        ]);

        $dataSource = $this->findDataSource($component, $uuid, $name);
        if ($dataSource !== null) {
            while ($dataSource->firstChild !== null) {
                $dataSource->removeChild($dataSource->firstChild);
            }
        } else {
            $dataSource = $dom->createElement('data-source');
            $component->appendChild($dataSource);
        }

        $dataSource->setAttribute('source', 'LOCAL');
        $dataSource->setAttribute('name', $name);
        $dataSource->setAttribute('uuid', $uuid);

        [$driverRef, $jdbcDriver, $scheme] = $this->resolveDriverConfig($dbInfo['database_type']);

        $jdbcUrl = sprintf(
            'jdbc:%s://%s:%d/%s?user=%s&password=%s',
            $scheme,
            $dbInfo['host'],
            $dbInfo['port'],
            $dbInfo['dbname'],
            rawurlencode($dbInfo['username']),
            rawurlencode($dbInfo['password'])
        );

        $this->appendTextElement($dom, $dataSource, 'driver-ref', $driverRef);
        $this->appendTextElement($dom, $dataSource, 'synchronize', 'true');
        $this->appendTextElement($dom, $dataSource, 'jdbc-driver', $jdbcDriver);
        $this->appendTextElement($dom, $dataSource, 'jdbc-url', $jdbcUrl);

        $additionalProps = $dom->createElement('jdbc-additional-properties');
        $properties = [
            'database.introspection.mysql.dbe5060' => 'true',
            'user' => $dbInfo['username'],
            'password' => $dbInfo['password'],
        ];
        foreach ($properties as $propName => $propValue) {
            $prop = $dom->createElement('property');
            $prop->setAttribute('name', $propName);
            $prop->setAttribute('value', $propValue);
            $additionalProps->appendChild($prop);
        }
        $dataSource->appendChild($additionalProps);

        $this->appendTextElement($dom, $dataSource, 'working-dir', '$ProjectFileDir$');

        $dom->save($filePath);
    }

    /**
     * @throws DOMException
     */
    private function updateDataSourcesLocalXml(string $filePath, string $uuid, string $name, array $dbInfo): void
    {
        [$dom, $project] = $this->loadOrCreateProjectDom($filePath);

        $component = $this->findOrCreateComponent($dom, $project, 'dataSourceStorageLocal');

        $dataSource = $this->findDataSource($component, $uuid, $name);
        if ($dataSource === null) {
            $dataSource = $dom->createElement('data-source');
            $component->appendChild($dataSource);
        }

        $dataSource->setAttribute('name', $name);
        $dataSource->setAttribute('uuid', $uuid);

        $managedTags = ['case-sensitivity', 'auth-provider', 'user-name', 'schema-mapping'];
        $toRemove = [];
        foreach ($dataSource->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->nodeName, $managedTags, true)) {
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $node) {
            $dataSource->removeChild($node);
        }

        $caseSensitivity = $dom->createElement('case-sensitivity');
        $caseSensitivity->setAttribute('plain-identifiers', 'exact');
        $caseSensitivity->setAttribute('quoted-identifiers', 'exact');
        $dataSource->appendChild($caseSensitivity);

        $this->appendTextElement($dom, $dataSource, 'auth-provider', 'no-auth');
        $this->appendTextElement($dom, $dataSource, 'user-name', $dbInfo['username']);

        $schemaMapping = $dom->createElement('schema-mapping');
        $introspectionScope = $dom->createElement('introspection-scope');
        $schemaNode = $dom->createElement('node');
        $schemaNode->setAttribute('kind', 'schema');
        $schemaNode->setAttribute('qname', $dbInfo['dbname']);
        $introspectionScope->appendChild($schemaNode);
        $schemaMapping->appendChild($introspectionScope);
        $dataSource->appendChild($schemaMapping);

        $dom->save($filePath);
    }

    /**
     * Loads an existing JetBrains project XML file or initialises a new DOMDocument.
     *
     * @return array{0: DOMDocument, 1: DOMElement}
     * @throws DOMException
     */
    private function loadOrCreateProjectDom(string $filePath): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (file_exists($filePath) && @$dom->load($filePath)) {
            $root = $dom->documentElement;
            if ($root instanceof DOMElement && $root->nodeName === 'project') {
                return [$dom, $root];
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $project = $dom->createElement('project');
        $project->setAttribute('version', '4');
        $dom->appendChild($project);

        return [$dom, $project];
    }

    /**
     * Finds or creates a named <component> element under <project>.
     * @throws DOMException
     */
    private function findOrCreateComponent(DOMDocument $dom, DOMElement $project, string $componentName, array $defaultAttributes = []): DOMElement
    {
        foreach ($project->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'component' && $child->getAttribute('name') === $componentName) {
                foreach ($defaultAttributes as $attr => $value) {
                    if (!$child->hasAttribute($attr)) {
                        $child->setAttribute($attr, $value);
                    }
                }
                return $child;
            }
        }

        $component = $dom->createElement('component');
        $component->setAttribute('name', $componentName);
        foreach ($defaultAttributes as $attr => $value) {
            $component->setAttribute($attr, $value);
        }
        $project->appendChild($component);

        return $component;
    }

    /**
     * Finds an existing <data-source> element matching either UUID or name.
     */
    private function findDataSource(DOMElement $component, string $uuid, string $name): ?DOMElement
    {
        foreach ($component->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->nodeName !== 'data-source') {
                continue;
            }
            if ($child->getAttribute('uuid') === $uuid || $child->getAttribute('name') === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Resolves JetBrains driver identifiers and JDBC URL scheme from DDEV database type.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveDriverConfig(string $databaseType): array
    {
        $type = strtolower($databaseType);

        if ($type === 'mysql') {
            return ['mysql.8', 'com.mysql.cj.jdbc.Driver', 'mysql'];
        }

        if ($type === 'postgres' || $type === 'postgresql') {
            return ['postgresql', 'org.postgresql.Driver', 'postgresql'];
        }

        return ['mariadb', 'org.mariadb.jdbc.Driver', 'mariadb'];
    }

    /**
     * @throws DOMException
     */
    private function appendTextElement(DOMDocument $dom, DOMElement $parent, string $tagName, string $text): void
    {
        $element = $dom->createElement($tagName);
        $element->appendChild($dom->createTextNode($text));
        $parent->appendChild($element);
    }
}
