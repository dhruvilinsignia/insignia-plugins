/**
 * ConfirmDialog.js — reusable, branded replacement for the browser's
 * native `confirm()` / `alert()` dialogs.
 *
 * Two ways to use it:
 *
 *  1. Promise-based imperative API (drop-in replacement for `confirm()`):
 *
 *         import { confirmDialog, alertDialog } from './ConfirmDialog';
 *
 *         const ok = await confirmDialog( {
 *             title: __( 'Delete file?', 'wptd' ),
 *             message: __( 'This cannot be undone.', 'wptd' ),
 *             confirmLabel: __( 'Delete', 'wptd' ),
 *             variant: 'danger',
 *         } );
 *         if ( ! ok ) return;
 *
 *  2. Mount <ConfirmDialogHost /> once near the app root (next to <ToastHost />).
 *
 * The host listens on a module-level queue and renders whichever dialog
 * was most recently requested. This keeps the call sites simple — they
 * don't need to manage their own open/close state.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon } from './Icon';
import { uid } from './utils';

// ── Module-level queue (single producer, single consumer) ───────────────────
//
// `_pending` holds an array of { resolve, options } tuples. When a new
// request comes in we push it onto the queue and the host picks it up.
// Only one dialog is shown at a time; subsequent requests queue behind it.

let _enqueue = null;

/**
 * Show a confirmation dialog and resolve to `true` (confirm) or `false`
 * (cancel). Works exactly like the native `confirm()`, but pretty.
 *
 * @param {Object}   opts
 * @param {string}   opts.title         Dialog title.
 * @param {string|ReactNode} opts.message  Body text or JSX.
 * @param {string}  [opts.confirmLabel] Label for the confirm button.
 * @param {string}  [opts.cancelLabel]  Label for the cancel button.
 * @param {'primary'|'danger'|'warning'} [opts.variant] Visual style.
 * @param {string}  [opts.icon]         Icon name from Icon.js.
 * @param {boolean} [opts.hideCancel]   If true, only show the confirm button (alert mode).
 * @returns {Promise<boolean>}
 */
export function confirmDialog( opts ) {
	return new Promise( ( resolve ) => {
		if ( _enqueue ) {
			_enqueue( { resolve, options: { variant: 'primary', ...opts } } );
		} else {
			// Host not mounted yet — fail safe (treat as not confirmed).
			resolve( false );
		}
	} );
}

/**
 * Show an alert dialog (single button). Resolves to `true` when dismissed.
 */
export function alertDialog( opts ) {
	return new Promise( ( resolve ) => {
		if ( _enqueue ) {
			_enqueue( {
				resolve,
				options: {
					variant: 'primary',
					hideCancel: true,
					confirmLabel: __( 'OK', 'wptd' ),
					...opts,
				},
			} );
		} else {
			resolve( true );
		}
	} );
}

const VARIANT_ICON = {
	primary: 'info',
	danger: 'alert-circle',
	warning: 'warning',
	success: 'check-circle',
};

export default function ConfirmDialogHost() {
	const [ current, setCurrent ] = useState( null );
	// Queue of pending requests. We only render the head of the queue.
	const [ queue, setQueue ] = useState( [] );

	const enqueue = useCallback( ( item ) => {
		setQueue( ( prev ) => [ ...prev, item ] );
	}, [] );

	useEffect( () => {
		_enqueue = enqueue;
		return () => { _enqueue = null; };
	}, [ enqueue ] );

	// When the queue becomes non-empty and we have no current dialog,
	// promote the head of the queue to `current`.
	useEffect( () => {
		if ( ! current && queue.length > 0 ) {
			const [ next, ...rest ] = queue;
			setQueue( rest );
			setCurrent( { ...next, id: uid() } );
		}
	}, [ current, queue ] );

	const handleClose = ( result ) => {
		if ( current?.resolve ) {
			current.resolve( result );
		}
		setCurrent( null );
	};

	// ESC to cancel.
	useEffect( () => {
		if ( ! current ) return;
		const h = ( e ) => {
			if ( e.key === 'Escape' && ! current.options.hideCancel ) {
				e.preventDefault();
				handleClose( false );
			}
		};
		window.addEventListener( 'keydown', h );
		return () => window.removeEventListener( 'keydown', h );
	}, [ current ] );

	if ( ! current ) return null;

	const { options } = current;
	const variant = options.variant || 'primary';
	const iconName = options.icon || VARIANT_ICON[ variant ] || 'info';
	const isDanger = variant === 'danger';
	const isWarning = variant === 'warning';

	return (
		<div
			className="wptd-confirm-overlay"
			onClick={ () => ! options.hideCancel && handleClose( false ) }
			role="dialog"
			aria-modal="true"
		>
			<div
				className={ `wptd-confirm wptd-confirm--${ variant }` }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div className="wptd-confirm__header">
					<span className={ `wptd-confirm__icon wptd-confirm__icon--${ variant }` }>
						<Icon name={ iconName } size={ 22 } />
					</span>
					<h3 className="wptd-confirm__title">{ options.title || __( 'Confirm', 'wptd' ) }</h3>
				</div>

				<div className="wptd-confirm__body">
					{ typeof options.message === 'string'
						? <p>{ options.message }</p>
						: options.message }
				</div>

				<div className="wptd-confirm__actions">
					{ ! options.hideCancel && (
						<button
							type="button"
							className="wptd-btn wptd-btn--ghost"
							onClick={ () => handleClose( false ) }
						>
							{ options.cancelLabel || __( 'Cancel', 'wptd' ) }
						</button>
					) }
					<button
						type="button"
						className={ `wptd-btn ${ isDanger ? 'wptd-btn--danger' : ( isWarning ? 'wptd-btn--warning' : 'wptd-btn--primary' ) }` }
						onClick={ () => handleClose( true ) }
						autoFocus
					>
						{ options.confirmLabel || ( options.hideCancel ? __( 'OK', 'wptd' ) : __( 'Confirm', 'wptd' ) ) }
					</button>
				</div>
			</div>
		</div>
	);
}
