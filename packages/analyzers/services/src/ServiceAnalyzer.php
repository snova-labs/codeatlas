<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Services;

use CodeAtlas\Analyzers\Services\DTOs\ServiceData;
use CodeAtlas\Analyzers\Services\Extraction\BindingExtractor;
use CodeAtlas\Analyzers\Services\Extraction\ServiceExtractor;
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
use CodeAtlas\Core\Parser\ParsedFile;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extracts Laravel service classes: their constructor dependency chains,
 * the interfaces they are bound to, and — the part that turns a
 * dependency graph into a call graph — which of their methods actually
 * call into which other classes.
 *
 * Detection is twofold:
 *   - convention: files the scanner classified as FileType::Service
 *   - bindings: interface => implementation registered in a service
 *     provider, which both supplies the `interface` field and catches
 *     services living outside app/Services/
 *
 * Output:
 *   - one service node per class (ID: service::{fqcn} — exactly what the
 *     controller analyzer's DependsOn edges already target, so the two
 *     graphs join with no dangling endpoints)
 *   - DependsOn edges for constructor dependencies recognizable by
 *     namespace convention (App\Services → service, App\Repositories →
 *     repository, App\Models → model), labelled with the parameter name
 *   - Calls edges for resolved call sites, labelled with the method
 *     being called
 *   - Extends / Implements / UsesTrait edges, plus the interface:: and
 *     trait:: nodes they reference
 */
final class ServiceAnalyzer implements AnalyzerInterface
{
    /**
     * Namespace prefix → dependency node type, by Laravel convention.
     *
     * Deliberately the same table the controller analyzer uses: the node
     * ID convention is the contract between analyzers, so both must map
     * App\Services\Foo to the same service::App\Services\Foo.
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
        return 'services';
    }

    public function supportedNodeTypes(): array
    {
        return [NodeType::Service, NodeType::Interface, NodeType::Trait];
    }

    public function analyze(ProjectContext $context): AnalysisResult
    {
        $nodes = [];
        $edges = [];
        $errors = [];
        $filesAnalyzed = 0;
        $filesSkipped = 0;
        $serviceCount = 0;
        $callCount = 0;

        [$bindings, $bindingErrors, $providersRead] = $this->bindings($context);
        $errors = [...$errors, ...$bindingErrors];
        $filesAnalyzed += $providersRead;

        foreach ($context->filesOfType(FileType::Service) as $file) {
            try {
                $parsed = $this->parser->parse($file->absolutePath);
                assert($parsed instanceof ParsedFile);
                $filesAnalyzed++;
            } catch (ParserException $e) {
                $filesSkipped++;
                $this->logger->warning('Skipping unparseable service {path}: {message}', [
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

            $service = (new ServiceExtractor($parsed))->extract($bindings);

            if ($service === null) {
                continue;
            }

            $nodes[] = $this->serviceNode($service, $file);
            $nodes = [...$nodes, ...$this->referencedNodes($service)];
            $edges = [...$edges, ...$this->serviceEdges($service)];
            $serviceCount++;

            foreach ($service->calls as $sites) {
                $callCount += count($sites);
            }
        }

        return new AnalysisResult(
            analyzer: $this->name(),
            nodes: $nodes,
            edges: $edges,
            metadata: [
                'services' => $serviceCount,
                'bindings' => count($bindings),
                'call_sites' => $callCount,
                'files_analyzed' => $filesAnalyzed,
                'files_skipped' => $filesSkipped,
            ],
            errors: $errors,
        );
    }

    /**
     * Container bindings across every service provider in the project.
     *
     * @return array{0: array<string, string>, 1: list<AnalysisError>, 2: int}
     */
    private function bindings(ProjectContext $context): array
    {
        $bindings = [];
        $errors = [];
        $read = 0;

        foreach ($context->filesOfType(FileType::Provider) as $file) {
            try {
                $parsed = $this->parser->parse($file->absolutePath);
                assert($parsed instanceof ParsedFile);
                $read++;
            } catch (ParserException $e) {
                $this->logger->warning('Skipping unparseable provider {path}', ['path' => $file->path]);
                $errors[] = new AnalysisError(
                    analyzer: $this->name(),
                    severity: Severity::Warning,
                    message: "Could not parse {$file->path}: {$e->getMessage()}",
                    file: $file->path,
                    exception: $e::class,
                );

                continue;
            }

            $bindings = [...$bindings, ...(new BindingExtractor($parsed))->extract()];
        }

        return [$bindings, $errors, $read];
    }

