<?php declare(strict_types=1);

namespace Oro\Rules\Methods;

use Nette\Neon\Neon;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\ObjectType;

class QueryBuilderInjectionRule implements \PHPStan\Rules\Rule
{
    const SAFE_FUNCTIONS = [
        'Doctrine\\ORM\\QueryBuilder::setParameter' => true,
        'Doctrine\\ORM\\QueryBuilder::setParameters' => true,
        'Doctrine\\ORM\\QueryBuilder::setMaxResults' => true,
        'Doctrine\\ORM\\QueryBuilder::setFirstResult' => true,
        'Doctrine\\ORM\\Query\\Expr::literal' => true
    ];

    const SAFE_STATIC_METHODS = [
        'Oro\\Bundle\\EntityExtendBundle\\Tools\\ExtendHelper::buildAssociationName' => true
    ];

    /**
     * @var \PHPStan\Rules\RuleLevelHelper
     */
    private $ruleLevelHelper;

    /**
     * @var bool
     */
    private $checkThisOnly;

    /**
     * @var \PhpParser\PrettyPrinter\Standard
     */
    private $printer;

    /**
     * @var string
     */
    private $currentFile;

    /**
     * @var array
     */
    private $localTrustedVars = [];

    /**
     * @var array
     */
    private $trustedVariables;

    /**
     * @var array
     */
    private $rootAliasesHolder = [];

    /**
     * @param \PhpParser\PrettyPrinter\Standard $printer
     * @param RuleLevelHelper $ruleLevelHelper
     * @param bool $checkThisOnly
     */
    public function __construct(
        \PhpParser\PrettyPrinter\Standard $printer,
        RuleLevelHelper $ruleLevelHelper,
        bool $checkThisOnly
    ) {
        $this->ruleLevelHelper = $ruleLevelHelper;
        $this->checkThisOnly = $checkThisOnly;
        $this->printer = $printer;
        $this->trustedVariables = Neon::decode(file_get_contents('trusted_variables.neon'));
    }

