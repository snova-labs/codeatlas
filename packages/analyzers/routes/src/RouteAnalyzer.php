<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Routes;

use CodeAtlas\Analyzers\Routes\DTOs\RouteData;
use CodeAtlas\Analyzers\Routes\Extraction\RouteExtractor;
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
 * Extracts Laravel routes from a project and emits graph nodes and edges.
 *
 * For every route file discovered by the scanner, the analyzer parses the
 * file to an AST (via the core parser — never regex), extracts route
 * definitions with full group-context resolution, and produces:
 *
 *   - one Route node per route
 *   - a Route → Controller edge (RoutesTo) when the handler is a controller
 *   - a Route → Middleware edge (UsesMiddleware) per applied middleware
 *
 * Fault isolation: a malformed route file never aborts the run. Parse
 * failures are caught per file, logged as warnings, and recorded as an
 * AnalysisError; the remaining files are still analyzed. This is the
 * constitution's per-file resilience guarantee.
 */
final class RouteAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ParserInterface $parser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function name(): string
    {
        return 'routes';
    }

    public function supportedNodeTypes(): array
    {
        return [NodeType::Route];
    }

    public function analyze(ProjectContext $context): AnalysisResult
    {
        $nodes = [];
        $edges = [];
        $errors = [];
        $routeCount = 0;
        $filesAnalyzed = 0;
        $filesSkipped = 0;

        foreach ($context->filesOfType(FileType::Route) as $file) {
            try {
                $routes = $this->analyzeFile($file);
                $filesAnalyzed++;
            } catch (ParserException $e) {
                $filesSkipped++;
                $this->logger->warning('Skipping unparseable route file {path}: {message}', [
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

            foreach ($routes as $route) {
                $nodes[] = $this->routeNode($route, $file);
                $edges = [...$edges, ...$this->routeEdges($route)];
                $routeCount++;
            }
        }

        return new AnalysisResult(
            analyzer: $this->name(),
            nodes: $nodes,
            edges: $edges,
            metadata: [
                'routes' => $routeCount,
                'files_analyzed' => $filesAnalyzed,
                'files_skipped' => $filesSkipped,
            ],
            errors: $errors,
        );
    }

    /**
     * @return list<RouteData>
     *
     * @throws ParserException
     */
    private function analyzeFile(FileReference $file): array
    {
        $parsed = $this->parser->parse($file->absolutePath);
        assert($parsed instanceof ParsedFile);

        $extractor = new RouteExtractor($parsed);

        return $extractor->extract($parsed->ast());
    }

    private function routeNode(RouteData $route, FileReference $file): Node
    {
        return Node::make(
            type: NodeType::Route,
            qualifier: $route->idQualifier(),
            label: $route->label(),
            group: $route->prefix,
            file: $file->withLineRange($route->line ?? 1),
            metadata: $route->toArray(),
            tags: $this->routeTags($route),
        );
    }

    /**
     * @return list<Edge>
     */
    private function routeEdges(RouteData $route): array
    {
        $edges = [];
        $routeNodeId = NodeType::Route->id($route->idQualifier());

        if ($route->controller !== null) {
            $controllerNodeId = NodeType::Controller->id($route->controller);
            $edges[] = Edge::make(
                source: $routeNodeId,
                target: $controllerNodeId,
                type: EdgeType::RoutesTo,
                label: $route->action,
            );
        }

        foreach ($route->middleware as $middleware) {
            // Node IDs identify the middleware itself; runtime parameters
            // ("auth:sanctum") are not part of identity per JSON_SCHEMA.md
            // ("middleware::auth"). The full string stays in route metadata.
            $alias = explode(':', $middleware, 2)[0];
            $middlewareNodeId = NodeType::Middleware->id($alias);
            $edges[] = Edge::make(
                source: $routeNodeId,
                target: $middlewareNodeId,
                type: EdgeType::UsesMiddleware,
            );
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    private function routeTags(RouteData $route): array
    {
        $tags = [];

        if ($route->isClosure) {
            $tags[] = 'closure';
        }

        if ($route->middleware !== []) {
            $tags[] = 'has-middleware';
        }

        if ($route->name !== null) {
            $tags[] = 'named';
        }

        return $tags;
    }
}
