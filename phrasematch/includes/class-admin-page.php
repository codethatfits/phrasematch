<?php
declare(strict_types=1);

namespace PhraseMatch;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the PhraseMatch admin page under Tools.
 */
class Admin_Page {

    /**
     * Register the admin menu item.
     */
    public function register_menu(): void {
        add_management_page(
            __( 'PhraseMatch', 'phrasematch' ),
            __( 'PhraseMatch', 'phrasematch' ),
            'manage_options',
            'phrasematch',
            [ $this, 'render' ]
        );
    }

    /**
     * Render the admin page.
     */
    public function render(): void {
        $post_types        = get_post_types( [ 'public' => true ], 'objects' );
        $revisions_enabled = ! ( defined( 'WP_POST_REVISIONS' ) && false === WP_POST_REVISIONS );
        ?>
        <div class="wrap" id="phrasematch-app">

            <!-- Header -->
            <div class="pm-header">
                <h1 class="pm-title"><?php esc_html_e( 'PhraseMatch', 'phrasematch' ); ?> <span class="pm-version"><?php echo esc_html( PHRASEMATCH_VERSION ); ?></span></h1>
                <p class="pm-subtitle"><?php esc_html_e( 'Find, remove, or replace specific phrases in your posts, pages, and custom post types.', 'phrasematch' ); ?></p>
            </div>

            <!-- Backup reminder -->
            <div class="pm-notice pm-notice-warning pm-backup-notice">
                <?php esc_html_e( 'Always run a backup before making any major changes.', 'phrasematch' ); ?>
            </div>

            <!-- Search card -->
            <div class="pm-card">

                <!-- Search bar row -->
                <div class="pm-search-bar">
                    <textarea
                        id="phrasematch-phrase"
                        class="pm-search-input"
                        rows="1"
                        placeholder="<?php esc_attr_e( 'Enter the exact phrase to search for…', 'phrasematch' ); ?>"
                        autocomplete="off"
                    ></textarea>
                    <button type="button" id="phrasematch-scan-btn" class="button button-primary pm-scan-btn">
                        <?php esc_html_e( 'Scan', 'phrasematch' ); ?>
                    </button>
                </div>

                <!-- Filters row -->
                <div class="pm-filters">
                    <div class="pm-filter-group">
                        <span class="pm-filter-label"><?php esc_html_e( 'Post types:', 'phrasematch' ); ?></span>
                        <?php foreach ( $post_types as $pt ) : ?>
                            <label class="pm-checkbox">
                                <input
                                    type="checkbox"
                                    name="phrasematch_post_types[]"
                                    value="<?php echo esc_attr( $pt->name ); ?>"
                                    <?php checked( in_array( $pt->name, [ 'post', 'page' ], true ) ); ?>
                                />
                                <?php echo esc_html( $pt->labels->name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="pm-filter-group">
                        <span class="pm-filter-label"><?php esc_html_e( 'Status:', 'phrasematch' ); ?></span>
                        <?php
                        $statuses = [
                            'publish' => __( 'Published', 'phrasematch' ),
                            'draft'   => __( 'Draft', 'phrasematch' ),
                            'private' => __( 'Private', 'phrasematch' ),
                            'pending' => __( 'Pending', 'phrasematch' ),
                        ];
                        foreach ( $statuses as $value => $label ) :
                            ?>
                            <label class="pm-checkbox">
                                <input
                                    type="checkbox"
                                    name="phrasematch_statuses[]"
                                    value="<?php echo esc_attr( $value ); ?>"
                                    <?php checked( 'publish' === $value ); ?>
                                />
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Revisions notice -->
                <?php if ( ! $revisions_enabled ) : ?>
                    <div class="pm-notice pm-notice-warning">
                        <?php esc_html_e( 'Revisions are disabled. Removed content cannot be restored automatically. Proceed with caution.', 'phrasematch' ); ?>
                    </div>
                <?php else : ?>
                    <div class="pm-notice pm-notice-info">
                        <?php esc_html_e( 'Revisions are enabled — any removal can be undone from the post editor.', 'phrasematch' ); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Results area -->
            <div id="phrasematch-results" class="pm-results" style="display: none;">

                <!-- Results header -->
                <div class="pm-results-header">
                    <h2 id="phrasematch-results-heading" class="pm-results-title"></h2>
                    <div class="pm-results-actions-top">
                        <button type="button" id="phrasematch-rescan-btn" class="button pm-btn-rescan" style="display: none;">
                            <?php esc_html_e( 'Re-scan', 'phrasematch' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Notices -->
                <div id="phrasematch-results-notices"></div>

                <!-- Results table -->
                <div class="pm-card pm-card-flush">
                    <div class="pm-table-wrap">
                        <table class="pm-table" id="phrasematch-results-table">
                            <thead>
                                <tr>
                                    <th class="pm-col-cb">
                                        <input type="checkbox" id="phrasematch-select-all" />
                                    </th>
                                    <th class="pm-col-post"><?php esc_html_e( 'Post', 'phrasematch' ); ?></th>
                                    <th class="pm-col-location"><?php esc_html_e( 'Location', 'phrasematch' ); ?></th>
                                    <th class="pm-col-context"><?php esc_html_e( 'Context', 'phrasematch' ); ?></th>
                                    <th class="pm-col-replace">
                                        <?php esc_html_e( 'Replace With', 'phrasematch' ); ?>
                                        <input
                                            type="text"
                                            id="phrasematch-bulk-replace"
                                            class="pm-bulk-replace-input"
                                            placeholder="<?php esc_attr_e( 'Fill checked rows…', 'phrasematch' ); ?>"
                                        />
                                    </th>
                                    <th class="pm-col-mode"><?php esc_html_e( 'Mode', 'phrasematch' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="phrasematch-results-body"></tbody>
                        </table>
                    </div>
                    <div id="phrasematch-pagination" class="pm-pagination" style="display: none;"></div>
                </div>

                <!-- Bottom action bar -->
                <div class="pm-action-bar">
                    <button type="button" id="phrasematch-remove-btn" class="button button-primary" disabled>
                        <?php esc_html_e( 'Apply Changes', 'phrasematch' ); ?>
                    </button>
                    <span class="spinner" id="phrasematch-remove-spinner"></span>
                    <span id="phrasematch-selection-count" class="pm-selection-count"></span>
                </div>
            </div>

            <!-- Confirmation modal -->
            <div id="phrasematch-modal" class="pm-modal" style="display: none;">
                <div class="pm-modal-backdrop"></div>
                <div class="pm-modal-dialog">
                    <div class="pm-modal-header">
                        <h2><?php esc_html_e( 'Confirm Changes', 'phrasematch' ); ?></h2>
                    </div>
                    <div class="pm-modal-body" id="phrasematch-modal-summary"></div>
                    <div class="pm-modal-footer">
                        <button type="button" id="phrasematch-modal-cancel" class="button">
                            <?php esc_html_e( 'Cancel', 'phrasematch' ); ?>
                        </button>
                        <button type="button" id="phrasematch-modal-confirm" class="button button-primary pm-btn-danger">
                            <?php esc_html_e( 'Yes, Apply', 'phrasematch' ); ?>
                        </button>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
