<?php

declare(strict_types=1);

use CodeAtlas\Analyzers\Controllers\DTOs\ControllerData;
use CodeAtlas\Analyzers\Controllers\Extraction\ControllerExtractor;
use CodeAtlas\Core\Parser\PhpParser;

function extractController(string $relative): ControllerData
{
    $path = __DIR__ . '/../Fixtures/app-full/app/Http/Controllers/' . $relative;
    $result = (new ControllerExtractor((new PhpParser())->parse($path)))->extract();

    assert($result !== null);

    return $result;
}

describe('ControllerExtractor — class identity', function (): void {
    $c = extractController('UserController.php');

    it('extracts fqcn, name, and namespace', function () use ($c): void {
        expect($c->fqcn)->toBe('App\\Http\\Controllers\\UserController');
        expect($c->name)->toBe('UserController');
        expect($c->namespace)->toBe('App\\Http\\Controllers');
    });

    it('resolves parent, interfaces, and traits via use statements', function () use ($c): void {
        expect($c->parent)->toBe('App\\Http\\Controllers\\Controller');
        expect($c->interfaces)->toBe(['App\\Contracts\\Auditable']);
        expect($c->traits)->toBe(['App\\Http\\Traits\\HasApiResponse']);
    });
});

describe('ControllerExtractor — constructor dependencies', function (): void {
    it('extracts class-typed constructor params, unwrapping nullables', function (): void {
        $c = extractController('UserController.php');

        expect($c->dependencies)->toHaveCount(2);
        expect($c->dependencies[0]->fqcn)->toBe('App\\Services\\UserService');
        expect($c->dependencies[0]->parameter)->toBe('userService');
        expect($c->dependencies[1]->fqcn)->toBe('App\\Models\\User');
    });
});

describe('ControllerExtractor — methods', function (): void {
    $c = extractController('UserController.php');
    $byName = [];
    foreach ($c->methods as $m) {
        $byName[$m->name] = $m;
    }

    it('excludes the constructor and captures visibility', function () use ($c, $byName): void {
        expect($c->methods)->toHaveCount(3);
        expect($byName['index']->visibility)->toBe('public');
        expect($byName['transform']->visibility)->toBe('protected');
    });

    it('resolves parameter and return types via use statements', function () use ($byName): void {
        expect($byName['index']->returnType)->toBe('Illuminate\\Http\\JsonResponse');
        expect($byName['index']->parameters[0]->type)->toBe('Illuminate\\Http\\Request');
    });

    it('handles scalars, nullables, defaults, and union return types', function () use ($byName): void {
        $show = $byName['show'];
        expect($show->parameters[1]->type)->toBe('int');
        expect($show->parameters[2]->type)->toBe('?string');
        expect($show->parameters[2]->nullable)->toBeTrue();
        expect($show->parameters[2]->default)->toBe("'json'");
        expect($show->returnType)->toBe('Illuminate\\Http\\JsonResponse|string');
    });

    it('resolves PHP attributes via aliased imports', function () use ($byName): void {
        expect($byName['show']->attributes)->toBe(['Illuminate\\Routing\\Controllers\\Middleware']);
    });
});

describe('ControllerExtractor — class flags', function (): void {
    it('flags abstract base controllers', function (): void {
        expect(extractController('Controller.php')->abstract)->toBeTrue();
    });

    it('flags invokable controllers via __invoke', function (): void {
        expect(extractController('HealthController.php')->invokable)->toBeTrue();
    });
});
