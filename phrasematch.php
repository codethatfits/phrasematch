<?php
declare(strict_types=1);

/**
 * Plugin Name: PhraseMatch
 * Plugin URI:  https://codethatfits.com/
 * Description: Scan posts, pages, and custom post types for a specific phrase and selectively remove occurrences — including their HTML wrappers or full Gutenberg blocks.
 * Version:     1.0
 * Author:      CodeThatFits.com
 * Author URI:  https://codethatfits.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: phrasematch
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'PHRASEMATCH_VERSION', '1.0' );
define( 'PHRASEMATCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHRASEMATCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PHRASEMATCH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload includes.
require_once PHRASEMATCH_PLUGIN_DIR . 'includes/class-phrasematch.php';
require_once PHRASEMATCH_PLUGIN_DIR . 'includes/class-scanner.php';
require_once PHRASEMATCH_PLUGIN_DIR . 'includes/class-remover.php';
require_once PHRASEMATCH_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once PHRASEMATCH_PLUGIN_DIR . 'includes/class-ajax-handler.php';

// Boot the plugin.
add_action( 'plugins_loaded', static function (): void {
    $plugin = new PhraseMatch\PhraseMatch();
    $plugin->init();
} );
