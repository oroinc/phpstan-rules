<?php

declare(strict_types=1);

namespace Oro\Rules\Math;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Base class for rules related to mathematical operations
 *
 * @implements Rule<Node>
 */
abstract class BaseMathOperationsRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * Checks if the node is a mathematical operation
     */
    protected function isMathOperation(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp\Plus
            || $node instanceof Node\Expr\BinaryOp\Minus
            || $node instanceof Node\Expr\BinaryOp\Mul
            || $node instanceof Node\Expr\BinaryOp\Div
            || $node instanceof Node\Expr\BinaryOp\Mod
            || $node instanceof Node\Expr\BinaryOp\Pow;
    }

    /**
     * Checks if the node is a mathematical operation with assignment
     */
    protected function isAssignOp(Node $node): bool
    {
        return $node instanceof Node\Expr\AssignOp\Plus
            || $node instanceof Node\Expr\AssignOp\Minus
            || $node instanceof Node\Expr\AssignOp\Mul
            || $node instanceof Node\Expr\AssignOp\Div
            || $node instanceof Node\Expr\AssignOp\Mod
            || $node instanceof Node\Expr\AssignOp\Pow;
    }

    /**
     * Checks if node is any supported math operation (binary or assignment)
     */
    protected function isAnyMathOperation(Node $node): bool
    {
        return $this->isMathOperation($node) || $this->isAssignOp($node);
    }

    /**
     * Creates an error with the specified message
     */
    protected function createError(string $message, ?string $identifier = null): array
    {
        $builder = RuleErrorBuilder::message($message);

        if ($identifier !== null) {
            $builder->identifier($identifier);
        }

        return [$builder->build()];
    }
}
