/* =========================================================================
   Insignia Backup — Admin JS
   v2.0 — Chunked engine with pause/resume/cancel + percentage progress.
   ========================================================================= */
( function ( $ ) {
        'use strict';

        var cfg = window.IBP || {};
        var $toast = $( '#ibp-toast' );
        var restoreTarget = null;

        /* Track the currently-active backup (for progress panel controls). */
        var activeBackupId = null;
        var activePollTimer = null;

        /* ---------- Helpers ---------- */
        function toast( msg, kind ) {
                $toast.text( msg ).removeClass( 'ok err show' ).addClass( kind || '' ).addClass( 'show' );
                clearTimeout( toast._t );
                toast._t = setTimeout( function () { $toast.removeClass( 'show' ); }, 3800 );
        }

        function post( action, data ) {
                data = data || {};
                data.action = action;
                data.nonce = cfg.nonce;
                return $.post( cfg.ajaxUrl, data );
        }

        /* =========================================================
         *  Generic confirm dialog (replaces window.confirm / alert)
         *
         *  confirmDialog({
         *      title:   'Delete this backup?',            // optional
         *      message: 'This cannot be undone.',         // required
         *      okText:  'Delete',                          // optional
         *      cancelText: 'Cancel',                       // optional
         *      variant: 'danger' | 'warning' | 'info'      // optional, default 'danger'
         *  }) → Promise<boolean>  (true = OK, false = Cancel)
         * ========================================================= */
        var $confirmModal   = $( '#ibp-confirm-modal' );
        var $confirmCard    = $confirmModal.find( '.ibp-modal-card--confirm' );
        var $confirmTitle   = $( '#ibp-confirm-title' );
        var $confirmMsg     = $( '#ibp-confirm-msg' );
        var $confirmOk      = $( '#ibp-confirm-ok' );
        var $confirmCancel  = $( '#ibp-confirm-cancel' );
        var confirmResolve  = null;

        function confirmDialog( opts ) {
                opts = opts || {};
                var variant = opts.variant || 'danger';
                $confirmCard.attr( 'data-ibp-variant', variant );
                $confirmTitle.text( opts.title || 'Are you sure?' );
                $confirmMsg.text( opts.message || '' );
                $confirmOk.text( opts.okText || 'Confirm' );
                $confirmCancel.text( opts.cancelText || 'Cancel' );

                // Reset focus each time so the previous confirm's focus isn't retained.
                $confirmModal.prop( 'hidden', false );
                setTimeout( function () { $confirmOk.trigger( 'focus' ); }, 30 );

                return new Promise( function ( resolve ) {
                        confirmResolve = resolve;
                } );
        }

        function closeConfirm( result ) {
                $confirmModal.prop( 'hidden', true );
                if ( confirmResolve ) {
                        var fn = confirmResolve;
                        confirmResolve = null;
                        fn( result );
                }
        }

        $confirmOk.on( 'click', function () { closeConfirm( true ); } );
        $confirmCancel.on( 'click', function () { closeConfirm( false ); } );
        // Click on backdrop = cancel.
        $confirmModal.on( 'click', function ( e ) {
                if ( e.target === this ) { closeConfirm( false ); }
        } );
        // Esc = cancel, Enter = OK (only while the dialog is open).
        $( document ).on( 'keydown.ibp-confirm', function ( e ) {
                if ( $confirmModal.prop( 'hidden' ) ) { return; }
                if ( 27 === e.keyCode ) { e.preventDefault(); closeConfirm( false ); }
                else if ( 13 === e.keyCode ) { e.preventDefault(); closeConfirm( true ); }
        } );

        /* ---------- Tabs ---------- */
        $( '.ibp-tab' ).on( 'click', function () {
                var tab = $( this ).data( 'tab' );
                $( '.ibp-tab' ).removeClass( 'is-active' );
                $( this ).addClass( 'is-active' );
                $( '.ibp-panel' ).removeClass( 'is-active' );
                $( '.ibp-panel[data-panel="' + tab + '"]' ).addClass( 'is-active' );
                if ( history.replaceState ) {
                        history.replaceState( null, '', '#' + tab );
                }
        } );

        ( function () {
                var hash = ( window.location.hash || '' ).replace( '#', '' );
                if ( hash && $( '.ibp-tab[data-tab="' + hash + '"]' ).length ) {
                        $( '.ibp-tab[data-tab="' + hash + '"]' ).trigger( 'click' );
                }
        } )();

        /* ---------- Backup type selection ---------- */
        $( '.ibp-type input' ).on( 'change', function () {
                $( '.ibp-type' ).removeClass( 'is-selected' );
                $( this ).closest( '.ibp-type' ).addClass( 'is-selected' );

                var isCustom = 'custom' === $( this ).val();
                $( '#ibp-folder-select-row' ).prop( 'hidden', ! isCustom );
                if ( isCustom ) {
                        updateFolderSummary();
                        $( '#ibp-choose-folders' ).trigger( 'click' );
                }
        } );

        /* ---------- Size estimates (type cards) ---------- */
        function formatBytes( bytes ) {
                bytes = parseFloat( bytes ) || 0;
                var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
                var i = 0;
                while ( bytes >= 1024 && i < units.length - 1 ) {
                        bytes /= 1024;
                        i++;
                }
                return ( Math.round( bytes * 100 ) / 100 ) + ' ' + units[ i ];
        }

        function loadSizeEstimates() {
                post( 'ibp_estimate_sizes' ).done( function ( res ) {
                        if ( ! res.success ) {
                                $( '.ibp-type-size[data-ibp-size!="custom"]' ).text( '—' );
                                return;
                        }
                        $( '.ibp-type-size[data-ibp-size="full"]' ).text( '≈ ' + res.data.full_h );
                        $( '.ibp-type-size[data-ibp-size="database"]' ).text( '≈ ' + res.data.database_h );
                        $( '.ibp-type-size[data-ibp-size="files"]' ).text( '≈ ' + res.data.files_h );
                } ).fail( function () {
                        $( '.ibp-type-size[data-ibp-size!="custom"]' ).text( '—' );
                } );
        }
        loadSizeEstimates();

        /* ---------- Folder picker ---------- */
        var selectedFolders = [];
        var folderTreeLoaded = false;
        var itemSizes = {}; // path -> bytes, filled as the tree loads.

        /**
         * Total bytes of the current selection, without double-counting
         * items nested inside an already-selected folder.
         */
        function selectedTotalBytes() {
                var total = 0;
                $.each( selectedFolders, function ( i, path ) {
                        var nested = false;
                        $.each( selectedFolders, function ( j, other ) {
                                if ( path !== other && 0 === path.indexOf( other + '/' ) ) {
                                        nested = true;
                                        return false;
                                }
                        } );
                        if ( ! nested && itemSizes[ path ] ) {
                                total += itemSizes[ path ];
                        }
                } );
                return total;
        }

        function updateSelectionTotals() {
                var count = selectedFolders.length;
                var sizeH = formatBytes( selectedTotalBytes() );
                var label = count + ( 1 === count ? ' item' : ' items' ) + ' · ≈ ' + sizeH;

                $( '#ibp-folder-total' ).text( count ? 'Selected: ' + label : 'Nothing selected yet' );
                $( '.ibp-type-size[data-ibp-size="custom"]' ).text( count ? '≈ ' + sizeH : 'Nothing selected' );
        }

        function updateFolderSummary() {
                var $s = $( '#ibp-folder-summary' );
                if ( ! selectedFolders.length ) {
                        $s.text( 'Nothing selected yet' );
                } else {
                        $s.text(
                                selectedFolders.length +
                                ( 1 === selectedFolders.length ? ' item selected' : ' items selected' ) +
                                ' · ≈ ' + formatBytes( selectedTotalBytes() )
                        );
                }
                updateSelectionTotals();
        }

        function treeNodeHtml( item ) {
                var isDir = 'dir' === item.type;
                var hasChildren = isDir && !! item.has_children;
                var checked = selectedFolders.indexOf( item.path ) !== -1;

                itemSizes[ item.path ] = parseInt( item.size, 10 ) || 0;

                var li = $( '<li class="ibp-tree-node"></li>' )
                        .addClass( isDir ? 'ibp-tree-node--dir' : 'ibp-tree-node--file' )
                        .attr( 'data-path', item.path )
                        .attr( 'data-loaded', hasChildren ? '0' : '1' );
                var row = $(
                        '<div class="ibp-tree-row">' +
                                '<button type="button" class="ibp-tree-toggle' + ( hasChildren ? '' : ' is-empty' ) + '">&#9656;</button>' +
                                '<input type="checkbox" class="ibp-tree-check">' +
                                '<span class="ibp-tree-icon"></span>' +
                                '<span class="ibp-tree-label"></span>' +
                                '<span class="ibp-tree-size"></span>' +
                        '</div>'
                );
                row.find( '.ibp-tree-icon' ).html( isDir ? '&#128193;' : '&#128196;' );
                row.find( '.ibp-tree-label' ).text( item.name );
                row.find( '.ibp-tree-size' ).text( item.size_h || formatBytes( item.size || 0 ) );
                row.find( '.ibp-tree-check' ).prop( 'checked', checked );
                li.append( row );
                if ( isDir ) {
                        li.append( '<ul class="ibp-tree-children"></ul>' );
                }
                return li;
        }

        function loadFolderChildren( $li, path ) {
                $li.addClass( 'is-loading' );
                post( 'ibp_get_folder_tree', { path: path } ).done( function ( res ) {
                        $li.removeClass( 'is-loading' ).attr( 'data-loaded', '1' );
                        var $ul = $li.children( '.ibp-tree-children' ).empty();
                        if ( ! res.success || ! res.data.items.length ) {
                                $ul.append( '<li class="ibp-tree-msg" style="padding-left:20px;">Empty folder</li>' );
                                return;
                        }
                        $.each( res.data.items, function ( i, item ) {
                                $ul.append( treeNodeHtml( item ) );
                        } );
                } );
        }

        function loadFolderRoot() {
                var $tree = $( '#ibp-folder-tree' );
                $tree.html( '<p class="ibp-tree-msg">Loading…</p>' );
                post( 'ibp_get_folder_tree', { path: '' } ).done( function ( res ) {
                        if ( ! res.success ) {
                                $tree.html( '<p class="ibp-tree-msg">Could not load the folder structure.</p>' );
                                return;
                        }
                        var $ul = $( '<ul class="ibp-tree-root"></ul>' );
                        $.each( res.data.items, function ( i, item ) {
                                $ul.append( treeNodeHtml( item ) );
                        } );
                        $tree.empty().append( $ul );
                        folderTreeLoaded = true;

                        var $wpContent = $ul.children( '[data-path="wp-content"]' );
                        if ( $wpContent.length ) {
                                $wpContent.find( '> .ibp-tree-row > .ibp-tree-toggle' ).trigger( 'click' );
                        }
                } );
        }

        $( '#ibp-choose-folders' ).on( 'click', function () {
                $( '#ibp-folder-modal' ).prop( 'hidden', false );
                updateSelectionTotals();
                if ( ! folderTreeLoaded ) { loadFolderRoot(); }
        } );

        $( document ).on( 'click', '#ibp-folder-tree .ibp-tree-toggle', function () {
                var $li = $( this ).closest( '.ibp-tree-node' );
                if ( $li.find( '> .ibp-tree-row > .ibp-tree-toggle' ).hasClass( 'is-empty' ) ) { return; }
                var opening = ! $li.hasClass( 'is-open' );
                $li.toggleClass( 'is-open', opening );
                if ( opening && '0' === $li.attr( 'data-loaded' ) ) {
                        loadFolderChildren( $li, $li.data( 'path' ) );
                }
        } );

        $( document ).on( 'click', '#ibp-folder-tree .ibp-tree-label', function () {
                $( this ).siblings( '.ibp-tree-check' ).trigger( 'click' );
        } );

        $( document ).on( 'change', '#ibp-folder-tree .ibp-tree-check', function () {
                var path = $( this ).closest( '.ibp-tree-node' ).data( 'path' );
                var idx = selectedFolders.indexOf( path );
                if ( $( this ).is( ':checked' ) ) {
                        if ( -1 === idx ) { selectedFolders.push( path ); }
                } else if ( idx !== -1 ) {
                        selectedFolders.splice( idx, 1 );
                }
                updateSelectionTotals();
        } );

        $( '#ibp-folder-cancel' ).on( 'click', function () {
                $( '#ibp-folder-modal' ).prop( 'hidden', true );
        } );
        $( '#ibp-folder-modal' ).on( 'click', function ( e ) {
                if ( e.target === this ) { $( this ).prop( 'hidden', true ); }
        } );
        $( '#ibp-folder-ok' ).on( 'click', function () {
                $( '#ibp-folder-modal' ).prop( 'hidden', true );
                updateFolderSummary();
        } );

        /* =========================================================
         *  Progress panel management
         * ========================================================= */

        var $progress      = $( '#ibp-progress' );
        var $pctLabel      = $( '#ibp-progress-pct' );
        var $pctBar        = $progress.find( '.ibp-progress-bar span' );
        var $pctTxt        = $( '#ibp-progress-txt' );
        var $pauseBtn      = $( '#ibp-pause-btn' );
        var $resumeBtn     = $( '#ibp-resume-btn' );
        var $cancelBtn     = $( '#ibp-cancel-btn' );
        var $createBtn     = $( '#ibp-create' );

        /**
         * Show the progress panel for a specific backup.
         */
        function showProgress( backupId ) {
                activeBackupId = backupId;
                $progress.prop( 'hidden', false );
                $createBtn.prop( 'disabled', true );
                updateProgressUI( 0, 'running', 'Starting backup…' );
                $pauseBtn.prop( 'hidden', false );
                $resumeBtn.prop( 'hidden', true );
                $cancelBtn.prop( 'hidden', false );
        }

        /**
         * Hide the progress panel and re-enable the create button.
         */
        function hideProgress() {
                activeBackupId = null;
                $progress.prop( 'hidden', true );
                $createBtn.prop( 'disabled', false );
                $pauseBtn.prop( 'hidden', true );
                $resumeBtn.prop( 'hidden', true );
                $cancelBtn.prop( 'hidden', true );
        }

        /**
         * Update the progress bar, percentage, and text.
         */
        function updateProgressUI( pct, status, phaseLabel ) {
                $pctBar.css( 'width', pct + '%' );
                $pctLabel.text( pct + '%' );
                $pctTxt.text( phaseLabel || ( 'Running… ' + pct + '%' ) );
        }

        /**
         * Switch progress controls to "paused" mode.
         */
        function showPausedControls() {
                $pauseBtn.prop( 'hidden', true );
                $resumeBtn.prop( 'hidden', false );
                $cancelBtn.prop( 'hidden', false );
                $pctTxt.text( 'Paused' );
        }

        /**
         * Switch progress controls to "running" mode.
         */
        function showRunningControls() {
                $pauseBtn.prop( 'hidden', false );
                $resumeBtn.prop( 'hidden', true );
                $cancelBtn.prop( 'hidden', false );
        }

        /* =========================================================
         *  Create backup
         * ========================================================= */
        $( '#ibp-create' ).on( 'click', function () {
                var type = $( 'input[name="ibp_type"]:checked' ).val() || 'full';
                var name = $( '#ibp-backup-name' ).val();
                var useFolders = 'custom' === type;

                if ( useFolders && ! selectedFolders.length ) {
                        toast( 'Choose at least one file or folder for a Custom backup.', 'err' );
                        return;
                }
                var folders = useFolders ? JSON.stringify( selectedFolders ) : '[]';

                $createBtn.prop( 'disabled', true );
                showProgress( null ); // Will be set when we get the backup_id.

                post( 'ibp_create_backup', { type: type, name: name, folders: folders } )
                        .done( function ( res ) {
                                if ( res.success && res.data.backup_id ) {
                                        toast( res.data.message, 'ok' );
                                        $( '#ibp-backup-name' ).val( '' );
                                        refreshList();
                                        refreshStats();
                                        activeBackupId = res.data.backup_id;
                                        pollBackupStatus( res.data.backup_id );
                                } else {
                                        toast( ( res.data && res.data.message ) || 'Backup failed.', 'err' );
                                        hideProgress();
                                }
                        } )
                        .fail( function () {
                                toast( 'Server error while starting backup.', 'err' );
                                hideProgress();
                        } );
        } );

        /* =========================================================
         *  Poll backup status with percentage
         * ========================================================= */
        function pollBackupStatus( backupId ) {
                if ( activePollTimer ) { clearInterval( activePollTimer ); }

                var interval = 2000; // Poll every 2s for snappier progress.
                activePollTimer = setInterval( function () {
                        post( 'ibp_backup_status', { backup_id: backupId } ).done( function ( res ) {
                                if ( ! res.success ) { return; }

                                var status = res.data.status;
                                var pct    = res.data.progress_pct || 0;
                                var phase  = res.data.phase || '';

                                // Phase labels for the progress text.
                                var phaseLabel = 'Working…';
                                if ( 'scanning' === phase ) { phaseLabel = 'Scanning files…'; }
                                else if ( 'files' === phase ) { phaseLabel = 'Archiving files… ' + pct + '%'; }
                                else if ( 'database' === phase ) { phaseLabel = 'Exporting database… ' + pct + '%'; }
                                else if ( 'extras' === phase ) { phaseLabel = 'Finalizing archive…'; }
                                else if ( 'finalizing' === phase ) { phaseLabel = 'Cleaning up…'; }

                                updateProgressUI( pct, status, phaseLabel );

                                if ( 'complete' === status ) {
                                        clearInterval( activePollTimer );
                                        activePollTimer = null;
                                        toast( 'Backup completed successfully!', 'ok' );
                                        refreshList();
                                        refreshStats();
                                        hideProgress();
                                } else if ( 'failed' === status ) {
                                        clearInterval( activePollTimer );
                                        activePollTimer = null;
                                        toast( res.data.note || 'Backup failed.', 'err' );
                                        refreshList();
                                        hideProgress();
                                } else if ( 'paused' === status ) {
                                        /* Don't clear the timer — user might resume,
                                           and we want to pick up the resumed state. */
                                        showPausedControls();
                                } else if ( 'running' === status ) {
                                        showRunningControls();
                                }
                                // 'pending' — just keep polling.
                        } );

                        // Safety valve: 45 minutes.
                        if ( $( '#ibp-progress' ).is( ':visible' ) ) {
                                var elapsed = 0;
                        }
                }, interval );

                // 45-minute safety valve.
                setTimeout( function () {
                        if ( activePollTimer ) {
                                clearInterval( activePollTimer );
                                activePollTimer = null;
                                toast( 'Still running in the background — refresh later.', 'ok' );
                                hideProgress();
                        }
                }, 45 * 60 * 1000 );
        }

        /* =========================================================
         *  Pause / Resume / Cancel (progress panel buttons)
         * ========================================================= */
        $pauseBtn.on( 'click', function () {
                if ( ! activeBackupId ) { return; }
                $( this ).prop( 'disabled', true );
                post( 'ibp_pause_backup', { backup_id: activeBackupId } ).done( function ( res ) {
                        if ( res.success ) {
                                toast( 'Backup paused.', 'ok' );
                                showPausedControls();
                        } else {
                                toast( res.data.message || 'Could not pause.', 'err' );
                        }
                        $pauseBtn.prop( 'disabled', false );
                } );
        } );

        $resumeBtn.on( 'click', function () {
                if ( ! activeBackupId ) { return; }
                $( this ).prop( 'disabled', true );
                post( 'ibp_resume_backup', { backup_id: activeBackupId } ).done( function ( res ) {
                        if ( res.success ) {
                                toast( 'Backup resumed.', 'ok' );
                                showRunningControls();
                                // Make sure we're polling.
                                if ( ! activePollTimer ) {
                                        pollBackupStatus( activeBackupId );
                                }
                        } else {
                                toast( res.data.message || 'Could not resume.', 'err' );
                        }
                        $resumeBtn.prop( 'disabled', false );
                } );
        } );

        $cancelBtn.on( 'click', function () {
                if ( ! activeBackupId ) { return; }
                confirmDialog( {
                        title:    'Cancel this backup?',
                        message:  'The partial archive will be deleted. This cannot be undone.',
                        okText:   'Cancel backup',
                        cancelText: 'Keep running',
                        variant:  'danger'
                } ).then( function ( ok ) {
                        if ( ! ok ) { return; }
                        $cancelBtn.prop( 'disabled', true );
                        post( 'ibp_cancel_backup', { backup_id: activeBackupId } ).done( function ( res ) {
                                if ( res.success ) {
                                        toast( 'Backup cancelled.', 'ok' );
                                        if ( activePollTimer ) { clearInterval( activePollTimer ); activePollTimer = null; }
                                        refreshList();
                                        refreshStats();
                                        hideProgress();
                                } else {
                                        toast( res.data.message || 'Could not cancel.', 'err' );
                                }
                                $cancelBtn.prop( 'disabled', false );
                        } );
                } );
        } );

        /* =========================================================
         *  Pause / Resume / Cancel (table row buttons)
         * ========================================================= */
        $( document ).on( 'click', '.ibp-act--pause', function () {
                var id = $( this ).data( 'id' );
                post( 'ibp_pause_backup', { backup_id: id } ).done( function ( res ) {
                        toast( res.success ? res.data.message : ( res.data.message || 'Pause failed.' ), res.success ? 'ok' : 'err' );
                        refreshList();
                } );
        } );

        $( document ).on( 'click', '.ibp-act--resume', function () {
                var id = $( this ).data( 'id' );
                post( 'ibp_resume_backup', { backup_id: id } ).done( function ( res ) {
                        toast( res.success ? res.data.message : ( res.data.message || 'Resume failed.' ), res.success ? 'ok' : 'err' );
                        refreshList();
                        // Also attach a poll for this backup if we don't have one.
                        if ( res.success && ! activePollTimer ) {
                                activeBackupId = id;
                                showProgress( id );
                                pollBackupStatus( id );
                        }
                } );
        } );

        $( document ).on( 'click', '.ibp-act--cancel', function () {
                var id = $( this ).data( 'id' );
                confirmDialog( {
                        title:    'Cancel this backup?',
                        message:  'The partial archive will be deleted. This cannot be undone.',
                        okText:   'Cancel backup',
                        cancelText: 'Keep running',
                        variant:  'danger'
                } ).then( function ( ok ) {
                        if ( ! ok ) { return; }
                        post( 'ibp_cancel_backup', { backup_id: id } ).done( function ( res ) {
                                toast( res.success ? res.data.message : ( res.data.message || 'Cancel failed.' ), res.success ? 'ok' : 'err' );
                                refreshList();
                                refreshStats();
                        } );
                } );
        } );

        /* =========================================================
         *  Refresh list + stats
         * ========================================================= */
        function refreshList() {
                post( 'ibp_list_backups' ).done( function ( res ) {
                        if ( res.success ) { $( '#ibp-rows' ).html( res.data.rows ); }
                } );
        }
        $( '#ibp-refresh' ).on( 'click', function () { refreshList(); toast( 'List refreshed.', 'ok' ); } );

        function refreshStats() {
                post( 'ibp_get_stats' ).done( function ( res ) {
                        if ( ! res.success ) { return; }
                        $.each( res.data, function ( key, val ) {
                                $( '[data-stat="' + key + '"]' ).text( val );
                        } );
                } );
        }

        /* Resume watching any backup still in progress on page load. */
        function resumeInProgressPolling() {
                $( '#ibp-rows > tr[data-status="running"], #ibp-rows > tr[data-status="pending"], #ibp-rows > tr[data-status="paused"]' ).each( function () {
                        var backupId = $( this ).data( 'id' );
                        if ( backupId && ! activePollTimer ) {
                                activeBackupId = backupId;
                                showProgress( backupId );
                                pollBackupStatus( backupId );
                                return false; // Only watch one at a time via the progress panel.
                        }
                } );
        }
        resumeInProgressPolling();

        /* =========================================================
         *  Download dropdown (Both / ZIP / Installer)
         * ========================================================= */
        function triggerDownload( url ) {
                if ( ! url ) { return; }
                var a = document.createElement( 'a' );
                a.href = url;
                a.style.display = 'none';
                document.body.appendChild( a );
                a.click();
                setTimeout( function () { a.remove(); }, 1000 );
        }

        $( document ).on( 'click', function ( e ) {
                var $toggle = $( e.target ).closest( '.ibp-act--dl-toggle' );
                if ( $toggle.length ) {
                        var $menu     = $toggle.siblings( '.ibp-dl-menu' );
                        var wasHidden = $menu.prop( 'hidden' );
                        $( '.ibp-dl-menu' ).prop( 'hidden', true );
                        if ( wasHidden ) {
                                var rect = $toggle[ 0 ].getBoundingClientRect();
                                $menu.css( {
                                        top:   ( rect.bottom + 6 ) + 'px',
                                        right: ( window.innerWidth - rect.right ) + 'px'
                                } );
                        }
                        $menu.prop( 'hidden', ! wasHidden );
                        return;
                }
                if ( $( e.target ).closest( '.ibp-dl-menu' ).length ) {
                        $( '.ibp-dl-menu' ).prop( 'hidden', true );
                        return;
                }
                $( '.ibp-dl-menu' ).prop( 'hidden', true );
        } );

        /* Close any open dropdown on scroll so it doesn't drift away from
           its button (it's position:fixed to escape the table's clipping). */
        $( window ).on( 'scroll resize', function () {
                $( '.ibp-dl-menu' ).prop( 'hidden', true );
        } );

        $( document ).on( 'click', '.ibp-dl-both', function () {
                var zipUrl       = $( this ).data( 'zip' );
                var installerUrl = $( this ).data( 'installer' );
                triggerDownload( zipUrl );
                setTimeout( function () { triggerDownload( installerUrl ); }, 400 );
        } );

        /* =========================================================
         *  Delete
         * ========================================================= */
        $( document ).on( 'click', '.ibp-act--del', function () {
                var id = $( this ).data( 'id' );
                confirmDialog( {
                        title:    'Delete this backup?',
                        message:  cfg.i18n.confirmDelete,
                        okText:   'Delete',
                        cancelText: 'Keep',
                        variant:  'danger'
                } ).then( function ( ok ) {
                        if ( ! ok ) { return; }
                        post( 'ibp_delete_backup', { backup_id: id } )
                                .done( function ( res ) {
                                        if ( res.success ) {
                                                toast( res.data.message, 'ok' );
                                                refreshList();
                                                refreshStats();
                                        } else {
                                                toast( res.data.message, 'err' );
                                        }
                                } );
                } );
        } );

        /* =========================================================
         *  Restore (modal)
         * ========================================================= */
        $( document ).on( 'click', '.ibp-act--restore', function () {
                restoreTarget = $( this ).data( 'id' );
                $( '#ibp-restore-modal' ).prop( 'hidden', false );
        } );
        $( '#ibp-rs-cancel' ).on( 'click', function () {
                $( '#ibp-restore-modal' ).prop( 'hidden', true );
                restoreTarget = null;
        } );
        $( '#ibp-restore-modal' ).on( 'click', function ( e ) {
                if ( e.target === this ) { $( this ).prop( 'hidden', true ); restoreTarget = null; }
        } );
        $( '#ibp-rs-confirm' ).on( 'click', function () {
                if ( ! restoreTarget ) { return; }
                var $btn = $( this ).prop( 'disabled', true ).text( cfg.i18n.working );
                post( 'ibp_restore_backup', {
                        backup_id: restoreTarget,
                        restore_db: $( '#ibp-rs-db' ).is( ':checked' ) ? 1 : 0,
                        restore_files: $( '#ibp-rs-files' ).is( ':checked' ) ? 1 : 0
                } )
                        .done( function ( res ) {
                                toast( res.success ? res.data.message : res.data.message, res.success ? 'ok' : 'err' );
                        } )
                        .fail( function () { toast( 'Restore failed.', 'err' ); } )
                        .always( function () {
                                $btn.prop( 'disabled', false ).text( 'Restore now' );
                                $( '#ibp-restore-modal' ).prop( 'hidden', true );
                                restoreTarget = null;
                        } );
        } );

        /* =========================================================
         *  Settings
         * ========================================================= */
        $( '#ibp-set-compression' ).on( 'input', function () {
                $( '#ibp-comp-val' ).text( $( this ).val() );
        } );

        $( '#ibp-save-settings' ).on( 'click', function () {
                var $btn = $( this ).prop( 'disabled', true );
                var settings = {
                        max_local_backups: $( '#ibp-set-retention' ).val(),
                        compression_level: $( '#ibp-set-compression' ).val(),
                        email_on_complete: $( '#ibp-set-email' ).val(),
                        archive_format: $( '#ibp-set-format' ).val(),
                        exclude_patterns: $( '#ibp-set-excludes' ).val(),
                        db_charset_fix: $( '#ibp-set-charset' ).is( ':checked' ) ? 1 : 0
                };
                post( 'ibp_save_settings', { settings: settings } )
                        .done( function ( res ) {
                                toast( res.success ? res.data.message : 'Save failed.', res.success ? 'ok' : 'err' );
                                if ( res.success ) {
                                        refreshStats();
                                        refreshList();
                                }
                        } )
                        .always( function () { $btn.prop( 'disabled', false ); } );
        } );

        /* =========================================================
         *  Schedule
         * ========================================================= */
        var selectedFreq = ( cfg.schedule && cfg.schedule.frequency ) || 'off';
        $( '#ibp-freq .ibp-seg' ).on( 'click', function () {
                $( '#ibp-freq .ibp-seg' ).removeClass( 'is-active' );
                $( this ).addClass( 'is-active' );
                selectedFreq = $( this ).data( 'freq' );
                $( '#ibp-custom-freq-row' ).prop( 'hidden', 'custom' !== selectedFreq );
        } );

        $( '#ibp-save-schedule' ).on( 'click', function () {
                var $btn = $( this ).prop( 'disabled', true );
                post( 'ibp_save_schedule', {
                        frequency: selectedFreq,
                        schedule_type: $( '#ibp-sched-type' ).val(),
                        custom_value: $( '#ibp-custom-value' ).val(),
                        custom_unit: $( '#ibp-custom-unit' ).val()
                } )
                        .done( function ( res ) {
                                if ( res.success ) {
                                        toast( res.data.message, 'ok' );
                                        $( '#ibp-next-run' ).text( res.data.label );
                                } else {
                                        toast( 'Could not save schedule.', 'err' );
                                }
                        } )
                        .always( function () { $btn.prop( 'disabled', false ); } );
        } );

} )( jQuery );