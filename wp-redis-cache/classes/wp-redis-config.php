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
     * The amount of memory you want to limit Redis to.
     *
     * The Redis cache will be cleared if the memory limit is reached.
     *
     * Specify values by ending in `MB` or `GB`.
     *
     * Example: 200MB
     *
     * Do not set a values if you do not want to enforce a memory limit.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $redis_memory_limit;

    /**
     * The port number to access Redis.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $redis_port;

    /**
     * The query string parameter used for search.
     *
     * This needs to be known because we do not cache search pages.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $search_query_string;

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

} // end class
