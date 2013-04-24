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
     * Query string to delete domain cache.
     *
     * @access public
     * @since 1.0
     * @var array
     */
    var $delete_domain_cache_query_string;

    /**
     * Query string to delete page cache.
     *
     * @access public
     * @since 1.0
     * @var array
     */
    var $delete_page_cache_query_string;

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
    var $site_name;

    /* Constructor
    ---------------------------------------------------------------------------------- */

    /**
     * Initializes class properties.
     */
    function __construct() {

        // Check for delete domain query string.
        if ( ! isset( $this->delete_domain_cache_query_string ) ) {

            // Set a default delete domain query string.
            $this->delete_domain_cache_query_string = 'delete-domain-cache';

        } // end if

        // Check for delete page query string.
        if ( ! isset( $this->delete_page_cache_query_string ) ) {

            // Set a default delete page query string.
            $this->delete_page_cache_query_string = 'delete-page-cache';

        } // end if

        // Check for Redis host.
        if ( ! isset( $this->redis_host ) ) {

            // Set a default Redis host.
            $this->redis_host = $_SERVER['CACHE2_HOST'];

        } // end if

        // Check for Redis port.
        if ( ! isset( $this->redis_port ) ) {

            // Set a default Redis port.
            $this->redis_port = $_SERVER['CACHE2_PORT'];

        } // end if

        // Check for site name.
        if ( ! isset( $this->site_name ) ) {

            // Set a default site name.
            $this->site_name = 'WP Redis';

        } // end if

    } // end constructor

} // end class
