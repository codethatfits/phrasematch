<?php
declare(strict_types=1);

namespace PhraseMatch;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scans post titles and content for a given phrase and returns per-occurrence
 * results with context snippets and wrapping-type detection.
 *
 * Matching uses byte-offset semantics (PHP string functions). For UTF-8 content,
 * offsets are byte positions, not character positions.
 */
class Scanner {

    /**
     * Number of characters of context to show around each match.
     */
    private const CONTEXT_CHARS = 80;

    /**
     * Scan the selected post types for occurrences of the phrase.
     *
     * @param string   $phrase     The phrase to search for.
     * @param string[] $post_types Post types to search.
     * @param string[] $statuses   Post statuses to include.
     *
     * @return array<int, array> Array of occurrence records.
     */
    public function scan( string $phrase, array $post_types, array $statuses ): array {
        if ( '' === $phrase || empty( $post_types ) ) {
            return [];
        }

        $posts = $this->get_posts_containing_phrase( $phrase, $post_types, $statuses );
        $results = [];

        foreach ( $posts as $post ) {
            $base = [
                'post_id'       => $post->ID,
                'title'         => get_the_title( $post ),
                'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
                'revisions_url' => $this->get_revisions_url_for_post( $post->ID ),
                'post_type'     => $post->post_type,
                'post_status'   => $post->post_status,
            ];

            // --- Check post title ---
            $title_occurrences = $this->find_occurrences( $post->post_title, $phrase );

            foreach ( $title_occurrences as $index => $offset ) {
                $snippet = $this->build_snippet( $post->post_title, $phrase, $offset );

                $results[] = array_merge( $base, [
                    'location'         => 'title',
                    'occurrence_index' => $index,
                    'char_offset'      => $offset,
                    'snippet'          => $snippet,
                    'wrapping'         => 'plain', // titles have no HTML wrapping
                ] );
            }

            // --- Check post content ---
            $content     = $post->post_content;
            $occurrences = $this->find_occurrences( $content, $phrase );

            foreach ( $occurrences as $index => $offset ) {
                $wrapping = $this->detect_wrapping( $content, $phrase, $offset );
                $snippet  = $this->build_snippet( $content, $phrase, $offset );

                $results[] = array_merge( $base, [
                    'location'         => 'content',
                    'occurrence_index' => $index,
                    'char_offset'      => $offset,
                    'snippet'          => $snippet,
                    'wrapping'         => $wrapping, // 'plain', 'html_element', or 'gutenberg_block'
                ] );
            }
        }

        return $results;
    }

    /**
     * Get post IDs that contain the exact phrase (title or content), then load full post objects.
     *
     * Uses a direct LIKE query so exact phrase matches are found; WP_Query 's' is term-based and can miss phrases.
     *
     * @param string   $phrase     The exact phrase to search for.
     * @param string[] $post_types Post types to include.
     * @param string[] $statuses   Post statuses to include.
     *
     * @return \WP_Post[] Array of post objects.
     */
    private function get_posts_containing_phrase( string $phrase, array $post_types, array $statuses ): array {
        global $wpdb;

        $cache_key = 'phrasematch_ids_' . md5( $phrase . '|' . implode( ',', $post_types ) . '|' . implode( ',', $statuses ) );
        $ids       = wp_cache_get( $cache_key, 'phrasematch' );
        if ( false !== $ids ) {
            if ( empty( $ids ) ) {
                return [];
            }
            $posts = get_posts( [
                'post__in'       => array_map( 'intval', (array) $ids ),
                'post_type'      => $post_types,
                'post_status'    => $statuses,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'orderby'        => 'post__in',
            ] );
            return $posts;
        }

        $phrase_like = '%' . $wpdb->esc_like( $phrase ) . '%';
        $type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $prepare_args       = array_merge( $post_types, $statuses, [ $phrase_like, $phrase_like ] );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact phrase LIKE not possible via WP_Query; result cached above. Dynamic IN placeholders; placeholder vars safe.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status IN ($status_placeholders) AND ( post_title LIKE %s OR post_content LIKE %s )",
                ...$prepare_args
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        wp_cache_set( $cache_key, $ids ? $ids : [], 'phrasematch' );

        if ( empty( $ids ) ) {
            return [];
        }

        $posts = get_posts( [
            'post__in'       => array_map( 'intval', $ids ),
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'post__in',
        ] );

        return $posts;
    }

