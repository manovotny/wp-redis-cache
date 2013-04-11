<?php

/*
 * This code is based on `index-with-redis.php` by Jim Westergren & Jeedo Aquino.
 *
 * URL:	http://www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/
 * Gist: 	https://gist.github.com/JimWestergren/3053250
*/

/*----------------------------------------------------------------------*
 * Configuration
 *----------------------------------------------------------------------*/

/*
 * Adds a HTML comment to the bottom of the page source with caching
 * messages and statistics.
 *
 * - Set to `true` to add the comment.
 * - Set to `false` to not add the comment.
 */
$logging_enabled = true;

/*
 * Sets the site name.
 *
 * This is used in the cache comment output.
 */
if( ! defined( 'SITE_NAME' ) ) {

    define( 'SITE_NAME', 'WP Daily' );

} // end if

/*
 * Sets Redis connection.
 */
$redis_host = $_SERVER['CACHE2_HOST'];
$redis_port = $_SERVER['CACHE2_PORT'];

/*----------------------------------------------------------------------*
 * WordPress
 *----------------------------------------------------------------------*/

/*
 * Tell WordPress whether or not to load the theme you’re using, which allows you to use all
 * WordPress’s functionality for something that doesn’t look like WordPress at all.
 *
 * Reference: http://betterwp.net/282-wordpress-constants/
 */
define( 'WP_USE_THEMES', true );

// Set the path to the `wp-blog-header.php` file.
$wp_blog_header_path = './wp-blog-header.php';

/*----------------------------------------------------------------------*
 * Caching
 *----------------------------------------------------------------------*/

/*--------------------------------------------*
 * Start Timing Page Execution
 *--------------------------------------------*/

$start = microtime();

/*--------------------------------------------*
 * Initialize Predis
 *--------------------------------------------*/

include( './predis.php' );
$redis = new Predis\Client(
    array(
        'host'   => $redis_host,
        'port'   => $redis_port
    )
);

/*--------------------------------------------*
 * Page Request Details
 *--------------------------------------------*/

// Get domain.
$domain = $_SERVER['HTTP_HOST'];

// Get the requested URL path and remove caching query parameters.
$path = $_SERVER['REQUEST_URI'];
$path = str_replace( '?r=y', '', $path );
$path = str_replace( '?c=y', '', $path );

// Determines if a comment is being submitted.
$comment_submitted = isset( $_POST['comment_post_ID'] ) ? true : false;

// Determines if a user is logged into WordPress.
$is_user_logged_in = preg_match( '/wordpress_logged_in/', var_export( $_COOKIE, true ) );

// Determines if feed is being requested.
$is_feed = strpos( $url, '/feed/' );

/*--------------------------------------------*
 * Caching Scenarios
 *--------------------------------------------*/

/*
 * Caching settings and vales are based on PHP and Redis best practices.
 *
 * Reference: http://phpmaster.com/an-introduction-to-redis-in-php-using-predis/
 */

/*
 * The `require` call for `wp-blog-header.php` has to be done within the global scope.
 * It cannot be called from within a function, so we must leave all those calls out here.
 */

if ( is_page_cache_available() ) {

    use_page_cache();

} else if ( is_page_cache_deletable() ) {

    // Let WordPress create the page.
    require( $wp_blog_header_path );

    delete_page_cache();

} else if ( is_cache_deletable() ) {

    // Let WordPress create the page.
    require( $wp_blog_header_path );

    delete_cache();

} else if ( $is_user_logged_in ) {

    // Let WordPress create the page.
    require( $wp_blog_header_path );

    bypass_cache();

} else {

    // Turn on the output buffer.
    ob_start();

    // Let WordPress create the page.
    require( $wp_blog_header_path );

    // Read contents of the output buffer.
    $page_content = ob_get_contents();

    // Clean and close the output buffer.
    ob_end_clean();

    cache_page( $page_content );

}

/*--------------------------------------------*
 * End Timing Page Execution
 *--------------------------------------------*/

$end = microtime();

// Check if we need to add a cache comment.
if ( $logging_enabled ) {

    wpd_display_log( t_exec( $start, $end ) );

} // end if

/*----------------------------------------------------------------------*
 * Helper Functions
 *----------------------------------------------------------------------*/

