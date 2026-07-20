# WP Plugin & Theme Downloader

Download any installed WordPress plugin or theme as a `.zip` file — beautifully — **plus a full VS Code-style code editor** with global search, file manager, and bulk operations.

Version **3.0** adds a complete in-browser IDE for editing plugin/theme source files, all wrapped in a soft light-purple + white design.

---

## ✨ What's new in 3.0

### Code Editor (new!)
- **Full VS Code-style layout** — file explorer on the left, tabs + editor in the middle, global search panel on the right.
- **CodeMirror 5** editor with syntax highlighting for PHP, JavaScript, CSS, HTML, JSON, Markdown, YAML, SQL.
- **Code folding**, line numbers, bracket matching, auto-close brackets/tags.
- **Multi-tab editing** — open as many files as you want, switch between them with tabs.
- **Global search** (`Ctrl/⌘ + Shift + F`) — grep-like search across every editable file in a plugin/theme; click any match to jump straight to that line.
- **Find in file** (`Ctrl/⌘ + F`) using CodeMirror's persistent search bar.
- **Save** with `Ctrl/⌘ + S` or the Save button — writes back to disk with a `.wptd-bak` safety backup and a server-side edit history.
- **File manager** — right-click any file/folder for a context menu: new file, new folder, rename, delete.
- **Switch target** — quickly switch between editing different plugins/themes via the picker dropdown.
- **Word-wrap toggle**, live cursor position, file size, modified date, and dirty indicator in the status bar.

### Downloader (carried over from v2)
- Dark / Light / Auto theme (now in light-purple + white palette)
- Grid & Table views, persisted
- Multi-select + bulk ZIP download
- Item details panel with file tree
- Download history (last 50, server-side)
- Sort & filter by name / size / modified / status
- Animated everything — staggered cards, shimmer skeletons, toasts, modals
- Search by name / slug / author with `Ctrl/⌘ + K`
- Copy slug to clipboard
- Responsive

### Design
- **Light-purple + white** color palette throughout (violet `#8b5cf6` primary, lavender backgrounds, white surfaces).
- CodeMirror uses the `material-darker` theme inside the editor for proper code contrast.
- Smooth animations, soft shadows, rounded corners.

---

## Folder Structure

```
wp-plugin-theme-downloader/
│
├── wp-plugin-theme-downloader.php              ← Plugin header + bootstrap
│
├── includes/
│   ├── class-plugin.php                        ← Singleton core
│   ├── class-assets.php                        ← Enqueues React + CodeMirror CDN
│   ├── Contracts/class-hookable.php
│   ├── Admin/class-menu.php
│   ├── Api/
│   │   ├── class-download-endpoint.php         ← ZIP / list / details / settings / history
│   │   └── class-file-endpoint.php             ← File CRUD + global search (NEW)
│   └── Download/class-zipper.php
│
├── admin/views/fallback-table.php
│
├── src/
│   ├── index.js
│   ├── index.css                               ← All styles, light-purple theme
│   ├── api/download.js                         ← REST helpers (downloads + files)
│   └── components/
│       ├── App.js                              ← Root with mode switch
│       ├── Header.js                           ← Tabs + Downloader/Editor mode toggle
│       ├── StatsBar.js, Toolbar.js
│       ├── ItemGrid.js, ItemTable.js
│       ├── DetailsModal.js, SettingsPanel.js, HistoryPanel.js
│       ├── Skeletons.js, Toast.js, utils.js
│       ├── CodeEditorView.js                   ← VS Code-like 3-pane layout (NEW)
│       ├── FileExplorer.js                     ← File tree sidebar (NEW)
│       ├── EditorTabs.js                       ← Open-file tabs (NEW)
│       ├── CodeEditor.js                       ← CodeMirror wrapper (NEW)
│       └── GlobalSearch.js                     ← Grep panel (NEW)
│
├── build/                                      ← Compiled output (committed)
├── languages/
├── package.json
└── README.md
```

---

## REST API

All routes under `wptd/v1`, require `manage_options`.

### Downloader
| Method | Route                | Purpose                                  |
|--------|----------------------|------------------------------------------|
| GET    | `/list`              | All plugins + themes with metadata       |
| GET    | `/details`           | File tree + size + mtime for one item    |
| GET    | `/download`          | Stream a single ZIP                      |
| POST   | `/bulk-download`     | Stream a ZIP containing multiple items   |
| GET    | `/settings`          | Read plugin settings                     |
| POST   | `/settings`          | Update plugin settings                   |
| GET    | `/history`           | Read recent download history             |
| POST   | `/history`           | Record a successful download             |
| DELETE | `/history`           | Clear all history                        |

### File Editor (NEW)
| Method | Route                | Purpose                                  |
|--------|----------------------|------------------------------------------|
| GET    | `/file-tree`         | Recursive file tree for a plugin/theme   |
| GET    | `/file`              | Read a single file's content + metadata  |
| POST   | `/file`              | Save file content (with `.wptd-bak` backup) |
| DELETE | `/file`              | Delete a file or directory               |
| POST   | `/file/create`       | Create a new file or directory           |
| POST   | `/file/rename`       | Rename / move a file or directory        |
| GET    | `/search`            | Grep-like search across all files        |

