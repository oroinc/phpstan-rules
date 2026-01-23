<?php

declare(strict_types=1);

namespace Oro\Extension;

use _PHPStan_b22655c3f\Nette\DI\CompilerExtension;
use PHPStan\Rules\LazyRegistry;

/**
 * Filters PHPStan rules, keeping only those in allowed namespaces
 */
class RemoveDefaultRulesExtension extends CompilerExtension
{
    public const ALLOWED_NAMESPACES = [
        'Oro\\Rules\\Math\\',
    ];

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($builder->findByTag(LazyRegistry::RULE_TAG) as $serviceName => $_) {
            $definition = $builder->getDefinition($serviceName);

            if (!$this->isOroRule($definition->getType())) {
                $builder->removeDefinition($serviceName);
            }
        }
    }

    private function isOroRule(?string $className): bool
    {
        if (null === $className) {
            return false;
        }
        foreach (self::ALLOWED_NAMESPACES as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
