<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers\Extraction;

use CodeAtlas\Analyzers\Controllers\DTOs\ControllerData;
use CodeAtlas\Core\Ast\ClassShapeExtractor;
use CodeAtlas\Core\Parser\ParsedFile;

/**
 * Extracts everything the controllers result schema needs from one
 * controller class: identity, inheritance, interfaces, traits, methods
 * (with typed parameters, return types, and PHP attributes), and
 * constructor-injected dependencies.
 *
 * The AST work itself lives in CodeAtlas\Core\Ast\ClassShapeExtractor,
 * shared with every other class-based analyzer; this class is the thin
 * controllers-specific mapping on top of it.
 *
 * Typed against the concrete ParsedFile (rather than ParsedFileInterface)
 * because the shape extractor needs its AST helpers, which the interface
 * intentionally omits to keep the contracts package free of a
 * PhpParser\Node import.
 */
final class ControllerExtractor
{
    public function __construct(private readonly ParsedFile $file) {}

    public function extract(): ?ControllerData
    {
        $shape = (new ClassShapeExtractor($this->file))->extract();

        return $shape === null ? null : ControllerData::fromShape($shape);
    }
}
