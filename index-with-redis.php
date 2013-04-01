<?php

/*
	This code is based on `index-with-redis.php`
	by Jim Westergren & Jeedo Aquino.
	
	URL:	http://www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/
	Gist: 	https://gist.github.com/JimWestergren/3053250
*/

/*----------------------------------------------------------------------*
 * Configuration and Caching
 *----------------------------------------------------------------------*/

/*--------------------------------------------*
 * Display Variables
 *--------------------------------------------*/

$debug = 1;						// set to 1 if you wish to see execution time and cache actions

/*--------------------------------------------*
 * Start Timing Page Execution
 *--------------------------------------------*/
 
$start = microtime(); 

/** 
 * Tell WordPress whether or not to load the theme you’re using, which allows you to use all 
 * WordPress’s functionality for something that doesn’t look like WordPress at all!
 *
 * Notes on this constant: http://betterwp.net/282-wordpress-constants/
 */
define( 'WP_USE_THEMES', true );

/*--------------------------------------------*
 * Initialize Predis
 *--------------------------------------------*/
 
include( "./predis.php" );
$redis = new Predis\Client(
	array(
    	'host'   => $_SERVER['CACHE2_HOST'],
    	'port'   => $_SERVER['CACHE2_PORT'],
    )
);

/*--------------------------------------------*
 * Server Configuration Variables
 *--------------------------------------------*/

$domain = $_SERVER['HTTP_HOST'];
$url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$url = str_replace( '?r=y', '', $url );
$url = str_replace( '?c=y', '', $url );
$dkey = md5( $domain );
$ukey = md5( $url );

// Check that the page isn't a comment submission
( ( isset( $_SERVER['HTTP_CACHE_CONTROL'] ) && ( $_SERVER['HTTP_CACHE_CONTROL'] == 'max-age=0' ) ) ? $submit = 1 : $submit = 0 );

// Check if we're logged into WordPress or not
$cookie = var_export( $_COOKIE, true );
$loggedin = preg_match( "/wordpress_logged_in/", $cookie );

/*--------------------------------------------------------------------------------------------------------------------*
 * Caching
 *
 * Note that this section uses tips from http://phpmaster.com/an-introduction-to-redis-in-php-using-predis/
 * for setting an expiration on the cache values.
 *--------------------------------------------------------------------------------------------------------------------*/

// If this page is cache and we're not logged in and we're not submitting a comment and we're not reading an RSS and we're not on the homepage
if ( $redis->hexists( $dkey, $ukey ) && ! $loggedin && ! $submit && ! strpos( $url, '/feed/' ) && $_SERVER["REQUEST_URI"] != '/' ) {

	// Pull the page from the cache
    echo $redis->hget( $dkey, $ukey );
    $cached = 1;
    $msg = 'this is a cache';

// Otherwise, if a comment was submitted or a 'clear page cache' request was sent...
} else if ( $submit || substr( $_SERVER['REQUEST_URI'], -4 ) == '?r=y' ) {

	// Delete the page from the cache
    require( './wp-blog-header.php' );
    $redis->hdel( $dkey, $ukey );
    $msg = 'cache of page deleted';


// If we're logged in and we pass the '?c=y' parameter...
} else if ( $loggedin && substr( $_SERVER['REQUEST_URI'], -4 ) == '?c=y' ) {

	// Then delete the entire cache
    require( 'wp-blog-header.php' );
    if ( $redis->exists( $dkey ) ) {
    
        $redis->del( $dkey );
        $msg = 'domain cache flushed';
        
    } else {
        $msg = 'no cache to flush';
    } // end if/else

// If we're logged in...
} else if ( $loggedin ) {

	// ...don't cache anything
    require( 'wp-blog-header.php' );
    $msg = 'not cached';

// If we're submitting a comment or the page cache request was sent...
} else if ( $submit || substr( $_SERVER['REQUEST_URI'], -4 ) == '?r=y' ) {

	// Delete the cache of the page
    require( 'wp-blog-header.php' );
    $redis->hdel( $dkey, $ukey );
    $msg = 'cache of page deleted';
    
    // TODO: this is where we need to clear the index as well

// Otherwise, we cache the entire page
} else {

    // Turn on the output buffering
    ob_start();
    require( 'wp-blog-header.php' );

    // Read the contents of the output buffer into $html
    $html = ob_get_contents();

    // Clean the output buffer
    ob_end_clean();
    
    // Echo the $html
    echo $html;
    
    // Store the contents of the page into the cache (if we're not a search result or a 404)
    if ( ! is_404() && ! is_search() ) { 
    
	    $redis->hset( $dkey, $ukey, $html );
	    
	    // Now set the value to expire in one week. We'll try this first.
	    $redis->expireat( "expire in 1 week", strtotime( "+1 week" ) );
	    $msg = 'cache is set';
	    
    } // end if
    
} // end if/else

/*--------------------------------------------*
 * End Timing Page Execution
 *--------------------------------------------*/
$end = microtime(); 

// If debug is enabled...
if ( $debug ) {
	
	// Print out the page execution time
	$html = '<!-- WP Daily Cache [ ';
		$html .= $msg . ': ';
		$html .= t_exec( $start, $end );
	$html .= ' ] -->';
	
    echo $html;
    
} // end if

/*----------------------------------------------------------------------*
 * Helper Functions
 *----------------------------------------------------------------------*/

/**
 * Calculate the amount of time it took to load the page.
 *
 * @param	float	$start	When the page began to load.
 * @param	float	$end	When the page stopped loading.
 * @return	float			How long it took to load the page.
 */
function t_exec( $start, $end ) {

    $t = ( getmicrotime( $end ) - getmicrotime( $start ) );
    return round( $t, 5 );
    
} // end t_exec

/**
 * Returns the Unix timestamp in microseconds based on the incoming value
 *
 * @param	float	$t		The incoming value of the microseconds.
 * @return	float			The rounded version of the microseconds.
 */
function getmicrotime( $t ) {

    list( $usec, $sec ) = explode( " ", $t );
    return ( (float)$usec + (float)$sec );
    
} // end getmicrotime