import { describe, expect, it } from 'vitest';

import { layoutGraph, synthesizeNode } from './graph-layout';
import { parseAnalysisDocument } from './analysis-loader';
import fixtureRaw from '../fixtures/analysis.json?raw';

const doc = parseAnalysisDocument(fixtureRaw);

describe('synthesizeNode', () => {
  it('derives type and short label from the node ID convention', () => {
    const ghost = synthesizeNode('controller::App\\Http\\Controllers\\UserController');

    expect(ghost).not.toBeNull();
    expect(ghost?.type).toBe('controller');
    expect(ghost?.label).toBe('UserController');
    expect(ghost?.tags).toContain('synthesized');
  });

  it('keeps non-namespaced qualifiers as-is', () => {
    expect(synthesizeNode('middleware::auth')?.label).toBe('auth');
  });

  it('returns null for unknown types and malformed IDs', () => {
    expect(synthesizeNode('alien::thing')).toBeNull();
    expect(synthesizeNode('no-separator')).toBeNull();
  });
});

describe('layoutGraph', () => {
  it('synthesizes placeholder nodes only for edge targets without real nodes', () => {
    const { flowNodes } = layoutGraph(doc.graph.nodes, doc.graph.edges);

    // 7 real nodes + synthesized dependency/hierarchy targets (service, model, interface, trait)
    expect(flowNodes.length).toBeGreaterThan(doc.graph.nodes.length);
    const ids = flowNodes.map((n) => n.id);
    expect(ids).toContain('service::App\\Services\\UserService');
    expect(ids).toContain('model::App\\Models\\User');

    // routes/middleware/controllers are REAL nodes now, not ghosts
    const userController = flowNodes.find(
      (n) => n.id === 'controller::App\\Http\\Controllers\\UserController',
    );
    expect(userController?.data.analysis.tags).not.toContain('synthesized');
  });

  it('keeps every edge renderable (no dangling endpoints)', () => {
    const { flowNodes, flowEdges } = layoutGraph(doc.graph.nodes, doc.graph.edges);
    const ids = new Set(flowNodes.map((n) => n.id));

    expect(flowEdges).toHaveLength(doc.graph.edges.length);
    for (const edge of flowEdges) {
      expect(ids.has(edge.source)).toBe(true);
      expect(ids.has(edge.target)).toBe(true);
    }
  });

  it('places routes in the first column and controllers to their right', () => {
    const { flowNodes } = layoutGraph(doc.graph.nodes, doc.graph.edges);

    const route = flowNodes.find((n) => n.data.analysis.type === 'route');
    const controller = flowNodes.find((n) => n.data.analysis.type === 'controller');

    expect(route?.position.x).toBe(0);
    expect(controller !== undefined && route !== undefined).toBe(true);
    expect((controller?.position.x ?? 0) > (route?.position.x ?? 0)).toBe(true);
  });

  it('is deterministic', () => {
    const a = layoutGraph(doc.graph.nodes, doc.graph.edges);
    const b = layoutGraph(doc.graph.nodes, doc.graph.edges);
    expect(a.flowNodes.map((n) => [n.id, n.position])).toEqual(
      b.flowNodes.map((n) => [n.id, n.position]),
    );
  });
});
