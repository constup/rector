<?php

declare(strict_types=1);

namespace Rector\CodingStyle\Rector\Throw_;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Throw_;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\Type\ObjectType;
use Rector\AttributeAwarePhpDoc\Ast\PhpDoc\AttributeAwarePhpDocTagNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\Core\Exception\NotImplementedException;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStan\Type\ShortenedObjectType;

final class AnnotateThrowablesRector extends AbstractRector
{
    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Throw_::class];
    }

    /**
     * From this method documentation is generated.
     */
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Adds @throws DocBlock comments to methods that thrwo \Throwables.', [
                new CodeSample(
                     // code before
                    <<<'PHP'
class RootExceptionInMethodWithDocblock
{
    /**
     * This is a comment.
     *
     * @param int $code
     */
    public function throwException(int $code)
    {
        throw new \RuntimeException('', $code);
    }
}
PHP
                    ,
                    // code after
                    <<<'PHP'
class RootExceptionInMethodWithDocblock
{
    /**
     * This is a comment.
     *
     * @param int $code
     * @throws \RuntimeException
     */
    public function throwException(int $code)
    {
        throw new \RuntimeException('', $code);
    }
}
PHP
                ),
            ]
        );
    }

    /**
     * @param Throw_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->isThrowableAnnotated($node)) {
            return null;
        }

        $this->annotateThrowable($node);

        return $node;
    }

    private function isThrowableAnnotated(Throw_ $throw): bool
    {
        $phpDocInfo = $this->getThrowingStmtPhpDocInfo($throw);
        $identifiedThrownThrowables = $this->identifyThrownThrowables($throw);

        foreach ($phpDocInfo->getThrowsTypes() as $throwsType) {
            if (! $throwsType instanceof ObjectType) {
                continue;
            }

            if ($throwsType instanceof ShortenedObjectType) {
                $className = $throwsType->getFullyQualifiedName();
            } else {
                $className = $throwsType->getClassName();
            }

            if (! in_array($className, $identifiedThrownThrowables, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function identifyThrownThrowables(Throw_ $throw): array
    {
        if ($throw->expr instanceof New_) {
            return [$this->getName($throw->expr->class)];
        }

        if ($throw->expr instanceof StaticCall) {
            return $this->identifyThrownThrowablesInStaticCall($throw);
        }

        throw new NotImplementedException(sprintf(
            'The \Throwable "%s" is not supported yet. Please, open an issue.',
            get_class($throw->expr)
        ));
    }

    private function identifyThrownThrowablesInStaticCall(Throw_ $node): array
    {
        return [];
    }

    private function annotateThrowable(Throw_ $node): void
    {
        $throwClass = $this->buildFQN($node);
        if ($throwClass === null) {
            throw new ShouldNotHappenException();
        }

        $docComment = $this->buildThrowsDocComment($throwClass);

        $throwingStmtPhpDocInfo = $this->getThrowingStmtPhpDocInfo($node);
        $throwingStmtPhpDocInfo->addPhpDocTagNode($docComment);
    }

    private function buildThrowsDocComment(string $throwableClass): AttributeAwarePhpDocTagNode
    {
        $genericTagValueNode = new ThrowsTagValueNode(new IdentifierTypeNode('\\' . $throwableClass), '');

        return new AttributeAwarePhpDocTagNode('@throws', $genericTagValueNode);
    }

    private function buildFQN(Throw_ $throw): ?string
    {
        if (! $throw->expr instanceof New_) {
            return null;
        }

        return $this->getName($throw->expr->class);
    }

    private function getThrowingStmtPhpDocInfo(Throw_ $throw): PhpDocInfo
    {
        $stmt = $this->getThrowingStmt($throw);

        return $stmt->getAttribute(AttributeKey::PHP_DOC_INFO);
    }

    /**
     * @return ClassMethod|Function_
     */
    private function getThrowingStmt(Throw_ $node): FunctionLike
    {
        $method = $node->getAttribute(AttributeKey::METHOD_NODE);
        $function = $node->getAttribute(AttributeKey::FUNCTION_NODE);

        $stmt = $method ?? $function ?? null;
        if ($stmt === null) {
            throw new ShouldNotHappenException();
        }

        return $stmt;
    }
}
