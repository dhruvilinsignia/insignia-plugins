/**
 * GlobalSearch.js — VS Code-style global search panel.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { searchCode } from '../api/download';
import { Icon, FileIcon } from './Icon';
import { toast } from './Toast';

export default function GlobalSearch( { type, slug, onOpenFile, onClose } ) {
	const [ query, setQuery ]             = useState( '' );
	const [ caseSensitive, setCaseSens ]  = useState( false );
	const [ useRegex, setUseRegex ]       = useState( false );
	const [ loading, setLoading ]         = useState( false );
	const [ result, setResult ]           = useState( null );
	const [ error, setError ]             = useState( null );
	const [ expandedFiles, setExpandedFiles ] = useState( new Set() );
	const inputRef = useRef( null );
	const debounceRef = useRef( null );

	useEffect( () => { inputRef.current?.focus(); }, [] );

	const runSearch = async ( q ) => {
		if ( ! q || ! q.trim() ) {
			setResult( null );
			setError( null );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const res = await searchCode( type, slug, q, { caseSensitive, regex: useRegex } );
			setResult( res );
			const initial = new Set( res.results.slice( 0, 10 ).map( ( r ) => r.path ) );
			setExpandedFiles( initial );
		} catch ( err ) {
			setError( err.message || __( 'Search failed', 'wptd' ) );
			toast( err.message, 'error' );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		if ( debounceRef.current ) clearTimeout( debounceRef.current );
		debounceRef.current = setTimeout( () => runSearch( query ), 350 );
		return () => { if ( debounceRef.current ) clearTimeout( debounceRef.current ); };
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ query, caseSensitive, useRegex, type, slug ] );

	const toggleFile = ( path ) => {
		setExpandedFiles( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( path ) ) next.delete( path );
			else next.add( path );
			return next;
		} );
	};

	const openMatch = ( file, match ) => {
		onOpenFile( {
			path: file.path,
			name: file.name,
			ext: file.ext,
			type: 'file',
		}, match.line, match.col );
	};

	const totalHits = result?.hits || 0;
	const totalFiles = result?.results?.length || 0;

	return (
		<div className="wptd-gsearch">
			<div className="wptd-gsearch__head">
				<div className="wptd-gsearch__title">
					<Icon name="search" size={ 14 } />
					<span>{ __( 'Global search', 'wptd' ) }</span>
				</div>
				<button className="wptd-icon-btn wptd-icon-btn--small" onClick={ onClose } aria-label={ __( 'Close search', 'wptd' ) }>
					<Icon name="close" size={ 13 } />
				</button>
			</div>

			<div className="wptd-gsearch__input-wrap">
				<input
					ref={ inputRef }
					type="text"
					className="wptd-gsearch__input"
					placeholder={ __( 'Search across all files…', 'wptd' ) }
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
				/>
				<div className="wptd-gsearch__toggles">
					<button
						className={ `wptd-gsearch__toggle ${ caseSensitive ? 'is-active' : '' }` }
						onClick={ () => setCaseSens( ( v ) => ! v ) }
						title={ __( 'Match case', 'wptd' ) }
					>Aa</button>
					<button
						className={ `wptd-gsearch__toggle ${ useRegex ? 'is-active' : '' }` }
						onClick={ () => setUseRegex( ( v ) => ! v ) }
						title={ __( 'Use regular expression', 'wptd' ) }
					>.*</button>
				</div>
			</div>

			{ loading && (
				<div className="wptd-gsearch__loading">
					<span className="wptd-btn-spinner wptd-btn-spinner--xs" />
					<span>{ __( 'Searching files…', 'wptd' ) }</span>
				</div>
			) }

			{ error && (
				<div className="wptd-gsearch__error">
					<Icon name="alert-circle" size={ 28 } />
					<p>{ error }</p>
				</div>
			) }

			{ ! loading && ! error && result && totalFiles === 0 && (
				<div className="wptd-gsearch__empty">
					<Icon name="search" size={ 28 } />
					<p>{ __( 'No matches in', 'wptd' ) } { result.filesScanned || 0 } { __( 'scanned files', 'wptd' ) }</p>
				</div>
			) }

			{ ! loading && ! error && result && totalFiles > 0 && (
				<>
					<div className="wptd-gsearch__summary">
						<strong>{ totalHits }</strong> { __( 'results in', 'wptd' ) } <strong>{ totalFiles }</strong> { __( 'files', 'wptd' ) }
						{ result.truncated && <span className="wptd-gsearch__truncated">· { __( 'truncated', 'wptd' ) }</span> }
					</div>

					<div className="wptd-gsearch__list">
						{ result.results.map( ( file ) => {
							const isExpanded = expandedFiles.has( file.path );
							return (
								<div key={ file.path } className="wptd-gsearch__file">
									<div
										className="wptd-gsearch__file-head"
										onClick={ () => toggleFile( file.path ) }
									>
										<span className="wptd-gsearch__caret">
											<Icon name={ isExpanded ? 'chevron-down' : 'chevron-right' } size={ 12 } />
										</span>
										<span className="wptd-gsearch__file-icon">
											<FileIcon ext={ file.ext } size={ 14 } />
										</span>
										<span className="wptd-gsearch__file-name" title={ file.path }>{ file.name }</span>
										<span className="wptd-gsearch__file-path">{ file.path }</span>
										<span className="wptd-gsearch__file-count">{ file.count }</span>
									</div>
									{ isExpanded && (
										<ul className="wptd-gsearch__hits">
											{ file.matches.map( ( m, i ) => (
												<li
													key={ i }
													className="wptd-gsearch__hit"
													onClick={ () => openMatch( file, m ) }
												>
													<span className="wptd-gsearch__line">L{ m.line }</span>
													<code
														className="wptd-gsearch__preview"
														dangerouslySetInnerHTML={ { __html: highlightMatch( m.preview, m.match ) } }
													/>
												</li>
											) ) }
										</ul>
									) }
								</div>
							);
						} ) }
					</div>
				</>
			) }
		</div>
	);
}

function highlightMatch( preview, match ) {
	if ( ! match || ! preview ) return escapeHtml( preview || '' );
	const safe = escapeHtml( preview );
	const safeMatch = escapeHtml( match );
	const escaped = safeMatch.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	try {
		return safe.replace( new RegExp( escaped, 'gi' ), '<mark>$&</mark>' );
	} catch ( e ) {
		return safe;
	}
}

function escapeHtml( s ) {
	return String( s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}
