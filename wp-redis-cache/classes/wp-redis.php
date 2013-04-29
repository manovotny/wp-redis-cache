<?php

/**
 * Class WP_Redis
 *
 * @package WP Redis
 * @subpackage Classes
 * @since 1.0
 */
class WP_Redis {

    /* Properties
    ---------------------------------------------------------------------------------- */

    /* Constants
    ---------------------------------------------- */

    /**
     * The comment key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const COMMENT_KEY = 'comment';

    /**
     * The index key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const INDEX_KEY = 'index';

    /**
     * The post position key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const POST_POSITION_KEY = 'post-position';

    /**
     * Select query to determine chronological post position.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const SELECT_POST_POSITION =
        "
        SELECT
            ID AS id
        FROM
            wp_posts
        WHERE
            post_type = 'post'
        AND post_status = 'publish'
        ORDER BY
            post_date DESC
        ";

    /**
     * The single key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const SINGLE_KEY = 'single';

    /* Public
    ---------------------------------------------- */

    /**
     * Domain name of the website.
     *
     * @access public
     * @since 1.0
     * @var String
     */
    var $domain;

    /**
     * Categories that are excluded on the blog home / index page.
     *
     * @access public
     * @since 1.0
     * @var array
     */
    var $excluded_categories;

    /**
     * Flag to determine if requested page has comment pagination
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $has_comment_pagination;

    /**
     * Flag to determine if `clear domain cache` query string was provided.
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $has_delete_domain_cache_query_string;

    /**
     * Flag to determine if `clear page cache` query string was provided.
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $has_delete_page_cache_query_string;

    /**
     * Flag to determine if a user is logged in.
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $is_user_logged_in;

    /**
     * The type of page being requested.
     *
     * Page types we are currently using:
     *     - `index`
     *     - `single`
     *
     * @access public
     * @since 1.0
     * @var String
     */
    var $page_type;

    /**
     * Path of the page that is being requested.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $path;

    /**
     * Path of the page that is being requested, with comment pagination removed
     * from the URL.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $path_without_comment_pagination;

    /**
     * The Redis client instance used to interact with the Redis data store.
     *
     * @access public
     * @since 1.0
     * @var Predis\Client
     */
    var $redis;

    /**
     * The name of the site.
     *
     * This is displayed in cache comments added to the page source.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    var $site_name;

    /* Constructor
    ---------------------------------------------------------------------------------- */

    /**
     * Initializes class properties and configures Redis client.
     */
    function __construct() {

        // Include configuration class.
        include_once 'wp-redis-config.php';
        $config = new WP_Redis_Config();

        // Include Predis.
        include_once realpath( __DIR__ . '/../predis/lib/Predis/Autoloader.php' );
        Predis\Autoloader::register();

        // Get Redis client.
        $this->redis  = new Predis\Client(
            array(
                'host' => $config->redis_host,
                'port' => $config->redis_port
            )
        );

        // Configure Redis.
        $this->site_name = $config->site_name;
        $this->excluded_categories = $config->exclude_categories;
        $this->domain = $this->get_domain();
        $this->is_user_logged_in = $this->is_user_logged_in();

        /*
         * The following variables must be populated in this specific order. 
         * Do not rearrange them!
         */
        $this->has_delete_domain_cache_query_string = $this->has_delete_domain_cache_query_string( $config );
        $this->has_delete_page_cache_query_string = $this->has_delete_page_cache_query_string( $config );
        $this->path = $this->get_page_path();
        $this->has_comment_pagination = $this->has_comment_pagination();
        $this->path_without_comment_pagination = $this->get_page_path_without_comment_pagination();
        $this->page_type = $this->get_page_type();

    } // end constructor

    /* Functions
    ---------------------------------------------------------------------------------- */

    /* Public
    ---------------------------------------------- */

    /**
     * Combines the domain name with the requested key type.
     *
     * Defaults to page type being loaded.
     *
     * This is done so we can use Redis objects to group similar types of
     * pages together.
     *
     * Current Redis keys are:
     *     - comment
     *     - index
     *     - post-position
     *     - single
     *
     * @param   string  $key_type (optional)    The type of key to use.
     * @param   string  $path     (optional)    The path to use.
     * @return  string                          The domain plus the type.
     */
    function get_key( $key_type = '', $path = '' ) {

        // Add domain to key.
        $key = $this->domain . ':';

        // Check if requested key type was not provided
        if ( empty( $key_type ) ) {

            // Set key type to page type
            $key_type = $this->page_type;

        } // end if

        // Append key type to key.
        $key .= $key_type;

        // Check for `comment` key type.
        if ( $this::COMMENT_KEY === $key_type ) {

            // Check if a path was supplied.
            if ( empty( $path ) ) {

                // Append path of page loading to key.
                $key .= ':' . $this->path_without_comment_pagination;

            } else {

                // Append supplied path to key.
                $key .= ':' . $path;

            } // end if / else

        } // end if

        // Return key.
        return $key;

    } // end get_key

