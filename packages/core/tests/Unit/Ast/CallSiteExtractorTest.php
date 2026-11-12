<?php

declare(strict_types=1);

use CodeAtlas\Core\Ast\CallSite;
use CodeAtlas\Core\Ast\CallSiteExtractor;
use CodeAtlas\Core\Parser\ParsedFile;
use CodeAtlas\Core\Parser\PhpParser;
use PhpParser\Node\Stmt\Class_;

function callSiteFixture(): ParsedFile
{
    $code = (string) file_get_contents(__DIR__ . '/../../Fixtures/Ast/CallSites.php.txt');
    $parsed = (new PhpParser())->parseString($code);
    assert($parsed instanceof ParsedFile);

    return $parsed;
}

function callSiteClass(): Class_
{
    $parsed = callSiteFixture();
    $class = $parsed->findNodes(Class_::class)[0] ?? null;
    assert($class instanceof Class_);

    return $class;
}

/**
 * @return list<string>
 */
function referencesFor(string $method): array
{
    $calls = (new CallSiteExtractor(callSiteFixture()))->extract(callSiteClass());

    return array_map(
        static fn(CallSite $c): string => $c->reference(),
        $calls[$method] ?? [],
    );
}

describe('CallSiteExtractor — receiver resolution', function (): void {
    it('maps promoted constructor parameters to their class', function (): void {
        $map = (new CallSiteExtractor(callSiteFixture()))->propertyTypes(callSiteClass());

        expect($map['users'])->toBe('App\\Repositories\\UserRepository');
    });

    it('maps constructor body assignments back to the parameter type', function (): void {
        $map = (new CallSiteExtractor(callSiteFixture()))->propertyTypes(callSiteClass());

        expect($map['mailer'])->toBe('App\\Services\\EmailService');
    });

    it('maps typed property declarations', function (): void {
        $map = (new CallSiteExtractor(callSiteFixture()))->propertyTypes(callSiteClass());

        expect($map['audit'])->toBe('App\\Services\\AuditLog');
    });

    it('ignores builtin-typed constructor parameters', function (): void {
        $map = (new CallSiteExtractor(callSiteFixture()))->propertyTypes(callSiteClass());

        expect($map)->not->toHaveKey('region');
    });

    it('ignores assignments from parameters with no resolvable type', function (): void {
        $map = (new CallSiteExtractor(callSiteFixture()))->propertyTypes(callSiteClass());

        expect($map)->not->toHaveKey('unknown');
    });
});

describe('CallSiteExtractor — call sites', function (): void {
    it('resolves instance calls on injected properties', function (): void {
        expect(referencesFor('place'))->toContain(
            'App\\Repositories\\UserRepository::create',
            'App\\Repositories\\UserRepository::touch',
            'App\\Services\\EmailService::sendReceipt',
            'App\\Services\\AuditLog::record',
        );
    });

    it('records each call site separately, in source order', function (): void {
        expect(referencesFor('place'))->toBe([
            'App\\Repositories\\UserRepository::create',
            'App\\Repositories\\UserRepository::touch',
            'App\\Services\\EmailService::sendReceipt',
            'App\\Services\\AuditLog::record',
        ]);
    });

    it('resolves static calls on a class name', function (): void {
        expect(referencesFor('lookup'))->toBe(['App\\Models\\User::query']);
    });

    it('keeps resolvable calls and drops every unresolvable one in the same method', function (): void {
        // dynamic() makes four calls: one resolvable, three that cannot be
        // resolved without executing code. Only the first should survive.
        expect(referencesFor('dynamic'))->toBe(['App\\Repositories\\UserRepository::flush']);
    });

    it('skips dynamic method names', function (): void {
        expect(referencesFor('dynamic'))->not->toContain('App\\Repositories\\UserRepository::');
        expect(count(referencesFor('dynamic')))->toBe(1);
    });

    it('skips calls on properties with no known type', function (): void {
        $references = referencesFor('dynamic');

        expect($references)->not->toBeEmpty();
        expect(implode(' ', $references))->not->toContain('doThing');
    });

    it('skips self:: calls, which are not a relationship to another class', function (): void {
        $references = referencesFor('dynamic');

        expect($references)->not->toBeEmpty();
        expect(implode(' ', $references))->not->toContain('helper');
    });

    it('omits methods with no resolvable call sites', function (): void {
        $calls = (new CallSiteExtractor(callSiteFixture()))->extract(callSiteClass());

        expect($calls)->not->toHaveKey('quiet');
    });

    it('records the kind, receiver, and line of each call', function (): void {
        $calls = (new CallSiteExtractor(callSiteFixture()))->extract(callSiteClass());

        $create = $calls['place'][0];
        expect($create->kind)->toBe(CallSite::KIND_INSTANCE);
        expect($create->via)->toBe('$this->users');
        expect($create->line)->toBeGreaterThan(0);

        $query = $calls['lookup'][0];
        expect($query->kind)->toBe(CallSite::KIND_STATIC);
        expect($query->via)->toBe('User');
    });

    it('serializes a call site for the result schema', function (): void {
        $calls = (new CallSiteExtractor(callSiteFixture()))->extract(callSiteClass());

        expect($calls['lookup'][0]->toArray())->toMatchArray([
            'target' => 'App\\Models\\User',
            'method' => 'query',
            'kind' => CallSite::KIND_STATIC,
        ]);
    });
});
