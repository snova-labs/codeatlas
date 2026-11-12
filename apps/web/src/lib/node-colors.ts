import type { NodeType } from '../types/analysis';

/**
 * Node type → accent color, exactly as specified in UI_GUIDELINES.md.
 */
export const NODE_COLORS: Record<NodeType, string> = {
  route: '#3b82f6',
  controller: '#8b5cf6',
  controller_method: '#8b5cf6',
  interface: '#94a3b8',
  trait: '#a8a29e',
  middleware: '#f59e0b',
  middleware_group: '#f59e0b',
  service: '#22c55e',
  repository: '#14b8a6',
  model: '#ef4444',
  model_relationship: '#ef4444',
  event: '#f97316',
  listener: '#a855f7',
  job: '#06b6d4',
  notification: '#ec4899',
  policy: '#84cc16',
  policy_method: '#84cc16',
  command: '#64748b',
  schedule_entry: '#64748b',
  migration: '#78716c',
  factory: '#78716c',
  seeder: '#78716c',
  provider: '#6b7280',
  config: '#6b7280',
  view: '#6b7280',
};

export function nodeColor(type: NodeType): string {
  return NODE_COLORS[type];
}
