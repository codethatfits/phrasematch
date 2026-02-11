<?php
declare(strict_types=1);

namespace PhraseMatch;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles removal and replacement of phrase occurrences in post titles and content.
 *
 * Uses char_offset (byte-offset) to target each occurrence directly.
 * Occurrences are processed from highest offset to lowest so that
 * earlier positions in the string remain valid after each modification.
 *
 * Supports three removal modes (content only — titles always use text_only):
 *  - text_only:       Remove just the phrase text.
 *  - html_element:    Remove the wrapping HTML element whose sole content is the phrase.
 *  - gutenberg_block: Remove the entire Gutenberg block (including comment markers).
 *
 * When a non-empty replace_with value is provided, the phrase is substituted
 * with the replacement text (removal mode is ignored).
 */
class Remover {

    /**
     * Remove selected occurrences from a single post.
     *
     * @param int    $post_id      The post ID.
     * @param string $phrase       The phrase to remove.
     * @param array  $occurrences  Array of [ 'char_offset' => int, 'mode' => string, 'location' => string, 'replace_with' => string ].
     *
     * @return array{success: bool, message: string, revisions_url: string}
     */
    public function remove( int $post_id, string $phrase, array $occurrences ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return [
                'success'       => false,
                'message'       => __( 'Post not found.', 'phrasematch' ),
                'revisions_url' => '',
            ];
        }

        $title   = $post->post_title;
        $content = $post->post_content;

        // Separate title vs content occurrences.
        $title_occs   = [];
        $content_occs = [];

        foreach ( $occurrences as $occ ) {
            $location = $occ['location'] ?? 'content';
            if ( 'title' === $location ) {
                $title_occs[] = $occ;
            } else {
                $content_occs[] = $occ;
            }
        }

        $removed_count  = 0;
        $replaced_count = 0;

        // --- Process title occurrences (always text_only for removal) ---
        // Sort by char_offset descending so later offsets are processed first.
        if ( ! empty( $title_occs ) ) {
            usort( $title_occs, static function ( array $a, array $b ): int {
                return (int) $b['char_offset'] <=> (int) $a['char_offset'];
            } );

            foreach ( $title_occs as $occ ) {
                $offset      = (int) $occ['char_offset'];
                $replace_with = $occ['replace_with'] ?? '';

                // Verify the phrase is still at this offset (case-insensitive).
                if ( ! $this->phrase_exists_at( $title, $phrase, $offset ) ) {
                    continue;
                }

                if ( '' !== $replace_with ) {
                    $title = $this->replace_at_offset( $title, $phrase, $offset, $replace_with );
                    $replaced_count++;
                } else {
                    $title = $this->remove_text_only( $title, $phrase, $offset );
                    $removed_count++;
                }
            }
        }

        // --- Process content occurrences ---
        // Sort by char_offset descending so later offsets are processed first.
        if ( ! empty( $content_occs ) ) {
            usort( $content_occs, static function ( array $a, array $b ): int {
                return (int) $b['char_offset'] <=> (int) $a['char_offset'];
            } );

            foreach ( $content_occs as $occ ) {
                $offset       = (int) $occ['char_offset'];
                $mode         = sanitize_key( $occ['mode'] );
                $replace_with = $occ['replace_with'] ?? '';

                // Verify the phrase is still at this offset (case-insensitive).
                if ( ! $this->phrase_exists_at( $content, $phrase, $offset ) ) {
                    continue;
                }

                if ( '' !== $replace_with ) {
                    $content = $this->replace_at_offset( $content, $phrase, $offset, $replace_with );
                    $replaced_count++;
                } else {
                    $content = $this->remove_at_offset( $content, $phrase, $offset, $mode );
                    $removed_count++;
                }
            }
        }

        $modified_count = $removed_count + $replaced_count;

        if ( 0 === $modified_count ) {
            return [
                'success'       => false,
                'message'       => __( 'No matching occurrences found to modify.', 'phrasematch' ),
                'revisions_url' => '',
            ];
        }

        // Clean up double blank lines left behind in content.
        $content = preg_replace( "/(\n\s*){3,}/", "\n\n", $content );

        // Trim whitespace from title in case removal left leading/trailing spaces.
        $title = trim( $title );

        $result = wp_update_post(
            [
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $content,
            ],
            true
        );

        if ( is_wp_error( $result ) ) {
            return [
                'success'       => false,
                'message'       => $result->get_error_message(),
                'revisions_url' => '',
            ];
        }

        // Build revisions URL.
        $revisions     = wp_get_post_revisions( $post_id, [ 'numberposts' => 1 ] );
        $revisions_url = '';
        if ( ! empty( $revisions ) ) {
            $latest_revision = reset( $revisions );
            $revisions_url   = admin_url( 'revision.php?revision=' . $latest_revision->ID );
        }

