import type { AnalysisEdge, AnalysisNode, NodeType } from '../types/analysis';
import type { AtlasFlowNode } from '../components/graph/nodes/AtlasNode';
import type { Edge as FlowEdge } from '@xyflow/react';

const COLUMN_ORDER: NodeType[] = ['route', 'middleware_group', 'middleware', 'controller', 'service', 'repository', 'model'];
const COLUMN_WIDTH = 340;
const ROW_HEIGHT = 96;

const KNOWN_TYPES = new Set<string>([
  'route', 'controller', 'controller_method', 'middleware', 'middleware_group',
  'service', 'repository', 'model', 'model_relationship', 'event', 'listener',
  'job', 'notification', 'policy', 'policy_method', 'command', 'schedule_entry',
  'migration', 'factory', 'seeder', 'provider', 'config', 'view',
]);

/**
 * Derive a placeholder node from an edge endpoint that no analyzer has
 * produced yet. Node IDs follow the {type}::{qualifier} convention
 * (JSON_SCHEMA.md), so the type and a display label are recoverable.
 *
 * Example: with only the routes analyzer installed, controllers exist
 * solely as edge targets — the graph still shows what routes point at.
 */
export function synthesizeNode(id: string): AnalysisNode | null {
  const separator = id.indexOf('::');

  if (separator === -1) {
    return null;
  }

  const type = id.slice(0, separator);
  const qualifier = id.slice(separator + 2);

  if (!KNOWN_TYPES.has(type)) {
    return null;
  }

  const shortLabel = qualifier.includes('\\') ? (qualifier.split('\\').pop() ?? qualifier) : qualifier;

  return {
    id,
    type: type as NodeType,
    label: shortLabel,
    group: null,
    file: null,
    metadata: { synthesized: true, qualifier },
    tags: ['synthesized'],
  };
}

/**
 * Deterministic columnar layout: node types flow left→right in pipeline
 * order (routes → middleware → controllers → …), instances stack top→down.
 * Edge endpoints without a real node get a synthesized placeholder so
 * relationships render even before their analyzers exist. Deliberately
 * simple for the MVP; force-directed layout arrives with the full
 * dependency graph in v0.7.
 */
export function layoutGraph(
  nodes: AnalysisNode[],
  edges: AnalysisEdge[],
): { flowNodes: AtlasFlowNode[]; flowEdges: FlowEdge[] } {
  const allNodes = [...nodes];
  const present = new Set(nodes.map((n) => n.id));

  for (const edge of edges) {
    for (const endpoint of [edge.source, edge.target]) {
      if (!present.has(endpoint)) {
        const ghost = synthesizeNode(endpoint);
        if (ghost !== null) {
          allNodes.push(ghost);
          present.add(endpoint);
        }
      }
    }
  }

  const rows = new Map<number, number>();

  const flowNodes: AtlasFlowNode[] = allNodes.map((node) => {
    const orderIndex = COLUMN_ORDER.indexOf(node.type);
    const column = orderIndex === -1 ? COLUMN_ORDER.length : orderIndex;
    const row = rows.get(column) ?? 0;
    rows.set(column, row + 1);

    return {
      id: node.id,
      type: 'atlas',
      position: { x: column * COLUMN_WIDTH, y: row * ROW_HEIGHT },
      data: { analysis: node },
    };
  });

  const flowEdges: FlowEdge[] = edges
    .filter((e) => present.has(e.source) && present.has(e.target))
    .map((edge) => ({
      id: edge.id,
      source: edge.source,
      target: edge.target,
      label: edge.label ?? undefined,
      type: 'smoothstep',
    }));

  return { flowNodes, flowEdges };
}
