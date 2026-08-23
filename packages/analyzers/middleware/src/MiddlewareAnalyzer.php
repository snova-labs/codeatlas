<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware;

use CodeAtlas\Analyzers\Middleware\DTOs\MiddlewareData;
use CodeAtlas\Analyzers\Middleware\DTOs\RegistrationData;
use CodeAtlas\Analyzers\Middleware\Extraction\BootstrapRegistrationExtractor;
use CodeAtlas\Analyzers\Middleware\Extraction\ClassExtractor;
use CodeAtlas\Analyzers\Middleware\Extraction\KernelRegistrationExtractor;
use CodeAtlas\Contracts\AnalyzerInterface;
use CodeAtlas\Contracts\Enums\EdgeType;
use CodeAtlas\Contracts\Enums\FileType;
use CodeAtlas\Contracts\Enums\NodeType;
use CodeAtlas\Contracts\Enums\Severity;
use CodeAtlas\Contracts\Exceptions\ParserException;
use CodeAtlas\Contracts\Graph\Edge;
use CodeAtlas\Contracts\Graph\Node;
use CodeAtlas\Contracts\ParserInterface;
use CodeAtlas\Contracts\ValueObjects\AnalysisError;
use CodeAtlas\Contracts\ValueObjects\AnalysisResult;
use CodeAtlas\Contracts\ValueObjects\FileReference;
use CodeAtlas\Contracts\ValueObjects\ProjectContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extracts Laravel middleware: classes, aliases, groups, priority, and
 * global registration — from BOTH registration styles:
 *
 *   - Laravel 11+:  bootstrap/app.php ->withMiddleware(fn (Middleware $m) => ...)
 *   - Laravel ≤10:  app/Http/Kernel.php protected-property arrays
 *
 * Output per JSON_SCHEMA.md:
 *   - one middleware node per alias or discovered class
 *     (ID: middleware::{alias} — exactly what the route analyzer's
 *     UsesMiddleware edges target, so the graphs join up)
 *   - one middleware_group node per group, with UsesMiddleware edges
 *     from the group to each member
 *
 * Per-file fault isolation as always: one unparseable file is a recorded
 * warning, never an aborted run.
 */
