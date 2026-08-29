<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Middleware;

use CodeAtlas\Contracts\ContainerInterface;
use CodeAtlas\Contracts\ParserInterface;
use CodeAtlas\Contracts\PluginInterface;

/**
 * Registers the middleware analyzer into the CodeAtlas container.
 */
final class MiddlewareAnalyzerPlugin implements PluginInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(MiddlewareAnalyzer::class, static function (ContainerInterface $c): MiddlewareAnalyzer {
            return new MiddlewareAnalyzer($c->make(ParserInterface::class));
        });
    }
}
