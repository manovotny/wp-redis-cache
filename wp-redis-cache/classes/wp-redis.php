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
     * The index page type key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const INDEX_KEY = ':index';

    /**
     * The post position key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const POST_POSITION_KEY = ':post-position';

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
     * The single page type key.
     *
     * This get gets paired with the `domain` to create a Redis key.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const SINGLE_KEY = ':single';

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
     * Flag to determine if a user is logged in.
     *
     * @access public
     * @since 1.0
     * @var boolean
     */
    var $is_user_logged_in;

    /**
     * Redis key.
     *
     * This is a combination of domain and page type.
     *
     * @access public
     * @since 1.0
     * @var String
     */
    var $key;

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
     * @var Predis
     */
    var $path;

    /**
     * The Redis client instance used to interact with the Redis data store.
     *
     * @access public
     * @since 1.0
     * @var Predis
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

        // Get config. 
        $config = new WP_Redis_Config();

        // Include Predis.
        include_once realpath( __DIR__ . '/../lib/predis.php' );

        // Get Redis client.
        $this->redis  = new Predis\Client(
            array(
                'host'   => $config->redis_host,
                'port'   => $config->redis_port
            )
        );

        // Configure Redis.
        $this->site_name = $config->site_name;
        $this->is_user_logged_in = $this->wp_redis_is_user_logged_in();
        $this->excluded_categories = $config->exclude_categories;

        /*
         * The following variables must be populated in this specific order. 
         * Do not rearrange them!
         */
        $this->path = $this->wp_redis_get_page_path();
        $this->page_type = $this->wp_redis_get_page_type();
        $this->domain = $this->wp_redis_get_domain();
        $this->key = $this->wp_redis_get_key();

    } // end constructor

    /* Functions
    ---------------------------------------------------------------------------------- */

    /* Private
    ---------------------------------------------- */

    /**
     * Gets the domain of the page being loaded.
     *
     * @return  string  The domain of the page being loaded.
     */
    private function wp_redis_get_domain() {

        return $_SERVER['HTTP_HOST'];

    } // end wp_redis_get_domain

    /**
     * Combines the domain name with the type of page being requested.
     *
     * This is done so we can use Redis objects to group similar types of
     * pages together.
     *
     * @return  string  The domain plus the type of page being loaded.
     */
    private function wp_redis_get_key() {

        return $this->domain . ':' . $this->page_type;

    } // end wp_redis_get_key

    /**
     * Gets the path of the page being requested and strips the caching query string
     * parameters.
     *
     * @return  string  The path of the page being requested, minus caching query strings.
     */
    private function wp_redis_get_page_path() {

        // Get requested path.
        $path = $_SERVER['REQUEST_URI'];

        // Strip `clear page` query string.
        $path = str_replace( '?r=y', '', $path );

        // Strip `clear cache` query string.
        $path = str_replace( '?c=y', '', $path );

        // Return cleansed path.
        return $path;

    } // end wp_redis_get_page_path

    /**
     * Determines if the page being requested is an index page or a single page.
     *
     * @return  string  The type of page being loaded, either `index` or `single`.
     */
    private function wp_redis_get_page_type() {

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

    } // end wp_redis_get_page_type

    /**
     * Determines if user is logged in or not based on cookies set by WordPress.
     *
     * @return  boolean     Flag to check if user is logged in.
     */

    private function wp_redis_is_user_logged_in() {

        return ( preg_match( '/wordpress_logged_in/', var_export( $_COOKIE, true ) ) );

    } // end wp_redis_is_user_logged_in

} // end class