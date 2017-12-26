<?php declare(strict_types=1);

namespace Oro\Rules\Methods;

use Nette\Neon\Neon;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;

class QueryBuilderInjectionRule implements \PHPStan\Rules\Rule
{
    const SAFE_FUNCTIONS = [
        'sprintf' => true,
        'implode' => true,
        'reset' => true,
        'current' => true
    ];

    const VAR = 'variables';
    const METHODS = 'safe_methods';
    const STATIC_METHODS = 'safe_static_methods';
    const PROPERTIES = 'properties';
    const CHECK_METHODS = 'check_methods';

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
    private $trustedData;

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
        $this->trustedData = Neon::decode(\file_get_contents('trusted_data.neon'));
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
            if ($value->name instanceof Node\Name
                && !empty(self::SAFE_FUNCTIONS[$value->name->toString()])) {
                foreach ($value->args as $arg) {
                    if ($this->isUnsafe($arg->value, $scope)) {
                        return true;
                    }
                }
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @return bool
     */
    private function isUnsafeStaticMethodCall(Node\Expr $value): bool
    {
        if ($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name) {
            return empty($this->trustedData[self::STATIC_METHODS][$value->class->toString()][$value->name]);
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
            $valueType = $scope->getType($value);
            if ($valueType instanceof IntegerType
                || $valueType instanceof FloatType
                || $valueType instanceof BooleanType
            ) {
                return false;
            }

            $argsCount = \count($value->args);
            $type = $scope->getType($value->var);
            if (!$type instanceof ObjectType && !$type instanceof ThisType) {
                return true;
            }
            $className = $type->getClassName();

            if ($value->name === 'getEntityName' && \is_a($className, 'Doctrine\ORM\EntityRepository', true)) {
                return false;
            }

            $checkArg = function ($pos) use ($className, $value, $scope, &$errors) {
                if ($this->isUnsafe($value->args[$pos]->value, $scope)) {
                    if ($value->name instanceof Node\Expr\Variable) {
                        $methodName = $value->name->name;
                    } else {
                        $methodName = $value->name;
                    }
                    $errors[] = \sprintf(
                        'Unsafe calling method %s::%s. ' . PHP_EOL .
                        'Argument %d contains unsafe values %s. ' . PHP_EOL .
                        'Class %s, method %s',
                        $className,
                        $methodName,
                        $pos,
                        $this->printer->prettyPrint([$value->args[$pos]]),
                        $scope->getClassReflection()->getName(),
                        $scope->getFunctionName()
                    );
                }
            };

            if (\is_string($value->name) && !empty($this->trustedData[self::METHODS][$className][$value->name])) {
                return false;
            }

            switch ($className) {
                case 'Doctrine\\ORM\\QueryBuilder':
                    if (\strpos($value->name, 'get') === 0) {
                        return false;
                    }
                    if (\stripos($value->name, 'where') !== false
                        || \stripos($value->name, 'having') !== false
                    ) {
                        $checkArg(0);
                    } elseif (\stripos($value->name, 'join') !== false) {
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
                    if (!empty($this->trustedData[self::CHECK_METHODS][$className][$value->name])) {
                        for ($i = 0; $i < $argsCount; $i++) {
                            $checkArg($i);
                        }

                        return !empty($errors);
                    }

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
    private function isUnsafeConcat(Node\Expr $value, Scope $scope): bool
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
    private function isUnsafeVariable(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Variable) {
            $className = $scope->getClassReflection()->getName();

            return empty($this->trustedData[self::VAR][$className][$scope->getFunctionName()][$value->name])
                && empty($this->localTrustedVars[$scope->getFile()][$scope->getFunctionName()][$value->name]);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeProperty(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\PropertyFetch) {
            $type = $scope->getType($value->var);
            if (!$type instanceof ObjectType && !$type instanceof ThisType) {
                return true;
            }
            $className = $type->getClassName();

            if ($value->name === '_entityName' && \is_a($className, 'Doctrine\ORM\EntityRepository', true)) {
                return false;
            }

            return empty($this->trustedData[self::PROPERTIES][$className][$scope->getFunctionName()][$value->name]);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeEncapsedString(Node\Expr $value, Scope $scope): bool
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
            || $value instanceof Node\Expr\PropertyFetch
            || $value instanceof Node\Scalar\Encapsed
            || $value instanceof Node\Scalar\EncapsedStringPart
            || $value instanceof Node\Scalar\String_
            || $value instanceof Node\Scalar\DNumber
            || $value instanceof Node\Scalar\LNumber
            || $value instanceof Node\Expr\StaticCall
            || $value instanceof Node\Expr\ClassConstFetch
            || $value instanceof Node\Expr\ArrayDimFetch
            || $value instanceof Node\Expr\ConstFetch
            || $value instanceof Node\Expr\Array_
            || $value instanceof Node\Expr\Cast
            || $value instanceof Node\Expr\Ternary
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
            return $this->isUnsafe($value->var, $scope);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeArray(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $arrayItem) {
                if ($this->isUnsafe($arrayItem->value, $scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeCast(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Cast) {
            if ($value instanceof Node\Expr\Cast\Int_
                || $value instanceof Node\Expr\Cast\Bool_
                || $value instanceof Node\Expr\Cast\Double
            ) {
                return false;
            }

            return $this->isUnsafe($value->expr, $scope);
        }

        return false;
    }

    /**
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeTernary(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Ternary) {
            return ($value->if && $this->isUnsafe($value->if, $scope)) || $this->isUnsafe($value->else, $scope);
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
        return $this->isUncheckedType($value)
            || $this->isUnsafeVariable($value, $scope)
            || $this->isUnsafeProperty($value, $scope)
            || $this->isUnsafeStaticMethodCall($value)
            || $this->isUnsafeFunctionCall($value, $scope)
            || $this->isUnsafeMethodCall($value, $scope)
            || $this->isUnsafeArrayDimFetch($value, $scope)
            || $this->isUnsafeConcat($value, $scope)
            || $this->isUnsafeEncapsedString($value, $scope)
            || $this->isUnsafeCast($value, $scope)
            || $this->isUnsafeArray($value, $scope)
            || $this->isUnsafeTernary($value, $scope);
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
            unset($this->localTrustedVars[$this->currentFile]);
            $this->currentFile = $scope->getFile();
        }

        /** @var Node\Expr\Variable $var */
        if (($var = $node->var) instanceof Node\Expr\Variable) {
            if (!$this->isUnsafe($node->expr, $scope)) {
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