    /**
     * {@inheritdoc}
     */
    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    /**
     * @param Node\Expr\MethodCall|Node $node
     * @param \PHPStan\Analyser\Scope $scope
     * @return string[]
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Node\Expr\Assign) {
            $this->processAssigns($node, $scope);
        } elseif ($node instanceof Node\Expr\MethodCall) {
            return $this->processMethodCalls($node, $scope);
        }

        return [];
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeFunctionCall(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\FuncCall) {
            if ($value->name instanceof Node\Name && $value->name->toString() === 'sprintf') {
                foreach ($value->args as $arg) {
                    if ($this->isUnsafe($arg->value, $scope)) {
                        return true;
                    }
                }
            } elseif ($value->name instanceof Node\Name
                && $value->name->toString() === 'reset'
                && ($argValue = $value->args[0]->value) instanceof Node\Expr\Variable
                && !empty($this->rootAliasesHolder[$scope->getFile()][$scope->getFunctionName()][$argValue->name])
            ) {
                return false;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeStaticMethodCall(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name) {
            $staticKey = $value->class->toString() . '::' . $value->name;

            return empty(self::SAFE_STATIC_METHODS[$staticKey]);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @param array $errors
     * @return bool
     */
    private function isUnsafeMethodCall(Node\Expr $value, Scope $scope, array &$errors = []): bool
    {
        $errors = [];
        if ($value instanceof Node\Expr\MethodCall) {
            $argsCount = count($value->args);
            $type = $scope->getType($value->var);
            if (!$type instanceof ObjectType) {
                return true;
            }
            $className = $type->getClassName();

            $checkArg = function ($pos) use ($className, $value, $scope, &$errors) {
                if ($this->isUnsafe($value->args[$pos]->value, $scope)) {
                    if ($value->name instanceof Node\Expr\Variable) {
                        $methodName = $value->name->name;
                    } else {
                        $methodName = $value->name;
                    }
                    $errors[] = sprintf(
                        'Unsafe calling method %s() of %s. Argument %d contains unsafe values %s',
                        $methodName,
                        $className,
                        $pos,
                        $this->printer->prettyPrint([$value->args[$pos]])
                    );
                }
            };

            if (!empty(self::SAFE_FUNCTIONS[$className . '::' . $value->name])) {
                return false;
            }

            switch ($className) {
                case 'Doctrine\\ORM\\QueryBuilder':
                    if (stripos($value->name, 'where') !== false
                        || stripos($value->name, 'having') !== false
                    ) {
                        $checkArg(0);
                    } elseif (stripos($value->name, 'join') !== false) {
                        $checkArg(0);
                        if (isset($node->args[3])) {
                            $checkArg(3);
                        }
                    } else {
                        for ($i = 0; $i < $argsCount; $i++) {
                            $checkArg($i);
                        }
                    }

                    return !empty($errors);

                case 'Doctrine\\ORM\\Query\\Expr':
                    if ($value->name === 'in' || $value->name === 'notIn') {
                        $checkArg(0);
                    } else {
                        for ($i = 0; $i < $argsCount; $i++) {
                            $checkArg($i);
                        }
                    }

                    return !empty($errors);

                default:
                    return true;
            }
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isConcat(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\BinaryOp\Concat) {
            return $this->isUnsafe($value->left, $scope) || $this->isUnsafe($value->right, $scope);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isVariable(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Variable) {
            $className = $scope->getClassReflection()->getName();

            return empty($this->trustedVariables[$className][$scope->getFunctionName()][$value->name])
                && empty($this->localTrustedVars[$scope->getFile()][$scope->getFunctionName()][$value->name]);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isEncapsedString(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Scalar\Encapsed) {
            foreach ($value->parts as $partValue) {
                if ($this->isUnsafe($partValue, $scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @return bool
     */
    private function isUncheckedType(Node\Expr $value): bool
    {
        return !(
            $value instanceof Node\Expr\MethodCall
            || $value instanceof Node\Expr\FuncCall
            || $value instanceof Node\Expr\BinaryOp\Concat
            || $value instanceof Node\Expr\Variable
            || $value instanceof Node\Scalar\Encapsed
            || $value instanceof Node\Scalar\String_
            || $value instanceof Node\Scalar\DNumber
            || $value instanceof Node\Scalar\LNumber
            || $value instanceof Node\Expr\StaticCall
            || $value instanceof Node\Expr\ClassConstFetch
        );
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeArrayDimFetch(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\ArrayDimFetch) {
            if ($value->var instanceof Node\Expr\Variable) {
                // Trust root aliases received as $rootAliases[0]
                return empty($this->rootAliasesHolder[$scope->getFile()][$scope->getFunctionName()][$value->var->name]);
            }

            return true;
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafe(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $arrayItem) {
                if ($this->isUnsafe($arrayItem->value, $scope)) {
                    return true;
                }
            }

            return false;
        }

        return $this->isUncheckedType($value)
            || $this->isUnsafeArrayDimFetch($value, $scope)
            || $this->isUnsafeFunctionCall($value, $scope)
            || $this->isUnsafeMethodCall($value, $scope)
            || $this->isUnsafeStaticMethodCall($value, $scope)
            || $this->isConcat($value, $scope)
            || $this->isVariable($value, $scope)
            || $this->isEncapsedString($value, $scope);
    }

    /**
     * @param Node\Expr\Assign $node
     * @param Scope $scope
     */
    private function processAssigns(Node\Expr\Assign $node, Scope $scope)
    {
        $trustVariable = function ($name) use ($scope) {
            $this->localTrustedVars[$scope->getFile()][$scope->getFunctionName()][$name] = true;
        };

        if ($this->currentFile !== $scope->getFile()) {
            unset($this->localTrustedVars[$scope->getFile()]);
            $this->currentFile = $scope->getFile();
        }

        /** @var Node\Expr\Variable $var */
        if (($var = $node->var) instanceof Node\Expr\Variable) {
            if ($node->expr instanceof Node\Expr\MethodCall) {
                // Get variables that store query builder root aliases
                $type = $scope->getType($node->expr->var);
                if ($type instanceof ObjectType
                    && $node->expr->name === 'getRootAliases'
                    && $type->getClassName() === 'Doctrine\\ORM\\QueryBuilder'
                ) {
                    $this->rootAliasesHolder[$scope->getFile()][$scope->getFunctionName()][$var->name] = true;
                }
            } elseif (!$this->isUnsafe($node->expr, $scope)) {
                // Trust safe variables
                $trustVariable($var->name);
            }
        }
    }

    /**
     * @param Node\Expr\MethodCall $node
     * @param Scope $scope
     * @return array
     */
    protected function processMethodCalls(Node\Expr\MethodCall $node, Scope $scope): array
    {
        if (!\is_string($node->name)) {
            return [];
        }

        if ($this->checkThisOnly && !$this->ruleLevelHelper->isThis($node->var)) {
            return [];
        }

        $errors = [];
        $this->isUnsafeMethodCall($node, $scope, $errors);

        return $errors;
    }
}
