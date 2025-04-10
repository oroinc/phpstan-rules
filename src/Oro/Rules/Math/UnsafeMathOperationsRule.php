<?php

declare(strict_types=1);

namespace Oro\Rules\Math;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

/**
 * Rule prohibiting unsafe mathematical operations
 *
 * @implements Rule<Node>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class UnsafeMathOperationsRule extends BaseMathOperationsRule
{
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isMathOperation($node)) {
            return $this->processBinaryOp($node, $scope);
        }

        if ($this->isAssignOp($node)) {
            return $this->processAssignOp($node, $scope);
        }

        return [];
    }

    /**
     * Process binary operations (+, -, *, /)
     */
    protected function processBinaryOp(BinaryOp $node, Scope $scope): array
    {
        $leftType = $scope->getType($node->left);
        $rightType = $scope->getType($node->right);

        if (!$this->isNumericType($leftType) || !$this->isNumericType($rightType)) {
            return [];
        }

        // If the operation is precision-safe
        if ($this->isPrecisionSafe($node, $leftType, $rightType)) {
            return [];
        }

        return $this->createError(
            'Mathematical operations on numeric values are prohibited. Use Brick\Math instead.',
            'unsafeMathOperations.prohibitedOperation'
        );
    }

    /**
     * Process assignment operations (+=, -=, *=)
     */
    protected function processAssignOp(AssignOp $node, Scope $scope): array
    {
        $varType = $scope->getType($node->var);
        $exprType = $scope->getType($node->expr);

        if (!$this->isNumericType($varType) || !$this->isNumericType($exprType)) {
            return [];
        }

        // Create a pseudo binary operation to reuse precision safety check
        $pseudoBinaryOp = new BinaryOp\Plus($node->var, $node->expr, $node->getAttributes());

        if ($this->isPrecisionSafe($pseudoBinaryOp, $varType, $exprType)) {
            return [];
        }

        return $this->createError(
            'Mathematical assignment operations on numeric values are prohibited. Use Brick\Math instead.',
            'unsafeMathOperations.prohibitedAssignmentOperation'
        );
    }

    /**
     * Checks if the type is numeric
     */
    private function isNumericType(Type $type): bool
    {
        return $type->isInteger()->yes() || $type->isFloat()->yes();
    }

    /**
     * Checks if the operation is safe in terms of precision
     * Unsafe by default
     */
    private function isPrecisionSafe(Node $node, Type $leftType, Type $rightType): bool
    {
        // Safe if both operands are integers
        if ($leftType instanceof IntegerType && $rightType instanceof IntegerType) {
            return true;
        }

        // Checks for IntegerRangeType and UnionType
        if ($leftType instanceof \PHPStan\Type\IntegerRangeType
            || $rightType instanceof \PHPStan\Type\IntegerRangeType
            || $leftType instanceof \PHPStan\Type\UnionType
            || $rightType instanceof \PHPStan\Type\UnionType
        ) {
            return $this->isSinglePrecisionSafe($leftType, $rightType)
                || $this->isSinglePrecisionSafe($rightType, $leftType);
        }

        // If left type is int|false
        if ($leftType->isInteger()->maybe() && $leftType->isSuperTypeOf(new IntegerType())->yes()) {
            return true;
        }

        // If right type is int|false
        if ($rightType->isInteger()->maybe() && $rightType->isSuperTypeOf(new IntegerType())->yes()) {
            return true;
        }

        // Operations with a variable and a constant, E.G., $count - 1
        if ($rightType instanceof ConstantIntegerType || $leftType instanceof ConstantIntegerType) {
            $constOperand = $rightType instanceof ConstantIntegerType ? $rightType : $leftType;

            if (($constOperand->isInteger()->yes() || $constOperand->isInteger()->maybe())
                && ($leftType->isInteger()->yes() || $rightType->isInteger()->yes())) {
                return true;
            }
        }

        // Safe if both operands are floating-point numbers
        if ($leftType instanceof FloatType && $rightType instanceof FloatType) {
            return true;
        }

        // Division operation check
        if ($node instanceof Node\Expr\BinaryOp\Div) {
            // Division is safe only if the result remains an integer
            if ($leftType instanceof IntegerType && $rightType instanceof IntegerType) {
                return $this->isDivisionResultInteger($node);
            }
            // Unsafe if different types or float
            return false;
        }

        /**
         * Implicit cast check
         * Unsafe if implicit type conversion occurs
         */
        if ($this->isImplicitCast($leftType, $rightType)) {
            return false;
        }

        // If both operands are explicitly cast to int
        if ($node instanceof BinaryOp &&
            $node->left instanceof Node\Expr\Cast\Int_ &&
            $node->right instanceof Node\Expr\Cast\Int_) {
            return true;
        }

        return false;
    }

    /**
     * Checks if one operand is safe in terms of precision
     * Unsafe by default
     */
    private function isSinglePrecisionSafe(Type $primary, Type $secondary): bool
    {
        // If both operands are IntegerType
        if ($primary instanceof IntegerType && $secondary instanceof IntegerType) {
            return true;
        }

        // If primary is IntegerRangeType
        if ($primary instanceof \PHPStan\Type\IntegerRangeType) {
            return true;
        }

        // If secondary is UnionType, check its contents
        if ($secondary instanceof \PHPStan\Type\UnionType) {
            foreach ($secondary->getTypes() as $type) {
                if (!$type instanceof IntegerType) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Checks if the division result is an integer
     */
    private function isDivisionResultInteger(Node\Expr\BinaryOp\Div $node): bool
    {
        if ($node->left instanceof Node\Scalar\LNumber && $node->right instanceof Node\Scalar\LNumber) {
            $leftValue = $node->left->value;
            $rightValue = $node->right->value;

            // Without remainder
            return $rightValue !== 0 && ($leftValue % $rightValue === 0);
        }

        // For dynamic values, consider division unsafe
        return false;
    }

    /**
     * Checks for implicit type casting
     */
    private function isImplicitCast(Type $leftType, Type $rightType): bool
    {
        return ($leftType instanceof IntegerType && $rightType instanceof FloatType) ||
            ($leftType instanceof FloatType && $rightType instanceof IntegerType);
    }
}
