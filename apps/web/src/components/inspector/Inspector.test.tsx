import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { Inspector } from './Inspector';
import { parseAnalysisDocument } from '../../lib/analysis-loader';
import { useAnalysisStore } from '../../stores/analysisStore';
import { useGraphStore } from '../../stores/graphStore';
import fixtureRaw from '../../fixtures/analysis.json?raw';

const doc = parseAnalysisDocument(fixtureRaw);

describe('Inspector', () => {
  beforeEach(() => {
    useAnalysisStore.setState({ document: doc, loadError: null });
    useGraphStore.setState({ selectedNodeId: null });
  });

  it('prompts when nothing is selected', () => {
    render(<Inspector />);
    expect(screen.getByText('Select a node to inspect it.')).toBeInTheDocument();
  });

  it('shows typed route details for a route node', () => {
    useGraphStore.setState({ selectedNodeId: 'route::get::/users' });
    render(<Inspector />);

    expect(screen.getByText('GET /users')).toBeInTheDocument();
    expect(screen.getByText('/users')).toBeInTheDocument();
    expect(screen.getByText('App\\Http\\Controllers\\UserController')).toBeInTheDocument();
    expect(screen.getByText('auth')).toBeInTheDocument();
    expect(screen.getByText('routes/web.php')).toBeInTheDocument();
  });

  it('lists outgoing connections', () => {
    useGraphStore.setState({ selectedNodeId: 'route::get::/users' });
    render(<Inspector />);

    const connections = screen.getByLabelText('Connections');
    expect(connections.textContent).toContain('controller::App\\Http\\Controllers\\UserController');
    expect(connections.textContent).toContain('middleware::auth');
  });

  it('inspects synthesized placeholder nodes', () => {
    useGraphStore.setState({
      selectedNodeId: 'service::App\\Services\\UserService',
    });
    render(<Inspector />);

    expect(screen.getByText('UserService')).toBeInTheDocument();
    // incoming DependsOn edge from the controller that injects it
    const connections = screen.getByLabelText('Connections');
    expect(connections.textContent).toContain('controller::App\\Http\\Controllers\\UserController');
  });
});
