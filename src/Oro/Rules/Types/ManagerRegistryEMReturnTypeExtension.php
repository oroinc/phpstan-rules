<?php declare(strict_types=1);

namespace Oro\Rules\Types;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class ManagerRegistryEMReturnTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{
    /**
     * @var string
     */
    private $supportedClass;

    /**
     * @param string $supportedClass
     */
    public function __construct($supportedClass)
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
            $methodReflection->getName(),
            ['getManagerForClass'],
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
        switch ($methodReflection->getName()) {
            case 'getManagerForClass':
                return new ObjectType('Doctrine\ORM\EntityManagerInterface');
        }
    }
}
