<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Services\DTOs;

use CodeAtlas\Core\Ast\CallSite;
use CodeAtlas\Core\Ast\ClassShape;
use CodeAtlas\Core\Ast\DependencyShape;
use CodeAtlas\Core\Ast\MethodShape;

/**
 * A fully extracted service class, mapped 1:1 to the Services result
 * schema in JSON_SCHEMA.md. The node ID is service::{fqcn} — exactly
 * what the controller analyzer's DependsOn edges already target, so the
 * two graphs join without either analyzer knowing about the other.
 *
 * `interface` is the contract this service is bound to in a service
 * provider, when one binds it. It is null for a service resolved by its
 * concrete class.
 */
final readonly class ServiceData
{
    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     * @param list<MethodShape> $methods
     * @param list<DependencyShape> $dependencies
     * @param array<string, list<CallSite>> $calls Call sites keyed by method name
     */
    public function __construct(
        public string $fqcn,
        public string $name,
        public ?string $namespace,
        public ?string $parent,
        public ?string $interface,
        public array $interfaces,
        public array $traits,
        public array $methods,
        public array $dependencies,
        public array $calls,
        public bool $abstract,
        public int $line,
    ) {}

    /**
     * @param array<string, list<CallSite>> $calls
     */
    public static function fromShape(ClassShape $shape, array $calls, ?string $interface = null): self
    {
        return new self(
            fqcn: $shape->fqcn,
            name: $shape->name,
            namespace: $shape->namespace,
            parent: $shape->parent,
            interface: $interface,
            interfaces: $shape->interfaces,
            traits: $shape->traits,
            methods: $shape->declaredMethods(),
            dependencies: $shape->dependencies,
            calls: $calls,
            abstract: $shape->abstract,
            line: $shape->lineStart,
        );
    }

    /**
     * Every distinct class this service calls into, across all methods.
     *
     * @return list<string>
     */
    public function calledClasses(): array
    {
        $classes = [];

        foreach ($this->calls as $sites) {
            foreach ($sites as $site) {
                $classes[$site->targetFqcn] = true;
            }
        }

        return array_keys($classes);
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
            'interface' => $this->interface,
            'interfaces' => $this->interfaces,
            'traits' => $this->traits,
            'methods' => array_map(
                fn(MethodShape $m): array => [
                    ...$m->toArray(),
                    'calls' => array_map(
                        static fn(CallSite $c): string => $c->reference(),
                        $this->calls[$m->name] ?? [],
                    ),
                ],
                $this->methods,
            ),
            'dependencies' => array_map(
                static fn(DependencyShape $d): array => $d->toArray(),
                $this->dependencies,
            ),
            'abstract' => $this->abstract,
        ];
    }
}
