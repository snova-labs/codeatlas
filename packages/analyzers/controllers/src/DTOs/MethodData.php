<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\DTOs;

/**
 * A controller method per the controllers result schema.
 */
final readonly class MethodData
{
    /**
     * @param list<ParameterData> $parameters
     * @param list<string> $attributes Attribute FQCNs
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public array $parameters,
        public ?string $returnType,
        public array $attributes,
        public int $lineStart,
        public int $lineEnd,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'visibility' => $this->visibility,
            'parameters' => array_map(
                static fn (ParameterData $p): array => $p->toArray(),
                $this->parameters,
            ),
            'return_type' => $this->returnType,
            'attributes' => $this->attributes,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
        ];
    }
}
