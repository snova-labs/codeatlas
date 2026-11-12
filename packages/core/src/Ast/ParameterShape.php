<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

/**
 * One parameter of a method or constructor, as written in source.
 *
 * A neutral AST fact: analyzers map this onto whatever their own result
 * schema calls for.
 */
final readonly class ParameterShape
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $nullable,
        public ?string $default,
        public bool $promoted = false,
        public bool $variadic = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
        ];
    }
}
