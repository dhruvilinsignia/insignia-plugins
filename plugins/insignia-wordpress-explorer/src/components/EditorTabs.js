/**
 * EditorTabs.js — open-file tabs above the code editor.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { Icon, FileIcon } from './Icon';

export default function EditorTabs( {
	openFiles, activePath, onSelect, onClose, dirty,
} ) {
	const scrollerRef = useRef( null );

	useEffect( () => {
		const el = scrollerRef.current?.querySelector( '.wptd-tab-file.is-active' );
		if ( el ) el.scrollIntoView( { inline: 'nearest', block: 'nearest' } );
	}, [ activePath ] );

	if ( openFiles.length === 0 ) return null;

	return (
		<div className="wptd-editor-tabs" ref={ scrollerRef }>
			{ openFiles.map( ( f ) => {
				const isDirty = dirty.has( f.path );
				const isActive = f.path === activePath;
				return (
					<div
						key={ f.path }
						className={ `wptd-tab-file ${ isActive ? 'is-active' : '' }` }
						onClick={ () => onSelect( f.path ) }
						title={ f.path }
					>
						<span className="wptd-tab-file__icon">
							<FileIcon ext={ f.ext } size={ 14 } />
						</span>
						<span className="wptd-tab-file__name">{ f.name }</span>
						<button
							className="wptd-tab-file__close"
							onClick={ ( e ) => { e.stopPropagation(); onClose( f.path ); } }
							aria-label={ __( 'Close', 'wptd' ) }
						>
							{ isDirty ? <span className="wptd-tab-file__dirty-dot" /> : <Icon name="close" size={ 12 } /> }
						</button>
					</div>
				);
			} ) }
		</div>
	);
}
