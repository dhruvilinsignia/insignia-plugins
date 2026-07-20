/**
 * Toast.js — small toast notification system.
 * Toasts auto-dismiss after 4s and slide in from the bottom-right.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { uid } from './utils';

let _push = null;

export function toast( message, type = 'info', timeout = 4000 ) {
	if ( _push ) _push( { id: uid(), message, type, timeout } );
}

export default function ToastHost() {
	const [ items, setItems ] = useState( [] );

	const push = useCallback( ( t ) => {
		setItems( ( prev ) => [ ...prev, t ] );
		if ( t.timeout > 0 ) {
			setTimeout( () => {
				setItems( ( prev ) => prev.filter( ( x ) => x.id !== t.id ) );
			}, t.timeout );
		}
	}, [] );

	useEffect( () => {
		_push = push;
		return () => { _push = null; };
	}, [ push ] );

	const dismiss = ( id ) => setItems( ( prev ) => prev.filter( ( x ) => x.id !== id ) );

	if ( items.length === 0 ) return null;

	return (
		<div className="wptd-toasts">
			{ items.map( ( t ) => (
				<div
					key={ t.id }
					className={ `wptd-toast wptd-toast--${ t.type }` }
					onClick={ () => dismiss( t.id ) }
					role="alert"
				>
					<span className="wptd-toast__icon">
						{ t.type === 'success' && '✓' }
						{ t.type === 'error' && '✕' }
						{ t.type === 'info' && 'ℹ' }
						{ t.type === 'warn' && '⚠' }
					</span>
					<span className="wptd-toast__msg">{ t.message }</span>
				</div>
			) ) }
		</div>
	);
}
