<?php

declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Provides EntityManagerInterface return type for the getmanagerforclass method
 */
class ManagerRegistryEMReturnTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * @var string
     */
    private $supportedClass;

    /**
     * @param string $supportedClass
     */
    public function __construct(string $supportedClass)
    {
        $this->supportedClass = $supportedClass;
    }

    /**
     * {@inheritDoc}
     */
    public function getClass(): string
    {
        return $this->supportedClass;
    }

    /**
     * {@inheritDoc}
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return strtolower($methodReflection->getName()) === 'getmanagerforclass';
    }

    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        return new ObjectType('Doctrine\ORM\EntityManagerInterface');
    }
}
