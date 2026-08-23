<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware\DTOs;

/**
 * A single middleware, merged from class discovery + registration.
 *
 * Maps 1:1 to the "middleware" result schema in JSON_SCHEMA.md. The
 * identity is the alias when one exists ("auth"), otherwise the class
 * basename — matching the middleware::{alias} node-ID convention that
 * the route analyzer's UsesMiddleware edges target.
 */
final readonly class MiddlewareData
{
    /**
     * @param list<string> $parameters handle() params after $next (e.g. ["guard"])
     * @param list<string> $groups Groups this middleware belongs to
     */
    public function __construct(
        public string $identity,
        public ?string $alias,
        public ?string $fqcn,
        public array $parameters = [],
        public array $groups = [],
        public ?int $priority = null,
        public bool $global = false,
        public ?string $filePath = null,
        public ?int $line = null,
    ) {}

    public function label(): string
    {
        return $this->alias ?? $this->shortName();
    }

    private function shortName(): string
    {
        if ($this->fqcn === null) {
            return $this->identity;
        }

        $pos = strrpos($this->fqcn, '\\');

        return $pos === false ? $this->fqcn : substr($this->fqcn, $pos + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alias' => $this->alias,
            'fqcn' => $this->fqcn,
            'parameters' => $this->parameters,
            'groups' => $this->groups,
            'priority' => $this->priority,
            'global' => $this->global,
        ];
    }
}
