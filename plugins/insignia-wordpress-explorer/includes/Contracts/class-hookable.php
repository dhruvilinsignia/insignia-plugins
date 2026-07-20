<?php
/**
 * Contract: any service that needs to register WordPress hooks
 * must implement this interface.
 *
 * @package WPTD\Contracts
 */

namespace WPTD\Contracts;

defined( 'ABSPATH' ) || exit;

interface Hookable {
    /**
     * Register all add_action / add_filter calls here.
     * Called once by Plugin::register_hooks().
     */
    public function register_hooks(): void;
}
