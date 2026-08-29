<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\DTOs;

/**
 * A single method parameter per the controllers result schema.
 */
final readonly class ParameterData
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $nullable,
        public ?string $default,
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
