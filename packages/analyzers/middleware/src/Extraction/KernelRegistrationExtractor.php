<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware\Extraction;

use CodeAtlas\Analyzers\Middleware\DTOs\RegistrationData;
use CodeAtlas\Contracts\ParsedFileInterface;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;

/**
 * Extracts middleware registration from a classic app/Http/Kernel.php:
 *
 *   protected $middleware = [TrustProxies::class, ...];          // global
 *   protected $middlewareGroups = ['web' => [...], 'api' => [...]];
 *   protected $middlewareAliases = ['auth' => Authenticate::class];
 *   protected $routeMiddleware   = [...];                        // pre-10 name
 *   protected $middlewarePriority = [...];
 *
 * Property default values are read straight off the AST — the kernel is
 * never instantiated (constitution: no code execution).
 */
final class KernelRegistrationExtractor
{
    public function __construct(private readonly ParsedFileInterface $file) {}

    public function extract(): RegistrationData
    {
        $class = $this->file->findNodes(Class_::class)[0] ?? null;

        if ($class === null) {
            return new RegistrationData();
        }

        $aliases = [];
        $groups = [];
        $global = [];
        $priority = [];

        foreach ($class->getProperties() as $property) {
            $name = $this->propertyName($property);
            $default = $property->props[0]->default ?? null;

            if ($name === null || !$default instanceof Array_) {
                continue;
            }

            switch ($name) {
                case 'middleware':
                    $global = $this->classList($default);
                    break;
                case 'middlewareGroups':
                    $groups = $this->groupMap($default);
                    break;
                case 'middlewareAliases':
                case 'routeMiddleware':
                    $aliases = [...$aliases, ...$this->classMap($default)];
                    break;
                case 'middlewarePriority':
                    $priority = $this->classList($default);
                    break;
            }
        }

        return new RegistrationData(
            aliases: $aliases,
            groups: $groups,
            global: $global,
            priority: $priority,
        );
    }

    private function propertyName(Property $property): ?string
    {
        $prop = $property->props[0] ?? null;

        return $prop?->name instanceof \PhpParser\Node\VarLikeIdentifier ? $prop->name->toString() : null;
    }

    /**
     * @return array<string, string>
     */
    private function classMap(Array_ $array): array
    {
        $map = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $key = $item->key instanceof String_ ? $item->key->value : null;
            $fqcn = $this->fqcn($item->value);

            if ($key !== null && $fqcn !== null) {
                $map[$key] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    private function groupMap(Array_ $array): array
    {
        $groups = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $group = $item->key instanceof String_ ? $item->key->value : null;

            if ($group === null || !$item->value instanceof Array_) {
                continue;
            }

            $groups[$group] = $this->entryList($item->value);
        }

        return $groups;
    }

    /**
     * Group entries may be FQCNs (X::class) or alias strings ('throttle:api').
     *
     * @return list<string>
     */
    private function entryList(Array_ $array): array
    {
        $out = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $fqcn = $this->fqcn($item->value);

            if ($fqcn !== null) {
                $out[] = $fqcn;

                continue;
            }

            if ($item->value instanceof String_) {
                $out[] = $item->value->value;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function classList(Array_ $array): array
    {
        $out = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $fqcn = $this->fqcn($item->value);

            if ($fqcn !== null) {
                $out[] = $fqcn;
            }
        }

        return $out;
    }

    private function fqcn(Expr $expr): ?string
    {
        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name) {
            return null;
        }

        $const = $expr->name;
        if (!$const instanceof Identifier || $const->toString() !== 'class') {
            return null;
        }

        // \Absolute\Refs parse as FullyQualified — already resolved; running
        // them through use-statement resolution would wrongly prepend the
        // file's namespace.
        if ($expr->class->isFullyQualified()) {
            return ltrim($expr->class->toString(), '\\');
        }

        return $this->file->resolveClassName($expr->class->toString());
    }
}
