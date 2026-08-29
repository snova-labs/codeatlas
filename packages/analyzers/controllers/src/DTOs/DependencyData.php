<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\DTOs;

/**
 * A constructor-injected dependency per the controllers result schema.
 */
final readonly class DependencyData
{
    public function __construct(
        public string $fqcn,
        public string $parameter,
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