final class MiddlewareAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ParserInterface $parser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function name(): string
    {
        return 'middleware';
    }

    public function supportedNodeTypes(): array
    {
        return [NodeType::Middleware, NodeType::MiddlewareGroup];
    }

    public function analyze(ProjectContext $context): AnalysisResult
    {
        $errors = [];
        $filesAnalyzed = 0;
        $filesSkipped = 0;

        // 1. Registration facts (bootstrap/app.php + Kernel.php, merged)
        $registration = new RegistrationData();

        foreach ($this->registrationFiles($context) as $file) {
            try {
                $parsed = $this->parser->parse($file->absolutePath);
                $filesAnalyzed++;
            } catch (ParserException $e) {
                $filesSkipped++;
                $errors[] = $this->skipError($file->path, $e);
                $this->logger->warning('Skipping unparseable registration file {path}', ['path' => $file->path]);

                continue;
            }

            $extracted = str_ends_with($file->path, 'bootstrap/app.php') || $file->path === 'bootstrap/app.php'
                ? (new BootstrapRegistrationExtractor($parsed))->extract()
                : (new KernelRegistrationExtractor($parsed))->extract();

            $registration = $registration->merge($extracted);
        }

        // 2. Class discovery (app/Http/Middleware/*)
        /** @var array<string, array{fqcn: string, parameters: list<string>, line: int, file: FileReference}> $classes */
        $classes = [];

        foreach ($context->filesOfType(FileType::Middleware) as $file) {
            try {
                $parsed = $this->parser->parse($file->absolutePath);
                $filesAnalyzed++;
            } catch (ParserException $e) {
                $filesSkipped++;
                $errors[] = $this->skipError($file->path, $e);
                $this->logger->warning('Skipping unparseable middleware {path}', ['path' => $file->path]);

                continue;
            }

            $extracted = (new ClassExtractor())->extract($parsed);

            if ($extracted !== null) {
                $classes[$extracted['fqcn']] = [...$extracted, 'file' => $file];
            }
        }

        // 3. Merge into MiddlewareData records
        $middleware = $this->mergeMiddleware($registration, $classes);

        // 4. Nodes + edges
        $nodes = [];
        $edges = [];

        foreach ($middleware as $data) {
            $nodes[] = $this->middlewareNode($data, $classes);
        }

        foreach ($registration->groups as $groupName => $members) {
            $groupNodeId = NodeType::MiddlewareGroup->id($groupName);
            $nodes[] = Node::make(
                type: NodeType::MiddlewareGroup,
                qualifier: $groupName,
                label: $groupName,
                metadata: ['members' => $members],
                tags: ['group'],
            );

            foreach ($members as $member) {
                $edges[] = Edge::make(
                    source: $groupNodeId,
                    target: NodeType::Middleware->id($this->identityFor($member, $registration)),
                    type: EdgeType::UsesMiddleware,
                );
            }
        }

        return new AnalysisResult(
            analyzer: $this->name(),
            nodes: $nodes,
            edges: $edges,
            metadata: [
                'middleware' => count($middleware),
                'groups' => count($registration->groups),
                'aliases' => count($registration->aliases),
                'files_analyzed' => $filesAnalyzed,
                'files_skipped' => $filesSkipped,
            ],
            errors: $errors,
        );
    }

    /**
     * @return list<FileReference>
     */
    private function registrationFiles(ProjectContext $context): array
    {
        $out = [];

        foreach ($context->files as $file) {
            if ($file->path === 'bootstrap/app.php' || $file->path === 'app/Http/Kernel.php') {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Combine registration facts and discovered classes into unique records.
     *
     * Identity rules (matching the node-ID convention):
     *   - aliased middleware       → the alias ("auth")
     *   - registered but unaliased → class basename
     *   - discovered but never registered → class basename (still a node;
     *     routes may reference it, and it exists in the codebase)
     *
     * @param array<string, array{fqcn: string, parameters: list<string>, line: int, file: FileReference}> $classes
     *
     * @return list<MiddlewareData>
     */
    private function mergeMiddleware(RegistrationData $registration, array $classes): array
    {
        /** @var array<string, MiddlewareData> $byIdentity */
        $byIdentity = [];

        $fqcnToAlias = array_flip($registration->aliases);
        $priorityIndex = array_flip($registration->priority);

        // Aliased middleware first — alias is the primary identity
        foreach ($registration->aliases as $alias => $fqcn) {
            $class = $classes[$fqcn] ?? null;

            $byIdentity[$alias] = new MiddlewareData(
                identity: $alias,
                alias: $alias,
                fqcn: $fqcn,
                parameters: $class['parameters'] ?? [],
                groups: $this->groupsContaining($fqcn, $alias, $registration),
                priority: isset($priorityIndex[$fqcn]) ? $priorityIndex[$fqcn] + 1 : null,
                global: in_array($fqcn, $registration->global, true),
                filePath: $class !== null ? $class['file']->path : null,
                line: $class['line'] ?? null,
            );
        }

        // Everything else that appears in groups, global list, or on disk
        $seenFqcns = array_values($registration->aliases);
        $others = [...$registration->global];

        foreach ($registration->groups as $members) {
            foreach ($members as $member) {
                if (str_contains($member, '\\')) {
                    $others[] = $member;
                }
            }
        }

        $others = [...$others, ...array_keys($classes)];

        foreach (array_unique($others) as $fqcn) {
            if (in_array($fqcn, $seenFqcns, true) || isset($fqcnToAlias[$fqcn])) {
                continue;
            }

            $identity = $this->basename($fqcn);

            if (isset($byIdentity[$identity])) {
                continue;
            }

            $class = $classes[$fqcn] ?? null;

            $byIdentity[$identity] = new MiddlewareData(
                identity: $identity,
                alias: null,
                fqcn: $fqcn,
                parameters: $class['parameters'] ?? [],
                groups: $this->groupsContaining($fqcn, null, $registration),
                priority: isset($priorityIndex[$fqcn]) ? $priorityIndex[$fqcn] + 1 : null,
                global: in_array($fqcn, $registration->global, true),
                filePath: $class !== null ? $class['file']->path : null,
                line: $class['line'] ?? null,
            );
        }

        return array_values($byIdentity);
    }

    /**
     * @return list<string>
     */
    private function groupsContaining(string $fqcn, ?string $alias, RegistrationData $registration): array
    {
        $groups = [];

        foreach ($registration->groups as $name => $members) {
            foreach ($members as $member) {
                $memberAlias = explode(':', $member, 2)[0];

                if ($member === $fqcn || ($alias !== null && $memberAlias === $alias)) {
                    $groups[] = $name;

                    break;
                }
            }
        }

        return $groups;
    }

    /**
     * @param array<string, array{fqcn: string, parameters: list<string>, line: int, file: FileReference}> $classes
     */
    private function middlewareNode(MiddlewareData $data, array $classes): Node
    {
        $file = null;

        if ($data->fqcn !== null && isset($classes[$data->fqcn])) {
            $file = $classes[$data->fqcn]['file']->withLineRange($data->line ?? 1);
        }

        $tags = [];

        if ($data->global) {
            $tags[] = 'global';
        }

        if ($data->alias !== null) {
            $tags[] = 'aliased';
        }

        if ($data->parameters !== []) {
            $tags[] = 'parameterized';
        }

        return Node::make(
            type: NodeType::Middleware,
            qualifier: $data->identity,
            label: $data->label(),
            file: $file,
            metadata: $data->toArray(),
            tags: $tags,
        );
    }

    /**
     * Resolve a group member entry (FQCN or alias string) to its node identity.
     */
    private function identityFor(string $member, RegistrationData $registration): string
    {
        // Alias entry, possibly with params: 'throttle:api' → throttle
        if (!str_contains($member, '\\')) {
            return explode(':', $member, 2)[0];
        }

        // FQCN entry: prefer its alias if one exists
        $alias = array_search($member, $registration->aliases, true);

        return $alias === false ? $this->basename($member) : $alias;
    }

    private function basename(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function skipError(string $path, ParserException $e): AnalysisError
    {
        return new AnalysisError(
            analyzer: $this->name(),
            severity: Severity::Warning,
            message: "Could not parse {$path}: {$e->getMessage()}",
            file: $path,
            exception: $e::class,
        );
    }
}
