import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { Inspector } from './Inspector';
import { parseAnalysisDocument } from '../../lib/analysis-loader';
import { useAnalysisStore } from '../../stores/analysisStore';
import { useGraphStore } from '../../stores/graphStore';
import fixtureRaw from '../../fixtures/analysis.json?raw';

const doc = parseAnalysisDocument(fixtureRaw);

describe('Inspector — typed controller view', () => {
  beforeEach(() => {
    useAnalysisStore.setState({ document: doc, loadError: null });
    useGraphStore.setState({
      selectedNodeId: 'controller::App\\Http\\Controllers\\UserController',
    });
  });

  it('shows identity, hierarchy, and traits', () => {
    render(<Inspector />);

    expect(screen.getByText('App\\Http\\Controllers\\UserController')).toBeInTheDocument();
    expect(screen.getByText('Controller')).toBeInTheDocument();
    expect(screen.getByText('Auditable')).toBeInTheDocument();
    expect(screen.getByText('HasApiResponse')).toBeInTheDocument();
  });

  it('lists constructor dependencies with parameter names', () => {
    render(<Inspector />);

    expect(screen.getByText('$userService')).toBeInTheDocument();
    expect(screen.getByText('UserService')).toBeInTheDocument();
  });

  it('renders method signatures with visibility and short types', () => {
    render(<Inspector />);

    expect(screen.getByText(/index\(Request \$request\): JsonResponse/)).toBeInTheDocument();
    expect(
      screen.getByText(/show\(Request \$request, int \$id, \?string \$format = 'json'\): JsonResponse\|string/),
    ).toBeInTheDocument();
    expect(screen.getAllByText('public').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText('protected')).toBeInTheDocument();
  });

  it('shows PHP attributes on annotated methods', () => {
    render(<Inspector />);

    expect(screen.getByText(/#\[Middleware\]/)).toBeInTheDocument();
  });
});

describe('Inspector — typed middleware view', () => {
  beforeEach(() => {
    useAnalysisStore.setState({ document: doc, loadError: null });
    useGraphStore.setState({ selectedNodeId: 'middleware::auth' });
  });

  it('shows alias, FQCN, and global flag', () => {
    render(<Inspector />);

    expect(screen.getAllByText('auth').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('App\\Http\\Middleware\\Authenticate')).toBeInTheDocument();
    expect(screen.getByText('Global')).toBeInTheDocument();
    expect(screen.getByText('no')).toBeInTheDocument();
  });

  it('lists incoming route connections', () => {
    render(<Inspector />);

    const connections = screen.getByLabelText('Connections');
    expect(connections.textContent).toContain('route::get::/users');
  });
});