    private function serviceNode(ServiceData $service, FileReference $file): Node
    {
        $tags = [];

        if ($service->abstract) {
            $tags[] = 'abstract';
        }

        if ($service->interface !== null) {
            $tags[] = 'bound';
        }

        if ($service->dependencies !== []) {
            $tags[] = 'has-dependencies';
        }

        return Node::make(
            type: NodeType::Service,
            qualifier: $service->fqcn,
            label: $service->name,
            group: $service->namespace,
            file: $file->withLineRange($service->line),
            metadata: $service->toArray(),
            tags: $tags,
        );
    }

    /**
     * Nodes for the interfaces and traits this service references. See
     * the referenced-only node convention in JSON_SCHEMA.md.
     *
     * @return list<Node>
     */
    private function referencedNodes(ServiceData $service): array
    {
        $nodes = [];
        $interfaces = $service->interfaces;

        // A bound contract is an interface of this service even when the
        // class does not declare `implements` for it directly.
        if ($service->interface !== null && !in_array($service->interface, $interfaces, true)) {
            $interfaces[] = $service->interface;
        }

        foreach ($interfaces as $interface) {
            $nodes[] = $this->referencedNode(NodeType::Interface, $interface);
        }

        foreach ($service->traits as $trait) {
            $nodes[] = $this->referencedNode(NodeType::Trait, $trait);
        }

        return $nodes;
    }

    private function referencedNode(NodeType $type, string $fqcn): Node
    {
        $position = strrpos($fqcn, '\\');

        return Node::make(
            type: $type,
            qualifier: $fqcn,
            label: $position === false ? $fqcn : substr($fqcn, $position + 1),
            group: $position === false ? null : substr($fqcn, 0, $position),
            metadata: ['fqcn' => $fqcn, 'referenced_only' => true],
            tags: ['referenced'],
        );
    }

    /**
     * @return list<Edge>
     */
    private function serviceEdges(ServiceData $service): array
    {
        $edges = [];
        $sourceId = NodeType::Service->id($service->fqcn);

        if ($service->parent !== null) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Service->id($service->parent),
                type: EdgeType::Extends,
            );
        }

        $interfaces = $service->interfaces;

        if ($service->interface !== null && !in_array($service->interface, $interfaces, true)) {
            $interfaces[] = $service->interface;
        }

        foreach ($interfaces as $interface) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Interface->id($interface),
                type: EdgeType::Implements,
            );
        }

        foreach ($service->traits as $trait) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: NodeType::Trait->id($trait),
                type: EdgeType::UsesTrait,
            );
        }

        foreach ($service->dependencies as $dependency) {
            $type = $this->nodeTypeFor($dependency->fqcn);

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

        return [...$edges, ...$this->callEdges($service, $sourceId)];
    }

    /**
     * One Calls edge per target class.
     *
     * Edge IDs are derived from source, target, and type (see
     * Edge::make), so two edges to the same target would collapse into
     * one in the merged graph and the second label would be lost.
     * Rather than emit edges that silently disappear, each target gets a
     * single edge whose metadata lists every method called on it; the
     * per-call-site detail stays in the node's metadata.
     *
     * @return list<Edge>
     */
    private function callEdges(ServiceData $service, string $sourceId): array
    {
        /** @var array<string, array{type: NodeType, methods: list<string>, lines: list<int>}> $targets */
        $targets = [];

        foreach ($service->calls as $sites) {
            foreach ($sites as $site) {
                $type = $this->nodeTypeFor($site->targetFqcn);

                if ($type === null) {
                    continue;
                }

                $targets[$site->targetFqcn] ??= ['type' => $type, 'methods' => [], 'lines' => []];

                if (!in_array($site->method, $targets[$site->targetFqcn]['methods'], true)) {
                    $targets[$site->targetFqcn]['methods'][] = $site->method;
                    $targets[$site->targetFqcn]['lines'][] = $site->line;
                }
            }
        }

        $edges = [];

        foreach ($targets as $fqcn => $target) {
            $edges[] = Edge::make(
                source: $sourceId,
                target: $target['type']->id($fqcn),
                type: EdgeType::Calls,
                label: $this->callLabel($target['methods']),
                metadata: ['methods' => $target['methods'], 'lines' => $target['lines']],
            );
        }

        return $edges;
    }

    /**
     * A compact edge label: the method name when there is one call, a
     * count when there are several, so the canvas stays readable.
     *
     * @param list<string> $methods
     */
    private function callLabel(array $methods): string
    {
        return count($methods) === 1
            ? $methods[0] . '()'
            : count($methods) . ' methods';
    }

    /**
     * The node type a class FQCN maps to, by namespace convention, or
     * null when it is not something this graph represents.
     */
    private function nodeTypeFor(string $fqcn): ?NodeType
    {
        foreach (self::DEPENDENCY_TYPES as $prefix => $type) {
            if (str_starts_with($fqcn, $prefix)) {
                return $type;
            }
        }

        return null;
    }
}
