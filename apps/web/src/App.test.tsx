import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import App from './App';
import { parseAnalysisDocument } from './lib/analysis-loader';
import { useAnalysisStore } from './stores/analysisStore';
import fixtureRaw from './fixtures/analysis.json?raw';

describe('App', () => {
  beforeEach(() => {
    useAnalysisStore.setState({ document: null, loadError: null });
  });

  it('renders the load screen before a document exists', () => {
    render(<App />);

    expect(screen.getByText('CodeAtlas')).toBeInTheDocument();
    expect(screen.getByText('Load an analysis')).toBeInTheDocument();
  });

  it('renders the three-panel workspace once a document loads', () => {
    useAnalysisStore.setState({ document: parseAnalysisDocument(fixtureRaw) });
    render(<App />);

    expect(screen.getByLabelText('Node list')).toBeInTheDocument();
    expect(screen.getByTestId('graph-canvas')).toBeInTheDocument();
    expect(screen.getByLabelText('Inspector')).toBeInTheDocument();
    expect(screen.getByText(/demo\/controllers-app/)).toBeInTheDocument();
  });
});
