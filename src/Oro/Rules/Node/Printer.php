<?php

namespace Oro\Rules\Node;

use PHPStan\DependencyInjection\AutowiredService;

/**
 * Custom PHPStan value printer wrapper
 */
#[AutowiredService]
final class Printer
{
    public function __construct(
        private readonly \PHPStan\Node\Printer\Printer $printer
    ) {
    }

    /**
     * Pretty prints an expression
     *
     * @param \PhpParser\Node\Expr $value
     * @return string
     */
    public function prettyPrintExpr(\PhpParser\Node\Expr $value): string
    {
        return $this->printer->prettyPrintExpr($value);
    }

    /**
     * Pretty prints an array of statements
     *
     * @param \PhpParser\Node[] $stmts
     * @return string
     */
    public function prettyPrint(array $stmts): string
    {
        return $this->printer->prettyPrint($stmts);
    }
}
