<?php

declare(strict_types=1);

namespace Oro\Rules\Math;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\Type;

/**
 * Base class for rules checking special math types
 *
 * @implements Rule<Node>
 */
abstract class MathTypeOperationsRule extends BaseMathOperationsRule
{
    /**
     * @var array<class-string>
     */
    protected array $restrictedTypes = [];

    /**
     * Component names for the error message
     */
    protected string $componentName = '';
    protected string $alternativeMethod = '';

    /**
     * Rule identifier
     */
    protected string $identifier = 'prohibitedMathOperations.prohibitedOperation';

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isAnyMathOperation($node)) {
            return [];
        }

        if ($this->isMathOperation($node)) {
            return $this->processBinaryOp($node, $scope);
        }

        if ($this->isAssignOp($node)) {
            return $this->processAssignOp($node, $scope);
        }

        return [];
    }

    /**
     * Process a binary operation node (like +, -, *, /)
     */
    protected function processBinaryOp(Node\Expr\BinaryOp $node, Scope $scope): array
    {
        // Cast check
        foreach ([$node->left, $node->right] as $operand) {
            if ($operand instanceof Node\Expr\Cast) {
                $innerType = $scope->getType($operand->expr);
                if ($this->isRestrictedType($innerType)) {
                    return $this->createError(
                        sprintf(
                            "Arithmetic operations on %s objects (even after casting) are prohibited. " .
                            "Use %s methods instead.",
                            $this->componentName,
                            $this->alternativeMethod
                        ),
                        $this->identifier
                    );
                }
            }
        }

        // Regular type check
        $leftType = $scope->getType($node->left);
        $rightType = $scope->getType($node->right);

        foreach ([$leftType, $rightType] as $type) {
            if ($this->isRestrictedType($type)) {
                return $this->createError(
                    sprintf(
                        "Arithmetic operations on %s objects are prohibited. Use %s methods instead.",
                        $this->componentName,
                        $this->alternativeMethod
                    ),
                    $this->identifier
                );
            }
        }

        return [];
    }

    /**
     * Process an assignment operation node (like +=, -=, *=)
     */
    protected function processAssignOp(Node\Expr\AssignOp $node, Scope $scope): array
    {
        // Check var type
        $varType = $scope->getType($node->var);
        if ($this->isRestrictedType($varType)) {
            return $this->createError(
                sprintf(
                    "Arithmetic assignment operations on %s objects are prohibited. Use %s methods instead.",
                    $this->componentName,
                    $this->alternativeMethod
                ),
                $this->identifier
            );
        }

        // Check expr type
        $exprType = $scope->getType($node->expr);
        if ($this->isRestrictedType($exprType)) {
            return $this->createError(
                sprintf(
                    "Arithmetic assignment operations with %s objects are prohibited. Use %s methods instead.",
                    $this->componentName,
                    $this->alternativeMethod
                ),
                $this->identifier
            );
        }

        // Check for cast in expr
        if ($node->expr instanceof Node\Expr\Cast) {
            $innerType = $scope->getType($node->expr->expr);
            if ($this->isRestrictedType($innerType)) {
                return $this->createError(
                    sprintf(
                        "Arithmetic assignment operations with %s objects (even after casting) are prohibited. " .
                        "Use %s methods instead.",
                        $this->componentName,
                        $this->alternativeMethod
                    ),
                    $this->identifier
                );
            }
        }

        return [];
    }

    /**
     * Checks if the type is among the restricted ones
     */
    protected function isRestrictedType(Type $type): bool
    {
        foreach ($this->restrictedTypes as $restrictedType) {
            if ($type instanceof $restrictedType) {
                return true;
            }
        }

        return false;
    }
}
