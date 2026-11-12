<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

use CodeAtlas\Core\Parser\ParsedFile;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\PrettyPrinter\Standard;

/**
 * Turns a parsed PHP file into ClassShapes: identity, hierarchy, traits,
 * methods with typed parameters and attributes, and constructor
 * dependencies — all off the AST, never by executing code.
 *
 * This is the extraction every class-based analyzer shares. Analyzers
 * depend on it rather than on each other (constitution rule 2), and
 * third-party analyzer plugins get the same surface, since they can
 * depend on codeatlas/core but not on a bundled analyzer package.
 *
 * Typed against the concrete ParsedFile because it needs findNodes() and
 * resolveClassName(), which ParsedFileInterface intentionally omits to
 * keep the contracts package free of a PhpParser\Node import.
 */
final class ClassShapeExtractor
{
    private readonly TypeRenderer $types;

    private readonly Standard $printer;

    public function __construct(private readonly ParsedFile $file)
    {
        $this->types = new TypeRenderer($file);
        $this->printer = new Standard();
    }

    /**
     * The first class declared in the file, which is the whole story for
     * the one-class-per-file convention every Laravel app follows.
     */
    public function extract(): ?ClassShape
    {
        return $this->extractAll()[0] ?? null;
    }

    /**
     * Every named class declared in the file.
     *
     * Anonymous classes are skipped: they have no FQCN, so they cannot be
     * a graph node.
     *
     * @return list<ClassShape>
     */
    public function extractAll(): array
    {
        $shapes = [];

        foreach ($this->file->findNodes(Class_::class) as $class) {
            $shape = $this->shape($class);

            if ($shape !== null) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    /**
     * Build the shape of one class node.
     */
    public function shape(Class_ $class): ?ClassShape
    {
        if ($class->name === null) {
            return null;
        }

        $namespace = $this->file->namespace();
        $name = $class->name->toString();

        return new ClassShape(
            fqcn: $namespace === null ? $name : $namespace . '\\' . $name,
            name: $name,
            namespace: $namespace,
            parent: $class->extends instanceof Name ? $this->types->resolve($class->extends) : null,
            interfaces: array_values(array_map($this->types->resolve(...), $class->implements)),
            traits: $this->traits($class),
            methods: $this->methods($class),
            dependencies: $this->dependencies($class),
            abstract: $class->isAbstract(),
            final: $class->isFinal(),
            lineStart: $class->getStartLine(),
            lineEnd: $class->getEndLine(),
        );
    }

    /**
     * @return list<string>
     */
    private function traits(Class_ $class): array
    {
        $traits = [];

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $traits[] = $this->types->resolve($trait);
            }
        }

        return $traits;
    }

    /**
     * @return list<MethodShape>
     */
    private function methods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[] = new MethodShape(
                name: $method->name->toString(),
                visibility: $this->visibility($method),
                parameters: $this->parameters($method->params),
                returnType: $method->returnType === null ? null : $this->types->render($method->returnType),
                attributes: $this->attributes($method),
                lineStart: $method->getStartLine(),
                lineEnd: $method->getEndLine(),
                static: $method->isStatic(),
                abstract: $method->isAbstract(),
            );
        }

        return $methods;
    }

    private function visibility(ClassMethod $method): string
    {
        return match (true) {
            $method->isPrivate() => 'private',
            $method->isProtected() => 'protected',
            default => 'public',
        };
    }

    /**
     * @param array<Param> $params
     *
     * @return list<ParameterShape>
     */
    private function parameters(array $params): array
    {
        $parameters = [];

        foreach ($params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $parameters[] = new ParameterShape(
                name: $param->var->name,
                type: $param->type === null ? null : $this->types->render($param->type),
                nullable: $param->type instanceof NullableType,
                default: $param->default === null ? null : $this->printer->prettyPrintExpr($param->default),
                promoted: $param->flags !== 0,
                variadic: $param->variadic,
            );
        }

        return $parameters;
    }

    /**
     * Fully qualified names of the PHP attributes declared on a method.
     *
     * @return list<string>
     */
    private function attributes(ClassMethod $method): array
    {
        $attributes = [];

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = $this->types->resolve($attr->name);
            }
        }

        return $attributes;
    }

    /**
     * Class-typed constructor parameters, promoted or not.
     *
     * Nullable types are unwrapped (`?UserService` is still a dependency
     * on UserService) and builtin-typed parameters are excluded.
     *
     * @return list<DependencyShape>
     */
    private function dependencies(Class_ $class): array
    {
        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return [];
        }

        $dependencies = [];

        foreach ($constructor->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $type = $param->type instanceof NullableType ? $param->type->type : $param->type;

            if (!$type instanceof Name) {
                continue;
            }

            $fqcn = $this->types->resolve($type);

            if (TypeRenderer::isBuiltin($fqcn)) {
                continue;
            }

            $dependencies[] = new DependencyShape(
                fqcn: $fqcn,
                parameter: $param->var->name,
                promoted: $param->flags !== 0,
            );
        }

        return $dependencies;
    }
}
