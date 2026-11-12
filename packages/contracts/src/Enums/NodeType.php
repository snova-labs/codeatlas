<?php

declare(strict_types=1);

namespace CodeAtlas\Contracts\Enums;

/**
 * Every kind of entity a CodeAtlas graph can contain.
 *
 * Backed values are the wire format used in JSON output and node IDs.
 */
enum NodeType: string
{
    case Route = 'route';
    case Controller = 'controller';
    case ControllerMethod = 'controller_method';
    case Interface = 'interface';
    case Trait = 'trait';
    case Middleware = 'middleware';
    case MiddlewareGroup = 'middleware_group';
    case Service = 'service';
    case Repository = 'repository';
    case Model = 'model';
    case ModelRelationship = 'model_relationship';
    case Event = 'event';
    case Listener = 'listener';
    case Job = 'job';
    case Notification = 'notification';
    case Policy = 'policy';
    case PolicyMethod = 'policy_method';
    case Command = 'command';
    case ScheduleEntry = 'schedule_entry';
    case Migration = 'migration';
    case Factory = 'factory';
    case Seeder = 'seeder';
    case Provider = 'provider';
    case Config = 'config';
    case View = 'view';

    /**
     * Build a deterministic node ID: "{type}::{qualifier}".
     *
     * Example: NodeType::Route->id('get::/api/users') => "route::get::/api/users"
     */
    public function id(string $qualifier): string
    {
        return $this->value . '::' . $qualifier;
    }
}
