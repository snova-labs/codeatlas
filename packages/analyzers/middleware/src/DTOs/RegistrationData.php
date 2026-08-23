<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware\DTOs;

/**
 * Raw registration facts extracted from ONE registration source
 * (bootstrap/app.php or app/Http/Kernel.php), before merging.
 */
final readonly class RegistrationData
{
    /**
     * @param array<string, string> $aliases alias => FQCN
     * @param array<string, list<string>> $groups group name => list of FQCN-or-alias entries
     * @param list<string> $global Globally applied middleware FQCNs
     * @param list<string> $priority FQCNs in priority order
     */
    public function __construct(
        public array $aliases = [],
        public array $groups = [],
        public array $global = [],
        public array $priority = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->aliases === [] && $this->groups === [] && $this->global === [] && $this->priority === [];
    }

    /**
     * Merge another registration source into this one (other wins on alias collision).
     */
    public function merge(self $other): self
    {
        $groups = $this->groups;

        foreach ($other->groups as $name => $entries) {
            $groups[$name] = array_values(array_unique([...($groups[$name] ?? []), ...$entries]));
        }

        return new self(
            aliases: [...$this->aliases, ...$other->aliases],
            groups: $groups,
            global: array_values(array_unique([...$this->global, ...$other->global])),
            priority: $other->priority !== [] ? $other->priority : $this->priority,
        );
    }
}
