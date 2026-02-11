<?php
declare(strict_types=1);

namespace PhraseMatch;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin orchestrator.
 *
 * Registers the admin menu, enqueues assets, and wires up
 * the AJAX handler so every piece talks to every other piece.
 */
class PhraseMatch {

    private Admin_Page $admin_page;
    private Ajax_Handler $ajax_handler;

    /**
     * Initialize the plugin components and hook into WordPress.
     */
    public function init(): void {
        $scanner            = new Scanner();
        $remover            = new Remover();
        $this->admin_page   = new Admin_Page();
        $this->ajax_handler = new Ajax_Handler( $scanner, $remover );

        // Register the admin menu page.
        add_action( 'admin_menu', [ $this->admin_page, 'register_menu' ] );

        // Enqueue admin assets only on the plugin page.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Register AJAX actions.
        $this->ajax_handler->register();
    }

    /**
     * Enqueue JS and CSS on the plugin admin page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        if ( 'tools_page_phrasematch' !== $hook_suffix ) {
            return;
        }

        $css_file = PHRASEMATCH_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file  = PHRASEMATCH_PLUGIN_DIR . 'assets/js/admin.js';

        wp_enqueue_style(
            'phrasematch-admin',
            PHRASEMATCH_PLUGIN_URL . 'assets/css/admin.css',
            [],
            (string) filemtime( $css_file )
        );

        wp_enqueue_script(
            'phrasematch-admin',
            PHRASEMATCH_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            (string) filemtime( $js_file ),
            true
        );

        wp_localize_script( 'phrasematch-admin', 'PhraseMatchData', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'phrasematch_nonce' ),
            'per_page' => 15,
        ] );
    }
}
