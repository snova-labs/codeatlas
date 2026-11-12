# CodeAtlas UI Guidelines

## Design Philosophy

CodeAtlas is a **developer tool**. The UI must be:
- **Dense but not cluttered** — developers want information, not whitespace
- **Fast** — no loading spinners for local data, instant search, sub-100ms interactions
- **Keyboard-first** — every action has a shortcut
- **Dark mode default** — matches IDE conventions
- **Focused** — the graph is the hero, everything else supports it

## Layout

```
┌─────────────────────────────────────────────────────────────┐
│  Toolbar                                            ☰ ⚙ 🌙 │
├────────┬────────────────────────────────────┬───────────────┤
│        │                                    │               │
│  Side  │        Graph Canvas                │   Inspector   │
│  bar   │        (React Flow)                │   Panel       │
│        │                                    │               │
│  240px │        flex-1                       │   320px       │
│  min   │                                    │   collapsible │
│        │                                    │               │
│        │                                    │               │
│        │                                    │               │
│        │                                    │               │
├────────┴────────────────────────────────────┴───────────────┤
│  Console / Output Panel (collapsible)              200px    │
└─────────────────────────────────────────────────────────────┘
```

All panels are resizable via drag handles. All side panels are collapsible. The graph canvas always takes remaining space.

## Color System

### Dark Theme (Default)

```
--atlas-bg-primary:     #0f1117    (main background)
--atlas-bg-secondary:   #161822    (panels, sidebar)
--atlas-bg-tertiary:    #1c1e2e    (cards, hover states)
--atlas-bg-elevated:    #242640    (dropdowns, modals)

--atlas-border:         #2a2d3e    (subtle borders)
--atlas-border-active:  #3d4065    (focused/active borders)

--atlas-text-primary:   #e2e4f0    (main text)
--atlas-text-secondary: #8b8fa8    (descriptions, labels)
--atlas-text-muted:     #5c6078    (placeholders, disabled)

--atlas-accent:         #6366f1    (indigo — primary actions)
--atlas-accent-hover:   #818cf8    (hover state)
--atlas-accent-subtle:  #6366f120  (backgrounds with accent tint)

--atlas-success:        #22c55e
--atlas-warning:        #f59e0b
--atlas-error:          #ef4444
--atlas-info:           #3b82f6
```

### Light Theme

```
--atlas-bg-primary:     #ffffff
--atlas-bg-secondary:   #f8f9fb
--atlas-bg-tertiary:    #f1f3f7
--atlas-bg-elevated:    #ffffff

--atlas-border:         #e2e5eb
--atlas-border-active:  #c4c8d4

--atlas-text-primary:   #1a1c2e
--atlas-text-secondary: #5c6078
--atlas-text-muted:     #9ca0b5

(accent colors same as dark)
```

### Node Colors (by type)

Each node type has a distinct color to make the graph instantly scannable:

```
Route:          #3b82f6  (blue)
Controller:     #8b5cf6  (violet)
Middleware:     #f59e0b  (amber)
Service:        #22c55e  (green)
Repository:     #14b8a6  (teal)
Model:          #ef4444  (red)
Event:          #f97316  (orange)
Listener:       #a855f7  (purple)
Job:            #06b6d4  (cyan)
Notification:   #ec4899  (pink)
Policy:         #84cc16  (lime)
Command:        #64748b  (slate)
Migration:      #78716c  (stone)
Config:         #6b7280  (gray)
```

Structural types are deliberately desaturated. Interfaces and traits are
scaffolding rather than domain entities, so they recede and let the eye
follow the request path (route -> controller -> service -> model) while
still showing what each class is built from:

```
Interface:      #94a3b8  (slate, muted)
Trait:          #a8a29e  (stone, muted)
```

## Typography

```
--atlas-font-mono:    'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace
--atlas-font-sans:    'Inter', -apple-system, system-ui, sans-serif

--atlas-text-xs:      0.75rem / 1rem
--atlas-text-sm:      0.8125rem / 1.25rem    (most UI text)
--atlas-text-base:    0.875rem / 1.5rem
--atlas-text-lg:      1rem / 1.5rem
--atlas-text-xl:      1.125rem / 1.75rem

Code displays: always mono
Labels: sans, text-sm
Headings: sans, text-base or text-lg, font-semibold
```

## Graph Nodes

### Standard Node

