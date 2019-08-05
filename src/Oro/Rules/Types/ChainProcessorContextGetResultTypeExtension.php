<?php declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class ChainProcessorContextGetResultTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * {@inheritDoc}
     */
    public function getClass(): string
    {
        return 'Oro\Component\ChainProcessor\ContextInterface';
    }

    /**
     * {@inheritDoc}
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'getResult';
    }

    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        return new ObjectType('Oro\Bundle\ApiBundle\Request\ApiResourceSubresourcesCollection');
    }
}
