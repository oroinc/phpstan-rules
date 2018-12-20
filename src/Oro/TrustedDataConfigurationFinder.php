<?php declare(strict_types=1);

namespace Oro;

use Composer\Autoload\ClassLoader;
use Nette\Utils\Finder;

/**
 * Return path to trusted_data configuration files stored in all paths registered in composer autoloaders.
 */
class TrustedDataConfigurationFinder
{
    const COMPOSER_AUTOLOADER_INIT = 'ComposerAutoloaderInit';
    const TRUSTED_DATA_SEARCH_PATTERN = 'Tests/trusted_data.neon';

    /**
     * @return array
     */
    public static function findFiles()
    {
        $directories = self::getAutoloadDirectories();

        $files = [];
        /** @var \SplFileInfo $trustedDataConfigFile */
        foreach (Finder::findFiles(self::TRUSTED_DATA_SEARCH_PATTERN)->from($directories) as $trustedDataConfigFile) {
            $files[] = $trustedDataConfigFile->getRealPath();
        }

        return $files;
    }

    /**
     * @return array
     */
    private static function getAutoloadDirectories(): array
    {
        // Collect autoloader registered directories
        $directories = [];
        foreach (self::getRegisteredComposerAutoloaders() as $loader) {
            $prefixesPsr4 = $loader->getPrefixesPsr4();
            array_walk(
                $prefixesPsr4,
                function ($dirs) use (&$directories) {
                    $directories[] = array_values($dirs);
                }
            );
            $prefixesPsr0 = $loader->getPrefixes();
            array_walk(
                $prefixesPsr0,
                function ($dirs) use (&$directories) {
                    $directories[] = array_values($dirs);
                }
            );
            $directories[] = $loader->getFallbackDirsPsr4();
            $directories[] = $loader->getFallbackDirs();
        }

        if ($directories) {
            $directories = array_merge(...$directories);
        }

        // Resolve directories real paths
        $directories = array_map('realpath', $directories);
        // Leave only unique records
        $directories = array_unique($directories);
        // Remove empty records
        $directories = array_filter($directories);

        return $directories;
    }

    /**
     * @return ClassLoader[]
     */
    private static function getRegisteredComposerAutoloaders()
    {
        $composerLoaderClasses = array_filter(get_declared_classes(), function ($className) {
            return strpos($className, self::COMPOSER_AUTOLOADER_INIT) === 0;
        });

        return array_map(function ($className) {
            return \call_user_func([$className, 'getLoader']);
        }, $composerLoaderClasses);
    }
}
