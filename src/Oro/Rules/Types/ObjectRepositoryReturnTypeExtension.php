<?php declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class ObjectRepositoryReturnTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
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
        return \in_array(
            strtolower($methodReflection->getName()),
            ['createquerybuilder', 'getentitymanager'],
            true
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        switch (strtolower($methodReflection->getName())) {
            case 'createquerybuilder':
                return new ObjectType('Doctrine\ORM\QueryBuilder');
            case 'getentitymanager':
                return new ObjectType('Doctrine\ORM\EntityManager');
            default:
                throw new \InvalidArgumentException('Unsupported method call');
        }
    }
}
