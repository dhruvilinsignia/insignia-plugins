/**
 * Icon.js — SVG icon library (no emojis).
 *
 * Usage:
 *   <Icon name="search" size={ 16 } />
 *   <Icon name="folder" className="wptd-folder-icon" />
 *
 * File-type icons:
 *   <FileIcon ext="php" size={ 16 } />
 */

const ICON_PATHS = {
        // Navigation
        'chevron-down':      [ <polyline key="1" points="6 9 12 15 18 9" /> ],
        'chevron-right':     [ <polyline key="1" points="9 6 15 12 9 18" /> ],
        'arrow-up':          [ <line key="1" x1="12" y1="19" x2="12" y2="5" />, <polyline key="2" points="5 12 12 5 19 12" /> ],
        'arrow-left':        [ <line key="1" x1="19" y1="12" x2="5" y2="12" />, <polyline key="2" points="12 19 5 12 19 5" /> ],

        // Files & folders
        'folder':            [ <path key="1" d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" /> ],
        'folder-open':       [ <path key="1" d="m6 14 1.5-2.5h9L18 14M6 14h12M6 14l-2 5h16l-2-5M6 14V5a2 2 0 0 1 2-2h5l2 3h5a2 2 0 0 1 2 2v6" /> ],
        'folder-plus':       [ <path key="1" d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />, <line key="2" x1="12" y1="11" x2="12" y2="17" />, <line key="3" x1="9" y1="14" x2="15" y2="14" /> ],
        'file-plus':         [ <path key="1" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />, <polyline key="2" points="14 2 14 8 20 8" />, <line key="3" x1="12" y1="12" x2="12" y2="18" />, <line key="4" x1="9" y1="15" x2="15" y2="15" /> ],
        'file':              [ <path key="1" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />, <polyline key="2" points="14 2 14 8 20 8" /> ],
        'file-text':         [ <path key="1" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />, <polyline key="2" points="14 2 14 8 20 8" />, <line key="3" x1="9" y1="13" x2="15" y2="13" />, <line key="4" x1="9" y1="17" x2="15" y2="17" /> ],

        // Actions
        'search':            [ <circle key="1" cx="11" cy="11" r="7" />, <line key="2" x1="21" y1="21" x2="16.5" y2="16.5" /> ],
        'download':          [ <path key="1" d="M12 3v12" />, <polyline key="2" points="6 11 12 17 18 11" />, <path key="3" d="M5 21h14" /> ],
        'upload':            [ <path key="1" d="M12 21V9" />, <polyline key="2" points="6 13 12 7 18 13" />, <path key="3" d="M5 3h14" /> ],
        'edit':              [ <path key="1" d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />, <path key="2" d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /> ],
        'trash':             [ <polyline key="1" points="3 6 5 6 21 6" />, <path key="2" d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />, <line key="3" x1="10" y1="11" x2="10" y2="17" />, <line key="4" x1="14" y1="11" x2="14" y2="17" /> ],
        'refresh':           [ <polyline key="1" points="23 4 23 10 17 10" />, <polyline key="2" points="1 20 1 14 7 14" />, <path key="3" d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /> ],
        'save':              [ <path key="1" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />, <polyline key="2" points="17 21 17 13 7 13 7 21" />, <polyline key="3" points="7 3 7 8 15 8" /> ],
        'copy':              [ <rect key="1" x="9" y="9" width="13" height="13" rx="2" ry="2" />, <path key="2" d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /> ],
        'rename':            [ <path key="1" d="M3 7h10M14 7h7M3 17h7M12 17h9" />, <circle key="2" cx="13" cy="7" r="2" fill="currentColor" stroke="none" />, <circle key="3" cx="11" cy="17" r="2" fill="currentColor" stroke="none" /> ],
        'close':             [ <line key="1" x1="18" y1="6" x2="6" y2="18" />, <line key="2" x1="6" y1="6" x2="18" y2="18" /> ],
        'check':             [ <polyline key="1" points="20 6 9 17 4 12" /> ],
        'check-circle':      [ <path key="1" d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />, <polyline key="2" points="22 4 12 14.01 9 11.01" /> ],
        'plus':              [ <line key="1" x1="12" y1="5" x2="12" y2="19" />, <line key="2" x1="5" y1="12" x2="19" y2="12" /> ],
        'minus':             [ <line key="1" x1="5" y1="12" x2="19" y2="12" /> ],
        'expand':            [ <polyline key="1" points="15 3 21 3 21 9" />, <polyline key="2" points="9 21 3 21 3 15" />, <line key="3" x1="21" y1="3" x2="14" y2="10" />, <line key="4" x1="3" y1="21" x2="10" y2="14" /> ],
        'collapse':          [ <polyline key="1" points="4 14 10 14 10 20" />, <polyline key="2" points="20 10 14 10 14 4" />, <line key="3" x1="14" y1="10" x2="21" y2="3" />, <line key="4" x1="3" y1="21" x2="10" y2="14" /> ],
        'maximize':          [ <path key="1" d="M8 3H5a2 2 0 0 0-2 2v3" />, <path key="2" d="M21 8V5a2 2 0 0 0-2-2h-3" />, <path key="3" d="M3 16v3a2 2 0 0 0 2 2h3" />, <path key="4" d="M16 21h3a2 2 0 0 0 2-2v-3" /> ],
        'minimize':          [ <path key="1" d="M8 3v3a2 2 0 0 1-2 2H3" />, <path key="2" d="M21 8h-3a2 2 0 0 1-2-2V3" />, <path key="3" d="M3 16h3a2 2 0 0 1 2 2v3" />, <path key="4" d="M16 21v-3a2 2 0 0 1 2-2h3" /> ],

        // Status & feedback
        'warning':           [ <path key="1" d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />, <line key="2" x1="12" y1="9" x2="12" y2="13" />, <line key="3" x1="12" y1="17" x2="12.01" y2="17" /> ],
        'info':              [ <circle key="1" cx="12" cy="12" r="10" />, <line key="2" x1="12" y1="16" x2="12" y2="12" />, <line key="3" x1="12" y1="8" x2="12.01" y2="8" /> ],
        'alert-circle':      [ <circle key="1" cx="12" cy="12" r="10" />, <line key="2" x1="12" y1="8" x2="12" y2="12" />, <line key="3" x1="12" y1="16" x2="12.01" y2="16" /> ],

        // Layout & view
        'grid':              [ <rect key="1" x="3" y="3" width="7" height="7" rx="1" />, <rect key="2" x="14" y="3" width="7" height="7" rx="1" />, <rect key="3" x="14" y="14" width="7" height="7" rx="1" />, <rect key="4" x="3" y="14" width="7" height="7" rx="1" /> ],
        'list':              [ <line key="1" x1="8" y1="6" x2="21" y2="6" />, <line key="2" x1="8" y1="12" x2="21" y2="12" />, <line key="3" x1="8" y1="18" x2="21" y2="18" />, <line key="4" x1="3" y1="6" x2="3.01" y2="6" />, <line key="5" x1="3" y1="12" x2="3.01" y2="12" />, <line key="6" x1="3" y1="18" x2="3.01" y2="18" /> ],
        'code':              [ <polyline key="1" points="16 18 22 12 16 6" />, <polyline key="2" points="8 6 2 12 8 18" /> ],
        'sort':              [ <path key="1" d="M7 3v18" />, <polyline key="2" points="3 7 7 3 11 7" />, <path key="3" d="M17 21V3" />, <polyline key="4" points="21 17 17 21 13 17" /> ],
        'terminal':          [ <polyline key="1" points="4 17 10 11 4 5" />, <line key="2" x1="12" y1="19" x2="20" y2="19" /> ],

        // Theme
        'sun':               [ <circle key="1" cx="12" cy="12" r="5" />, <line key="2" x1="12" y1="1" x2="12" y2="3" />, <line key="3" x1="12" y1="21" x2="12" y2="23" />, <line key="4" x1="4.22" y1="4.22" x2="5.64" y2="5.64" />, <line key="5" x1="18.36" y1="18.36" x2="19.78" y2="19.78" />, <line key="6" x1="1" y1="12" x2="3" y2="12" />, <line key="7" x1="21" y1="12" x2="23" y2="12" />, <line key="8" x1="4.22" y1="19.78" x2="5.64" y2="18.36" />, <line key="9" x1="18.36" y1="5.64" x2="19.78" y2="4.22" /> ],
        'moon':              [ <path key="1" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /> ],
        'monitor':           [ <rect key="1" x="2" y="3" width="20" height="14" rx="2" ry="2" />, <line key="2" x1="8" y1="21" x2="16" y2="21" />, <line key="3" x1="12" y1="17" x2="12" y2="21" /> ],

        // Settings
        'settings':          [ <circle key="1" cx="12" cy="12" r="3" />, <path key="2" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" /> ],

        // Types
        'puzzle':            [ <path key="1" d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568A2.402 2.402 0 0 1 1.998 12c0-.617.236-1.234.706-1.704L4.23 8.77c.24-.24.581-.353.917-.303.515.077.877.528 1.073 1.01a2.5 2.5 0 1 0 3.259-3.259c-.482-.196-.933-.558-1.01-1.073-.05-.336.062-.676.303-.917l1.525-1.525A2.402 2.402 0 0 1 12 1.998c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.074.84-.504 1.02-.968a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02z" /> ],
        'palette':           [ <circle key="1" cx="13.5" cy="6.5" r="1.5" fill="currentColor" stroke="none" />, <circle key="2" cx="17.5" cy="10.5" r="1.5" fill="currentColor" stroke="none" />, <circle key="3" cx="8.5" cy="7.5" r="1.5" fill="currentColor" stroke="none" />, <circle key="4" cx="6.5" cy="12.5" r="1.5" fill="currentColor" stroke="none" />, <path key="5" d="M12 2C6.5 2 2 6.14 2 11.25c0 4.66 3.88 8.25 9 8.25 1.04 0 1.97-.28 2.65-.77.81-.58 1.27-1.47 1.27-2.48 0-1.06-.58-1.86-1.36-2.29-.26-.15-.46-.23-.65-.3-.17-.07-.3-.12-.43-.2-.18-.1-.26-.2-.26-.4 0-.17.07-.28.2-.37.16-.12.42-.18.72-.18H17c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-1c-.28 0-.5-.22-.5-.5S15.72 5 16 5h1c1.66 0 3 1.34 3 3v3c0 1.66-1.34 3-3 3h-3.7c-.13.01-.22.12-.22.25 0 .08.05.15.12.18.07.04.16.07.26.11.18.08.42.17.7.36.6.35 1.05 1.07 1.05 2.1 0 .95-.43 1.75-1.07 2.28-.69.57-1.6.87-2.6.97" /> ],
        'clock':             [ <circle key="1" cx="12" cy="12" r="10" />, <polyline key="2" points="12 6 12 12 16 14" /> ],
        'archive':           [ <polyline key="1" points="21 8 21 21 3 21 3 8" />, <rect key="2" x="1" y="3" width="22" height="5" rx="1" />, <line key="3" x1="10" y1="12" x2="14" y2="12" /> ],
        'database':          [ <ellipse key="1" cx="12" cy="5" rx="9" ry="3" />, <path key="2" d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3" />, <path key="3" d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5" /> ],
        'hard-drive':        [ <line key="1" x1="22" y1="12" x2="2" y2="12" />, <path key="2" d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />, <line key="3" x1="6" y1="16" x2="6.01" y2="16" />, <line key="4" x1="10" y1="16" x2="10.01" y2="16" /> ],
        'tag':               [ <path key="1" d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />, <line key="2" x1="7" y1="7" x2="7.01" y2="7" /> ],
        'globe':             [ <circle key="1" cx="12" cy="12" r="10" />, <line key="2" x1="2" y1="12" x2="22" y2="12" />, <path key="3" d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /> ],
        'server':            [ <rect key="1" x="2" y="2" width="20" height="8" rx="2" ry="2" />, <rect key="2" x="2" y="14" width="20" height="8" rx="2" ry="2" />, <line key="3" x1="6" y1="6" x2="6.01" y2="6" />, <line key="4" x1="6" y1="18" x2="6.01" y2="18" /> ],
        'home':              [ <path key="1" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />, <polyline key="2" points="9 22 9 12 15 12 15 22" /> ],

        // Editor
        'wrap':              [ <line key="1" x1="3" y1="6" x2="21" y2="6" />, <path key="2" d="M3 12c0 0 4 0 7 0s3 2 3 2-1 2-3 2-7 0-7 0" />, <path key="3" d="M3 6v6" />, <path key="4" d="M21 18H10s-3 0-3-3 3-3 3-3h11" />, <polyline key="5" points="18 11 21 8 18 5" /> ],
        'find':              [ <circle key="1" cx="11" cy="11" r="7" />, <line key="2" x1="21" y1="21" x2="16.5" y2="16.5" /> ],
        'zap':               [ <polygon key="1" points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" /> ],
        'eye':               [ <path key="1" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />, <circle key="2" cx="12" cy="12" r="3" /> ],
        'external-link':     [ <path key="1" d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />, <polyline key="2" points="15 3 21 3 21 9" />, <line key="3" x1="10" y1="14" x2="21" y2="3" /> ],
};

export function Icon( { name, size = 16, className = '', strokeWidth = 2, ...rest } ) {
        const paths = ICON_PATHS[ name ];
        if ( ! paths ) return null;
        return (
                <svg
                        width={ size }
                        height={ size }
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={ strokeWidth }
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className={ `wptd-icon wptd-icon--${ name } ${ className }` }
                        aria-hidden="true"
                        { ...rest }
                >
                        { paths }
                </svg>
        );
}

// ── File-type icons with color coding ───────────────────────────────────────

const FILE_COLORS = {
        php:    '#777BB4',
        phtml:  '#777BB4',
        php3:   '#777BB4',
        php5:   '#777BB4',
        js:     '#F0DB4F',
        mjs:    '#F0DB4F',
        jsx:    '#61DAFB',
        ts:     '#3178C6',
        tsx:    '#3178C6',
        vue:    '#41B883',
        css:    '#1572B6',
        scss:   '#CC6699',
        sass:   '#CC6699',
        less:   '#1D365D',
        html:   '#E34F26',
        htm:    '#E34F26',
        xml:    '#21759B',
        svg:    '#FFB13B',
        json:   '#6c757d',
        geojson:'#6c757d',
        md:     '#0366D6',
        markdown:'#0366D6',
        txt:    '#6c757d',
        yml:    '#CB171E',
        yaml:   '#CB171E',
        sql:    '#336791',
        po:     '#73B739',
        mo:     '#73B739',
        htaccess:'#494949',
        env:    '#ECD53F',
        gitignore:'#F05033',
        htpasswd:'#494949',
        conf:   '#6c757d',
        ini:    '#6c757d',
        lock:   '#6c757d',
        log:    '#6c757d',
        map:    '#6c757d',
};

export function FileIcon( { ext, size = 16 } ) {
        const color = FILE_COLORS[ ( ext || '' ).toLowerCase() ] || '#6c757d';
        return (
                <svg
                        width={ size }
                        height={ size }
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke={ color }
                        strokeWidth="1.6"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className="wptd-file-icon"
                        aria-hidden="true"
                >
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                </svg>
        );
}

export function FolderIcon( { open = false, size = 16 } ) {
        if ( open ) {
                return (
                        <svg width={ size } height={ size } viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" fill="#d4edda" stroke="#1e7e34" />
                                <path d="M2 10h20l-2 9H4z" fill="#fff3cd" stroke="#1e7e34" strokeWidth="1" opacity="0.8" />
                        </svg>
                );
        }
        return (
                <svg width={ size } height={ size } viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" fill="#d4edda" stroke="#1e7e34" />
                </svg>
        );
}

/**
 * InsigniaLogo — the brand mark for "Insignia WordPress Explorer".
 *
 * Design: a heraldic shield (the "insignia") with a compass/explorer
 * needle at its centre.  Uses a multi-stop gradient so the mark has
 * depth and reads well on both light and dark admin themes.
 *
 * The gradient id is namespaced (`wptd-insignia-grad`) so it cannot
 * collide with any other SVG on the WP admin page.
 */
export function InsigniaLogo( { size = 40, className = '' } ) {
        return (
                <svg
                        width={ size }
                        height={ size }
                        viewBox="0 0 48 48"
                        className={ `wptd-insignia-logo ${ className }` }
                        aria-hidden="true"
                >
                        <defs>
                                <linearGradient id="wptd-insignia-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%"  stopColor="#6366f1" />
                                        <stop offset="50%" stopColor="#8b5cf6" />
                                        <stop offset="100%" stopColor="#ec4899" />
                                </linearGradient>
                                <linearGradient id="wptd-insignia-shine" x1="0%" y1="0%" x2="0%" y2="100%">
                                        <stop offset="0%"  stopColor="#ffffff" stopOpacity="0.45" />
                                        <stop offset="55%" stopColor="#ffffff" stopOpacity="0" />
                                </linearGradient>
                                <filter id="wptd-insignia-glow" x="-20%" y="-20%" width="140%" height="140%">
                                        <feGaussianBlur stdDeviation="1.2" result="blur" />
                                        <feMerge>
                                                <feMergeNode in="blur" />
                                                <feMergeNode in="SourceGraphic" />
                                        </feMerge>
                                </filter>
                        </defs>

                        {/* Shield body */}
                        <path
                                d="M24 3 6 9v11c0 9.5 7 16.5 18 21 11-4.5 18-11.5 18-21V9L24 3z"
                                fill="url(#wptd-insignia-grad)"
                                stroke="#ffffff"
                                strokeWidth="1.4"
                                strokeLinejoin="round"
                        />
                        {/* Top shine overlay */}
                        <path
                                d="M24 3 6 9v11c0 9.5 7 16.5 18 21 11-4.5 18-11.5 18-21V9L24 3z"
                                fill="url(#wptd-insignia-shine)"
                        />

                        {/* Compass needle (explorer mark) — counter-rotates inside the shield */}
                        <g transform="translate(24 22)" filter="url(#wptd-insignia-glow)">
                                <circle r="9" fill="none" stroke="#ffffff" strokeWidth="1.6" opacity="0.85" />
                                <circle r="2.2" fill="#ffffff" />
                                {/* N pointer */}
                                <path d="M0 -7.5 L2.4 0 L-2.4 0 Z" fill="#ffffff" />
                                {/* S pointer */}
                                <path d="M0 7.5 L2.4 0 L-2.4 0 Z" fill="#1e1b4b" opacity="0.55" />
                                {/* E + W ticks */}
                                <line x1="7.5" y1="0" x2="9" y2="0" stroke="#ffffff" strokeWidth="1.4" strokeLinecap="round" />
                                <line x1="-7.5" y1="0" x2="-9" y2="0" stroke="#ffffff" strokeWidth="1.4" strokeLinecap="round" />
                                <line x1="0" y1="-7.5" x2="0" y2="-9" stroke="#ffffff" strokeWidth="1.4" strokeLinecap="round" />
                        </g>
                </svg>
        );
}
