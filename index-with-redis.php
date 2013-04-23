<?php

/*
/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\ CONTENTS /\/\/\/\/\/\/\/\/\/\/\/\/\/\//\/\/\/\/\

    1. WordPress
    2. Redis
    3. Caching
        1. Start Timer
        2. Caching Scenarios
        3. End Timer
    3. Helper Functions
        1. Caching
        2. Execution Time
        3. Logging

/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\//\/\/\/\/\/\/\/\/\/\
*/

/* WordPress
---------------------------------------------------------------------------------- */

/*
 * Tell WordPress whether or not to load the theme you’re using, which allows you to
 * use all WordPress’s functionality for something that does not look like WordPress
 * at all.
 *
 * Reference: http://betterwp.net/282-wordpress-constants/
 */
define( 'WP_USE_THEMES', true );

/* Redis
---------------------------------------------------------------------------------- */

include_once 'wp-content/plugins/wp-redis-cache/setup.php';

global $wpredis;

// Check if Redis is ready for use.
if ( ! isset( $wpredis, $wpredis->redis ) ) {

    // Let WordPress create the page.
    require 'wp-blog-header.php';

    // Redis was not setup correctly.
    exit();

} // end if

/* Caching
---------------------------------------------------------------------------------- */

/* Start Timer
---------------------------------------------- */

$start = microtime();

/* Caching Scenarios
---------------------------------------------- */

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
    require 'wp-blog-header.php';

    delete_page_cache();

} else if ( is_cache_deletable() ) {

    // Let WordPress create the page.
    require 'wp-blog-header.php';

    delete_cache();

} else if ( $wpredis->is_user_logged_in ) {

    // Let WordPress create the page.
    require 'wp-blog-header.php';

    bypass_cache();

} else {

    // Turn on the output buffer.
    ob_start();

    // Let WordPress create the page.
    require 'wp-blog-header.php';

    // Read contents of the output buffer.
    $page_content = ob_get_contents();

    // Clean and close the output buffer.
    ob_end_clean();

    cache_page( $page_content );

}

/* End Timer
---------------------------------------------- */

$end = microtime();

// Log execution time.
wpd_display_log( get_execution_time( $start, $end ) );

/* Helper Functions
---------------------------------------------------------------------------------- */

/* Caching
---------------------------------------------- */

/**
 * Bypasses using a cached page.
 */
function bypass_cache() {

    //  Logged in users always see the site as is, in real time.
    wpd_display_log( 'not cached, user is logged in' );

} // end bypass_cache

/**
 * Adds page to cache.
 *
 * @param   string  $page_content   The content of the page being loaded.
 */
function cache_page( $page_content ) {

    global $wpredis;

    // Display page source.
    echo $page_content;

    // Only add the page to the cache if it is not a search results page or a 404 page.
    if ( ! is_404() && ! is_search() ) {

        // Store the contents of the page in the cache.
        $wpredis->redis->hset( $wpredis->key, $wpredis->path, $page_content );

        // Set cached page to expire in one week.
        $wpredis->redis->expireat( 'expire in 1 week', strtotime( '+1 week' ) );

        wpd_display_log( 'cache is set' );

    } // end if

} // end cache_page

/**
 * Deletes entire cache.
 */
function delete_cache() {

    global $wpredis;

    // Check if there is anything cached.
    if ( $wpredis->redis->exists( $wpredis->key ) ) {

        // Get all keys for the domain.
        $keys = $wpredis->redis->keys( $wpredis->key . '*' );

        // Loop over keys since we are using Redis objects to group types of pages together.
        foreach ( $keys as $key ) {

            // Delete key.
            $wpredis->redis->del( $key );

        }

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

    global $wpredis;

    // Delete the page from the cache.
    $wpredis->redis->hdel( $wpredis->key, $wpredis->path );

    wpd_display_log( 'cache of page deleted' );

} // end delete_page_cache

/**
 * Checks to see if the entire cache should be deleted.
 *
 * Criteria:
 *     - User is logged in
 *     - Clear entire cache query string (?c=y) is provided
 *
 * @return  boolean     If the entire cache can be deleted.
 */
function is_cache_deletable() {

    global $wpredis;

    return ( $wpredis->is_user_logged_in && ( isset( $_GET['c'] ) && 'y' == $_GET['c'] ) );

} // end is_cache_deletable

/**
 * Checks to see if the page is already cached and can be used.
 *
 * Criteria:
 *     - Page is already cached
 *     - User is not logged in
 *     - Not submitting a comment
 *     - Not an RSS request
 *     - Not an index page (for now)
 *
 * @return  boolean     If the page is already cached and can be used.
 */
function is_page_cache_available() {

    global $wpredis;

    return ( $wpredis->redis->hexists( $wpredis->key, $wpredis->path ) && ! $wpredis->is_user_logged_in && 'feed' !== $wpredis->page_type );

} // end is_page_cache_available

/**
 * Checks to see if the cached page should be deleted.
 *
 * Criteria:
 *     - Clear page cache query string (?r=y) is provided
 *
 * @return  boolean     If the page cache can be deleted.
 */
function is_page_cache_deletable() {

    return ( isset( $_GET['r'] ) && 'y' == $_GET['r'] );

} // end is_page_cache_deletable

/**
 * Gets page from cache and displays it.
 */
function use_page_cache() {

    global $wpredis;

    // Pull the page from the cache.
    echo $wpredis->redis->hget( $wpredis->key, $wpredis->path );

    wpd_display_log( 'this is a cache' );

} // end use_page_cache

/* Execution Time
---------------------------------------------- */

/**
 * Calculate the amount of time it took to load the page.
 *
 * @param   float   $start  When the page began to load.
 * @param   float   $end    When the page stopped loading.
 * @return  float           How long it took to load the page.
 */
function get_execution_time( $start, $end ) {

    $time = ( get_microtime( $end ) - get_microtime( $start ) );
    return round( $time, 5 );

} // end get_execution_time

/**
 * Returns the Unix timestamp in microseconds based on the incoming value.
 *
 * @param   float   $time   The incoming value of the microseconds.
 * @return  float           The rounded version of the microseconds.
 */
function get_microtime( $time ) {

    list( $usec, $sec ) = explode( ' ', $time );
    return ( (float)$usec + (float)$sec );

} // end get_microtime

/* Logging
---------------------------------------------- */

/**
 * Displays a log message at the bottom of the page.
 *
 * @param   string  $message    The message to display.
 */
function wpd_display_log( $message ) {

    global $wpredis;

    $site_name = '';

    if ( isset( $wpredis->site_name ) ) {

        $site_name = $wpredis->site_name;

    } // end if

    // Display message.
    echo '<!-- ' . $site_name . ' Cache: [ ' . $message . ' ] -->';

} // end wpd_display_log