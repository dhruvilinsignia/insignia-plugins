/**
 * StatsBar.js — animated stat cards for the current tab.
 *
 * All icons are SVG (no emojis) for a consistent, professional look.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, useRef } from '@wordpress/element';
import { formatSize } from './utils';
import { Icon } from './Icon';

function useAnimatedNumber( target, duration = 800 ) {
	const [ value, setValue ] = useState( 0 );
	const startRef = useRef( null );
	const fromRef = useRef( 0 );

	useEffect( () => {
		fromRef.current = value;
		startRef.current = null;
		const from = fromRef.current;
		const delta = target - from;

		let raf;
		const step = ( ts ) => {
			if ( startRef.current === null ) startRef.current = ts;
			const p = Math.min( ( ts - startRef.current ) / duration, 1 );
			const eased = 1 - Math.pow( 1 - p, 3 ); // easeOutCubic
			setValue( Math.round( from + delta * eased ) );
			if ( p < 1 ) raf = requestAnimationFrame( step );
		};
		raf = requestAnimationFrame( step );
		return () => cancelAnimationFrame( raf );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ target ] );

	return value;
}

function StatCard( { value, label, accent, icon, sub } ) {
	const animated = useAnimatedNumber( value );
	return (
		<div className={ `wptd-stat wptd-stat--${ accent }` }>
			<div className="wptd-stat__icon">{ icon }</div>
			<div className="wptd-stat__main">
				<span className="wptd-stat__value">{ animated }</span>
				<span className="wptd-stat__label">{ label }</span>
				{ sub && <span className="wptd-stat__sub">{ sub }</span> }
			</div>
		</div>
	);
}

export default function StatsBar( { items, filtered, type, selectedCount } ) {
	const active    = items.filter( ( i ) => i.active ).length;
	const inactive  = items.length - active;
	const totalSize = items.reduce( ( sum, i ) => sum + ( i.size || 0 ), 0 );
	const label     = type === 'plugins' ? __( 'plugins', 'wptd' ) : __( 'themes', 'wptd' );

	return (
		<div className="wptd-stats">
			<StatCard
				value={ items.length }
				label={ `${ __( 'Total', 'wptd' ) } ${ label }` }
				accent="total"
				icon={ <Icon name="archive" size={ 20 } /> }
			/>
			<StatCard
				value={ active }
				label={ __( 'Active', 'wptd' ) }
				accent="active"
				icon={ <Icon name="check-circle" size={ 20 } /> }
			/>
			<StatCard
				value={ inactive }
				label={ __( 'Inactive', 'wptd' ) }
				accent="inactive"
				icon={ <Icon name="minus" size={ 20 } /> }
			/>
			<div className="wptd-stat wptd-stat--size">
				<div className="wptd-stat__icon">
					<Icon name="hard-drive" size={ 20 } />
				</div>
				<div className="wptd-stat__main">
					<span className="wptd-stat__value">{ formatSize( totalSize ) }</span>
					<span className="wptd-stat__label">{ __( 'Total size', 'wptd' ) }</span>
				</div>
			</div>
			{ filtered.length !== items.length && (
				<div className="wptd-stat wptd-stat--filtered">
					<div className="wptd-stat__icon">
						<Icon name="search" size={ 20 } />
					</div>
					<div className="wptd-stat__main">
						<span className="wptd-stat__value">{ filtered.length }</span>
						<span className="wptd-stat__label">{ __( 'Matching search', 'wptd' ) }</span>
					</div>
				</div>
			) }
			{ selectedCount > 0 && (
				<div className="wptd-stat wptd-stat--selected">
					<div className="wptd-stat__icon">
						<Icon name="check" size={ 20 } strokeWidth={ 2.6 } />
					</div>
					<div className="wptd-stat__main">
						<span className="wptd-stat__value">{ selectedCount }</span>
						<span className="wptd-stat__label">{ __( 'Selected for bulk', 'wptd' ) }</span>
					</div>
				</div>
			) }
		</div>
	);
}
