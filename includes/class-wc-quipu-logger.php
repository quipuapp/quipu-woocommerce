<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Quipu_Logger {

	/**
	 * @var String
	 */
	private $debug;

	/**
	 * WC_Quipu_Logger constructor.
	 *
	 * @param WC_XR_debug $debug
	 */
	public function __construct( $debug ) {
		$this->debug = $debug;
	}

	/**
	 * Check if logging is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {

		// Check if debug is on
		if ( 'yes' === $this->debug ) {
			return true;
		}

		return false;
	}

	/**
	 * Write the message to log
	 *
	 * @param String $message
	 */
	public function write( $message ) {

		// Check if enabled
		if ( $this->is_enabled() ) {

			// Logger object
			$wc_logger = new WC_Logger();

			// Add to logger
			$wc_logger->add( 'quipu', $message );
		}

	}

}