/*--------------------------------------------*
 * Caching Scenarios
 *--------------------------------------------*/

/**
 * Logged in users always see the site as is, in real time.
 */
function bypass_cache() {

    wpd_display_log( 'not cached, user is logged in' );

} // end bypass_cache

/**
 * Adds page to cache.
 *
 * @param   string   $page_content  The HTML of the page.
 */
function cache_page( $page_content ) {

    global $domain, $path, $redis;

    // Display page source.
    echo $page_content;

    // Only add the page to the cache if it is not a search results page or a 404 page.
    if ( ! is_404() && ! is_search() ) {

        // Store the contents of the page in the cache.
        $redis->hset( $domain, $path, $page_content );

        // Set cached page to expire in one week.
        $redis->expireat( 'expire in 1 week', strtotime( '+1 week' ) );
        wpd_display_log( 'cache is set' );

    } // end if

} // end cache_page

/**
 * Deletes entire cache.
 */
function delete_cache() {

    global $domain, $redis;

    // Check if there is anything cached.
    if ( $redis->exists( $domain ) ) {

        // Delete the entire cache.
        $redis->del( $domain );
        wpd_display_log( 'domain cache flushed' );

    } else {

        // No cache to delete.
        wpd_display_log( 'no cache to flush' );

    } // end if/else

} // end delete_cache

/**
 * Deletes page from cache.
 */
function delete_page_cache() {

    global $domain, $path, $redis;

    // Delete the page from the cache.
    $redis->hdel( $domain, $path );

    wpd_display_log( 'cache of page deleted' );

} // end delete_page_cache

/**
 * Checks to see if the entire cache should be deleted.
 *
 * Criteria:
 *     - User is logged in
 *     - Clear entire cache query string (?c=y) is provided
 */
function is_cache_deletable() {

    global $is_user_logged_in;

    return ( $is_user_logged_in && ( isset( $_GET['c'] ) && 'y' == $_GET['c'] ) );

} // end is_cache_deletable

/**
 * Checks to see if the page is already cached and can be used.
 *
 * Criteria:
 *     - Page is already cached
 *     - User is not logged in
 *     - Not submitting a comment
 *     - Not an RSS request
 */
function is_page_cache_available() {

    global $comment_submitted, $domain, $is_feed, $is_user_logged_in, $path, $redis;

    return ( $redis->hexists( $domain, $path ) && ! $is_user_logged_in && ! $comment_submitted && ! $is_feed );

} // end is_page_cache_available

/**
 * Checks to see if the cached page should be deleted.
 *
 * Criteria:
 *     - Comment submitted
 *     - Clear page cache query string (?r=y) is provided
 */
function is_page_cache_deletable() {

    global $comment_submitted;

    return ( $comment_submitted || ( isset( $_GET['r'] ) && 'y' == $_GET['r'] ) );

} // end is_page_cache_deletable

/**
 * Gets page from cache and displays it.
 */
function use_page_cache() {

    global $domain, $path, $redis;

    // Pull the page from the cache.
    echo $redis->hget( $domain, $path );
    wpd_display_log( 'this is a cache' );

} // end use_page_cache

/*--------------------------------------------*
 * Execution Time
 *--------------------------------------------*/

/**
 * Calculate the amount of time it took to load the page.
 *
 * @param   float   $start  When the page began to load.
 * @param   float   $end    When the page stopped loading.
 * @return  float           How long it took to load the page.
 */
function t_exec( $start, $end ) {

    $t = ( getmicrotime( $end ) - getmicrotime( $start ) );
    return round( $t, 5 );

} // end t_exec

/**
 * Returns the Unix timestamp in microseconds based on the incoming value.
 *
 * @param   float   $t      The incoming value of the microseconds.
 * @return  float           The rounded version of the microseconds.
 */
function getmicrotime( $t ) {

    list( $usec, $sec ) = explode( ' ', $t );
    return ( (float)$usec + (float)$sec );

} // end getmicrotime

/*--------------------------------------------*
 * Logging
 *--------------------------------------------*/

/**
 * Displays a log message at the bottom of the page.
 *
 * @param	string	$msg	The message to display
 */
function wpd_display_log( $msg ) {

    echo '<!-- ' . SITE_NAME . ' Cache: [ ' . $msg . ' ] -->';

} // end wpd_display_log