<?php declare(strict_types=1);

namespace Oro\DI;

// We use phpstan/phpstan-shim as OroPlatform itself since 4.2 LTS has dependencies on nette packages:
// https://github.com/phpstan/phpstan-shim
// phpstan/phpstan-shim is shipped as a phar with all dependencies in randomly prefixed namespaces.
//
// This class (Oro\DI\SqlInjectionTestingConfigExtension) will most likely go away entirely
// after upgrading to PHPStan 0.12 which no longer allows compiler extensions anyway.
//
// Internal reference to track PHPStan upgrade to 0.12 - OIS-417

//use Nette\DI\CompilerExtension;
//use Nette\DI\Config\Helpers;

$compilerExtensionClass = null;
$configHelpersClass = null;

$classes = \get_declared_classes();
foreach ($classes as $fqcn) {
    if (null === $compilerExtensionClass && 'Nette\\DI\\CompilerExtension' === \substr($fqcn, -26)) {
        $compilerExtensionClass = $fqcn;
    }
    if (null === $configHelpersClass && 'Nette\\DI\\Config\\Helpers' === \substr($fqcn, -23)) {
        $configHelpersClass = $fqcn;
    }
    if (null !== $compilerExtensionClass && null !== $configHelpersClass) {
        break;
    }
}

\class_alias($compilerExtensionClass, 'Oro\DI\CompilerExtension');
\class_alias($configHelpersClass, 'Oro\DI\Helpers');

use Oro\TrustedDataConfigurationFinder;

/**
 * Load trusted_data configurations and merge them into trusted_data DI parameter
 */
class SqlInjectionTestingConfigExtension extends CompilerExtension
{
    /**
     * {@inheritdoc}
     */
    public function loadConfiguration()
    {
        foreach (TrustedDataConfigurationFinder::findFiles() as $file) {
            $this->validateConfig($this->loadFromFile($file));
        }

        $this->getContainerBuilder()->parameters = Helpers::merge(
            $this->getContainerBuilder()->parameters,
            $this->config
        );
    }
}