```
┌──────────────────────────┐
│ ● UserController         │   ← colored dot + label
│   App\Http\Controllers   │   ← namespace in muted text
│   6 methods              │   ← summary stat
└──────────────────────────┘
```

- Min width: 180px
- Max width: 280px
- Border radius: 8px
- Border: 1px solid `--atlas-border`, 2px when selected
- Left border or top accent: node type color
- Background: `--atlas-bg-tertiary`
- Shadow: subtle, 0 2px 4px rgba(0,0,0,0.1)

### Compact Node (for dense graphs)

```
┌────────────────────┐
│ ● UserController   │
└────────────────────┘
```

### Expanded Node (on click/hover)

```
┌──────────────────────────┐
│ ● UserController         │
│   App\Http\Controllers   │
├──────────────────────────┤
│  index()                 │
│  show()                  │
│  store()                 │
│  update()                │
│  destroy()               │
│  export()                │
└──────────────────────────┘
```

## Edges

- Default: 1px solid, subtle gray
- Hover: 2px, node-type color of source
- Selected: 2px, accent color
- Animated: dashed stroke animation for event/job flows
- Labels: small text at midpoint, visible on hover
- Arrow: small arrowhead at target

## Sidebar

```
┌─────────────────────┐
│ 🔍 Search...        │   ← global search, Cmd+K
├─────────────────────┤
│ ▸ Routes       (24) │   ← collapsible sections
│ ▸ Controllers  (12) │      with count badges
│ ▸ Services      (8) │
│ ▸ Repositories  (6) │
│ ▸ Models       (15) │
│ ▸ Events        (5) │
│ ▸ Jobs          (3) │
│ ▸ Middleware    (7) │
│ ▸ Policies      (4) │
│ ▸ Schedule      (2) │
└─────────────────────┘
```

- Click section → filter graph to show only that type
- Click item → center graph on that node, open inspector
- Drag item → add to canvas (future)
- Right-click → context menu (focus, hide, export)

## Inspector Panel

```
┌─────────────────────────┐
│ UserController          │
│ App\Http\Controllers    │
├─────────────────────────┤
│ Properties              │
│  File: app/Http/...     │
│  Lines: 10-80           │
│  Methods: 6             │
│  Dependencies: 2        │
├─────────────────────────┤
│ Code Preview            │   ← Monaco editor, read-only
│  class UserController   │
│  {                      │
│    public function ...  │
│  }                      │
├─────────────────────────┤
│ Connections             │
│  → UserService          │
│  → AuthMiddleware       │
│  ← GET /api/users       │
└─────────────────────────┘
```

## Keyboard Shortcuts

```
Cmd+K          Global search (command palette)
Cmd+\          Toggle sidebar
Cmd+I          Toggle inspector
Cmd+J          Toggle console
Cmd+E          Export current view
Cmd+0          Fit graph to viewport
Cmd++          Zoom in
Cmd+-          Zoom out
Cmd+Shift+M    Toggle minimap
/              Focus search
Esc            Deselect / close panels
1-9            Switch graph view (routes, controllers, etc.)
```

## Interactions

- **Click node:** Select, show in inspector
- **Double-click node:** Expand/collapse
- **Right-click node:** Context menu (focus, hide, go to file, copy ID)
- **Click edge:** Highlight relationship, show in inspector
- **Hover node:** Show tooltip with summary
- **Drag canvas:** Pan
- **Scroll:** Zoom
- **Drag node:** Reposition (sticky)
- **Cmd+click:** Multi-select
- **Box select:** Select multiple nodes

## Responsive Behavior

- **< 768px:** Sidebar and inspector become slide-over drawers
- **768–1024px:** Sidebar visible, inspector as drawer
- **> 1024px:** All three panels visible

The desktop app can ignore responsive — it controls window size.

## Accessibility

- All interactive elements are keyboard focusable
- ARIA labels on graph nodes and edges
- Color is never the only differentiator (use shapes/icons alongside color)
- Reduced motion support (disable edge animations)
- High contrast mode (future)
- Screen reader: announce node/edge selection changes

## Performance Targets

- Initial render: < 500ms for 500 nodes
- Search results: < 50ms
- Node click → inspector update: < 16ms (single frame)
- Graph layout recalculation: < 200ms for 500 nodes
- Virtualize node lists in sidebar beyond 100 items
- Lazy-load Monaco editor (code preview)
