<?php

declare(strict_types=1);

namespace CodeAtlas\Laravel;

use CodeAtlas\Analyzers\Controllers\ControllerAnalyzer;
use CodeAtlas\Analyzers\Controllers\ControllerAnalyzerPlugin;
use CodeAtlas\Analyzers\Middleware\MiddlewareAnalyzer;
use CodeAtlas\Analyzers\Middleware\MiddlewareAnalyzerPlugin;
use CodeAtlas\Analyzers\Routes\RouteAnalyzer;
use CodeAtlas\Analyzers\Routes\RouteAnalyzerPlugin;
use CodeAtlas\Contracts\ContainerInterface;
use CodeAtlas\Contracts\ParserInterface;
use CodeAtlas\Contracts\ScannerInterface;
use CodeAtlas\Core\Container\Container;
use CodeAtlas\Core\Events\EventBus;
use CodeAtlas\Core\Parser\PhpParser;
use CodeAtlas\Core\Pipeline\PipelineRunner;
use CodeAtlas\Core\Plugin\PluginLoader;
use CodeAtlas\Exporters\Json\JsonExporter;
use CodeAtlas\Exporters\Json\JsonExporterPlugin;
use CodeAtlas\Scanner\Scanner;
use PhpParser\Parser as NikicParser;
use PhpParser\ParserFactory as NikicParserFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Bootstraps a fully wired CodeAtlas engine.
 *
 * This is the composition root: it builds the core container, binds the
 * concrete parser and scanner, registers every bundled plugin, tags the
 * analyzers and exporters, and hands back a ready PipelineRunner.
 *
 * The factory itself is framework-free on purpose — the Laravel service
 * provider (and any future Symfony bundle, CLI binary, or test harness)
 * calls this one method instead of duplicating the wiring. That keeps
 * the framework layer thin and this logic runnable anywhere.
 */
final class CodeAtlasFactory
{
    /**
     * @return array{runner: PipelineRunner, container: ContainerInterface, events: EventBus}
     */
    public static function make(?LoggerInterface $logger = null): array
    {
        $logger ??= new NullLogger();

        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(LoggerInterface::class, $logger);

        // nikic/php-parser: Parser is an interface. Bind it to the factory
        // so the CodeAtlas parser wrapper's constructor dependency resolves.
        $container->singleton(
            NikicParser::class,
            static fn(): NikicParser => (new NikicParserFactory())->createForNewestSupportedVersion(),
        );

        $container->singleton(ParserInterface::class, PhpParser::class);
        $container->singleton(ScannerInterface::class, static fn(): Scanner => Scanner::default());

        $loader = new PluginLoader($container);
        $loader->registerMany([
            RouteAnalyzerPlugin::class,
            MiddlewareAnalyzerPlugin::class,
            ControllerAnalyzerPlugin::class,
            JsonExporterPlugin::class,
        ]);
        $loader->tagAnalyzer(RouteAnalyzer::class);
        $loader->tagAnalyzer(MiddlewareAnalyzer::class);
        $loader->tagAnalyzer(ControllerAnalyzer::class);
        $loader->tagExporter(JsonExporter::class);

        $events = new EventBus();

        $runner = new PipelineRunner(
            container: $container,
            scanner: $container->make(ScannerInterface::class),
            events: $events,
            logger: $logger,
        );

        return ['runner' => $runner, 'container' => $container, 'events' => $events];
    }
}
