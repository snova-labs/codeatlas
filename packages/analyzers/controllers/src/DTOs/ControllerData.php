<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\DTOs;

use CodeAtlas\Core\Ast\ClassShape;
use CodeAtlas\Core\Ast\DependencyShape;
use CodeAtlas\Core\Ast\MethodShape;

/**
 * A fully extracted controller class, mapped 1:1 to the controllers
 * result schema in JSON_SCHEMA.md. The node ID is controller::{fqcn} —
 * exactly what the route analyzer's RoutesTo edges target.
 *
 * Methods, parameters, and dependencies are carried as core AST shapes
 * rather than controller-specific copies, so every analyzer emits them
 * in the same wire format and the UI can render one method signature
 * component for all of them.
 */
final readonly class ControllerData
{
    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     * @param list<MethodShape> $methods
     * @param list<DependencyShape> $dependencies
     */
    public function __construct(
        public string $fqcn,
        public string $name,
        public ?string $namespace,
        public ?string $parent,
        public array $interfaces,
        public array $traits,
        public array $methods,
        public array $dependencies,
        public bool $abstract,
        public bool $invokable,
        public int $line,
    ) {}

    /**
     * Build controller data from a generic class shape.
     *
     * The constructor is excluded from $methods: it is represented by
     * $dependencies, which is what the schema's "dependencies" key holds.
     */
    public static function fromShape(ClassShape $shape): self
    {
        return new self(
            fqcn: $shape->fqcn,
            name: $shape->name,
            namespace: $shape->namespace,
            parent: $shape->parent,
            interfaces: $shape->interfaces,
            traits: $shape->traits,
            methods: $shape->declaredMethods(),
            dependencies: $shape->dependencies,
            abstract: $shape->abstract,
            invokable: $shape->invokable(),
            line: $shape->lineStart,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'name' => $this->name,
            'namespace' => $this->namespace,
            'parent' => $this->parent,
            'interfaces' => $this->interfaces,
            'traits' => $this->traits,
            'methods' => array_map(
                static fn(MethodShape $m): array => $m->toArray(),
                $this->methods,
            ),
            'dependencies' => array_map(
                static fn(DependencyShape $d): array => $d->toArray(),
                $this->dependencies,
            ),
            'abstract' => $this->abstract,
            'invokable' => $this->invokable,
        ];
    }
}
