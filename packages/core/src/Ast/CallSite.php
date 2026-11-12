<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

/**
 * One resolved call site inside a method body: who is being called, which
 * method, and how the receiver was reached.
 *
 * Only calls whose receiver resolves to a known class are recorded.
 * Dynamic receivers ($this->{$name}, $container->make(...)->do()) are
 * unresolvable without executing code, so they are skipped rather than
 * guessed at.
 */
final readonly class CallSite
{
    public const KIND_INSTANCE = 'instance';

    public const KIND_STATIC = 'static';

    public function __construct(
        public string $targetFqcn,
        public string $method,
        public int $line,
        public string $kind = self::KIND_INSTANCE,
        public ?string $via = null,
    ) {}

    /**
     * The call as the Services result schema writes it:
     * "App\Repositories\UserRepository::create".
     */
    public function reference(): string
    {
        return $this->targetFqcn . '::' . $this->method;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->targetFqcn,
            'method' => $this->method,
            'kind' => $this->kind,
            'via' => $this->via,
            'line' => $this->line,
        ];
    }
}
