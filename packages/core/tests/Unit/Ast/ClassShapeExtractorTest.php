<?php

declare(strict_types=1);

use CodeAtlas\Core\Ast\ClassShape;
use CodeAtlas\Core\Ast\ClassShapeExtractor;
use CodeAtlas\Core\Ast\MethodShape;
use CodeAtlas\Core\Parser\ParsedFile;
use CodeAtlas\Core\Parser\PhpParser;

function astFixture(string $name = 'RichClass'): ParsedFile
{
    $code = (string) file_get_contents(__DIR__ . '/../../Fixtures/Ast/' . $name . '.php.txt');
    $parsed = (new PhpParser())->parseString($code);
    assert($parsed instanceof ParsedFile);

    return $parsed;
}

function userServiceShape(): ClassShape
{
    foreach ((new ClassShapeExtractor(astFixture()))->extractAll() as $shape) {
        if ($shape->name === 'UserService') {
            return $shape;
        }
    }

    throw new RuntimeException('UserService fixture shape not found');
}

function shapeMethod(ClassShape $shape, string $name): MethodShape
{
    foreach ($shape->methods as $method) {
        if ($method->name === $name) {
            return $method;
        }
    }

    throw new RuntimeException("Method {$name} not found");
}

describe('ClassShapeExtractor — identity and hierarchy', function (): void {
    it('extracts every named class in the file', function (): void {
        $shapes = (new ClassShapeExtractor(astFixture()))->extractAll();

        expect(array_map(static fn(ClassShape $s): string => $s->name, $shapes))
            ->toEqualCanonicalizing(['BaseService', 'UserService']);
    });

    it('builds the fully qualified name from the file namespace', function (): void {
        expect(userServiceShape()->fqcn)->toBe('App\\Services\\UserService');
        expect(userServiceShape()->namespace)->toBe('App\\Services');
    });

    it('resolves the parent class through the file namespace', function (): void {
        expect(userServiceShape()->parent)->toBe('App\\Services\\BaseService');
    });

    it('resolves implemented interfaces through use statements', function (): void {
        expect(userServiceShape()->interfaces)->toBe(['App\\Support\\Contracts\\Auditable']);
    });

    it('resolves used traits through use statements', function (): void {
        expect(userServiceShape()->traits)->toBe(['App\\Support\\Concerns\\LogsActivity']);
    });

    it('records abstract and final modifiers', function (): void {
        $shapes = (new ClassShapeExtractor(astFixture()))->extractAll();
        $byName = [];

        foreach ($shapes as $shape) {
            $byName[$shape->name] = $shape;
        }

        expect($byName['BaseService']->abstract)->toBeTrue();
        expect($byName['BaseService']->final)->toBeFalse();
        expect($byName['UserService']->abstract)->toBeFalse();
        expect($byName['UserService']->final)->toBeTrue();
    });

    it('reports a line range covering the whole declaration', function (): void {
        $shape = userServiceShape();

        expect($shape->lineStart)->toBeGreaterThan(0);
        expect($shape->lineEnd)->toBeGreaterThan($shape->lineStart);
    });

    it('returns null when the file declares no named class', function (): void {
        $parsed = (new PhpParser())->parseString('<?php $x = new class {};');
        assert($parsed instanceof ParsedFile);

        expect((new ClassShapeExtractor($parsed))->extract())->toBeNull();
    });
});

describe('ClassShapeExtractor — methods', function (): void {
    it('captures every declared method including the constructor', function (): void {
        expect(array_map(static fn(MethodShape $m): string => $m->name, userServiceShape()->methods))
            ->toEqualCanonicalizing(['__construct', 'createUser', 'findOrFail', 'tag']);
    });

    it('excludes the constructor from declaredMethods()', function (): void {
        expect(array_map(static fn(MethodShape $m): string => $m->name, userServiceShape()->declaredMethods()))
            ->toEqualCanonicalizing(['createUser', 'findOrFail', 'tag']);
    });

    it('records visibility', function (): void {
        $shape = userServiceShape();

        expect(shapeMethod($shape, 'createUser')->visibility)->toBe('public');
        expect(shapeMethod($shape, 'findOrFail')->visibility)->toBe('protected');
        expect(shapeMethod($shape, 'tag')->visibility)->toBe('private');
    });

    it('records static and abstract flags', function (): void {
        $shape = userServiceShape();

        expect(shapeMethod($shape, 'tag')->static)->toBeTrue();
        expect(shapeMethod($shape, 'createUser')->static)->toBeFalse();
        expect(shapeMethod($shape, 'createUser')->abstract)->toBeFalse();
    });

    it('resolves PHP attributes to fully qualified names', function (): void {
        expect(shapeMethod(userServiceShape(), 'createUser')->attributes)->toBe(['App\\Services\\Deprecated']);
    });

    it('renders return types, resolving classes and keeping intersections', function (): void {
        $shape = userServiceShape();

        expect(shapeMethod($shape, 'createUser')->returnType)->toBe('App\\Models\\User');
        expect(shapeMethod($shape, 'findOrFail')->returnType)
            ->toBe('App\\Models\\User&App\\Support\\Contracts\\Auditable');
        expect(shapeMethod($shape, 'tag')->returnType)->toBe('string');
    });
});

