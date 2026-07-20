/**
 * Entry point — mounts the React app into #wptd-root.
 *
 * @wordpress/element re-exports React, so we never import React directly.
 * This keeps the bundle small (React is shared with WordPress core).
 */
import { render } from '@wordpress/element';
import App from './components/App';
import './index.css';

const root = document.getElementById( 'wptd-root' );
if ( root ) {
    render( <App />, root );
}
