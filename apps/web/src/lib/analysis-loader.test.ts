import { describe, expect, it } from 'vitest';

import { parseAnalysisDocument } from './analysis-loader';
import fixtureRaw from '../fixtures/analysis.json?raw';

describe('parseAnalysisDocument', () => {
  it('parses a real backend-produced document', () => {
    const doc = parseAnalysisDocument(fixtureRaw);

    expect(doc.version).toBe('1.0.0');
    expect(doc.project.name).toBe('demo/controllers-app');
    expect(doc.graph.nodes).toHaveLength(7);
    expect(doc.graph.edges.length).toBeGreaterThan(0);
  });

  it('rejects invalid JSON', () => {
    expect(() => parseAnalysisDocument('{not json')).toThrow('not valid JSON');
  });

  it('rejects JSON that is not a CodeAtlas document', () => {
    expect(() => parseAnalysisDocument('{"foo": 1}')).toThrow('missing $schema');
  });

  it('rejects documents from an unsupported future major version', () => {
    const future = fixtureRaw.replace('"version": "1.0.0"', '"version": "2.0.0"');
    expect(() => parseAnalysisDocument(future)).toThrow('Unsupported schema version');
  });

  it('accepts newer minor versions of the same major', () => {
    const minor = fixtureRaw.replace('"version": "1.0.0"', '"version": "1.9.3"');
    expect(parseAnalysisDocument(minor).version).toBe('1.9.3');
  });
});
