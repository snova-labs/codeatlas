<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

/**
 * Everything structurally interesting about one class-like declaration:
 * identity, hierarchy, traits, methods, and constructor dependencies.
 *
 * This is a neutral AST fact, deliberately not shaped like any one
 * analyzer's JSON result. Analyzers consume a ClassShape and map it onto
 * their own DTOs, which is where domain meaning (is this a service? which
 * model does it query?) gets added.
 */
final readonly class ClassShape
{
    /**
     * @param list<string> $interfaces Fully qualified interface names
     * @param list<string> $traits Fully qualified trait names
     * @param list<MethodShape> $methods Every declared method, constructor included
     * @param list<DependencyShape> $dependencies Class-typed constructor parameters
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
        public bool $final,
        public int $lineStart,
        public int $lineEnd,
    ) {}

    /**
     * The constructor, if the class declares one.
     */
    public function constructor(): ?MethodShape
    {
        foreach ($this->methods as $method) {
            if ($method->name === '__construct') {
                return $method;
            }
        }

        return null;
    }

    /**
     * Every method except the constructor.
     *
     * Constructors are represented by $dependencies, so most analyzers
     * want this rather than the raw list.
     *
     * @return list<MethodShape>
     */
    public function declaredMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(MethodShape $m): bool => $m->name !== '__construct',
        ));
    }

    /**
     * Does the class declare a method with this name?
     */
    public function hasMethod(string $name): bool
    {
        foreach ($this->methods as $method) {
            if ($method->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this an invokable class (single-action controller, handler, …)?
     */
    public function invokable(): bool
    {
        return $this->hasMethod('__invoke');
    }

    /**
     * The constructor dependency injected under this parameter name.
     */
    public function dependencyFor(string $parameter): ?DependencyShape
    {
        foreach ($this->dependencies as $dependency) {
            if ($dependency->parameter === $parameter) {
                return $dependency;
            }
        }

        return null;
    }
}
