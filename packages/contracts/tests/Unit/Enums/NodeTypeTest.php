<?php

declare(strict_types=1);

use CodeAtlas\Contracts\Enums\NodeType;

describe('NodeType', function (): void {
    it('is a backed string enum', function (): void {
        expect(NodeType::Route)->toBeInstanceOf(NodeType::class);
        expect(NodeType::Route->value)->toBe('route');
    });

    it('exposes every domain entity type expected by JSON_SCHEMA.md', function (): void {
        $expected = [
            'route', 'controller', 'controller_method', 'interface', 'trait',
            'middleware', 'middleware_group',
            'service', 'repository', 'model', 'model_relationship',
            'event', 'listener', 'job', 'notification',
            'policy', 'policy_method', 'command', 'schedule_entry',
            'migration', 'factory', 'seeder', 'provider', 'config', 'view',
        ];

        $actual = array_map(static fn(NodeType $t): string => $t->value, NodeType::cases());

        expect($actual)->toEqualCanonicalizing($expected);
    });

    it('builds a deterministic node ID from type and qualifier', function (): void {
        expect(NodeType::Route->id('get::/api/users'))->toBe('route::get::/api/users');
        expect(NodeType::Controller->id('App\\Http\\Controllers\\UserController'))
            ->toBe('controller::App\\Http\\Controllers\\UserController');
    });

    it('allows the reserved words Interface and Trait as case names', function (): void {
        expect(NodeType::Interface->value)->toBe('interface');
        expect(NodeType::Trait->value)->toBe('trait');
        expect(NodeType::from('interface'))->toBe(NodeType::Interface);
        expect(NodeType::Trait->id('App\\Concerns\\LogsActivity'))
            ->toBe('trait::App\\Concerns\\LogsActivity');
    });

    it('rejects unknown backing values', function (): void {
        NodeType::from('not_a_real_type');
    })->throws(ValueError::class);
});
