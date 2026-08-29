<?php

declare(strict_types=1);

namespace CodeAtlas\Analyzers\Controllers;

use CodeAtlas\Contracts\ContainerInterface;
use CodeAtlas\Contracts\ParserInterface;
use CodeAtlas\Contracts\PluginInterface;

/**
 * Registers the controller analyzer into the CodeAtlas container.
 */
final class ControllerAnalyzerPlugin implements PluginInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(ControllerAnalyzer::class, static function (ContainerInterface $c): ControllerAnalyzer {
            return new ControllerAnalyzer($c->make(ParserInterface::class));
        });
    }
}
