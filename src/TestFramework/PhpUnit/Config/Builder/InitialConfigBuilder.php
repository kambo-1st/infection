<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework\PhpUnit\Config\Builder;

use Infection\TestFramework\Config\InitialConfigBuilder as ConfigBuilder;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Infection\TestFramework\PhpUnit\Config\XmlConfigurationHelper;

/**
 * @internal
 */
class InitialConfigBuilder implements ConfigBuilder
{
    /**
     * @var string
     */
    private $tmpDir;
    /**
     * @var string
     */
    private $originalXmlConfigContent;
    /**
     * @var XmlConfigurationHelper
     */
    private $xmlConfigurationHelper;

    /**
     * @var string
     */
    private $jUnitFilePath;

    /**
     * @var array
     */
    private $srcDirs = [];

    /**
     * @var bool
     */
    private $skipCoverage;

    public function __construct(
        string $tmpDir,
        string $originalXmlConfigContent,
        XmlConfigurationHelper $xmlConfigurationHelper,
        string $jUnitFilePath,
        array $srcDirs,
        bool $skipCoverage
    ) {
        $this->tmpDir = $tmpDir;
        $this->originalXmlConfigContent = $originalXmlConfigContent;
        $this->xmlConfigurationHelper = $xmlConfigurationHelper;
        $this->jUnitFilePath = $jUnitFilePath;
        $this->srcDirs = $srcDirs;
        $this->skipCoverage = $skipCoverage;
    }

    public function build(string $version): string
    {
        $path = $this->buildPath();

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($this->originalXmlConfigContent);

        $xPath = new \DOMXPath($dom);

        $this->xmlConfigurationHelper->validate($dom, $xPath);

        $this->addCoverageFilterWhitelistIfDoesNotExist($dom, $xPath);
        $this->addRandomTestsOrderAttributes($version, $xPath);
        $this->xmlConfigurationHelper->replaceWithAbsolutePaths($xPath);
        $this->xmlConfigurationHelper->setStopOnFailure($xPath);
        $this->xmlConfigurationHelper->deactivateColours($xPath);
        $this->xmlConfigurationHelper->removeExistingLoggers($dom, $xPath);
        $this->xmlConfigurationHelper->removeExistingPrinters($dom, $xPath);

        if (!$this->skipCoverage) {
            $this->addCodeCoverageLogger($dom, $xPath);
            $this->addJUnitLogger($dom, $xPath);
        }

        file_put_contents($path, $dom->saveXML());

        return $path;
    }

    private function buildPath(): string
    {
        return $this->tmpDir . '/phpunitConfiguration.initial.infection.xml';
    }

    private function addJUnitLogger(\DOMDocument $dom, \DOMXPath $xPath): void
    {
        $logging = $this->getOrCreateNode($dom, $xPath, 'logging');

        $junitLog = $dom->createElement('log');
        $junitLog->setAttribute('type', 'junit');
        $junitLog->setAttribute('target', $this->jUnitFilePath);

        $logging->appendChild($junitLog);
    }

    private function addCodeCoverageLogger(\DOMDocument $dom, \DOMXPath $xPath): void
    {
        $logging = $this->getOrCreateNode($dom, $xPath, 'logging');

        $coverageXmlLog = $dom->createElement('log');
        $coverageXmlLog->setAttribute('type', 'coverage-xml');
        $coverageXmlLog->setAttribute('target', $this->tmpDir . '/' . CodeCoverageData::PHP_UNIT_COVERAGE_DIR);

        $logging->appendChild($coverageXmlLog);
    }

    private function addCoverageFilterWhitelistIfDoesNotExist(\DOMDocument $dom, \DOMXPath $xPath): void
    {
        $filterNode = $this->getNode($xPath, 'filter');

        if (!$filterNode) {
            $filterNode = $this->createNode($dom, 'filter');

            $whiteListNode = $dom->createElement('whitelist');

            foreach ($this->srcDirs as $srcDir) {
                $directoryNode = $dom->createElement(
                    'directory',
                    $srcDir
                );

                $whiteListNode->appendChild($directoryNode);
            }

            $filterNode->appendChild($whiteListNode);
        }
    }

    private function getOrCreateNode(\DOMDocument $dom, \DOMXPath $xPath, string $nodeName): \DOMElement
    {
        $node = $this->getNode($xPath, $nodeName);

        if (!$node) {
            $node = $this->createNode($dom, $nodeName);
        }

        return $node;
    }

    private function getNode(\DOMXPath $xPath, string $nodeName)
    {
        $nodeList = $xPath->query(sprintf('/phpunit/%s', $nodeName));

        if ($nodeList->length) {
            return $nodeList->item(0);
        }

        return null;
    }

    private function createNode(\DOMDocument $dom, string $nodeName): \DOMElement
    {
        $node = $dom->createElement($nodeName);
        $dom->documentElement->appendChild($node);

        return $node;
    }

    private function addRandomTestsOrderAttributes(string $version, \DOMXPath $xPath): void
    {
        if (!version_compare($version, '7.2', '>=')) {
            return;
        }

        $this->updateOrAddAttribute('executionOrder', 'random', $xPath);
        $this->updateOrAddAttribute('resolveDependencies', 'true', $xPath);
    }

    private function updateOrAddAttribute(string $attribute, string $value, \DOMXPath $xPath): void
    {
        $nodeList = $xPath->query(sprintf('/phpunit/@%s', $attribute));

        if ($nodeList->length) {
            $nodeList[0]->nodeValue = $value;
        } else {
            $node = $xPath->query('/phpunit')[0];
            $node->setAttribute($attribute, $value);
        }
    }
}
