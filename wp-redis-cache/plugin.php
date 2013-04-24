<?php
/*
Plugin Name: WP Redis Cache
Plugin URI: https://github.com/eightbit/wp-redis-cache
Description: Compliments index.php replacement for total caching coverage using Redis.
Version: 1.0
Author: 8BIT
Author URI: http://8bit.io
Author Email: sales@8bit.io
License: GNU General Public License v3.0 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/*
/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\ CONTENTS /\/\/\/\/\/\/\/\/\/\/\/\/\/\//\/\/\/\/\

    1. Properties
        1. Constants
    2. Constructor
    3. Actions
        1. Posts
        2. Comments
    3. Helpers
        1. Posts
        2. Comments
        3. Redis
        4. PHP
    4. Instantiation

/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\/\//\/\/\/\/\/\/\/\/\/\
*/

class WP_Redis_Cache {

    /* Properties
    ---------------------------------------------------------------------------------- */

    /* Constants
    ---------------------------------------------- */

    /**
     * Post `publish` state.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const PUBLISH = 'publish';

    /**
     * Post `revision` state.
     *
     * @access public
     * @since 1.0
     * @var string
     */
    const REVISION = 'revision';

    /* Constructor
    ---------------------------------------------------------------------------------- */

    /**
     * Initializes the plugin by setting localization, filters, and administration functions.
     */
    function __construct() {

        include_once 'wp-redis-setup.php';

        global $wpredis;

        // Only add hooks if Redis is ready to go.
        if ( isset( $wpredis, $wpredis->redis, $wpredis->domain ) ) {

            // Register post actions.
            add_action( 'transition_post_status', array( $this, 'wp_redis_cache_transition_post_status' ), 10, 3 );

            // Register comment actions.
            add_action( 'edit_comment', array( $this, 'wp_redis_cache_edit_comment' ) );
            add_action( 'transition_comment_status', array( $this, 'wp_redis_cache_transition_comment_status' ), 10, 3 );
            add_action( 'wp_insert_comment', array( $this, 'wp_redis_cache_wp_insert_comment_action' ), 10, 2 );

        }

    } // end constructor

    /* Actions
    ---------------------------------------------------------------------------------- */

    /* Posts
    ---------------------------------------------------------------- */

    /**
     * Action triggered when the post status has changed.
     *
     * @param   string      $new_status     The new post status.
     * @param   string      $old_status     The old post status.
     * @param   WP_Post     $post           Post object.
     */
    function wp_redis_cache_transition_post_status( $new_status, $old_status, $post ) {

        // Do not process revisions
        if ( ! $this->wp_redis_cache_is_post_revision( $post ) ) {

            // Check if post was added.
            if ( $this->wp_redis_cache_was_post_added( $new_status, $old_status ) ) {

                // Delete index cache.
                $this->wp_redis_cache_delete_index_cache();

                // Run position query.
                $this->wp_redis_cache_run_post_position_query();

            // Check if post was removed
            } else if ( $this->wp_redis_cache_was_post_removed( $new_status, $old_status ) ) {

                // Delete index cache.
                $this->wp_redis_cache_delete_index_cache();

                /*
                 * It is not possible to delete the page cache in this scenario.
                 *
                 * 1. We are saving pages to the cache based on the page URL, aka.
                 * "pretty" permalink.
                 *
                 * 2. Since the post was removed from the public timeline, WordPress
                 * has already removed the "pretty" permalink and replaced it with
                 * the "fugly" permalink (ie. ?p=1234).
                 */

                // Delete page cache.
                //$this->wp_redis_cache_delete_page_cache( $post );

                // Run position query.
                $this->wp_redis_cache_run_post_position_query();

            // Check if post was updated.
            } else if ( $this->wp_redis_cache_was_post_updated( $new_status, $old_status ) ) {

                // Delete page cache.
                $this->wp_redis_cache_delete_page_cache( $post );

                // Delete page index cache.
                $this->wp_redis_cache_delete_page_index_cache( $post );

            } // end if / else

        } // end if

    } // end wp_redis_cache_transition_post_status

    /* Comments
    ---------------------------------------------------------------- */

    /**
     * Action triggered when comment is edited.
     *
     * @param   string  $comment_id     The comment id.
     */
    function wp_redis_cache_edit_comment( $comment_id ) {

        // Get comment object.
        $comment = get_comment( $comment_id );

        // Comment has changed.
        $this->wp_redis_cache_comments_changed( $comment );

    } // end wp_redis_cache_edit_comment 

    /**
     * Action triggered when comment status changes.
     *
     * @param   string  $new_status     The new comment status.
     * @param   string  $old_status     The old comment status.
     * @param   object  $comment        Comment object.
     */
    function wp_redis_cache_transition_comment_status( $new_status, $old_status, $comment ) {

        // Comment has changed.
        $this->wp_redis_cache_comments_changed( $comment );

    } // end wp_redis_cache_transition_comment_status

    /**
     * Action triggered when comment is created.
     *
     * @param   string  $comment_id     The comment id.
     * @param   object  $comment        Comment object.
     */
    function wp_redis_cache_wp_insert_comment_action( $comment_id, $comment ) {

        // Comment has changed.
        $this->wp_redis_cache_comments_changed( $comment );

    } // end wp_redis_cache_wp_insert_comment_action

    /* Helpers
    ---------------------------------------------------------------------------------- */

    /* Posts
    ---------------------------------------------------------------- */

    /**
     * Gets path portion of a post's permalink URL.
     *
     * @param   WP_Post     $post   Post object.
     * @return  string              Path portion of the post permalink URL.
     */
    function wp_redis_cache_get_permalink_path( $post ) {

        // Get post's permalink
        $permalink = get_permalink( $post->ID );

        // Return just the path portion of the post permalink URL.
        return parse_url( $permalink, PHP_URL_PATH );

    } // end wp_redis_cache_get_permalink_path

