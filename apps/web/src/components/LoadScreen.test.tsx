import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { LoadScreen } from './LoadScreen';
import { useAnalysisStore } from '../stores/analysisStore';
import fixtureRaw from '../fixtures/analysis.json?raw';

describe('LoadScreen', () => {
  beforeEach(() => {
    useAnalysisStore.setState({ document: null, loadError: null });
  });

  it('loads a valid analysis file via the picker', async () => {
    render(<LoadScreen />);

    const file = new File([fixtureRaw], 'codeatlas-analysis.json', { type: 'application/json' });
    file.text = vi.fn().mockResolvedValue(fixtureRaw);

    fireEvent.change(screen.getByLabelText('Analysis JSON file'), {
      target: { files: [file] },
    });

    await waitFor(() => {
      expect(useAnalysisStore.getState().document?.project.name).toBe('demo/controllers-app');
    });
  });

  it('shows an error for a non-CodeAtlas JSON file', async () => {
    render(<LoadScreen />);

    const file = new File(['{"foo":1}'], 'other.json', { type: 'application/json' });
    file.text = vi.fn().mockResolvedValue('{"foo":1}');

    fireEvent.change(screen.getByLabelText('Analysis JSON file'), {
      target: { files: [file] },
    });

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toContain('missing $schema');
    });
  });
});
