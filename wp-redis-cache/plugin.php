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

        global $wpredis;

        // Do not process revisions
        if ( ! $this->wp_redis_cache_is_post_revision( $post ) ) {

            // Check if post was added.
            if ( $this->wp_redis_cache_was_post_added( $new_status, $old_status ) ) {

                // Delete index cache.
                $this->wp_redis_cache_delete_index_cache();

                // Run position query.
                $wpredis->run_post_position_query();

            // Check if post was removed
            } else if ( $this->wp_redis_cache_was_post_removed( $new_status, $old_status ) ) {

                // Delete index cache.
                $this->wp_redis_cache_delete_index_cache();

                // Delete page cache.
                $this->wp_redis_cache_delete_page_cache( $post );

                // Delete paginated comments.
                $this->wp_redis_cache_delete_paginated_comments( $post );

                // Run position query.
                $wpredis->run_post_position_query();

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

        /*
         * When a post is removed from the public timeline, WordPress
         * removes the "pretty" permalink and replaces it with a placeholder
         * "fugly" permalink (ie. ?p=1234).
         *
         * We need to check if the post is published or not. If it is not,
         * then we need to generate a sample permalink like the WordPress
         * admin does.
         */

        // Check if post is published.
        if ( $this::PUBLISH === $post->post_status ) {

            // Get post's permalink
            $permalink = get_permalink( $post->ID );

        } else {

            // Generate a sample permalink.
            list( $permalink, $post_name ) = get_sample_permalink( $post->ID );
            $permalink = str_replace( '%postname%', $post_name, $permalink );

        } // end if

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

        // Delete paginated comments.
        $this->wp_redis_cache_delete_paginated_comments( $post );

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
        $wpredis->redis->del( $wpredis->get_key( $wpredis::INDEX_KEY ) );

    } // end wp_redis_cache_delete_index_cache

    /**
     * Deletes paginated comments related to a page.
     *
     * @param   WP_Post     $post   Post object.
     */
    function wp_redis_cache_delete_paginated_comments( $post ) {

        global $wpredis;

        if ( isset( $post ) ) {

            // Get permalink path.
            $path = $this->wp_redis_cache_get_permalink_path( $post );

            // Delete paginated comments.
            $wpredis->delete_paginated_comments( $path );

        } // end if

    } // end wp_redis_cache_delete_paginated_comments

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
            $wpredis->redis->hdel( $wpredis->get_key( $wpredis::SINGLE_KEY ), $path );

            // Delete paginated comments.
            $wpredis->delete_paginated_comments( $path );

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
            $position = $wpredis->redis->hget( $wpredis->get_key( $wpredis::POST_POSITION_KEY ), $post->ID );

            // Get posts per page option.
            $posts_per_page = get_option( 'posts_per_page' );

            // Calculate what index page a post is on.
            $page_index = ceil( $position / $posts_per_page );

            // Delete the index page from the cache.
            $wpredis->redis->hdel( $wpredis->get_key( $wpredis::INDEX_KEY ), '/page/' . $page_index . '/' );

        } // end if

    } // end delete_index_position_cache

} // end class

/* Instantiation
---------------------------------------------------------------------------------- */

// Instantiate plugin.
new WP_Redis_Cache();