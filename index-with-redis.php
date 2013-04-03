<?php

/*
    This code is based on `index-with-redis.php`
    by Jim Westergren & Jeedo Aquino.

    URL:	http://www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/
    Gist: 	https://gist.github.com/JimWestergren/3053250
*/

/*----------------------------------------------------------------------*
 * Configuration
 *----------------------------------------------------------------------*/

/**
 * Controls if an HTML comment is added to the page source with the caching
 * details.
 *
 * Set to `true` to add the comment.
 *
 * Set to `false` to not add the comment.
 */
$cache_comment = true;

if( ! defined( 'SITE_NAME' ) ) {
	define( 'SITE_NAME', 'WP Daily' );
} // end if

/*----------------------------------------------------------------------*
 * Caching
 *----------------------------------------------------------------------*/
 
/*--------------------------------------------*
 * Start Timing Page Execution
 *--------------------------------------------*/
 
$start = microtime(); 

/** 
 * Tell WordPress whether or not to load the theme you’re using, which allows you to use all 
 * WordPress’s functionality for something that doesn’t look like WordPress at all.
 *
 * Reference: http://betterwp.net/282-wordpress-constants/
 */
define( 'WP_USE_THEMES', true );

/*--------------------------------------------*
 * Initialize Predis
 *--------------------------------------------*/
 
include( './predis.php' );
$redis = new Predis\Client(
    array(
        'host'   => $_SERVER['CACHE2_HOST'],
        'port'   => $_SERVER['CACHE2_PORT'],
    )
);

/*--------------------------------------------*
 * Server Variables
 *--------------------------------------------*/

$domain = $_SERVER['HTTP_HOST'];
$url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$url = str_replace( '?r=y', '', $url );
$url = str_replace( '?c=y', '', $url );
$dkey = md5( $domain );
$ukey = md5( $url );

// Check that the page isn't a comment submission.
$submit = isset( $_POST['comment_post_ID'] ) ? 1 : 0;

// Check if user is logged into WordPress or not.
$cookie = var_export( $_COOKIE, true );
$loggedin = preg_match( '/wordpress_logged_in/', $cookie );

/*--------------------------------------------*
 * Caching Scenarios
 *--------------------------------------------*/

/**
 * Caching settings and vales are based on PHP and Redis best practices.
 *
 * Reference: http://phpmaster.com/an-introduction-to-redis-in-php-using-predis/
 */

/**
 * Scenario: Display already cached page
 *
 * Criteria:
 *     - Page is already cached
 *     - User is not logged in
 *     - Not submitting a comment
 *     - Not RSS
 *     - Not on the home / index page
 */
if ( $redis->hexists( $dkey, $ukey ) && ! $loggedin && ! $submit && ! strpos( $url, '/feed/' ) && '/' != $_SERVER["REQUEST_URI"] ) {

    // Pull the page from the cache.
    echo $redis->hget( $dkey, $ukey );
    $cached = 1;
    wpd_display_log( 'this is a cache' );

/**
 * Scenario: Comment submitted or "clear page cache" request
 *
 * Criteria:
 *     - Comment submitted
 *     - Clear the page cache query string flag (currently doable by anyone, not just logged in users)
 */
} else if ( $submit || '?r=y' == substr( $_SERVER['REQUEST_URI'], -4 ) ) {

    // Delete the page from the cache.
    require( './wp-blog-header.php' );
    $redis->hdel( $dkey, $ukey );
    wpd_display_log( 'cache of page deleted' );

    // TODO: This is where we need to clear the index as well.

/**
 * Scenario: Clear cache for entire site
 *
 * Criteria:
 *     - User is logged in
 *     - Clear the entire site cache query string flag (?c=y)
 */
} else if ( $loggedin && '?c=y' == substr( $_SERVER['REQUEST_URI'], -4 ) ) {

    require( 'wp-blog-header.php' );

    // Check if there is anything cached.
    if ( $redis->exists( $dkey ) ) {

        // Delete the entire cache.
        $redis->del( $dkey );
        wpd_display_log( 'domain cache flushed' );
        
    } else {

        // No cache to delete.
        wpd_display_log( 'no cache to flush' );

    } // end if/else

/**
 * Scenario: Logged in user
 *
 * Criteria:
 *     - User is logged in
 */
} else if ( $loggedin ) {

    // Don't cache anything. Logged in users always see the site as is.
    require( 'wp-blog-header.php' );
    wpd_display_log( 'not cached, user is logged in' );

/**
 * Scenario: Display page
 *
 * Criteria:
 *     - None
 */
} else {

    // Turn on the output buffer.
    ob_start();

    require( 'wp-blog-header.php' );

    // Read the contents of the output buffer.
    $html = ob_get_contents();

    // Clean the output buffer.
    ob_end_clean();

    // Display page source.
    echo $html;

    // Only add the page to the cache if it is not a search results page or a 404 page.
    if ( ! is_404() && ! is_search() ) {

        // Store the contents of the page in the cache.
        $redis->hset( $dkey, $ukey, $html );

        // Set cached page to expire in one week.
        $redis->expireat( 'expire in 1 week', strtotime( '+1 week' ) );
        wpd_display_log( 'cache is set' );

    } // end if

} // end if/else

/*--------------------------------------------*
 * End Timing Page Execution
 *--------------------------------------------*/
$end = microtime(); 

// Check if we need to add a cache comment.
if ( $cache_comment ) {

    // Add comment to source with message and execution time.
    $html = '<!-- WP Redis Cache [ ';
        $html .= $msg . ': ';
        $html .= t_exec( $start, $end );
    $html .= ' ] -->';

    wpd_display_log( $html );

} // end if

/*----------------------------------------------------------------------*
 * Helper Functions
 *----------------------------------------------------------------------*/

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

/**
 * Displays a log message at the bottom of the page.
 *
 * @param	string	$msg	The message to display
 */
function wpd_display_log( $msg ) {
	echo '<!-- ' . SITE_NAME . ' Cache: [ ' . $msg . ' ] -->';
} // end wpd_display_log