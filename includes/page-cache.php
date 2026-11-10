<?php
/**
 * EventAdmin Volunteer Management - Keep volunteer pages out of caches
 * The volunteer page is personal (my shifts, open slots, nonces), so a cached copy shows a
 * volunteer stale or someone else's state — e.g. "my shifts" empty and no sign-ups after
 * reopening the browser on a phone, when a page cache or CDN served an old snapshot.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Shortcodes whose output is personal or carries nonces. [eventadmin_open_positions] is
 * deliberately not listed: it's a public, anonymous summary that is fine to cache.
 *
 * @return string[]
 */
function eventadmin_uncacheable_shortcodes(): array
{
    return ['eventadmin', 'eventadmin_register', 'eventadmin_cockpit', 'eventadmin_shiftselector', 'eventadmin_profile'];
}

/**
 * Marks the current request as not cacheable: the DONOTCACHEPAGE constant (honoured by WP
 * Super Cache, W3 Total Cache, WP Rocket, WP Fastest Cache, LiteSpeed Cache and others),
 * LiteSpeed's own no-cache hook, no-store headers for logged-out visitors too (WordPress
 * only sends them for logged-in users), and a reload when the browser restores the page
 * from its back/forward cache.
 *
 * Safe to call more than once and late in the request: headers are only sent if still
 * possible, the constant and footer script work regardless.
 *
 * @return void
 */
function eventadmin_mark_page_uncacheable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    do_action('litespeed_control_set_nocache', 'eventadmin volunteer page');

    if (!headers_sent()) {
        nocache_headers();
    }

    add_action('wp_footer', 'eventadmin_print_bfcache_reload_script', 99);
}

/**
 * A page restored from the back/forward cache shows the DOM as it was when the volunteer
 * left — reload it so sign-ups made elsewhere (or expired nonces) don't linger.
 *
 * @return void
 */
function eventadmin_print_bfcache_reload_script(): void
{
    echo "<script>window.addEventListener('pageshow',function(e){if(e.persisted){window.location.reload();}});</script>\n";
}

/**
 * Early detection, while headers can still be sent: a singular page whose content contains
 * one of the volunteer shortcodes (has_shortcode() also finds them nested inside page
 * builder shortcodes).
 */
add_action('template_redirect', static function (): void {
    if (!is_singular()) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return;
    }
    foreach (eventadmin_uncacheable_shortcodes() as $tag) {
        if (has_shortcode($post->post_content, $tag)) {
            eventadmin_mark_page_uncacheable();
            return;
        }
    }
});

/**
 * Fallback for shortcodes placed where template_redirect can't see them (widgets, block
 * templates, builder data stored outside post_content): mark the page as soon as one of
 * them renders.
 */
add_filter('pre_do_shortcode_tag', static function ($output, $tag) {
    if (in_array($tag, eventadmin_uncacheable_shortcodes(), true)) {
        eventadmin_mark_page_uncacheable();
    }
    return $output;
}, 10, 2);
