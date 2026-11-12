<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

/**
 * One method of a class-like declaration, as written in source.
 */
final readonly class MethodShape
{
    /**
     * @param list<ParameterShape> $parameters
     * @param list<string> $attributes Fully qualified PHP attribute names
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public array $parameters,
        public ?string $returnType,
        public array $attributes,
        public int $lineStart,
        public int $lineEnd,
        public bool $static = false,
        public bool $abstract = false,
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
                static fn(ParameterShape $p): array => $p->toArray(),
                $this->parameters,
            ),
            'return_type' => $this->returnType,
            'attributes' => $this->attributes,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
        ];
    }
}
