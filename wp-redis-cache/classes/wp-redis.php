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
     * Flag to determine if Redis memory limit has been reached.
     *
     * @access public
     * @since 1.0
     * @var String
     */
    var $is_memory_limit_reached;

    /**
     * Flag to determine if requested page is a search page.
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $is_search;

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

        // Instantiate configuration.
        $config = new WP_Redis_Config();

        // Set configuration defaults.
        $this->set_configuration_defaults( $config );

        // Include Predis.
        include_once realpath( __DIR__ . '/../predis/lib/Predis/Autoloader.php' );
        Predis\Autoloader::register();

        // Setup Redis client.
        $this->redis = new Predis\Client(
            array(
                'host' => $config->redis_host,
                'port' => $config->redis_port
            )
        );

        // Configure Redis.
        $this->site_name = $config->site_name;
        $this->excluded_categories = $config->exclude_categories;
        $this->is_memory_limit_reached = $this->is_memory_limit_reached( $config );
        $this->domain = $this->get_domain();
        $this->is_user_logged_in = $this->is_user_logged_in();
        $this->is_search = $this->is_search( $config );

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

    /**
     * Runs position query and adds post positions to the cache.
     */
    function run_post_position_query() {

        global $wpdb;

        // Delete all post position caches.
        $this->redis->del( $this->get_key( $this::POST_POSITION_KEY ) );

        // Run post position query.
        $posts = $wpdb->get_results( $this::SELECT_POST_POSITION );

        // Reset post position.
        $post_position = 0;

        // Loop over published posts.
        foreach ( $posts as $post ) {

            // Get the post categories
            $post_categories = wp_get_post_categories( $post->id );

            // Check if post category is being excluded on the index page.
            if ( ! $this->array_in_array( $this->excluded_categories, $post_categories ) ) {

                // Increment post position.
                $post_position++;

                // Cache post position.
                $this->redis->hset( $this->get_key( $this::POST_POSITION_KEY ), $post->id, $post_position );

            } // end if

        } // end foreach

    } // end run_post_position_query

    /* Private
    ---------------------------------------------- */

    /**
     * This has to be used because `in_array` is broke when `needle` is
     * an array (aka. comparing an array to an array).
     *
     * @param   array       $needles    Array of vales to look for.
     * @param   array       $haystacks  Array of values to look in.
     * @return  boolean                 If a value within `needles` was found in `haystacks`.
     */
    private function array_in_array( $needles, $haystacks ) {

        // Remove array keys.
        $needles = array_values( $needles );
        $haystacks = array_values( $haystacks );

        // Loop over needles.
        foreach ( $needles as $needle ) {

            // Loop over haystacks
            foreach ( $haystacks as $haystack ) {

                // Compare values
                if ( $needle === $haystack ) {

                    // Value matched.
                    return true;

                } // end if

            } // end foreach

        } // end foreach

        // No values matched.
        return false;

    } // end array_in_array

    /**
     * Gets the domain of the page being loaded.
     *
     * @return  string  The domain of the page being loaded.
     */
    private function get_domain() {

        // Get host and strip `www` subdomain, but leave all others alone.
        return str_replace( 'www.', '', strtolower( $_SERVER['HTTP_HOST'] ) );

    } // end get_domain

    /**
     * Gets the path of the page being requested and strips the caching query string
     * parameters.
     *
     * @return  string  The path of the page being requested, without caching query strings.
     */
    private function get_page_path() {

        // Get request.
        $path = strtolower( $_SERVER['REQUEST_URI'] );

        // Strip query string.
        $path = str_replace( '?' . $_SERVER['QUERY_STRING'], '', $path );

        // Strip comments identifier.
        $path = str_replace( '#comments', '', $path );

        // Return cleansed URI.
        return $path;

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
     * Determines if the Redis memory limit has been reached..
     *
     * @param   WP_Redis_Config     $config     The configuration file for WP Redis.
     * @return  boolean                         Flag to check if memory limit has been reached.
     */

    private function is_memory_limit_reached( $config ) {

        // Check for memory limit.
        if ( ! isset( $config->redis_memory_limit ) ) {

            // Not enforcing a memory limit.
            return false;

        } // end if

        // Get Redis info.
        $redis_info = $this->redis->info();

        // Get Redis used memory
        $used_memory_bytes = floatval( $redis_info['Memory']['used_memory'] );

        // Get memory limit bytes.
        $memory_limit_bytes = $this->size_to_bytes( $config->redis_memory_limit );

        // Check for bytes.
        if ( empty( $used_memory_bytes ) || empty( $memory_limit_bytes ) ) {

            // Unable to determine.
            return false;

        } // end if

        // Check if memory limit has been reached.
        return ( $used_memory_bytes > $memory_limit_bytes );

    } // end is_memory_limit_reached

    /**
     * Determines if requested page is a search page.
     *
     * @param   WP_Redis_Config     $config     The configuration file for WP Redis.
     * @return  boolean                         Flag to check if requested page is a search page.
     */

    private function is_search( $config ) {

        return isset( $_GET[$config->search_query_string] );

    } // end is_search

    /**
     * Determines if user is logged in or not based on cookies set by WordPress.
     *
     * @return  boolean     Flag to check if user is logged in.
     */

    private function is_user_logged_in() {

        return ( preg_match( '/wordpress_logged_in/', var_export( $_COOKIE, true ) ) );

    } // end is_user_logged_in

    /**
     * Determines if user is logged in or not based on cookies set by WordPress.
     *
     * @param   WP_Redis_Config     $config     The configuration file for WP Redis.
     * @return  boolean     Flag to check if user is logged in.
     */

    private function set_configuration_defaults( $config ) {

        // Check for delete domain query string.
        if ( ! isset( $config->delete_domain_cache_query_string ) ) {

            // Set a default delete domain query string.
            $config->delete_domain_cache_query_string = 'delete-domain-cache';

        } // end if

        // Check for delete page query string.
        if ( ! isset( $config->delete_page_cache_query_string ) ) {

            // Set a default delete page query string.
            $config->delete_page_cache_query_string = 'delete-page-cache';

        } // end if

        // Check for Redis host.
        if ( ! isset( $config->redis_host ) ) {

            // Set a default Redis host.
            $config->redis_host = $_SERVER['CACHE2_HOST'];

        } // end if

        // Check for Redis port.
        if ( ! isset( $config->redis_port ) ) {

            // Set a default Redis port.
            $config->redis_port = $_SERVER['CACHE2_PORT'];

        } // end if

        // Check for site name.
        if ( ! isset( $config->site_name ) ) {

            // Set a default site name.
            $config->site_name = 'WP Redis';

        } // end if

        // Check for search query string
        if ( ! isset( $config->search_query_string ) ) {

            // Set a default search query string.
            $config->search_query_string = 's';

        } // end if

    } // end set_configuration_defaults

    /**
     * Converts a human readable size (ie. `50MB`) into actual bytes.
     *
     * @param   string      $readable_size  Human readable size (ie. `50 MB`).
     * @return  float|int                   Actual size in bytes.
     */
    function size_to_bytes( $readable_size ) {

        // Define expected sizes.
        $expected_sizes = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );

        // Get the number portion of the size and initialize the bytes.
        $bytes = preg_replace( '/[^0-9]*/','', $readable_size );

        // Get the text potion of the size.
        $size_text = trim( preg_replace( '/\d/', '', $readable_size ) );

        // Calculate multiplier.
        $multiplier = array_search( $size_text, $expected_sizes );

        // Check if there was an error in calculating the multiplier
        if ( ! $multiplier ) {

            // Return undefined.
            return 0;

        } // end if

        // Keep multiplying size based on multiplier.
        while ( $multiplier != 0 ) {

            // Multiply current size by kilobytes.
            $bytes = $bytes * 1024;

            // Decrement multiplier.
            $multiplier--;

        } // end while

        // Return size in bytes.
        return floatval( $bytes );

    } // end size_to_bytes

} // end class