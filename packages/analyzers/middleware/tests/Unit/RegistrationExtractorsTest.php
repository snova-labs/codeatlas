<?php

declare(strict_types=1);

use CodeAtlas\Analyzers\Middleware\Extraction\BootstrapRegistrationExtractor;
use CodeAtlas\Analyzers\Middleware\Extraction\ClassExtractor;
use CodeAtlas\Analyzers\Middleware\Extraction\KernelRegistrationExtractor;
use CodeAtlas\Core\Parser\PhpParser;

function mwFixtures(): string
{
    return __DIR__ . '/../Fixtures';
}

describe('ClassExtractor', function (): void {
    it('extracts the FQCN and post-$next handle() parameters', function (): void {
        $parsed = (new PhpParser())->parse(mwFixtures() . '/l11-app/app/Http/Middleware/EnsureUserIsAdmin.php');
        $result = (new ClassExtractor())->extract($parsed);

        expect($result)->not->toBeNull();
        expect($result['fqcn'])->toBe('App\\Http\\Middleware\\EnsureUserIsAdmin');
        expect($result['parameters'])->toBe(['level']);
    });

    it('returns an empty parameter list when handle() has nothing after $next', function (): void {
        $parsed = (new PhpParser())->parse(mwFixtures() . '/l11-app/app/Http/Middleware/ForceJsonResponse.php');
        expect((new ClassExtractor())->extract($parsed)['parameters'])->toBe([]);
    });
});

describe('BootstrapRegistrationExtractor — Laravel 11 style', function (): void {
    $reg = (new BootstrapRegistrationExtractor(
        (new PhpParser())->parse(mwFixtures() . '/l11-app/bootstrap/app.php'),
    ))->extract();

    it('extracts alias() with resolved FQCNs', function () use ($reg): void {
        expect($reg->aliases)->toBe([
            'admin' => 'App\\Http\\Middleware\\EnsureUserIsAdmin',
            'track' => 'App\\Http\\Middleware\\TrackLastActivity',
        ]);
    });

    it('treats append() as global registration', function () use ($reg): void {
        expect($reg->global)->toBe(['App\\Http\\Middleware\\LogRequests']);
    });

    it('extracts appendToGroup() and the web()/api() shorthands', function () use ($reg): void {
        expect($reg->groups['api'])->toBe(['App\\Http\\Middleware\\ForceJsonResponse']);
        expect($reg->groups['web'])->toBe(['App\\Http\\Middleware\\TrackLastActivity']);
    });

    it('preserves priority() order', function () use ($reg): void {
        expect($reg->priority)->toBe([
            'App\\Http\\Middleware\\LogRequests',
            'App\\Http\\Middleware\\EnsureUserIsAdmin',
        ]);
    });
});

describe('KernelRegistrationExtractor — Laravel ≤10 style', function (): void {
    $reg = (new KernelRegistrationExtractor(
        (new PhpParser())->parseString(
            (string) file_get_contents(mwFixtures() . '/kernel-style/Kernel.php.txt'),
            'app/Http/Kernel.php',
        ),
    ))->extract();

    it('reads the $middleware property as the global list', function () use ($reg): void {
        expect($reg->global)->toBe([
            'App\\Http\\Middleware\\TrustProxies',
            'App\\Http\\Middleware\\TrimStrings',
        ]);
    });

    it('reads $middlewareAliases including absolute FQCN references', function () use ($reg): void {
        expect($reg->aliases['auth'])->toBe('App\\Http\\Middleware\\Authenticate');
        expect($reg->aliases['throttle'])->toBe('Illuminate\\Routing\\Middleware\\ThrottleRequests');
    });

    it('reads groups containing both FQCNs and alias strings', function () use ($reg): void {
        expect($reg->groups['web'])->toBe(['App\\Http\\Middleware\\EncryptCookies', 'throttle:web']);
        expect($reg->groups['api'])->toBe(['throttle:api']);
    });

    it('reads $middlewarePriority', function () use ($reg): void {
        expect($reg->priority)->toBe(['App\\Http\\Middleware\\Authenticate']);
    });
});
