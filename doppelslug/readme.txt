=== Doppelslug ===
Contributors: ararai
Tags: slug, permalink, redirect, 404, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Warns authors when a slug starts like another one, so shortened or mistyped addresses can't send visitors to the wrong post.

== Description ==

When a visitor opens an address that doesn't exist, WordPress tries to help: it looks for a published post or page whose slug *starts with* what was typed, and redirects there.

That works well until two slugs start the same way:

* example.com/how-write-book
* example.com/how-write-blog

Now example.com/how-write-b matches both. WordPress redirects to whichever one the database returns first. There is no rule for which one wins, and publishing a new post can quietly change where an old, shortened link goes.

Doppelslug catches this while you write.

= In the editor =

* **Block editor:** a warning notice, a "Lookalike URLs" panel in the post sidebar, and a check in the pre-publish panel.
* **Classic Editor:** a "Lookalike URLs" box in the sidebar that updates when the slug changes.

Each warning shows the address visitors could type, the posts it could open, and where it leads right now.

= Optional fix =

Under Settings → Doppelslug, choose what happens to addresses that don't exist:

* **Let WordPress decide** (default): nothing changes.
* **Redirect only when one post matches:** unambiguous addresses still redirect, and an exact slug match always wins. Ambiguous ones show the Not Found page instead of a random post.
* **Redirect only exact slug matches:** still finds posts that moved to a different date or parent page.
* **Never redirect.**

You can also choose how similar two slugs must be before a warning appears.

= Light by design =

* No custom tables, no tracking, no external requests.
* On the front end it adds three small filters that run only on Not Found requests.
* An editor check is a few indexed queries, only for the post you are editing.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `doppelslug` folder to `/wp-content/plugins/`.
2. Activate it.
3. Edit any post or page. A warning appears when its slug starts like another published one.
4. Optional: review Settings → Doppelslug.

== Frequently Asked Questions ==

= Which post does WordPress open for a partial address? =

WordPress queries for published content whose slug starts with the address and takes the first row the database returns. The query has no sort order, so with several matches the result depends on the database. Doppelslug runs the same query to show you where an address leads right now.

= Does it change my redirects? =

Only if you choose a different option under Settings → Doppelslug. By default it only warns.

= Which content is checked? =

Everything WordPress itself may redirect to: published posts, pages, and public custom post types.

= Does it work with plain permalinks? =

WordPress only completes partial addresses when pretty permalinks are on. With plain permalinks there is nothing to warn about, so Doppelslug stays quiet.

= Does it support non-Latin slugs? =

Yes. Slugs are compared character by character after decoding, so Persian, Arabic, Cyrillic, Chinese and other slugs work.

= Which languages does it speak? =

English and Persian (فارسی) are built in. The plugin follows the site language, or your own profile language if you set one; any other language shows English. Translations into more languages are welcome on translate.wordpress.org.

= Who sees the warnings? =

Anyone who can edit the post. Only administrators see the link to the settings.

= What does it store? =

One option holding your two settings. It is removed when you delete the plugin.

== Screenshots ==

1. Block editor: the warning notice and the Lookalike URLs panel, showing the shared address, the post it could open, and where it leads today.
2. The pre-publish check lists lookalike addresses before you publish.
3. Classic Editor: the Lookalike URLs box appears only when there is something to warn about.
4. Settings → Doppelslug: choose how addresses that don't exist are handled, and how sensitive the warnings are.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