        // Build a descriptive success message.
        $parts = [];
        if ( $removed_count > 0 ) {
            /* translators: %d: number of occurrences removed */
            $parts[] = sprintf( __( '%d removed', 'phrasematch' ), $removed_count );
        }
        if ( $replaced_count > 0 ) {
            /* translators: %d: number of occurrences replaced */
            $parts[] = sprintf( __( '%d replaced', 'phrasematch' ), $replaced_count );
        }
        $message = sprintf(
            /* translators: %1$d: total modified, %2$s: breakdown (e.g. "2 removed, 1 replaced") */
            __( 'Modified %1$d occurrence(s): %2$s.', 'phrasematch' ),
            $modified_count,
            implode( ', ', $parts )
        );

        return [
            'success'       => true,
            'message'       => $message,
            'revisions_url' => $revisions_url,
        ];
    }

    /**
     * Check whether the phrase exists at the given byte-offset (case-insensitive).
     *
     * @param string $content The content string.
     * @param string $phrase  The phrase to check.
     * @param int    $offset  Byte-offset to check at.
     *
     * @return bool True if the phrase is found at the offset.
     */
    private function phrase_exists_at( string $content, string $phrase, int $offset ): bool {
        $phrase_len = strlen( $phrase );

        if ( $offset < 0 || $offset + $phrase_len > strlen( $content ) ) {
            return false;
        }

        $segment = substr( $content, $offset, $phrase_len );

        return strtolower( $segment ) === strtolower( $phrase );
    }

    /**
     * Remove the phrase (or its wrapper) at a specific byte-offset.
     *
     * @param string $content The post content.
     * @param string $phrase  The phrase.
     * @param int    $offset  Byte-offset of the phrase.
     * @param string $mode    Removal mode: text_only | html_element | gutenberg_block.
     *
     * @return string Modified content.
     */
    private function remove_at_offset( string $content, string $phrase, int $offset, string $mode ): string {
        switch ( $mode ) {
            case 'gutenberg_block':
                return $this->remove_gutenberg_block( $content, $phrase, $offset );

            case 'html_element':
                return $this->remove_html_element( $content, $phrase, $offset );

            case 'text_only':
            default:
                return $this->remove_text_only( $content, $phrase, $offset );
        }
    }

    /**
     * Remove just the phrase text at the given offset.
     */
    private function remove_text_only( string $content, string $phrase, int $offset ): string {
        return substr( $content, 0, $offset ) . substr( $content, $offset + strlen( $phrase ) );
    }

    /**
     * Replace the phrase at the given offset with replacement text.
     *
     * @param string $content     The content string.
     * @param string $phrase      The phrase to replace.
     * @param int    $offset      Byte-offset of the phrase.
     * @param string $replacement The replacement text.
     *
     * @return string Modified content.
     */
    private function replace_at_offset( string $content, string $phrase, int $offset, string $replacement ): string {
        return substr( $content, 0, $offset ) . $replacement . substr( $content, $offset + strlen( $phrase ) );
    }

    /**
     * Remove the HTML element that wraps the phrase (if it is the sole content).
     * Falls back to text_only removal if no wrapping element is found.
     */
    private function remove_html_element( string $content, string $phrase, int $offset ): string {
        $escaped = preg_quote( $phrase, '#' );

        // Match any HTML element whose sole meaningful content is the phrase,
        // allowing nested inline wrappers like <p><strong>phrase</strong></p>.
        $pattern = '#<(\w+)[^>]*>\s*(?:<\w+[^>]*>\s*)*' . $escaped . '(?:\s*</\w+>)*\s*</\1>#is';

        if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
            foreach ( $matches as $set ) {
                $match_start = $set[0][1];
                $match_end   = $match_start + strlen( $set[0][0] );

                if ( $offset >= $match_start && $offset < $match_end ) {
                    return substr( $content, 0, $match_start ) . substr( $content, $match_end );
                }
            }
        }

        // Fallback: remove text only.
        return $this->remove_text_only( $content, $phrase, $offset );
    }

    /**
     * Remove the entire Gutenberg block that wraps the phrase.
     * Falls back to html_element removal if no block is found.
     */
    private function remove_gutenberg_block( string $content, string $phrase, int $offset ): string {
        $escaped = preg_quote( $phrase, '#' );

        // Match a full Gutenberg block whose visible content is only the phrase.
        $pattern = '#<!--\s*wp:\w+(?:\s+\{[^}]*\})?\s*-->\s*'
            . '<(\w+)[^>]*>\s*(?:<\w+[^>]*>\s*)*' . $escaped . '(?:\s*</\w+>)*\s*</\1>\s*'
            . '<!--\s*/wp:\w+\s*-->#is';

        if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
            foreach ( $matches as $set ) {
                $match_start = $set[0][1];
                $match_end   = $match_start + strlen( $set[0][0] );

                if ( $offset >= $match_start && $offset < $match_end ) {
                    return substr( $content, 0, $match_start ) . substr( $content, $match_end );
                }
            }
        }

        // Fallback: try html_element removal.
        return $this->remove_html_element( $content, $phrase, $offset );
    }
}