describe('ClassShapeExtractor — parameters', function (): void {
    it('renders builtin, union, nullable, and aliased parameter types', function (): void {
        $shape = userServiceShape();

        $create = shapeMethod($shape, 'createUser')->parameters;
        expect($create[0]->name)->toBe('data');
        expect($create[0]->type)->toBe('array');
        expect($create[1]->name)->toBe('request');
        expect($create[1]->type)->toBe('?Illuminate\\Http\\Request');
        expect($create[1]->nullable)->toBeTrue();

        expect(shapeMethod($shape, 'findOrFail')->parameters[0]->type)->toBe('int|string');
    });

    it('pretty-prints default values', function (): void {
        $params = shapeMethod(userServiceShape(), 'createUser')->parameters;

        expect($params[0]->default)->toBeNull();
        expect($params[1]->default)->toBe('null');
    });

    it('flags promoted constructor parameters', function (): void {
        $params = shapeMethod(userServiceShape(), '__construct')->parameters;
        $promoted = [];

        foreach ($params as $param) {
            $promoted[$param->name] = $param->promoted;
        }

        expect($promoted['users'])->toBeTrue();
        expect($promoted['mailer'])->toBeTrue();
        expect($promoted['logger'])->toBeFalse();
    });
});

describe('ClassShapeExtractor — constructor dependencies', function (): void {
    it('collects class-typed constructor parameters, promoted or not', function (): void {
        $deps = [];

        foreach (userServiceShape()->dependencies as $dependency) {
            $deps[$dependency->parameter] = $dependency->fqcn;
        }

        expect($deps)->toBe([
            'users' => 'App\\Repositories\\UserRepository',
            'mailer' => 'App\\Services\\EmailService',
            'logger' => 'Psr\\Log\\LoggerInterface',
            'cache' => 'App\\Services\\CacheService',
        ]);
    });

    it('excludes builtin-typed constructor parameters', function (): void {
        $names = array_map(
            static fn(object $d): string => $d->parameter,
            userServiceShape()->dependencies,
        );

        expect($names)->not->toContain('connection');
    });

    it('unwraps nullable dependency types', function (): void {
        expect(userServiceShape()->dependencyFor('cache')?->fqcn)->toBe('App\\Services\\CacheService');
    });

    it('does not prepend the file namespace to absolute class references', function (): void {
        expect(userServiceShape()->dependencyFor('mailer')?->fqcn)->toBe('App\\Services\\EmailService');
    });

    it('returns no dependencies for a class without a constructor', function (): void {
        $parsed = (new PhpParser())->parseString('<?php namespace App; class Bare { public function go(): void {} }');
        assert($parsed instanceof ParsedFile);

        expect((new ClassShapeExtractor($parsed))->extract()?->dependencies)->toBe([]);
    });
});

describe('ClassShape — convenience accessors', function (): void {
    it('finds the constructor', function (): void {
        expect(userServiceShape()->constructor()?->name)->toBe('__construct');
    });

    it('detects invokable classes', function (): void {
        expect(userServiceShape()->invokable())->toBeFalse();

        $parsed = (new PhpParser())->parseString('<?php namespace App; class Action { public function __invoke(): void {} }');
        assert($parsed instanceof ParsedFile);

        expect((new ClassShapeExtractor($parsed))->extract()?->invokable())->toBeTrue();
    });

    it('answers hasMethod()', function (): void {
        expect(userServiceShape()->hasMethod('createUser'))->toBeTrue();
        expect(userServiceShape()->hasMethod('nope'))->toBeFalse();
    });

    it('returns null for an unknown dependency parameter', function (): void {
        expect(userServiceShape()->dependencyFor('nope'))->toBeNull();
    });
});
