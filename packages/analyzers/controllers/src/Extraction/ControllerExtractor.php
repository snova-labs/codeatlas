<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\Extraction;

use CodeAtlas\Analyzers\Controllers\DTOs\ControllerData;
use CodeAtlas\Analyzers\Controllers\DTOs\DependencyData;
use CodeAtlas\Analyzers\Controllers\DTOs\MethodData;
use CodeAtlas\Analyzers\Controllers\DTOs\ParameterData;
use CodeAtlas\Contracts\ParsedFileInterface;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\PrettyPrinter\Standard;

/**
 * Extracts everything the controllers result schema needs from one
 * controller class: identity, inheritance, interfaces, traits, methods
 * (with typed parameters, return types, and PHP attributes), and
 * constructor-injected dependencies — all off the AST.
 *
 * Type names are resolved against the file's use statements; scalar
 * builtins pass through untouched. Complex types render to their source
 * form ("string|int", "?Request") via segment-wise resolution.
 */
final class ControllerExtractor
{
    private readonly Standard $printer;

    public function __construct(private readonly ParsedFileInterface $file)
    {
        $this->printer = new Standard();
    }

    public function extract(): ?ControllerData
    {
        $class = $this->file->findNodes(Class_::class)[0] ?? null;

        if ($class === null || $class->name === null) {
            return null;
        }

        $namespace = $this->file->namespace();
        $name = $class->name->toString();
        $fqcn = $namespace === null ? $name : $namespace . '\\' . $name;

        $methods = $this->methods($class);
        $invokable = false;

        foreach ($methods as $method) {
            if ($method->name === '__invoke') {
                $invokable = true;
            }
        }

        return new ControllerData(
            fqcn: $fqcn,
            name: $name,
            namespace: $namespace,
            parent: $class->extends instanceof Name ? $this->resolveName($class->extends) : null,
            interfaces: array_map($this->resolveName(...), $class->implements),
            traits: $this->traits($class),
            methods: $methods,
            dependencies: $this->dependencies($class),
            abstract: $class->isAbstract(),
            invokable: $invokable,
            line: $class->getStartLine(),
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
                $traits[] = $this->resolveName($trait);
            }
        }

        return $traits;
    }

    /**
     * @return list<MethodData>
     */
    private function methods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === '__construct') {
                continue;
            }

            $methods[] = new MethodData(
                name: $method->name->toString(),
                visibility: $this->visibility($method),
                parameters: array_values(array_filter(array_map(
                    $this->parameter(...),
                    $method->params,
                ))),
                returnType: $method->returnType === null ? null : $this->typeToString($method->returnType),
                attributes: $this->attributes($method),
                lineStart: $method->getStartLine(),
                lineEnd: $method->getEndLine(),
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

    private function parameter(Param $param): ?ParameterData
    {
        if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
            return null;
        }

        return new ParameterData(
            name: $param->var->name,
            type: $param->type === null ? null : $this->typeToString($param->type),
            nullable: $param->type instanceof NullableType,
            default: $param->default === null ? null : $this->printer->prettyPrintExpr($param->default),
        );
    }

    /**
     * @return list<string>
     */
    private function attributes(ClassMethod $method): array
    {
        $attributes = [];

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = $this->resolveName($attr->name);
            }
        }

        return $attributes;
    }

    /**
     * @return list<DependencyData>
     */
    private function dependencies(Class_ $class): array
    {
        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return [];
        }

        $dependencies = [];

        foreach ($constructor->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $type = $param->type instanceof NullableType ? $param->type->type : $param->type;

            if (!$type instanceof Name) {
                continue;
            }

            $fqcn = $this->resolveName($type);

            if ($this->isBuiltin($fqcn)) {
                continue;
            }

            $dependencies[] = new DependencyData(fqcn: $fqcn, parameter: $param->var->name);
        }

        return $dependencies;
    }

    private function typeToString(Node $type): string
    {
        if ($type instanceof NullableType) {
            return '?' . $this->typeToString($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $glue = $type instanceof UnionType ? '|' : '&';

            return implode($glue, array_map(
                fn (Node $inner): string => $this->typeToString($inner),
                $type->types,
            ));
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return $this->resolveName($type);
        }

        if ($type instanceof ComplexType) {
            return 'mixed';
        }

        return 'mixed';
    }

    private function resolveName(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        return $this->file->resolveClassName($name->toString());
    }

    private function isBuiltin(string $type): bool
    {
        return in_array(strtolower($type), [
            'string', 'int', 'float', 'bool', 'array', 'callable',
            'iterable', 'object', 'mixed', 'self', 'static', 'parent', 'null',
        ], true);
    }
}
