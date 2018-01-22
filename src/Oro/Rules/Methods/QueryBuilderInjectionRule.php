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

/**
 * Check methods listed in trusted_data.neon for unsafe calls.
 */
class QueryBuilderInjectionRule implements \PHPStan\Rules\Rule
{
    const TRUSTED_DATA_FILE = 'trusted_data.neon';

    const SAFE_FUNCTIONS = [
        'sprintf' => true,
        'implode' => true,
        'join' => true,
        'reset' => true,
        'current' => true
    ];

    const VAR = 'variables';
    const PROPERTIES = 'properties';

    const SAFE_METHODS = 'safe_methods';
    const SAFE_STATIC_METHODS = 'safe_static_methods';
    const CHECK_METHODS_SAFETY = 'check_methods_safety';

    const CLEAR_METHODS = 'clear_methods';
    const CLEAR_STATIC_METHODS = 'clear_static_methods';

    const CHECK_METHODS = 'check_methods';
    const ALL_METHODS = '__all__';

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
        $this->loadTrustedData();
    }

    /**
     * Apply for all node types, as we need to process assigns and method calls.
     *
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
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->processStaticMethodCall($node, $scope);
        }

        return [];
    }

    /**
     * Check that all arguments of function are safe.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeFunctionCall(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\FuncCall) {
            if ($value->name instanceof Node\Name
                && !empty(self::SAFE_FUNCTIONS[\strtolower($value->name->toString())])) {
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
     * If static method is whitelisted it is considered as safe.
     *
     * @param Node\Expr $value
     * @return bool
     */
    private function isUnsafeStaticMethodCall(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name) {
            $className = $value->class->toString();
            if ($className === 'self') {
                $className = $scope->getClassReflection()->getName();
            }
            $methodName = \strtolower($value->name);

            return empty($this->trustedData[self::SAFE_STATIC_METHODS][$className][$methodName]);
        }

        return false;
    }

    /**
     * Check that method is whitelisted or it's arguments are safe.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @param array $errors
     * @return bool
     */
    private function isUnsafeMethodCall(Node\Expr $value, Scope $scope, array &$errors = []): bool
    {
        $errors = [];
        if ($value instanceof Node\Expr\MethodCall) {
            if (!\is_string($value->name)) {
                return true;
            }
            // Mark method safe if it's returned type is boolean or numeric
            $valueType = $scope->getType($value);
            if ($valueType instanceof IntegerType
                || $valueType instanceof FloatType
                || $valueType instanceof BooleanType
            ) {
                return false;
            }

            $argsCount = \count($value->args);
            // Check only methods that are called on object or $this
            $type = $scope->getType($value->var);
            if (!$type instanceof ObjectType && !$type instanceof ThisType) {
                return true;
            }
            $className = $type->getClassName();
            $this->checkClearMethodCall(self::CLEAR_METHODS, $className, $value, $scope);

            if (!\is_string($value->name)) {
                return true;
            }

            // Consider Doctrine\ORM\EntityRepository::getEntityName as safe
            if ($value->name === 'getEntityName' && \is_a($className, 'Doctrine\ORM\EntityRepository', true)) {
                return false;
            }

            $lowerMethodName = \strtolower($value->name);
            $checkArg = function ($pos, array &$errors = []) use ($className, $value, $scope) {
                if ($this->isUnsafe($value->args[$pos]->value, $scope)) {
                    $errors[] = \sprintf(
                        'Unsafe calling method %s::%s. ' . PHP_EOL .
                        'Argument %d contains unsafe values %s. ' . PHP_EOL .
                        'Class %s, method %s',
                        $className,
                        $value->name,
                        $pos,
                        $this->printer->prettyPrint([$value->args[$pos]]),
                        $scope->getClassReflection()->getName(),
                        $scope->getFunctionName()
                    );
                }
            };

            // Whitelisted methods are safe
            if (!empty($this->trustedData[self::SAFE_METHODS][$className][$lowerMethodName])) {
                return false;
            }

            $checkMethodArguments = function (
                array $config,
                array &$errors = []
            ) use (
                $className,
                $value,
                $argsCount,
                $lowerMethodName,
                $checkArg,
                $scope
            ) {
                if (isset($config[$className])) {
                    // If method is listed in check methods and only certain arguments should be checked - check them
                    if (isset($config[$className][$lowerMethodName])
                        && \is_array($config[$className][$lowerMethodName])) {
                        foreach ($config[$className][$lowerMethodName] as $argNum) {
                            if (isset($value->args[$argNum])) {
                                $checkArg($argNum, $errors);
                            }
                        }
                    } elseif ((isset($config[$className][$lowerMethodName])
                            && $config[$className][$lowerMethodName] === true
                        )
                        || !empty($config[$className][self::ALL_METHODS])
                    ) {
                        // Check all arguments if method is marked for checks or method is in class marked for checking
                        for ($i = 0; $i < $argsCount; $i++) {
                            $checkArg($i, $errors);
                        }
                    }

                    // If there are errors consider method as unsafe
                    if (!empty($errors)) {
                        // Trusted variable that was modified by unsafe method should become untrusted
                        $unsafeVar = $this->getRootVariable($value);
                        if ($unsafeVar) {
                            $this->untrustVariable($unsafeVar, $scope);
                        }

                        return true;
                    }

                    return false;
                }

                return null;
            };

            // Methods marked for checked with safe arguments are safe
            if (($result = $checkMethodArguments($this->trustedData[self::CHECK_METHODS_SAFETY])) !== null) {
                return $result;
            }
            if (($result = $checkMethodArguments($this->trustedData[self::CHECK_METHODS], $errors)) !== null) {
                return $result;
            }

            // All unchecked methods are unsafe
            return true;
        }

        return false;
    }

    /**
     * Check that all parts of concat are safe.
     *
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
     * Check that variable is whitelisted or was considered safe during assignment.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeVariable(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Variable) {
            $className = $scope->getClassReflection()->getName();

            $functionName = \strtolower($scope->getFunctionName());
            $varName = \strtolower($value->name);

            return empty($this->trustedData[self::VAR][$className][$functionName][$varName])
                && empty($this->localTrustedVars[$scope->getFile()][$functionName][$varName]);
        }

        return false;
    }

    /**
     * Check that property is whitelisted or it is _entityName of some repository.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeProperty(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\PropertyFetch && is_string($value->name)) {
            $type = $scope->getType($value->var);
            if (!$type instanceof ObjectType && !$type instanceof ThisType) {
                return true;
            }
            $className = $type->getClassName();

            if ($value->name === '_entityName' && \is_a($className, 'Doctrine\ORM\EntityRepository', true)) {
                return false;
            }

            $functionName = \strtolower($scope->getFunctionName());
            $varName = \strtolower($value->name);

            return empty($this->trustedData[self::PROPERTIES][$className][$functionName][$varName]);
        }

        return false;
    }

    /**
     * Check that all parts of encapsed are safe.
     *
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
     * Only checked types may be safe. All unchecked types are considered as unsafe by default.
     *
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
     * Check that array dim is safe.
     *
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
     * Check that all array elements are safe.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafeArray(Node\Expr $value, Scope $scope): bool
    {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $arrayItem) {
                if (($arrayItem->key && $this->isUnsafe($arrayItem->key, $scope))
                    || $this->isUnsafe($arrayItem->value, $scope)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Consider variables casted to boolean or numeric as safe.
     *
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
     * Check that if-else branches of ternary operator are safe.
     *
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
     * Check node safety.
     *
     * @param Node\Expr $value
     * @param Scope $scope
     * @return bool
     */
    private function isUnsafe(Node\Expr $value, Scope $scope): bool
    {
        return $this->isUncheckedType($value)
            || $this->isUnsafeVariable($value, $scope)
            || $this->isUnsafeProperty($value, $scope)
            || $this->isUnsafeStaticMethodCall($value, $scope)
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
     * Gather information about variable safety during assignment.
     *
     * @param Node\Expr\Assign $node
     * @param Scope $scope
     */
    private function processAssigns(Node\Expr\Assign $node, Scope $scope)
    {
        if ($this->currentFile !== $scope->getFile()) {
            unset($this->localTrustedVars[$this->currentFile]);
            $this->currentFile = $scope->getFile();
        }

        $isUnsafe = $this->isUnsafe($node->expr, $scope);
        /** @var Node\Expr\Variable $var */
        if (($var = $node->var) instanceof Node\Expr\Variable) {
            if ($isUnsafe) {
                // Do not trust unsafe variables
                $this->untrustVariable($var, $scope);
            } else {
                // Trust safe variables
                $this->trustVariable($var, $scope);
            }
        } elseif ($node->var instanceof Node\Expr\List_) {
            foreach ($node->var->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Expr\Variable) {
                    if ($isUnsafe) {
                        $this->untrustVariable($item->value, $scope);
                    } else {
                        $this->trustVariable($item->value, $scope);
                    }
                }
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

    /**
     * @param Node $node
     * @param Scope $scope
     */
    private function processStaticMethodCall(Node $node, Scope $scope)
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            if ($className === 'self') {
                $className = $scope->getClassReflection()->getName();
            }
            $this->checkClearMethodCall(self::CLEAR_STATIC_METHODS, $className, $node, $scope);
        }
    }

    /**
     * Trust variables checked by clear methods
     *
     * @param string $type
     * @param Node|Node\Expr\StaticCall|Node\Expr\MethodCall $value
     * @param Scope $scope
     */
    private function checkClearMethodCall($type, $className, Node $value, Scope $scope)
    {
        if (!empty($this->trustedData[$type][$className][\strtolower($value->name)])
            && $value->args[0]->value instanceof Node\Expr\Variable
        ) {
            $this->trustVariable($value->args[0]->value, $scope);
        }
    }

    /**
     * Load trusted data.
     * Convert all function and variable names to lower case.
     */
    protected function loadTrustedData()
    {
        $loadedData = Neon::decode(\file_get_contents(self::TRUSTED_DATA_FILE));
        $data = [];

        $lowerVariables = function ($loaded, $key) use (&$data) {
            foreach ($loaded[$key] as $class => $methods) {
                foreach ($methods as $method => $variables) {
                    $lowerMethod = \strtolower($method);
                    $data[$key][$class][$lowerMethod] = [];
                    foreach ($variables as $variable => $varData) {
                        $data[$key][$class][$lowerMethod][strtolower($variable)] = $varData;
                    }
                }
            }
        };
        $lowerVariables($loadedData, self::VAR);
        $lowerVariables($loadedData, self::PROPERTIES);

        $lowerMethods = function ($loaded, $key) use (&$data) {
            foreach ($loaded[$key] as $class => $methods) {
                foreach ($methods as $method => $methodData) {
                    $data[$key][$class][strtolower($method)] = $methodData;
                }
            }
        };
        $lowerMethods($loadedData, self::SAFE_METHODS);
        $lowerMethods($loadedData, self::CHECK_METHODS_SAFETY);
        $lowerMethods($loadedData, self::SAFE_STATIC_METHODS);
        $lowerMethods($loadedData, self::CHECK_METHODS);
        $lowerMethods($loadedData, self::CLEAR_METHODS);
        $lowerMethods($loadedData, self::CLEAR_STATIC_METHODS);

        $this->trustedData = $data;
    }

    /**
     * @param Node\Expr\Variable $var
     * @param Scope $scope
     */
    private function trustVariable(Node\Expr\Variable $var, Scope $scope)
    {
        $functionName = \strtolower($scope->getFunctionName());
        $varName = \strtolower($var->name);
        $this->localTrustedVars[$scope->getFile()][$functionName][$varName] = true;
    }

    /**
     * @param Node\Expr\Variable $var
     * @param Scope $scope
     */
    private function untrustVariable(Node\Expr\Variable $var, Scope $scope)
    {
        $functionName = \strtolower($scope->getFunctionName());
        $varName = \strtolower($var->name);
        unset($this->localTrustedVars[$scope->getFile()][$functionName][$varName]);
    }

    /**
     * @param Node $node
     * @return null|Node\Expr\Variable
     */
    private function getRootVariable(Node $node)
    {
        if ($node instanceof Node\Expr\Variable) {
            return $node;
        } elseif ($node instanceof Node\Expr\MethodCall) {
            return $this->getRootVariable($node->var);
        }

        return null;
    }
}
