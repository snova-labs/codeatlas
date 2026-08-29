/**
 * TypeScript mirror of the CodeAtlas JSON schema (JSON_SCHEMA.md).
 * The UI only ever consumes this shape — never PHP.
 */

export type NodeType =
  | 'route'
  | 'controller'
  | 'controller_method'
  | 'middleware'
  | 'middleware_group'
  | 'service'
  | 'repository'
  | 'model'
  | 'model_relationship'
  | 'event'
  | 'listener'
  | 'job'
  | 'notification'
  | 'policy'
  | 'policy_method'
  | 'command'
  | 'schedule_entry'
  | 'migration'
  | 'factory'
  | 'seeder'
  | 'provider'
  | 'config'
  | 'view';

export type EdgeType =
  | 'routes_to'
  | 'calls'
  | 'depends_on'
  | 'extends'
  | 'implements'
  | 'uses_trait'
  | 'uses_middleware'
  | 'has_relationship'
  | 'dispatches'
  | 'listens_to'
  | 'queues'
  | 'notifies'
  | 'authorizes'
  | 'schedules'
  | 'migrates';

export interface FileReference {
  path: string;
  absolute_path?: string;
  type?: string;
  line_start: number | null;
  line_end: number | null;
}

export interface AnalysisNode {
  id: string;
  type: NodeType;
  label: string;
  group: string | null;
  file: FileReference | null;
  metadata: Record<string, unknown>;
  tags: string[];
}

/** Route analyzer node metadata (JSON_SCHEMA.md routes result). */
export interface RouteMetadata {
  uri: string;
  methods: string[];
  name: string | null;
  controller: string | null;
  action: string | null;
  is_closure: boolean;
  middleware: string[];
  prefix: string | null;
  domain: string | null;
  where: Record<string, string>;
  parameters: string[];
  line: number | null;
}

export function isRouteMetadata(m: Record<string, unknown>): m is Record<string, unknown> & RouteMetadata {
  return typeof m['uri'] === 'string' && Array.isArray(m['methods']);
}

/** Controller analyzer node metadata (JSON_SCHEMA.md controllers result). */
export interface ControllerParameter {
  name: string;
  type: string | null;
  nullable: boolean;
  default: string | null;
}

export interface ControllerMethod {
  name: string;
  visibility: string;
  parameters: ControllerParameter[];
  return_type: string | null;
  attributes: string[];
  line_start: number;
  line_end: number;
}

export interface ControllerDependency {
  fqcn: string;
  parameter: string;
  type: string;
}

export interface ControllerMetadata {
  fqcn: string;
  name: string;
  namespace: string | null;
  parent: string | null;
  interfaces: string[];
  traits: string[];
  methods: ControllerMethod[];
  dependencies: ControllerDependency[];
  abstract: boolean;
  invokable: boolean;
}

export function isControllerMetadata(
  m: Record<string, unknown>,
): m is Record<string, unknown> & ControllerMetadata {
  return typeof m['fqcn'] === 'string' && Array.isArray(m['methods']) && Array.isArray(m['dependencies']);
}

/** Middleware analyzer node metadata (JSON_SCHEMA.md middleware result). */
export interface MiddlewareMetadata {
  alias: string | null;
  fqcn: string | null;
  parameters: string[];
  groups: string[];
  priority: number | null;
  global: boolean;
}

export function isMiddlewareMetadata(
  m: Record<string, unknown>,
): m is Record<string, unknown> & MiddlewareMetadata {
  return 'alias' in m && 'global' in m && Array.isArray(m['groups']);
}

export interface AnalysisEdge {
  id: string;
  source: string;
  target: string;
  type: EdgeType;
  label: string | null;
  metadata: Record<string, unknown>;
}

export interface AnalysisError {
  analyzer: string;
  severity: 'error' | 'warning' | 'info';
  message: string;
  file: string | null;
  line: number | null;
  exception?: string;
}

export interface AnalysisDocument {
  $schema: string;
  version: string;
  project: {
    name: string | null;
    path: string | null;
    framework: string | null;
    framework_version: string | null;
    php_version: string | null;
  };
  analysis: {
    timestamp: string;
    duration_ms: number;
    analyzers: string[];
  };
  graph: {
    nodes: AnalysisNode[];
    edges: AnalysisEdge[];
  };
  results: Record<string, unknown>;
  errors: AnalysisError[];
}