    /**
     * Determines if a post is a revision.
     *
     * @param   WP_Post     $post   Post object.
     * @return  boolean             If a post is a revision.
     */
    function wp_redis_cache_is_post_revision( $post ) {

        // Check if post is a revision.
        return ( $this::REVISION === $post->post_type );

    } // end wp_redis_cache_is_post_revision

    /**
     * Determines if post was added to the public timeline.
     *
     * @param   string      $new_status     The new post status.
     * @param   string      $old_status     The old post status.
     * @return  boolean                     If the post was added to the public timeline.
     */
    function wp_redis_cache_was_post_added( $new_status, $old_status ) {

        // Check if post was added to the public timeline.
        return ( $this::PUBLISH === $new_status && $new_status !== $old_status );

    } // end wp_redis_cache_was_post_added

    /**
     * Determines if post was removed to the public timeline.
     *
     * @param   string      $new_status     The new post status.
     * @param   string      $old_status     The old post status.
     * @return  boolean                     If the post was removed from the public timeline.
     */
    function wp_redis_cache_was_post_removed( $new_status, $old_status ) {

        // Check if post was removed to the public timeline.
        return ( $this::PUBLISH !== $new_status && $this::PUBLISH === $old_status );

    } // end wp_redis_cache_was_post_removed

    /**
     * Determines if published post was updated.
     *
     * @param   string      $new_status     The new post status.
     * @param   string      $old_status     The old post status.
     * @return  boolean                     If a published post was updated.
     */
    function wp_redis_cache_was_post_updated( $new_status, $old_status ) {

        // Check if published post was updated.
        return ( $this::PUBLISH === $new_status && $new_status === $old_status );

    } // end wp_redis_cache_was_post_updated

    /* Comments
    ---------------------------------------------------------------- */

    /**
     * Comments have changed.
     *
     * This same function is called when a comment is created, edited, or its
     * status changes.
     *
     * @param   object  $comment    Comment object.
     */
    function wp_redis_cache_comments_changed( $comment ) {

        // Get the post associated with the comment.
        $post = get_post( $comment->comment_post_ID );
        
        // Delete page cache.
        $this->wp_redis_cache_delete_page_cache( $post );

        // Delete page index cache.
        $this->wp_redis_cache_delete_page_index_cache( $post );

    } // end wp_redis_cache_comments_changed

    /* Redis
    ---------------------------------------------------------------- */

    /**
     * Deletes all index page caches.
     */
    function wp_redis_cache_delete_index_cache() {

        global $wpredis;

        // Delete all index keys.
        $wpredis->redis->del( $wpredis->domain . $wpredis::INDEX_KEY);

    } // end wp_redis_cache_delete_index_cache

    /**
     * Deletes a single page cache.
     *
     * @param   WP_Post     $post   Post object.
     */
    function wp_redis_cache_delete_page_cache( $post ) {

        global $wpredis;

        if ( isset( $post ) ) {

            // Get permalink path.
            $path = $this->wp_redis_cache_get_permalink_path( $post );

            // Delete the page from the cache.
            $wpredis->redis->hdel( $wpredis->domain . $wpredis::SINGLE_KEY, $path );

        } // end if

    } // end wp_redis_cache_delete_page_cache

    /**
     * Deletes an index page cache.
     *
     * @param   WP_Post     $post   Post object.
     */
    function wp_redis_cache_delete_page_index_cache( $post ) {

        global $wpredis;

        if ( isset( $post ) ) {

            // Get index page based on post's chronological position.
            $position = $wpredis->redis->hget( $wpredis->domain . $wpredis::POST_POSITION_KEY, $post->ID );

            // Get posts per page option.
            $posts_per_page = get_option( 'posts_per_page' );

            // Calculate what index page a post is on.
            $page_index = ceil( $position / $posts_per_page );

            // Delete the index page from the cache.
            $wpredis->redis->hdel( $wpredis->domain . $wpredis::INDEX_KEY, '/page/' . $page_index . '/' );

        } // end if

    } // end delete_index_position_cache

    /**
     * Runs position query and adds post positions to the cache.
     */
    function wp_redis_cache_run_post_position_query() {

        global $wpredis, $wpdb;

        // Delete all post position caches.
        $wpredis->redis->del( $wpredis->domain . $wpredis::POST_POSITION_KEY );

        // Run post position query.
        $posts = $wpdb->get_results( $wpredis::SELECT_POST_POSITION );

        // Reset post position.
        $post_position = 0;

        // Loop over published posts.
        foreach ( $posts as $post ) {

            // Get the post categories
            $post_categories = wp_get_post_categories( $post->id );

            // Check if post category is being excluded on the index page.
            if ( ! $this->wp_redis_cache_in_array( $wpredis->excluded_categories, $post_categories ) ) {

                // Increment post position.
                $post_position++;

                // Cache post position.
                $wpredis->redis->hset( $wpredis->domain . $wpredis::POST_POSITION_KEY, $post->id, $post_position );

            } // end if

        } // end foreach

    } // end wp_redis_cache_run_post_position_query

    /* PHP
    ---------------------------------------------------------------- */

    /**
     * This has to be used because `in_array` is broke when `needle` is
     * an array (aka. comparing an array to an array).
     *
     * @param   array       $needles    Array of vales to look for.
     * @param   array       $haystacks  Array of values to look in.
     * @return  boolean                 If a value within `needles` was found in `haystacks`.
     */
    function wp_redis_cache_in_array( $needles, $haystacks ) {

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
    }

} // end class

/* Instantiation
---------------------------------------------------------------------------------- */

// Instantiate plugin.
new WP_Redis_Cache();