<?php

/**
 * Class WP_Redis_Config
 *
 * @package WP Redis Cache
 * @subpackage Classes
 * @since 1.0
 */
class WP_Redis_Config {

    /* Properties
    ---------------------------------------------------------------------------------- */

    /**
     * Categories that are excluded on the blog home / index page.
     *
     * @access public
     * @since 1.0
     * @var array
     */
    var $exclude_categories;

    /**
     * The host name / IP address to access Redis.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $redis_host;

    /**
     * The port number to access Redis.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $redis_port;

    /**
     * The name of the site.
     *
     * This is displayed in cache comments added to the site source.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $site_name = 'WP Daily';

    /* Constructor
    ---------------------------------------------------------------------------------- */

    /**
     * Initializes class properties.
     */
    function __construct() {

        // Check for redis host.
        if ( ! isset( $this->redis_host ) ) {

            // Set a default Redis host.
            $this->redis_host = $_SERVER['CACHE2_HOST'];

        } // end if

        // Check for redis port.
        if ( ! isset( $this->redis_port ) ) {

            // Set a default Redis port.
            $this->redis_port = $_SERVER['CACHE2_PORT'];

        } // end if

    } // end constructor

} // end class