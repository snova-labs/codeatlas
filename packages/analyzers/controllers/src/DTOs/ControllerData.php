<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\DTOs;

/**
 * A fully extracted controller class, mapped 1:1 to the controllers
 * result schema in JSON_SCHEMA.md. The node ID is controller::{fqcn} —
 * exactly what the route analyzer's RoutesTo edges target.
 */
final readonly class ControllerData
{
    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     * @param list<MethodData> $methods
     * @param list<DependencyData> $dependencies
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
                static fn(MethodData $m): array => $m->toArray(),
                $this->methods,
            ),
            'dependencies' => array_map(
                static fn(DependencyData $d): array => $d->toArray(),
                $this->dependencies,
            ),
            'abstract' => $this->abstract,
            'invokable' => $this->invokable,
        ];
    }
}
