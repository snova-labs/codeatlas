<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

/**
 * A constructor-injected dependency: a class-typed constructor parameter.
 *
 * Builtin-typed parameters (string, int, array, …) are not dependencies
 * and never appear here.
 */
final readonly class DependencyShape
{
    public function __construct(
        public string $fqcn,
        public string $parameter,
        public bool $promoted = false,
        public string $type = 'constructor',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'parameter' => $this->parameter,
            'type' => $this->type,
        ];
    }
}
