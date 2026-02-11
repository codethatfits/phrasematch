=== PhraseMatch ===
Contributors: CodeThatFits
Tags: search, replace, content, bulk edit, phrase removal
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan posts, pages, and custom post types for a phrase and selectively remove occurrences, including HTML wrappers or blocks.

== Description ==

PhraseMatch lets you search your entire site for a specific phrase and selectively remove every occurrence in one click. It understands both classic HTML wrappers and Gutenberg blocks, so you can clean up content without breaking your layouts.

**Features:**

* Scan posts, pages, and any registered custom post type for a target phrase.
* Preview every match with its surrounding context before making changes.
* Remove individual occurrences or bulk-remove across multiple posts.
* Automatically handles HTML wrapper elements and full Gutenberg blocks.
* Simple, lightweight admin interface — no bloat.

== Installation ==

1. Upload the `phrasematch` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to the PhraseMatch admin page to begin scanning your content.

== Frequently Asked Questions ==

= Which post types can I scan? =

PhraseMatch supports posts, pages, and any public custom post type registered on your site.

= Will this break my Gutenberg blocks? =

No. PhraseMatch is block-aware and removes the entire block when appropriate, keeping the remaining content valid.

= Can I undo a removal? =

PhraseMatch modifies post content directly. It is recommended to back up your database before performing bulk removals. You can also use WordPress revisions to restore individual posts.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release of PhraseMatch.
