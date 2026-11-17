<?php

declare(strict_types=1);

namespace Oro\TrustedData;

use Nette\Neon\Neon;
use Oro\TrustedDataConfigurationFinder;

/**
 * Collects trusted data per file from the application packages and a PHPStan config with its includes,
 * except the configs of this package, and the bootstrap files of that config.
 */
class TrustedDataSources
{
    private const PACKAGE_DIR = __DIR__ . '/../../..';

    /** @var string[] */
    private array $configFiles = [];

    public function __construct(string $configFile)
    {
        $this->collectConfigFiles($configFile);
    }

    /**
     * @return string[]
     */
    public function getBootstrapFiles(): array
    {
        $bootstrapFiles = [];
        foreach ($this->configFiles as $configFile) {
            $config = $this->decode($configFile);
            foreach ($config['parameters']['bootstrapFiles'] ?? [] as $file) {
                $bootstrapFiles[] = $this->resolvePath($file, $configFile);
            }
        }

        return $bootstrapFiles;
    }

    /**
     * Returns trusted data of the application packages, call after the bootstrap files are loaded.
     *
     * @return array [file => trusted data, ...]
     */
    public function getSources(): array
    {
        $sources = [];
        foreach (TrustedDataConfigurationFinder::findFiles() as $file) {
            $sources[$file] = $this->decode($file)['trusted_data'] ?? [];
        }

        foreach ($this->configFiles as $file) {
            $sources[$file] = $this->decode($file)['parameters']['sql_injection_testing']['trusted_data'] ?? [];
        }

        return $sources;
    }

    private function collectConfigFiles(string $configFile): void
    {
        $configFile = realpath($configFile);
        if (!$configFile || in_array($configFile, $this->configFiles, true)) {
            return;
        }

        if (str_starts_with($configFile, realpath(self::PACKAGE_DIR) . '/')) {
            return;
        }

        $this->configFiles[] = $configFile;
        foreach ($this->decode($configFile)['includes'] ?? [] as $include) {
            $this->collectConfigFiles($this->resolvePath($include, $configFile));
        }
    }

    private function resolvePath(string $path, string $configFile): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return dirname($configFile) . '/' . $path;
    }

    private function decode(string $file): array
    {
        return Neon::decode(file_get_contents($file)) ?? [];
    }
}