**Safety:**
- All paths are confined to the resolved plugin/theme root via `realpath()` containment.
- Dotfiles and binary extensions (png, jpg, zip, woff, mo, etc.) are blocked from editing.
- Files over 2 MB cannot be opened in the editor.
- `node_modules`, `vendor`, `.git`, `bower_components` directories are skipped in both tree and search.
- Saving creates a `.wptd-bak` sidecar so you can quickly roll back.
- The server keeps a 30-entry edit-history option with previews.
- Honors `DISALLOW_FILE_EDIT` — if it's `true` in `wp-config.php`, all write/delete/create/rename routes return 403.

---

## Keyboard shortcuts

| Shortcut             | Action                                  |
|----------------------|-----------------------------------------|
| `Ctrl/⌘ + K`         | Focus the search box (Downloader mode)  |
| `Ctrl/⌘ + S`         | Save the current file (Editor mode)     |
| `Ctrl/⌘ + F`         | Find in file (Editor mode)              |
| `Ctrl/⌘ + G`         | Next search match in file               |
| `Ctrl/⌘ + Shift + F` | Open global search (Editor mode)        |
| `Esc`                | Close any open panel / context menu     |
| `Ctrl/⌘ + Click`     | Toggle card selection (Downloader)      |
| Right-click          | Context menu on file/folder (Editor)    |

---

## Changelog

### 1.0.3
- **Fullscreen now shows the whole editor.** Instead of maximizing a single pane (which hid the code editor when you fullscreened the folder tree, and vice-versa), fullscreen now promotes the entire 2-pane layout — file tree on the left, code editor on the right, VS Code style. Either fullscreen button (explorer toolbar or editor status bar) triggers it; Esc or the exit bar restores. On mobile the tree stacks above the editor.
- **Emerald/gold theme.** The fullscreen header bar, top accent sweep, header aurora blobs, brand badge, and brand title gradient are now emerald green with gold accents instead of purple/pink.

### 1.0.2
- **Fixed: fullscreen mode.** A leftover CSS rule (`opacity: .35; pointer-events: none` on `.wptd-editor-view.has-fullscreen`) from the old duplicate-layer architecture dimmed the promoted pane, made every button (including Exit) unclickable, and trapped the pane's z-index below the WP admin bar. Removed; fullscreen now covers the viewport, locks page scroll, and exits via button or Esc.
- **Fixed: fullscreen sizing.** Editor pane now uses flex sizing under the exit bar, so the status bar is no longer pushed off-screen; explorer pane no longer overflows by 48px. Mobile fullscreen uses `100dvh` and overrides the 320px explorer cap.
- **Fixed: Themes tab file browsing.** Switching Plugins → Themes briefly rendered `type=theme` with the previous *plugin* slug, firing doomed `/file-tree` + `/file` requests (404 "Directory not found") and flashing errors in the explorer/editor. The selected slug is now stored per tab and derived during render, so an invalid type/slug pair can never be requested.
- **Fixed: theme trees now match the Appearance editor.** `vendor/`, `build/`, `dist/`, `cache/` and `upgrade/` folders inside a plugin/theme are no longer hidden (the aggressive skip list now applies only to WordPress-root mode), so compiled assets and vendor files open just like in Appearance → Theme File Editor. `node_modules/` and `.git/` stay hidden.
- Fixed stale error state lingering in the code editor after all tabs were closed by a tab switch.

## Setup

### 1. Install the plugin

Copy the entire folder to `wp-content/plugins/wp-plugin-theme-downloader/` and activate it.  
The `build/` directory is included — no `npm install` needed.

### 2. Use it

Go to the new top-level **ZIP Downloader** menu in the WordPress admin. Use the
**Downloader / Code Editor** toggle in the tab bar to switch modes.

### 3. (Optional) Build from source

```bash
cd wp-content/plugins/wp-plugin-theme-downloader
npm install
npm run build        # production build → build/
# or
npm start            # dev mode with hot reload
```

---

## Architecture notes

| Layer        | Pattern                                                        |
|--------------|----------------------------------------------------------------|
| PHP bootstrap | Singleton (`Plugin::instance()`)                              |
| Services     | OOP classes implementing `Hookable` interface                  |
| Autoloader   | PSR-4 style via `spl_autoload_register`                        |
| REST API     | `wptd/v1` namespace, `manage_options` permission, nonce-gated  |
| React        | `@wordpress/scripts` + `@wordpress/element` (shared React)     |
| Code editor  | CodeMirror 5 loaded from CDN (cdnjs) — small plugin footprint  |
| Theming      | CSS variables on `[data-wptd-theme]` attribute                 |
| Settings     | Stored in `wptd_settings` option (array)                       |
| History      | Stored in `wptd_download_history` option (max 50 entries)      |
| Edit history | Stored in `wptd_edit_history` option (max 30 entries)          |
| Path safety  | `realpath()` confinement, slug regex, dotfiles blocked         |

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- PHP `ZipArchive` extension (for ZIP downloads)
- `DISALLOW_FILE_EDIT` must NOT be `true` in `wp-config.php` (for the code editor)
- Internet access to cdnjs (for CodeMirror 5) — the editor won't load offline
- Node 18+ / npm 9+ (only for rebuilding the React UI)
