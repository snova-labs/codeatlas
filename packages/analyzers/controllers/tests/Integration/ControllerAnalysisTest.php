<?php

declare(strict_types=1);

use CodeAtlas\Analyzers\Controllers\ControllerAnalyzer;
use CodeAtlas\Analyzers\Middleware\MiddlewareAnalyzer;
use CodeAtlas\Analyzers\Routes\RouteAnalyzer;
use CodeAtlas\Contracts\Enums\EdgeType;
use CodeAtlas\Core\Parser\PhpParser;
use CodeAtlas\Scanner\Scanner;

function fullApp(): string
{
    return __DIR__ . '/../Fixtures/app-full';
}

describe('ControllerAnalyzer — full pipeline', function (): void {
    it('produces controller nodes with schema-conformant IDs and metadata', function (): void {
        $result = (new ControllerAnalyzer(new PhpParser()))->analyze(Scanner::default()->scan(fullApp()));

        expect($result->errors)->toBe([]);
        expect($result->metadata['controllers'])->toBe(3);

        $ids = array_map(fn($n): string => $n->id(), $result->nodes);
        expect($ids)->toContain('controller::App\\Http\\Controllers\\UserController');
    });

    it('emits Extends, Implements, UsesTrait, and typed DependsOn edges', function (): void {
        $result = (new ControllerAnalyzer(new PhpParser()))->analyze(Scanner::default()->scan(fullApp()));

        $byType = [];
        foreach ($result->edges as $e) {
            $byType[$e->type()->value][] = $e;
        }

        expect($byType['extends'])->toHaveCount(2);
        expect($byType['implements'])->toHaveCount(1);
        expect($byType['uses_trait'])->toHaveCount(1);
        expect($byType['depends_on'])->toHaveCount(2);
        expect($byType['depends_on'][0]->target())->toBe('service::App\\Services\\UserService');
        expect($byType['depends_on'][1]->target())->toBe('model::App\\Models\\User');
    });

    it('joins all three analyzers: every RoutesTo edge resolves to a real controller', function (): void {
        $context = Scanner::default()->scan(fullApp());
        $controllers = (new ControllerAnalyzer(new PhpParser()))->analyze($context);
        $routes = (new RouteAnalyzer(new PhpParser()))->analyze($context);
        $middleware = (new MiddlewareAnalyzer(new PhpParser()))->analyze($context);

        $allIds = array_merge(
            array_map(fn($n): string => $n->id(), $controllers->nodes),
            array_map(fn($n): string => $n->id(), $routes->nodes),
            array_map(fn($n): string => $n->id(), $middleware->nodes),
        );

        foreach ($routes->edges as $edge) {
            if ($edge->type() === EdgeType::RoutesTo || $edge->type() === EdgeType::UsesMiddleware) {
                expect($allIds)->toContain($edge->target());
            }
        }
    });

    it('isolates malformed controllers as warnings', function (): void {
        $tmp = sys_get_temp_dir() . '/ctrl-test-' . uniqid();
        mkdir($tmp . '/app/Http/Controllers', 0777, true);
        file_put_contents($tmp . '/composer.json', '{"name":"t/t","require":{"laravel/framework":"^11.0"}}');
        touch($tmp . '/artisan');
        file_put_contents($tmp . '/app/Http/Controllers/Ok.php', "<?php\nnamespace App\\Http\\Controllers;\nclass Ok {}\n");
        file_put_contents($tmp . '/app/Http/Controllers/Broken.php', "<?php\nclass Broken { public function x() { return }\n");

        try {
            $result = (new ControllerAnalyzer(new PhpParser()))->analyze(Scanner::default()->scan($tmp));

            expect($result->errors)->toHaveCount(1);
            expect($result->nodes)->toHaveCount(1);
        } finally {
            foreach (glob($tmp . '/app/Http/Controllers/*') ?: [] as $f) {
                @unlink($f);
            }
            @unlink($tmp . '/composer.json');
            @unlink($tmp . '/artisan');
            @rmdir($tmp . '/app/Http/Controllers');
            @rmdir($tmp . '/app/Http');
            @rmdir($tmp . '/app');
            @rmdir($tmp);
        }
    });
});