    /**
     * Deletes paginated comments related to a page.
     *
     * @param   string  $path   The path of the primary page (aka. path without comment pagination).
     */
    function delete_paginated_comments( $path ) {

        // Get comment key.
        $comment_key = $this->get_key( $this::COMMENT_KEY, $path );

        // Get paginated comment pages.
        $members = $this->redis->smembers( $comment_key );

        // Loop over members.
        foreach ( $members as $member ) {

            // Delete the related paginated comment page from the cache.
            $this->redis->hdel( $this->get_key( $this::SINGLE_KEY ), $member );

        } // end foreach

        // Delete the primary page from the cache.
        $this->redis->hdel( $this->get_key( $this::SINGLE_KEY ), $path );

        // Delete paginated comment references.
        $this->redis->del( $comment_key );
        
    } // end delete_paginated_comments 

    /* Private
    ---------------------------------------------- */

    /**
     * Gets the domain of the page being loaded.
     *
     * @return  string  The domain of the page being loaded.
     */
    private function get_domain() {

        return $_SERVER['HTTP_HOST'];

    } // end get_domain

    /**
     * Gets the path of the page being requested and strips the caching query string
     * parameters.
     *
     * @return  string  The path of the page being requested, without caching query strings.
     */
    private function get_page_path() {

        // Get request.
        $uri = $_SERVER['REQUEST_URI'];

        // Strip query string.
        $uri = str_replace( '?' . $_SERVER['QUERY_STRING'], '', $uri );

        // Strip comments identifier.
        $uri = str_replace( '#comments', '', $uri );

        // Return cleansed URI.
        return $uri;

    } // end get_page_path

    /**
     * Removes comment pagination from the path of the page being requested.
     *
     * @return  string  The path of the page being requested, without comment pagination.
     */
    private function get_page_path_without_comment_pagination() {

        // Check for comment pagination.
        if ( $this->has_comment_pagination ) {

            // Remove comment pagination.
            return preg_replace( '%comment-page.*$%i', '', $this->path );

        } // end if

        // No comment pagination to remove.
        return $this->path;

    } // end get_page_path_without_comment_pagination

    /**
     * Determines if the page being requested is an index page or a single page.
     *
     * @return  string  The type of page being loaded, either `index` or `single`.
     */
    private function get_page_type() {

        /*
         * Check for `page` in the path, which denotes index pagination, or if we are at the
         * root index of the site (ie. `/`).
         */
        if ( false !== strpos( $this->path, '/page/' ) || '/' === $this->path ) {

            // Index page.
            return 'index';

        } // end if

        // Check for `feed` in the path, which denotes a feed is being requested.
        if ( false !== strpos( $this->path, '/feed/' ) ) {

            // Feed request.
            return 'feed';

        } // end if

        // We are left to assume this is a single page.
        return 'single';

    } // end get_page_type

    /**
     * Checks if the requested page has comment pagination.
     *
     * @return  boolean     If the requested page has comment pagination.
     */
    private function has_comment_pagination() {

        // Check for comment pagination.
        return ( false !== strpos( $this->path, '/comment-page-' ) );

    } // end has_comment_pagination

    /**
     * Checks to see if the `clear domain cache` query string was provided.
     *
     * @param   WP_Redis_Config     $config     The configuration file for WP Redis.
     * @return  boolean                         If the `clear domain cache` query string was provided.
     */
    private function has_delete_domain_cache_query_string( $config ) {

        return isset( $_GET[$config->delete_domain_cache_query_string] );

    } // end has_delete_domain_cache_query_string

    /**
     * Checks to see if the `clear page cache` query string was provided.
     *
     * @param   WP_Redis_Config     $config     The configuration file for WP Redis.
     * @return  boolean                         If the `clear page cache` query string was provided.
     */
    private function has_delete_page_cache_query_string( $config ) {

        return isset( $_GET[$config->delete_page_cache_query_string] );

    } // end has_delete_page_cache_query_string

    /**
     * Determines if user is logged in or not based on cookies set by WordPress.
     *
     * @return  boolean     Flag to check if user is logged in.
     */

    private function is_user_logged_in() {

        return ( preg_match( '/wordpress_logged_in/', var_export( $_COOKIE, true ) ) );

    } // end is_user_logged_in

} // end class