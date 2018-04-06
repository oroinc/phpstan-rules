<?php declare(strict_types=1);

namespace Oro\DI;

use Nette\DI\CompilerExtension;
use Nette\DI\Config\Helpers;
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
