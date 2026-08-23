<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Routes\Extraction;

use CodeAtlas\Contracts\ParsedFileInterface;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * Resolves static-analyzable literal values out of AST argument nodes.
 *
 * Route definitions carry their meaning in literal arguments: string URIs,
 * arrays of middleware, `Controller::class` references. This resolver
 * extracts those literals without ever executing code (constitution: no
 * runtime evaluation). Dynamic expressions it cannot resolve return null,
 * and the caller decides how to degrade gracefully.
 *
 * We depend on the concrete ParsedFile (rather than ParsedFileInterface)
 * because we need resolveClassName(), which the interface intentionally
 * omits to keep the contracts package free of a PhpParser\Node import.
 */
final class ValueResolver
{
    public function __construct(private readonly ParsedFileInterface $file) {}

    /**
     * Resolve an argument to a plain string, if it is a string literal.
     */
    public function stringArg(Arg $arg): ?string
    {
        return $this->stringExpr($arg->value);
    }

    public function stringExpr(Expr $expr): ?string
    {
        return $expr instanceof String_ ? $expr->value : null;
    }

    /**
     * Resolve an argument to a list of strings.
     * Accepts a single string literal or an array of string literals.
     *
     * @return list<string>
     */
    public function stringListArg(Arg $arg): array
    {
        $value = $arg->value;

        if ($value instanceof String_) {
            return [$value->value];
        }

        if ($value instanceof Array_) {
            return $this->stringListFromArray($value);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function stringListFromArray(Array_ $array): array
    {
        $out = [];

        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem && $item->value instanceof String_) {
                $out[] = $item->value->value;
            }
        }

        return $out;
    }

    /**
     * Resolve `Controller::class` to a fully-qualified class name.
     */
    public function classConstFqcn(Expr $expr): ?string
    {
        if (!$expr instanceof ClassConstFetch) {
            return null;
        }

        if (!$expr->class instanceof Name) {
            return null;
        }

        $constName = $expr->name;
        if (!$constName instanceof Identifier || $constName->toString() !== 'class') {
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

    /**
     * Resolve a string→string associative array (used by ->where([...])).
     *
     * @return array<string, string>
     */
    public function stringMapArg(Arg $arg): array
    {
        if (!$arg->value instanceof Array_) {
            return [];
        }

        $out = [];

        foreach ($arg->value->items as $item) {
            if (!$item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $key = $this->stringExpr($item->key);
            $val = $this->stringExpr($item->value);

            if ($key !== null && $val !== null) {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
