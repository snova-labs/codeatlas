<?php

declare(strict_types=1);

namespace CodeAtlas\Core\Ast;

use CodeAtlas\Core\Parser\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Renders a PhpParser type node back to its source form, resolving class
 * names against the declaring file's use statements.
 *
 * Scalar builtins pass through untouched ("string", "int"); class names
 * come back fully qualified ("Illuminate\Http\Request"); complex types
 * render segment-wise so unions, intersections, and nullables keep their
 * written shape ("string|int", "?Request", "Countable&Traversable").
 *
 * Every analyzer needs this, so it lives in core rather than being
 * reimplemented per analyzer package.
 */
final class TypeRenderer
{
    /**
     * Type names that are language builtins rather than class references.
     */
    private const BUILTINS = [
        'string', 'int', 'float', 'bool', 'array', 'callable', 'iterable',
        'object', 'mixed', 'void', 'never', 'null', 'false', 'true',
        'self', 'static', 'parent',
    ];

    public function __construct(private readonly ParsedFile $file) {}

    /**
     * Render a type node to its source form.
     */
    public function render(Node $type): string
    {
        if ($type instanceof NullableType) {
            return '?' . $this->render($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $glue = $type instanceof UnionType ? '|' : '&';

            return implode($glue, array_map(
                fn(Node $inner): string => $this->render($inner),
                $type->types,
            ));
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return $this->resolve($type);
        }

        // DNF types (PHP 8.2 `(A&B)|null`) and anything else unrecognized.
        if ($type instanceof ComplexType) {
            return 'mixed';
        }

        return 'mixed';
    }

    /**
     * Resolve a name node to a fully qualified class name.
     *
     * Absolute references (`\Fully\Qualified`) are returned as written,
     * minus the leading separator — the file's namespace must not be
     * prepended to them.
     */
    public function resolve(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        return $this->file->resolveClassName($name->toString());
    }

    /**
     * Is this rendered type a language builtin rather than a class reference?
     */
    public static function isBuiltin(string $type): bool
    {
        return in_array(strtolower(ltrim($type, '?')), self::BUILTINS, true);
    }
}
