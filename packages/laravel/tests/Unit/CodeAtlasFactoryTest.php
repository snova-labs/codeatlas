<?php

declare(strict_types=1);

use CodeAtlas\Contracts\ParserInterface;
use CodeAtlas\Contracts\ScannerInterface;
use CodeAtlas\Contracts\ValueObjects\ExportConfig;
use CodeAtlas\Core\Plugin\PluginLoader;
use CodeAtlas\Exporters\Json\JsonExporter;
use CodeAtlas\Laravel\CodeAtlasFactory;

describe('CodeAtlasFactory', function (): void {
    it('wires parser, scanner, and all bundled plugins', function (): void {
        ['container' => $container] = CodeAtlasFactory::make();

        expect($container->has(ParserInterface::class))->toBeTrue();
        expect($container->has(ScannerInterface::class))->toBeTrue();
        expect($container->tagged(PluginLoader::TAG_ANALYZER))->toHaveCount(2);
        expect($container->tagged(PluginLoader::TAG_EXPORTER))->toHaveCount(1);
    });

    it('registers the parser as a singleton', function (): void {
        ['container' => $container] = CodeAtlasFactory::make();
        expect($container->make(ParserInterface::class))->toBe($container->make(ParserInterface::class));
    });

    it('runs the full real pipeline against the integration fixture', function (): void {
        ['runner' => $runner] = CodeAtlasFactory::make();
        $appPath = dirname(__DIR__, 3) . '/analyzers/routes/tests/Fixtures/integration-app';

        $result = $runner->run(
            projectPath: $appPath,
            exporters: [JsonExporter::class],
            exportConfig: new ExportConfig(prettyPrint: false),
        );

        expect($result->context->framework)->toBe('laravel');
        expect($result->graph->nodeCount())->toBe(4);
        expect($result->errorCount())->toBe(0);
        expect($result->exports)->toHaveKey('json');
    });

    it('auto-injects project metadata into exported JSON', function (): void {
        ['runner' => $runner] = CodeAtlasFactory::make();
        $appPath = dirname(__DIR__, 3) . '/analyzers/routes/tests/Fixtures/integration-app';

        $result = $runner->run(projectPath: $appPath, exporters: [JsonExporter::class]);

        /** @var array<string, mixed> $doc */
        $doc = json_decode($result->exports['json']->content, true, 512, JSON_THROW_ON_ERROR);
        expect($doc['project']['name'])->toBe('demo/integration-app');
        expect($doc['project']['framework'])->toBe('laravel');
        expect($doc['analysis']['duration_ms'])->toBeInt();
    });
});
