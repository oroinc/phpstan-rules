<?php

namespace Oro\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleLevelHelper;

/**
 * Checks whatever catch block is valid.
 * Catch block considered as correct if an exception is logged (using Psr\Log\LoggerInterface) or thrown.
 * Also it can be marked as @ignoreException if author is really sure no logging needed.
 */
class ValidExceptionCatchRule implements Rule
{
    /**
     * @var RuleLevelHelper
     */
    private $ruleLevelHelper;

    /**
     * @param RuleLevelHelper $ruleLevelHelper
     */
    public function __construct(RuleLevelHelper $ruleLevelHelper)
    {
        $this->ruleLevelHelper = $ruleLevelHelper;
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /**
     * @param \PhpParser\Node\Stmt\Catch_ $node
     * {@inheritdoc}
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isValidCatchBlock($node->stmts, $scope)) {
            return [
                'Invalid catch block found. You should log exception or throw it.' . PHP_EOL .
                'If you are certainly sure this is meant to be empty, please add ' . PHP_EOL .
                'a "// @ignoreException" comment in the catch block.'
            ];
        }

        return [];
    }

    /**
     * @param Node[] $stmts
     * @param Scope $scope
     * @return bool
     */
    private function isValidCatchBlock(array $stmts, Scope $scope): bool
    {
        foreach ($stmts as $stmt) {
            //Throwing exception in catch block considered valid situation
            if ($stmt instanceof Node\Stmt\Throw_) {
                return true;
            }

            //If logger was called catch considered as valid
            if ($stmt instanceof Node\Expr\MethodCall) {
                $type = $this->ruleLevelHelper->findTypeToCheck(
                    $scope,
                    $stmt->var,
                    'Unknown class'
                );

                if (array_key_exists(0, $type->getReferencedClasses()) &&
                    $type->getReferencedClasses()[0] === 'Psr\Log\LoggerInterface'
                ) {
                    return true;
                }
            }

            //Comment with @ignoreException tag marks catch statement as valid
            if ($this->hasIgnoreComment($stmt)) {
                return true;
            }
        }

        //Otherwise catch is invalid
        return false;
    }

    /**
     * @param Node $statement
     * @return bool
     */
    private function hasIgnoreComment(Node $statement): bool
    {
        $comments = [];

        //Try to find comments in statement
        if (\method_exists($statement, 'getComments')) {
            $comments = $statement->getComments();
        } elseif ($statement->hasAttribute('comments')) {
            $comments = $statement->getAttribute('comments');
        }

        foreach ($comments as $comment) {
            if (\strpos($comment->getText(), '@ignoreException') !== false) {
                return true;
            }
        }

        return false;
    }
}
