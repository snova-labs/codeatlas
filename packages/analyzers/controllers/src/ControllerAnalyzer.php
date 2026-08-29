<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers;

use CodeAtlas\Analyzers\Controllers\DTOs\ControllerData;
use CodeAtlas\Analyzers\Controllers\Extraction\ControllerExtractor;
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
 * Extracts Laravel controllers: methods (with typed parameters, return
 * types, PHP attributes), constructor-injected dependencies, traits,
 * interfaces, and inheritance.
 *
 * Output:
 *   - one controller node per class (ID: controller::{fqcn} — exactly
 *     what the route analyzer's RoutesTo edges target, so the graphs join)
 *   - Extends / Implements / UsesTrait edges
 *   - DependsOn edges for constructor dependencies whose node type is
 *     recognizable by namespace convention (App\Services → service, etc.);
 *     unrecognized dependencies stay in metadata only
 *
 * Methods live in node metadata (the UI's expanded node + inspector view),
 * not as separate graph nodes — method-level nodes arrive with the full
 * dependency graph in v0.7.
 */
final class ControllerAnalyzer implements AnalyzerInterface
{
    /**
     * Namespace prefix → dependency node type, by Laravel convention.
     */
    private const DEPENDENCY_TYPES = [
        'App\\Services\\' => NodeType::Service,
        'App\\Repositories\\' => NodeType::Repository,
        'App\\Models\\' => NodeType::Model,
    ];

    public function __construct(
        private readonly ParserInterface $parser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function name(): string
    {
        return 'controllers';
    }

    public function supportedNodeTypes(): array
    {
        return [NodeType::Controller];
    }

    public function analyze(ProjectContext $context): AnalysisResult
    {
        $nodes = [];
        $edges = [];
        $errors = [];
        $filesAnalyzed = 0;
        $filesSkipped = 0;
        $methodCount = 0;

        foreach ($context->filesOfType(FileType::Controller) as $file) {
            try {
                $parsed = $this->parser->parse($file->absolutePath);
                $filesAnalyzed++;
            } catch (ParserException $e) {
                $filesSkipped++;
                $this->logger->warning('Skipping unparseable controller {path}: {message}', [
                    'path' => $file->path,
                    'message' => $e->getMessage(),
                ]);
                $errors[] = new AnalysisError(
                    analyzer: $this->name(),
                    severity: Severity::Warning,
                    message: "Could not parse {$file->path}: {$e->getMessage()}",
                    file: $file->path,
                    exception: $e::class,
                );

                continue;
            }

            $controller = (new ControllerExtractor($parsed))->extract();

            if ($controller === null) {
                continue;
            }

            $nodes[] = $this->controllerNode($controller, $file);
            $edges = [...$edges, ...$this->controllerEdges($controller)];
            $methodCount += count($controller->methods);
        }

        return new AnalysisResult(
            analyzer: $this->name(),
            nodes: $nodes,
            edges: $edges,
            metadata: [
                'controllers' => count($nodes),
                'methods' => $methodCount,
                'files_analyzed' => $filesAnalyzed,
                'files_skipped' => $filesSkipped,
            ],
            errors: $errors,
        );
    }

    private function controllerNode(ControllerData $controller, FileReference $file): Node
    {
        $tags = [];

        if ($controller->invokable) {
            $tags[] = 'invokable';
        }

        if ($controller->abstract) {
            $tags[] = 'abstract';
        }

        if ($controller->dependencies !== []) {
            $tags[] = 'has-dependencies';
        }

        return Node::make(
            type: NodeType::Controller,
            qualifier: $controller->fqcn,
            label: $controller->name,
            group: $controller->namespace,
            file: $file->withLineRange($controller->line),
            metadata: $controller->toArray(),
            tags: $tags,
        );
    }

    /**
     * @return list<Edge>
     */
    private function controllerEdges(ControllerData $controller): array
    {
        $edges = [];
        $sourceId = NodeType::Controller->id($controller->fqcn);

        if ($controller->parent !== null) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Controller->id($controller->parent),
                type: EdgeType::Extends,
            );
        }

        foreach ($controller->interfaces as $interface) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Controller->id($interface),
                type: EdgeType::Implements,
            );
        }

        foreach ($controller->traits as $trait) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Controller->id($trait),
                type: EdgeType::UsesTrait,
            );
        }

        foreach ($controller->dependencies as $dependency) {
            $type = $this->dependencyNodeType($dependency->fqcn);

            if ($type === null) {
                continue;
            }

            $edges[] = Edge::make(
                source: $sourceId,
                target: $type->id($dependency->fqcn),
                type: EdgeType::DependsOn,
                label: $dependency->parameter,
            );
        }

        return $edges;
    }

    private function dependencyNodeType(string $fqcn): ?NodeType
    {
        foreach (self::DEPENDENCY_TYPES as $prefix => $type) {
            if (str_starts_with($fqcn, $prefix)) {
                return $type;
            }
        }

        return null;
    }
}
