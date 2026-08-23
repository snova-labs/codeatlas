<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware\Extraction;

use CodeAtlas\Contracts\ParsedFileInterface;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Extracts middleware facts from a middleware class file.
 *
 * The interesting parts: the FQCN, and the parameters of handle() that
 * come AFTER $next — those are the runtime middleware parameters
 * ("auth:sanctum" → handle($request, $next, string $guard)).
 */
final class ClassExtractor
{
    /**
     * @return array{fqcn: string, parameters: list<string>, line: int}|null
     */
    public function extract(ParsedFileInterface $file): ?array
    {
        $classes = $file->findNodes(Class_::class);

        foreach ($classes as $class) {
            if ($class->name === null) {
                continue;
            }

            $fqcn = $file->namespace() === null
                ? $class->name->toString()
                : $file->namespace() . '\\' . $class->name->toString();

            return [
                'fqcn' => $fqcn,
                'parameters' => $this->handleParameters($class),
                'line' => $class->getStartLine(),
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function handleParameters(Class_ $class): array
    {
        foreach ($class->getMethods() as $method) {
            if (!$method instanceof ClassMethod || $method->name->toString() !== 'handle') {
                continue;
            }

            $names = [];
            $pastNext = false;

            foreach ($method->params as $param) {
                if (!$param->var instanceof \PhpParser\Node\Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }

                if ($pastNext) {
                    $names[] = $param->var->name;
                }

                if ($param->var->name === 'next') {
                    $pastNext = true;
                }
            }

            return $names;
        }

        return [];
    }
}