    /**
     * Get the URL to view revisions for a post, or empty string if none exist.
     *
     * @param int $post_id Post ID.
     *
     * @return string URL to revision screen (revision ID in query) or empty.
     */
    private function get_revisions_url_for_post( int $post_id ): string {
        $revisions = wp_get_post_revisions( $post_id, [ 'numberposts' => 1 ] );
        if ( empty( $revisions ) ) {
            return '';
        }
        $latest = reset( $revisions );

        return admin_url( 'revision.php?revision=' . $latest->ID );
    }

    /**
     * Find all byte-offsets of the phrase in the content (case-insensitive).
     *
     * @param string $content The post content.
     * @param string $phrase  The phrase to locate.
     *
     * @return int[] Array of byte-offsets.
     */
    private function find_occurrences( string $content, string $phrase ): array {
        $offsets    = [];
        $search_pos = 0;
        $phrase_len = strlen( $phrase );
        $lower      = strtolower( $content );
        $lower_phr  = strtolower( $phrase );

        while ( ( $pos = strpos( $lower, $lower_phr, $search_pos ) ) !== false ) {
            $offsets[]  = $pos;
            $search_pos = $pos + $phrase_len;
        }

        return $offsets;
    }

    /**
     * Detect whether the phrase at the given offset is wrapped in an HTML element
     * and/or a full Gutenberg block.
     *
     * @param string $content The post content.
     * @param string $phrase  The phrase.
     * @param int    $offset  Byte-offset of the phrase in content.
     *
     * @return string 'gutenberg_block', 'html_element', or 'plain'.
     */
    private function detect_wrapping( string $content, string $phrase, int $offset ): string {
        $escaped = preg_quote( $phrase, '#' );

        // Check for Gutenberg block wrapping first (most specific).
        $block_pattern = '#<!--\s*wp:\w+(?:\s+\{[^}]*\})?\s*-->\s*'
            . '<(\w+)[^>]*>\s*' . $escaped . '\s*</\1>\s*'
            . '<!--\s*/wp:\w+\s*-->#is';

        if ( preg_match_all( $block_pattern, $content, $all_matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
            foreach ( $all_matches as $set ) {
                $full_match_start = $set[0][1];
                $full_match_end   = $full_match_start + strlen( $set[0][0] );
                if ( $offset >= $full_match_start && $offset < $full_match_end ) {
                    return 'gutenberg_block';
                }
            }
        }

        // Check for HTML element wrapping (phrase is the sole content of a tag).
        // Support nested wrappers like <p><strong>phrase</strong></p> — match innermost.
        $html_pattern = '#<(\w+)[^>]*>\s*' . $escaped . '\s*</\1>#is';

        if ( preg_match_all( $html_pattern, $content, $all_matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
            foreach ( $all_matches as $set ) {
                $full_match_start = $set[0][1];
                $full_match_end   = $full_match_start + strlen( $set[0][0] );
                if ( $offset >= $full_match_start && $offset < $full_match_end ) {
                    return 'html_element';
                }
            }
        }

        return 'plain';
    }

    /**
     * Build a context snippet around the match with highlighting markup.
     *
     * @param string $content The post content.
     * @param string $phrase  The phrase.
     * @param int    $offset  Byte-offset of the phrase.
     *
     * @return string HTML snippet with the phrase wrapped in <mark>.
     */
    private function build_snippet( string $content, string $phrase, int $offset ): string {
        $phrase_len = strlen( $phrase );
        $start      = max( 0, $offset - self::CONTEXT_CHARS );
        $end        = min( strlen( $content ), $offset + $phrase_len + self::CONTEXT_CHARS );

        $before = substr( $content, $start, $offset - $start );
        $match  = substr( $content, $offset, $phrase_len );
        $after  = substr( $content, $offset + $phrase_len, $end - ( $offset + $phrase_len ) );

        // Trim to word boundaries where possible.
        if ( $start > 0 ) {
            $space_pos = strpos( $before, ' ' );
            if ( false !== $space_pos ) {
                $before = '…' . ltrim( substr( $before, $space_pos ) );
            } else {
                $before = strlen( $before ) > self::CONTEXT_CHARS
                    ? '…' . substr( $before, - self::CONTEXT_CHARS )
                    : '…' . $before;
            }
        }
        if ( $end < strlen( $content ) ) {
            $last_space = strrpos( $after, ' ' );
            if ( false !== $last_space ) {
                $after = substr( $after, 0, $last_space );
            }
            $after .= '…';
        }

        return esc_html( $before ) . '<mark>' . esc_html( $match ) . '</mark>' . esc_html( $after );
    }
}
