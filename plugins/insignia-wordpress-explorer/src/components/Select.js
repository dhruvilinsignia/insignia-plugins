/**
 * Select.js — small, fully custom dropdown used in place of the native
 * <select>. Native <option> lists are rendered by the OS and can't be
 * restyled (that's the plain white/blue list you get with a real <select>),
 * so this renders its own trigger button + floating panel that follows the
 * app's theme, with hover/active states and a checkmark on the current
 * value.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Icon } from './Icon';

export default function Select( { value, onChange, options, ariaLabel, icon } ) {
	const [ open, setOpen ] = useState( false );
	const [ activeIndex, setActiveIndex ] = useState( -1 );
	const rootRef = useRef( null );

	const selectedIndex = options.findIndex( ( o ) => o.value === value );
	const selected = options[ selectedIndex ] || options[ 0 ];

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const handlePointerDown = ( e ) => {
			if ( rootRef.current && ! rootRef.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Escape' ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handlePointerDown );
		document.addEventListener( 'keydown', handleKeyDown );
		return () => {
			document.removeEventListener( 'mousedown', handlePointerDown );
			document.removeEventListener( 'keydown', handleKeyDown );
		};
	}, [ open ] );

	const openPanel = () => {
		setActiveIndex( selectedIndex >= 0 ? selectedIndex : 0 );
		setOpen( true );
	};

	const commit = ( opt ) => {
		if ( opt.value !== value ) {
			onChange( opt.value );
		}
		setOpen( false );
	};

	const onTriggerKeyDown = ( e ) => {
		if ( e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			openPanel();
		}
	};

	const onPanelKeyDown = ( e ) => {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setActiveIndex( ( i ) => Math.min( options.length - 1, i + 1 ) );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setActiveIndex( ( i ) => Math.max( 0, i - 1 ) );
		} else if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			if ( options[ activeIndex ] ) {
				commit( options[ activeIndex ] );
			}
		} else if ( e.key === 'Tab' ) {
			setOpen( false );
		}
	};

	return (
		<div className={ `wptd-dselect ${ open ? 'is-open' : '' }` } ref={ rootRef }>
			<button
				type="button"
				className="wptd-dselect__trigger"
				aria-haspopup="listbox"
				aria-expanded={ open }
				aria-label={ ariaLabel }
				onClick={ () => ( open ? setOpen( false ) : openPanel() ) }
				onKeyDown={ onTriggerKeyDown }
			>
				<span className="wptd-dselect__icon" aria-hidden="true">
					<Icon name={ icon || 'sort' } size={ 14 } strokeWidth={ 2.3 } />
				</span>
				<span className="wptd-dselect__value">{ selected?.label || '' }</span>
				<span className="wptd-dselect__chevron" aria-hidden="true">
					<Icon name="chevron-down" size={ 14 } strokeWidth={ 2.4 } />
				</span>
			</button>

			{ open && (
				<div
					className="wptd-dselect__panel"
					role="listbox"
					aria-label={ ariaLabel }
					tabIndex={ -1 }
					onKeyDown={ onPanelKeyDown }
				>
					{ options.map( ( opt, i ) => (
						<button
							type="button"
							key={ opt.value }
							role="option"
							aria-selected={ opt.value === value }
							className={ `wptd-dselect__option ${ opt.value === value ? 'is-selected' : '' } ${ i === activeIndex ? 'is-active' : '' }` }
							onMouseEnter={ () => setActiveIndex( i ) }
							onClick={ () => commit( opt ) }
						>
							<span className="wptd-dselect__option-label">{ opt.label }</span>
							{ opt.value === value && (
								<span className="wptd-dselect__check" aria-hidden="true">
									<Icon name="check" size={ 14 } strokeWidth={ 2.8 } />
								</span>
							) }
						</button>
					) ) }
					{ options.length === 0 && (
						<div className="wptd-dselect__empty">{ __( 'No options', 'wptd' ) }</div>
					) }
				</div>
			) }
		</div>
	);
}
