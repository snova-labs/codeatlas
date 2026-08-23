<?php

declare(strict_types=1);

use CodeAtlas\Analyzers\Middleware\MiddlewareAnalyzer;
use CodeAtlas\Analyzers\Routes\RouteAnalyzer;
use CodeAtlas\Contracts\Enums\NodeType;
use CodeAtlas\Core\Parser\PhpParser;
use CodeAtlas\Scanner\Scanner;

function l11App(): string
{
    return __DIR__ . '/../Fixtures/l11-app';
}

describe('MiddlewareAnalyzer — full pipeline', function (): void {
    it('produces nodes for aliased, global, and group-only middleware plus groups', function (): void {
        $context = Scanner::default()->scan(l11App());
        $result = (new MiddlewareAnalyzer(new PhpParser()))->analyze($context);

        $ids = array_map(fn($n): string => $n->id(), $result->nodes);

        expect($ids)->toContain(
            'middleware::admin',
            'middleware::track',
            'middleware::LogRequests',
            'middleware::ForceJsonResponse',
            'middleware_group::api',
            'middleware_group::web',
        );
        expect($result->errors)->toBe([]);
        expect($result->metadata['middleware'])->toBe(4);
        expect($result->metadata['groups'])->toBe(2);
    });

    it('merges registration facts with class discovery', function (): void {
        $context = Scanner::default()->scan(l11App());
        $result = (new MiddlewareAnalyzer(new PhpParser()))->analyze($context);

        $admin = null;
        foreach ($result->nodes as $node) {
            if ($node->id() === 'middleware::admin') {
                $admin = $node;
            }
        }

        expect($admin)->not->toBeNull();
        expect($admin->metadata()['fqcn'])->toBe('App\\Http\\Middleware\\EnsureUserIsAdmin');
        expect($admin->metadata()['parameters'])->toBe(['level']);
        expect($admin->metadata()['priority'])->toBe(2);
        expect($admin->file()?->path)->toBe('app/Http/Middleware/EnsureUserIsAdmin.php');
        expect($admin->tags())->toContain('aliased', 'parameterized');
    });

    it('declares support for middleware and middleware_group node types', function (): void {
        expect((new MiddlewareAnalyzer(new PhpParser()))->supportedNodeTypes())
            ->toBe([NodeType::Middleware, NodeType::MiddlewareGroup]);
    });

    it('joins with the route analyzer: no dangling middleware edge targets', function (): void {
        $context = Scanner::default()->scan(l11App());
        $middleware = (new MiddlewareAnalyzer(new PhpParser()))->analyze($context);
        $routes = (new RouteAnalyzer(new PhpParser()))->analyze($context);

        $middlewareIds = array_map(fn($n): string => $n->id(), $middleware->nodes);

        foreach ($routes->edges as $edge) {
            if (str_starts_with($edge->target(), 'middleware::')) {
                expect($middlewareIds)->toContain($edge->target());
            }
        }
    });

    it('strips runtime parameters from route middleware edge targets', function (): void {
        $context = Scanner::default()->scan(l11App());
        $routes = (new RouteAnalyzer(new PhpParser()))->analyze($context);

        $targets = array_map(fn($e): string => $e->target(), $routes->edges);

        expect($targets)->toContain('middleware::admin');
        expect($targets)->not->toContain('middleware::admin:super');
    });

    it('isolates a broken registration file without losing class analysis', function (): void {
        $tmp = sys_get_temp_dir() . '/mw-test-' . uniqid();
        mkdir($tmp . '/app/Http/Middleware', 0777, true);
        mkdir($tmp . '/bootstrap', 0777, true);
        file_put_contents($tmp . '/composer.json', '{"name":"t/t","require":{"laravel/framework":"^11.0"}}');
        touch($tmp . '/artisan');
        file_put_contents($tmp . '/bootstrap/app.php', "<?php\nreturn broken( {\n");
        file_put_contents(
            $tmp . '/app/Http/Middleware/Ok.php',
            "<?php\nnamespace App\\Http\\Middleware;\nclass Ok { public function handle(\$r, \\Closure \$next) { return \$next(\$r); } }\n",
        );

        try {
            $context = Scanner::default()->scan($tmp);
            $result = (new MiddlewareAnalyzer(new PhpParser()))->analyze($context);

            expect($result->errors)->toHaveCount(1);
            expect(array_map(fn($n): string => $n->id(), $result->nodes))->toContain('middleware::Ok');
        } finally {
            @unlink($tmp . '/bootstrap/app.php');
            @unlink($tmp . '/app/Http/Middleware/Ok.php');
            @unlink($tmp . '/composer.json');
            @unlink($tmp . '/artisan');
            @rmdir($tmp . '/app/Http/Middleware');
            @rmdir($tmp . '/app/Http');
            @rmdir($tmp . '/app');
            @rmdir($tmp . '/bootstrap');
            @rmdir($tmp);
        }
    });
});
