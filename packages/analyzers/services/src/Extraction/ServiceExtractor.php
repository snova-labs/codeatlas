<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Services\Extraction;

use CodeAtlas\Analyzers\Services\DTOs\ServiceData;
use CodeAtlas\Core\Ast\CallSiteExtractor;
use CodeAtlas\Core\Ast\ClassShapeExtractor;
use CodeAtlas\Core\Parser\ParsedFile;
use PhpParser\Node\Stmt\Class_;

/**
 * Extracts everything the Services result schema needs from one service
 * class: the class shape (identity, hierarchy, traits, methods, typed
 * constructor dependencies) plus the call sites inside each method.
 *
 * The AST work lives in CodeAtlas\Core\Ast; this is the thin
 * services-specific mapping on top of it.
 *
 * Typed against the concrete ParsedFile because the core extractors need
 * its AST helpers, which ParsedFileInterface intentionally omits to keep
 * the contracts package free of a PhpParser\Node import.
 */
final class ServiceExtractor
{
    public function __construct(private readonly ParsedFile $file) {}

    /**
     * @param array<string, string> $bindings Interface FQCN => implementation FQCN
     */
    public function extract(array $bindings = []): ?ServiceData
    {
        $class = $this->file->findNodes(Class_::class)[0] ?? null;

        if ($class === null) {
            return null;
        }

        $shape = (new ClassShapeExtractor($this->file))->shape($class);

        if ($shape === null) {
            return null;
        }

        $calls = (new CallSiteExtractor($this->file))->extract($class);

        return ServiceData::fromShape($shape, $calls, $this->boundInterface($shape->fqcn, $bindings));
    }

    /**
     * The interface this implementation is bound to, if a provider binds
     * one to it.
     *
     * @param array<string, string> $bindings
     */
    private function boundInterface(string $fqcn, array $bindings): ?string
    {
        foreach ($bindings as $abstract => $concrete) {
            if ($concrete === $fqcn) {
                return $abstract;
            }
        }

        return null;
    }
}
