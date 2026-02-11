<?php
declare(strict_types=1);

namespace PhraseMatch;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and handles AJAX endpoints for scanning and removal.
 */
class Ajax_Handler {

    private Scanner $scanner;
    private Remover $remover;

    public function __construct( Scanner $scanner, Remover $remover ) {
        $this->scanner = $scanner;
        $this->remover = $remover;
    }

    /**
     * Register the wp_ajax actions.
     */
    public function register(): void {
        add_action( 'wp_ajax_phrasematch_scan', [ $this, 'handle_scan' ] );
        add_action( 'wp_ajax_phrasematch_remove', [ $this, 'handle_remove' ] );
    }

    /**
     * AJAX handler: scan for phrase occurrences.
     */
    public function handle_scan(): void {
        if ( ! check_ajax_referer( 'phrasematch_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'phrasematch' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'phrasematch' ) ] );
        }

        $phrase     = isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '';
        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) )
            : [];
        $statuses   = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) )
            : [ 'publish' ];

        if ( '' === $phrase ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a phrase to search for.', 'phrasematch' ) ] );
        }

        if ( empty( $post_types ) ) {
            wp_send_json_error( [ 'message' => __( 'Please select at least one post type.', 'phrasematch' ) ] );
        }

        // Validate post types against registered types.
        $valid_types = get_post_types( [ 'public' => true ] );
        $post_types  = array_intersect( $post_types, array_keys( $valid_types ) );

        if ( empty( $post_types ) ) {
            wp_send_json_error( [ 'message' => __( 'None of the selected post types are valid.', 'phrasematch' ) ] );
        }

        $results = $this->scanner->scan( $phrase, $post_types, $statuses );

        wp_send_json_success( [
            'results' => $results,
            'total'   => count( $results ),
            'phrase'  => $phrase,
        ] );
    }

    /**
     * AJAX handler: remove or replace selected phrase occurrences.
     *
     * Items are received as a JSON string to avoid jQuery nested-object
     * serialization issues with $.post(). Each item may include an optional
     * replace_with field; when non-empty the phrase is replaced rather than removed.
     */
    public function handle_remove(): void {
        if ( ! check_ajax_referer( 'phrasematch_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'phrasematch' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'phrasematch' ) ] );
        }

        $phrase     = isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '';
        $items_json = isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '';

        // Decode the JSON items string.
        $items = is_string( $items_json ) ? json_decode( $items_json, true ) : [];

        if ( '' === $phrase || ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing phrase or items to process.', 'phrasematch' ) ] );
        }

        // Group items by post_id.
        $grouped = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $post_id      = absint( $item['post_id'] ?? 0 );
            $mode         = sanitize_key( $item['mode'] ?? 'text_only' );
            $char_offset  = (int) ( $item['char_offset'] ?? -1 );
            $location     = sanitize_key( $item['location'] ?? 'content' );
            $replace_with = isset( $item['replace_with'] ) ? sanitize_text_field( $item['replace_with'] ) : '';

            if ( 0 === $post_id || $char_offset < 0 ) {
                continue;
            }

            if ( ! in_array( $mode, [ 'text_only', 'html_element', 'gutenberg_block' ], true ) ) {
                $mode = 'text_only';
            }

            if ( ! in_array( $location, [ 'title', 'content' ], true ) ) {
                $location = 'content';
            }

            // Title matches are always text_only when removing.
            if ( 'title' === $location && '' === $replace_with ) {
                $mode = 'text_only';
            }

            if ( ! isset( $grouped[ $post_id ] ) ) {
                $grouped[ $post_id ] = [];
            }

            $grouped[ $post_id ][] = [
                'char_offset'  => $char_offset,
                'mode'         => $mode,
                'location'     => $location,
                'replace_with' => $replace_with,
            ];
        }

        $post_results = [];

        foreach ( $grouped as $post_id => $occurrences ) {
            $result = $this->remover->remove( $post_id, $phrase, $occurrences );

            $post_results[] = [
                'post_id'       => $post_id,
                'title'         => get_the_title( $post_id ),
                'success'       => $result['success'],
                'message'       => $result['message'],
                'revisions_url' => $result['revisions_url'],
            ];
        }

        wp_send_json_success( [ 'results' => $post_results ] );
    }

}
