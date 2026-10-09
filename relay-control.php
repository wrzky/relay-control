<?php
/**
 * Plugin Name: Relay Control
 * Plugin URI: https://github.com/wrzky/relay-control
 * Description: Verified site-wide relay routing, stable actor keys, bounded delivery retries, automatic outbox recovery, deduplicated receipts and resumable known-remote fanout.
 * Version: 2.0.0
 * Author: WRZKY
 * Author URI: https://www.wrzky.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * 2.0.0: Neutral-name release for public sharing. Site-specific prefixes were
 *        replaced with relay_control_. Settings are saved under the new names,
 *        so do not drop this over a site already running an earlier build. The
 *        short-post type used for /fedi/ updates is now relay_control_fedi_activity;
 *        it belongs to an optional companion plugin, so on other sites the hook
 *        that listens for it stays idle. The two local accounts the code used
 *        to assume (users 4 and 5) are now settings: relay_control_primary_user_id
 *        (default 5, or the companion plugin's answer) and
 *        relay_control_secondary_user_id (default none, which switches the
 *        secondary-account handling off). No other behaviour changes.
 *
 * 1.9.0: Use one site relay subscription for both actors; block and locally clean up relay Follows from the secondary account.
 *
 * 1.8.15 (27 Sep 2026): Revalidate legacy holds, resume stalled work without
 * false completion, and persist worker admission before sending.
 *
 * 1.8.14 (26 Sep 2026): Rotate pending recovery so held items cannot starve
 * later work; validate native reconstructed activities and log recovery state.
 *
 * 1.8.13 (26 Sep 2026): Bind saved boosts to their originating user before
 * native dispatch and custom fanout; defer ambiguous ownership, never borrow
 * another actor's key or relay subscription. Keep five fanout slots.
 *
 * 1.8.12 (25 Sep 2026): Match activity actors to native signing users and
 * published keys; normalize only explicit local aliases, protect final
 * signatures, honor domain blocks and automatically retry recorded signature
 * failures once within a bounded recovery budget. Five fanout slots.
 *
 * 1.8.11 (25 Sep 2026): The whole regenerate-from-source-post approach
 * (1.8.7 through 1.8.10) is gone. The only thing ever wrong in a stale
 * item was the actor field -- a single string -- and rebuilding the
 * entire activity through the transformer to fix one field was never
 * necessary. It's also what caused every problem this mechanism had:
 * 634 source_unresolved lines across 249 distinct outbox IDs in one
 * log window (unreliable meta-based source lookup), transformer errors
 * on otherwise-fine activities, and Deletes that can never resolve a
 * source post by definition, since the post being gone is what makes
 * them a Delete. Now: decode the stored JSON, correct actor/
 * attributedTo in place wherever the old ?author= form appears, write
 * back. No source-post lookup, no transformer, works uniformly across
 * every activity type including Deletes.
 *
 * 1.8.10 (25 Sep 2026): 1.8.9's post-meta "permanently unresolved" flag
 * was itself wrong, confirmed live -- outbox_id 235554 got marked
 * unresolved and excluded forever, then the pre-existing single-item
 * backfill button successfully queued that same item seconds later
 * using its already-stored content. A Delete's source post is gone by
 * definition, so failing to find one to regenerate from doesn't mean
 * an item is broken. Replaced the permanent exclusion with the same
 * rotating, persisted cursor wrzky-relay-control.php's own
 * auto_recover_cooldowns() already uses: log and move past anything
 * that can't be handled this pass, wrap back to the start once the
 * cursor reaches the end, so everything is revisited over time and
 * nothing is ever permanently locked out.
 * 1.8.9 (25 Sep 2026): Caught live in the actual log after deploying
 * 1.8.7 -- outbox_ids 3171/3174/3177/3180/3198 logged
 * reason=source_unresolved every single minute, forever, because
 * nothing recorded a prior failed attempt. ORDER BY ID ASC kept
 * re-selecting the same permanently-unresolvable items, blocking this
 * batch from ever reaching anything past them.
 *
 * 1.8.8 (25 Sep 2026): The manual "Backfill recent public outbox items"
 * admin button was returning scanned=0 every time -- never actually
 * debugged, because the deeper problem was keeping it as a manual step
 * at all when automatic recovery already runs continuously. Folded its
 * logic into relay_control_recover_backlog() as a small,
 * bounded automatic pass instead, and removed the button, its form, and
 * its dead POST handler. The single-outbox-ID backfill button stays --
 * that one is a genuinely targeted manual tool, not something to
 * automate blindly.
 *
 * 1.8.7 (25 Sep 2026): Every fix to actor identity this session only
 * changes what gets built into NEW outbox items -- Outbox::get_activity()
 * reads post_content verbatim and never regenerates it, so anything
 * already queued or retried kept resending the exact same wrong actor
 * forever, no matter the fix. relay_control_recover_backlog()
 * now repairs a small bounded batch of stale outbox content (rebuilt
 * fresh from each item's current source post) on every pass, before
 * waking or rescheduling anything else -- so recovery works from
 * corrected content instead of perpetually resending the same failure.
 *
 * 1.8.6: Delete activities now go through the same relay fanout path as
 * Create/Update/Announce -- previously excluded outright, which meant a
 * post that reached a relay mirror (e.g. a Lemmy community) had no route
 * for its retraction to ever follow it there. Confirmed against a real
 * case: a post deleted from the site on 25 Sep 2026 stayed
 * visible on lemmy.ml indefinitely. Lemmy-side acceptance of a relayed
 * Delete is unconfirmed -- no Delete activity appears in the 24-25 Sep
 * logs to test against. Also: relay_control_delivery_recovery_tick reschedule
 * failures (41 occurrences, 24-25 Sep debug log, "could not be saved")
 * were silently discarded; now logged when they happen.
 * 1.8.5: Remove the dashboard recovery panel; keep automatic recovery running.
 * 1.8.4: Five fanout worker slots concentrate on one outbox at a time;
 * existing queues adopt the limit without resetting delivery progress.
 * 1.8.3: Separate background-worker transport diagnostics from federation
 * receipts; refresh queue state under locks, preserve interrupted checkpoints,
 * and resume existing chunks incorrectly stopped by the old watchdog.
 * 1.8.2: Failures-only delivery logging, including configured relays.
 * 1.8.1: Automatic first-request recovery and missed-cron fallback.
 * 1.8.0: Bounded delivery, shared attempt ledger and verified relay state.
 * 1.7.9: Added exact-URL inbox blocklist (relay_control_inbox_blocklist_urls)
 * alongside the existing hostname blocklist. Needed because dead endpoints can
 * share a host with still-working inboxes (e.g. wordpress.com sites all live
 * under public-api.wordpress.com), so blocking by host would take out working
 * deliveries too. Blocks wpcom site 179910553 (permanent 404 rest_no_route).
 */

if (!defined('ABSPATH')) {
    exit;
}

function relay_control_log($message) {
    $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array();
    $base = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';
    $file = trailingslashit($base) . 'relay-control-delivery.log';

    file_put_contents(
        $file,
        '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function relay_control_ap_ready() {
    return defined('ACTIVITYPUB_PLUGIN_VERSION')
        && class_exists('Activitypub\\Collection\\Actors')
        && class_exists('Activitypub\\Collection\\Outbox')
        && function_exists('Activitypub\\add_to_outbox')
        && function_exists('Activitypub\\follow');
}

/**
 * The primary account: the main site user that publishes and signs as the site.
 * Set with the option relay_control_primary_user_id. Without it, the answer of
 * an optional companion plugin is used, and failing that, user 5.
 */
function relay_control_primary_user_id() {
    $configured = get_option('relay_control_primary_user_id', null);
    if ($configured !== null && is_numeric($configured) && (int) $configured >= 0) {
        return (int) $configured;
    }
    if (function_exists('relay_control_bridge_ap_user_id')) {
        return (int) relay_control_bridge_ap_user_id();
    }
    return 5;
}

/**
 * The optional secondary account: a second local user whose activities are
 * signed separately and which never subscribes to relays. Set with the option
 * relay_control_secondary_user_id. Returns null when no secondary account is set.
 */
function relay_control_secondary_user_id() {
    $configured = get_option('relay_control_secondary_user_id', null);
    if ($configured !== null && is_numeric($configured) && (int) $configured > 0) {
        return (int) $configured;
    }
    return null;
}

/** The existing site subscription owner is independent of the content sender. */
function relay_control_subscription_actor_id() {
    $default = relay_control_primary_user_id();
    $configured = get_option('relay_control_actor_user_id', null);
    $secondary_uid = relay_control_secondary_user_id();
    // Preserve explicitly configured blog/user ownership; never choose the secondary account
    // by ascending user-ID fallback or inherit its accepted subscriptions.
    if ($configured !== null && is_numeric($configured) && (int) $configured >= 0 && (int) $configured !== $secondary_uid) {
        return (int) $configured;
    }
    return $default;
}

function relay_control_blog_actor_id() {
    return relay_control_subscription_actor_id();
}

function relay_control_normalize_inbox($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $url = untrailingslashit($url);

    if (preg_match('#/actor$#i', $url)) {
        $url = preg_replace('#/actor$#i', '/inbox', $url);
    }

    return esc_url_raw($url);
}

function relay_control_actor_from_inbox($inbox) {
    $inbox = untrailingslashit((string) $inbox);

    if (preg_match('#/inbox$#i', $inbox)) {
        return preg_replace('#/inbox$#i', '/actor', $inbox);
    }

    return $inbox;
}

function relay_control_get_relays() {
    $relays = get_option('activitypub_relays', array());
    if (!is_array($relays)) {
        $relays = array();
    }

    $clean = array();
    foreach ($relays as $relay) {
        $inbox = relay_control_normalize_inbox($relay);
        if ($inbox !== '') {
            $clean[] = $inbox;
        }
    }

    return array_values(array_unique($clean));
}

function relay_control_clean_relays_option($value) {
    if (!is_array($value)) {
        return $value;
    }

    $clean = array();
    foreach ($value as $relay) {
        $inbox = relay_control_normalize_inbox($relay);
        if ($inbox !== '') {
            $clean[] = $inbox;
        }
    }

    return array_values(array_unique($clean));
}
add_filter('pre_update_option_activitypub_relays', 'relay_control_clean_relays_option', 10, 1);

function relay_control_current_relay_map() {
    $map = array();

    foreach (relay_control_get_relays() as $relay) {
        $relay = relay_control_normalize_inbox($relay);
        if ($relay !== '') {
            $map[$relay] = true;
        }
    }

    return $map;
}

function relay_control_is_current_relay($relay_inbox) {
    $relay_inbox = relay_control_normalize_inbox($relay_inbox);
    if ($relay_inbox === '') {
        return false;
    }

    $current = relay_control_current_relay_map();
    return isset($current[$relay_inbox]);
}

function relay_control_prune_stale_relay_states($source = 'auto') {
    $states = get_option('relay_control_states', array());
    if (!is_array($states)) {
        if ($states !== array()) {
            update_option('relay_control_states', array(), false);
            relay_control_log('[RELAY CONTROL] Relay state reset because stored state was not an array source=' . $source);
        }
        return array();
    }

    $current = relay_control_current_relay_map();
    $kept = array();
    $removed = array();

    foreach ($states as $relay => $state) {
        $relay = relay_control_normalize_inbox($relay);
        if ($relay === '') {
            continue;
        }

        if (isset($current[$relay])) {
            $kept[$relay] = is_array($state) ? $state : array();
        } else {
            $removed[] = $relay;
        }
    }

    if ($kept !== $states) {
        update_option('relay_control_states', $kept, false);
    }

    if (!empty($removed)) {
        relay_control_log('[RELAY CONTROL] Pruned stale relay state source=' . $source . ' removed=' . implode(',', array_values(array_unique($removed))) . ' current=' . implode(',', array_keys($current)));
    }

    return $kept;
}

add_action('updated_option', function ($option, $old_value, $value) {
    if ($option !== 'activitypub_relays') {
        return;
    }

    relay_control_prune_stale_relay_states('activitypub_relays_updated');
}, 10, 3);

add_action('init', function () {
    relay_control_prune_stale_relay_states('init');
}, 1);


function relay_control_activity_summary($body) {
    $body = (string) $body;
    $json = json_decode($body, true);

    if (!is_array($json)) {
        return 'activity=unreadable body_len=' . strlen($body);
    }

    $type = isset($json['type']) ? (string) $json['type'] : 'unknown';
    $id = isset($json['id']) ? (string) $json['id'] : '';
    $actor = isset($json['actor']) ? (is_array($json['actor']) ? wp_json_encode($json['actor']) : (string) $json['actor']) : '';
    $object = $json['object'] ?? '';

    if (is_array($object)) {
        $object = $object['id'] ?? ($object['type'] ?? wp_json_encode($object));
    }

    return 'activity=' . $type . ' id=' . $id . ' actor=' . $actor . ' object=' . (string) $object;
}

function relay_control_response_details($response) {
    if (is_wp_error($response)) {
        $data = $response->get_error_data();
        $status = $response->get_error_code();
        $body = '';

        if (is_array($data) && isset($data['response'])) {
            $inner = $data['response'];
            $code = wp_remote_retrieve_response_code($inner);
            if ($code) {
                $status = $code;
            }
            $body = wp_remote_retrieve_body($inner);
        }

        return array(
            'status' => $status,
            'body' => $body,
            'error' => $response->get_error_message(),
        );
    }

    return array(
        'status' => is_array($response) ? wp_remote_retrieve_response_code($response) : 'unknown',
        'body' => is_array($response) ? wp_remote_retrieve_body($response) : '',
        'error' => '',
    );
}

function relay_control_log_http_result($label, $method, $url, $status, $activity, $error = '', $response_body = '') {
    $accepted = in_array((int) $status, array(200, 202), true) ? 'ACCEPTED' : 'FAILED';
    $response_body = trim(substr(wp_strip_all_tags((string) $response_body), 0, 900));

    relay_control_log(
        $label . ' ' . $accepted . ' ' . $method . ' ' . $url . ' => ' . $status .
        ' | ' . $activity .
        ($error !== '' ? ' | error=' . $error : '') .
        ($response_body !== '' ? ' | response=' . $response_body : '')
    );
}

function relay_control_is_activitypub_delivery_target($url) {
    if (relay_control_is_worker_url($url)) { return false; }
    $u = strtolower((string) $url);

    return str_contains($u, '/inbox') ||
        str_contains($u, '/sharedinbox') ||
        str_contains($u, 'sharedinbox') ||
        str_contains($u, 'relay');
}

function relay_control_is_worker_url($url) {
    foreach (array('outbox-worker', 'known-remote-fanout-chunk') as $route) {
        if ((string) $url === rest_url('relay-control/v1/' . $route)) { return true; }
    }
    return false;
}

function relay_control_delivery_target_type($url) {
    $u = strtolower((string) $url);

    if (relay_control_is_current_relay($url)) {
        return 'RELAY';
    }

    if (str_contains($u, 'sharedinbox')) {
        return 'SHARED_INBOX';
    }

    return 'INBOX';
}

function relay_control_activity_id_from_json($body) {
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return '';
    }

    return isset($json['id']) ? (string) $json['id'] : '';
}

function relay_control_activity_id_from_activity($activity) {
    $id = relay_control_activity_get_field($activity, 'id');
    return is_scalar($id) ? (string) $id : '';
}

function relay_control_activity_marker_key($prefix, $activity_id) {
    return 'relay_control_' . $prefix . '_' . md5((string) $activity_id);
}

function relay_control_mark_relay_delivered($activity_id) {
    if ($activity_id === '') {
        return;
    }

    set_transient(relay_control_activity_marker_key('relay_delivered', $activity_id), 1, 6 * HOUR_IN_SECONDS);
}

function relay_control_was_relay_delivered($activity_id) {
    if ($activity_id === '') {
        return false;
    }

    return (bool) get_transient(relay_control_activity_marker_key('relay_delivered', $activity_id));
}

function relay_control_mark_relay_fallback_sent($activity_id) {
    if ($activity_id === '') {
        return;
    }

    set_transient(relay_control_activity_marker_key('relay_fallback_sent', $activity_id), 1, 6 * HOUR_IN_SECONDS);
}

function relay_control_was_relay_fallback_sent($activity_id) {
    if ($activity_id === '') {
        return false;
    }

    return (bool) get_transient(relay_control_activity_marker_key('relay_fallback_sent', $activity_id));
}

function relay_control_clean_response_body($body, $limit = 900) {
    $body = trim(wp_strip_all_tags((string) $body));
    $body = preg_replace('/\s+/', ' ', $body);

    return substr($body, 0, (int) $limit);
}

function relay_control_log_delivery_receipt($method, $url, $status, $activity, $error = '', $response_body = '') {
    $status_int = is_numeric($status) ? (int) $status : 0;
    $accepted = ($status_int >= 200 && $status_int < 300) ? 'ACCEPTED' : 'FAILED';
    $target_type = relay_control_delivery_target_type($url);
    $response_body = relay_control_clean_response_body($response_body, 900);
    // Preserve the installed 1.8.2 failures-only policy for every destination.
    if ($accepted === 'ACCEPTED' && !apply_filters('relay_control_log_successes', false)) { return; }

    relay_control_log(
        '[RELAY CONTROL DELIVERY] ' . $accepted .
        ' target=' . $target_type .
        ' ' . strtoupper((string) $method) . ' ' . $url . ' => ' . $status .
        ' | ' . $activity .
        ($error !== '' ? ' | error=' . $error : '') .
        ($response_body !== '' ? ' | response=' . $response_body : '')
    );
}

/**
 * Main delivery receipt logger.
 *
 * This is the only normal delivery logger in this file. It watches the actual
 * WordPress HTTP response and writes one receipt line to relay-control-delivery.log.
 * It replaces the separate wire/acceptance logger plugins.
 */
add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    if ($context !== 'response') {
        return;
    }

    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($method !== 'POST') {
        return;
    }

    // A non-blocking launch returns no receipt. Never call it an accepted or
    // failed federation delivery. Actual transport errors remain visible.
    if (relay_control_is_worker_url($url)) {
        $status = (int) wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $status >= 400) {
            $details = relay_control_response_details($response);
            relay_control_log('[RELAY CONTROL WORKER TRANSPORT] ERROR url=' . $url
                . ' status=' . $details['status'] . ' error=' . $details['error']);
        }
        return;
    }

    $body = $args['body'] ?? '';
    $activity = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($activity) || empty($activity['type']) || empty($activity['id']) || empty($activity['actor'])) { return; }
    // Nonstandard inbox paths are valid too; identify the ActivityPub payload,
    // not a substring such as "relay" anywhere in an arbitrary URL.
    $details = relay_control_response_details($response);

    if (empty($GLOBALS['relay_control_managed_delivery'])) {
    relay_control_log_delivery_receipt(
        $method,
        $url,
        $details['status'],
        relay_control_activity_summary($body),
        $details['error'],
        $details['body']
    );

    }
    if (relay_control_delivery_target_type($url) === 'RELAY') {
        $status_int = is_numeric($details['status']) ? (int) $details['status'] : 0;
        if ($status_int >= 200 && $status_int < 300) {
            relay_control_mark_relay_delivered(relay_control_activity_id_from_json($body));
        }
    }
}, 10, 5);

/**
 * Internal ActivityPub plugin hooks are intentionally quiet by default.
 * Enable only if the HTTP receipt log is not enough for debugging.
 */
add_action('activitypub_safe_remote_post_response', function ($response, $url, $body, $user_id) {
    if (!defined('RELAY_CONTROL_INTERNAL_DELIVERY_LOG') || !RELAY_CONTROL_INTERNAL_DELIVERY_LOG) {
        return;
    }

    $details = relay_control_response_details($response);
    relay_control_log_http_result(
        '[RELAY CONTROL ACTIVITYPUB SAFE_POST]',
        'POST',
        $url,
        $details['status'],
        relay_control_activity_summary($body) . ' user_id=' . $user_id,
        $details['error'],
        $details['body']
    );
}, 10, 4);

add_action('activitypub_sent_to_inbox', function ($result, $inbox, $json, $actor_id, $outbox_item_id) {
    if (!defined('RELAY_CONTROL_INTERNAL_DELIVERY_LOG') || !RELAY_CONTROL_INTERNAL_DELIVERY_LOG) {
        return;
    }

    $details = relay_control_response_details($result);
    relay_control_log_http_result(
        '[RELAY CONTROL ACTIVITYPUB OUTBOX]',
        'POST',
        $inbox,
        $details['status'],
        relay_control_activity_summary($json) . ' actor_id=' . $actor_id . ' outbox_id=' . $outbox_item_id,
        $details['error'],
        $details['body']
    );
}, 10, 5);

function relay_control_follow_relay_native($relay_inbox, $force_resend = false, $user_id = null) {
    if (!relay_control_ap_ready()) {
        return new WP_Error('activitypub_not_ready', 'ActivityPub 8.3.0 functions/classes are not loaded.');
    }

    $relay_inbox = relay_control_normalize_inbox($relay_inbox);
    if (!$relay_inbox) {
        return new WP_Error('invalid_relay', 'Invalid relay inbox.');
    }

    $relay_actor = relay_control_actor_from_inbox($relay_inbox);
    if ($user_id === null) {
        $user_id = relay_control_blog_actor_id();
    }

    if ((int) $user_id !== relay_control_subscription_actor_id()) {
        return new WP_Error('relay_control_site_relay_subscription_only', 'Relay subscriptions belong to the configured site subscription account.');
    }
    if (relay_control_inbox_is_blocked($relay_inbox, (int) $user_id)) {
        return new WP_Error('relay_control_relay_blocked', 'Relay is blocked.');
    }
    if (relay_control_native_follow_state($relay_inbox, (int) $user_id) === 'accepted') {
        return new WP_Error('relay_control_relay_already_accepted', 'Existing verified relay subscription retained.');
    }

    $remote_actor_post = \Activitypub\Collection\Remote_Actors::fetch_by_uri($relay_actor);
    if (is_wp_error($remote_actor_post)) {
        relay_control_save_state($relay_inbox, array(
            'relay_actor' => $relay_actor,
            'last_follow_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'last_follow_status' => 'fetch_failed',
            'last_follow_error' => $remote_actor_post->get_error_message(),
            'last_follow_response' => '',
        ));
        return $remote_actor_post;
    }

    if ($force_resend) {
        delete_post_meta($remote_actor_post->ID, \Activitypub\Collection\Following::PENDING_META_KEY, (string) $user_id);
    }

    $result = \Activitypub\follow($relay_actor, $user_id);

    if (is_int($result) && class_exists('Activitypub\\Collection\\Outbox')) {
        \Activitypub\Collection\Outbox::reschedule($result);
    }

    relay_control_save_state($relay_inbox, array(
        'relay_actor' => $relay_actor,
        'last_follow_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        'last_follow_status' => is_wp_error($result) ? 'error' : 'queued',
        'last_follow_error' => is_wp_error($result) ? $result->get_error_message() : '',
        'last_follow_response' => is_wp_error($result) ? '' : 'outbox_id=' . $result,
    ));

    relay_control_log('[RELAY CONTROL] Native ActivityPub 8.3.0 Follow queued relay_inbox=' . $relay_inbox . ' relay_actor=' . $relay_actor . ' result=' . (is_wp_error($result) ? 'ERROR ' . $result->get_error_message() : 'outbox_id=' . $result));

    return $result;
}

function relay_control_save_state($relay_inbox, $state) {
    $relay_inbox = relay_control_normalize_inbox($relay_inbox);

    if ($relay_inbox === '') {
        return;
    }

    if (!relay_control_is_current_relay($relay_inbox)) {
        relay_control_prune_stale_relay_states('save_state_stale_attempt');
        relay_control_log('[RELAY CONTROL] Relay state save ignored reason=stale_relay_not_in_activitypub_relays relay_inbox=' . $relay_inbox);
        return;
    }

    $states = relay_control_prune_stale_relay_states('before_save_state');
    if (!is_array($states)) {
        $states = array();
    }

    $states[$relay_inbox] = array_merge($states[$relay_inbox] ?? array(), is_array($state) ? $state : array());
    update_option('relay_control_states', $states, false);
}

function relay_control_subscribe_all($force_resend = false, $user_ids = null) {
    relay_control_prune_stale_relay_states('before_subscribe_all');
    // Legacy callers may still pass [5, 4]. There is only one subscription owner.
    $user_ids = array(relay_control_subscription_actor_id());
    $results = array();
    foreach (relay_control_get_relays() as $relay) {
        foreach ($user_ids as $uid) {
            $results[$relay . ':' . $uid] = relay_control_follow_relay_native($relay, $force_resend, $uid);
        }
    }
    return $results;
}

function relay_control_clear_legacy_cron() {
    $timestamp = wp_next_scheduled('relay_control_subscribe_all');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'relay_control_subscribe_all');
        $timestamp = wp_next_scheduled('relay_control_subscribe_all');
    }
}

register_activation_hook(__FILE__, function () {
    update_option('relay_control_proxy_intahnet_202', false, false);
    relay_control_clear_legacy_cron();
    relay_control_reconcile_native_follow_states();
});

register_deactivation_hook(__FILE__, function () {
    relay_control_clear_legacy_cron();
});

function relay_control_register_dispatch_filter() {
    if (class_exists('Activitypub\\Dispatcher')) {
        remove_filter('activitypub_additional_inboxes', array('Activitypub\\Dispatcher', 'add_inboxes_of_relays'), 10);
    }

    add_filter('activitypub_additional_inboxes', 'relay_control_add_relay_inboxes', PHP_INT_MAX, 3);
}
add_action('init', 'relay_control_register_dispatch_filter', PHP_INT_MAX);

function relay_control_proxy_enabled() {
    return false;
}

function relay_control_proxy_key_for_target($target) {
    return substr(hash('sha256', $target . '|' . wp_salt('auth')), 0, 16);
}

function relay_control_proxy_url($target) {
    $key = relay_control_proxy_key_for_target($target);
    $targets = get_option('relay_control_proxy_targets', array());
    if (!is_array($targets)) {
        $targets = array();
    }
    $targets[$key] = $target;
    update_option('relay_control_proxy_targets', $targets, false);

    return rest_url('relay-control/v1/proxy/' . rawurlencode($key));
}


function relay_control_activity_object_to_array($activity) {
    if (is_array($activity)) {
        return $activity;
    }

    if (!is_object($activity)) {
        return array();
    }

    if (method_exists($activity, 'to_array')) {
        try {
            $array = $activity->to_array();
            if (is_array($array)) {
                return $array;
            }
        } catch (Throwable $e) {
            relay_control_log('[RELAY CONTROL] Activity to_array failed error=' . $e->getMessage());
        }
    }

    if (method_exists($activity, 'to_json')) {
        try {
            $json = $activity->to_json();
            $array = json_decode((string) $json, true);
            if (is_array($array)) {
                return $array;
            }
        } catch (Throwable $e) {
            relay_control_log('[RELAY CONTROL] Activity to_json failed error=' . $e->getMessage());
        }
    }

    return array();
}

function relay_control_activity_type($activity) {
    if (is_array($activity)) {
        return isset($activity['type']) ? (string) $activity['type'] : '';
    }

    if (is_object($activity)) {
        if (method_exists($activity, 'get')) {
            try {
                $value = $activity->get('type');
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            } catch (Throwable $e) {
                relay_control_log('[RELAY CONTROL] Activity get(type) failed error=' . $e->getMessage());
            }
        }

        if (is_callable(array($activity, 'get_type'))) {
            try {
                $value = $activity->get_type();
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            } catch (Throwable $e) {
                relay_control_log('[RELAY CONTROL] Activity get_type failed error=' . $e->getMessage());
            }
        }

        if (isset($activity->type)) {
            return (string) $activity->type;
        }

        $array = relay_control_activity_object_to_array($activity);
        if (isset($array['type'])) {
            return (string) $array['type'];
        }
    }

    return '';
}

function relay_control_relay_actor_for_uri($actor_uri) {
    $actor_uri = untrailingslashit((string) $actor_uri);

    foreach (relay_control_get_relays() as $relay) {
        $relay_actor = untrailingslashit(relay_control_actor_from_inbox($relay));
        if ($actor_uri === $relay_actor) {
            return $relay;
        }
    }

    return '';
}


function relay_control_remote_actor_post_for_relay($relay_inbox) {
    if (!class_exists('Activitypub\\Collection\\Remote_Actors')) {
        return new WP_Error('activitypub_remote_actors_missing', 'ActivityPub Remote_Actors collection is not loaded.');
    }

    $relay_actor = relay_control_actor_from_inbox($relay_inbox);
    $post = \Activitypub\Collection\Remote_Actors::get_by_uri($relay_actor);

    if (!is_wp_error($post)) {
        return $post;
    }

    return \Activitypub\Collection\Remote_Actors::fetch_by_uri($relay_actor);
}

function relay_control_sync_native_follow_state($relay_inbox, $status, $source = 'manual') {
    // Compatibility wrapper: only ActivityPub's verified inbox handler may change Following.
    return relay_control_native_follow_state($relay_inbox) === 'accepted';
}

function relay_control_native_follow_state($relay_inbox, $actor_id = null) {
    if (!class_exists('Activitypub\\Collection\\Following')) {
        return 'unknown';
    }

    $post = relay_control_remote_actor_post_for_relay($relay_inbox);
    if (is_wp_error($post) || !$post instanceof WP_Post) {
        return 'missing actor';
    }

    $user_id = (string) ($actor_id === null ? relay_control_blog_actor_id() : $actor_id);
    $following = get_post_meta($post->ID, \Activitypub\Collection\Following::FOLLOWING_META_KEY, false);
    $pending = get_post_meta($post->ID, \Activitypub\Collection\Following::PENDING_META_KEY, false);

    if (in_array($user_id, $following, true)) {
        return 'accepted';
    }

    if (in_array($user_id, $pending, true)) {
        return 'pending';
    }

    return 'none';
}

function relay_control_reconcile_native_follow_states() {
    // Native verified state is authoritative. Never forge acceptance from cached receipts.
    foreach (relay_control_get_relays() as $relay) {
        relay_control_save_state($relay, array(
            'last_follow_status' => relay_control_native_follow_state($relay),
        ));
    }
}

add_action('activitypub_handled_accept', function ($activity, $users, $success, $result) {
    relay_control_verified_follow($activity, $users, $success, 'accept');
}, 20, 4);
add_action('activitypub_handled_reject', function ($activity, $users, $success, $result) {
    relay_control_verified_follow($activity, $users, $success, 'reject');
}, 20, 4);

function relay_control_relay_state($relay_inbox) {
    $relay_inbox = relay_control_normalize_inbox($relay_inbox);

    if ($relay_inbox === '' || !relay_control_is_current_relay($relay_inbox)) {
        return array();
    }

    $states = get_option('relay_control_states', array());
    if (!is_array($states)) {
        return array();
    }

    return $states[$relay_inbox] ?? array();
}

function relay_control_relay_is_accepted($relay_inbox, $actor_id = null) {
    // $actor_id is retained for old callers; eligibility is site-wide.
    return relay_control_native_follow_state($relay_inbox, relay_control_subscription_actor_id()) === 'accepted';
}

function relay_control_dispatch_type_allowed_for_relays($type) {
    $type = strtolower((string) $type);
    // 'delete' added 25 Sep 2026: content that reached a Lemmy community
    // (or any other mirror) purely through relay fanout, not a direct
    // Follow, has no other path a retraction could take to reach it.
    // Confirmed missing before this fix -- a genuinely deleted post stayed
    // visible on lemmy.ml indefinitely because Delete never had a route
    // there at all. AodeRelay itself relays Delete verbatim; whether a
    // given receiving server accepts it depends on that server's own
    // signature requirements, which is between it and the relay, not
    // something to gate here. Unconfirmed against Lemmy specifically --
    // no Delete activity appears in the 24-25 Sep logs to check against,
    // so this closes the routing gap on the sending side but doesn't
    // guarantee Lemmy's receiving side honours it. Worth watching the
    // relay-delivery log after this ships for the first real Delete case.
    return in_array($type, array('create', 'update', 'announce', 'delete'), true);
}

function relay_control_activity_get_field($activity, $field) {
    if (is_array($activity)) {
        return $activity[$field] ?? null;
    }

    if (is_object($activity)) {
        if (method_exists($activity, 'get')) {
            try {
                $value = $activity->get($field);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (Throwable $e) {
                relay_control_log('[RELAY CONTROL] Activity get(' . $field . ') failed error=' . $e->getMessage());
            }
        }

        $getter = 'get_' . $field;
        if (is_callable(array($activity, $getter))) {
            try {
                $value = $activity->$getter();
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (Throwable $e) {
                relay_control_log('[RELAY CONTROL] Activity ' . $getter . ' failed error=' . $e->getMessage());
            }
        }

        if (isset($activity->$field)) {
            return $activity->$field;
        }

        $array = relay_control_activity_object_to_array($activity);
        if (array_key_exists($field, $array)) {
            return $array[$field];
        }
    }

    return null;
}

function relay_control_activity_has_public_audience($activity) {
    $public = 'https://www.w3.org/ns/activitystreams#Public';
    $fields = array('to', 'cc', 'audience');

    foreach ($fields as $field) {
        $value = relay_control_activity_get_field($activity, $field);
        if (!is_array($value)) {
            $value = $value !== null && $value !== '' ? array($value) : array();
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? '';
            }
            if ((string) $item === $public) {
                return true;
            }
        }
    }

    $object = relay_control_activity_get_field($activity, 'object');
    if (is_array($object)) {
        foreach ($fields as $field) {
            $value = $object[$field] ?? null;
            if (!is_array($value)) {
                $value = $value !== null && $value !== '' ? array($value) : array();
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    $item = $item['id'] ?? '';
                }
                if ((string) $item === $public) {
                    return true;
                }
            }
        }
    }

    return false;
}

function relay_control_activity_is_public_for_relay($activity) {
    if (function_exists('Activitypub\is_activity_public')) {
        try {
            if (\Activitypub\is_activity_public($activity)) {
                return true;
            }
        } catch (Throwable $e) {
            relay_control_log('[RELAY CONTROL] Public check error=' . $e->getMessage());
        }
    }

    return relay_control_activity_has_public_audience($activity);
}

function relay_control_add_relay_inboxes($inboxes, $actor_id, $activity) {
    // Recompute configured relay destinations from verified site state, including
    // when another dispatcher filter already inserted a relay inbox.
    $inboxes = array_values(array_diff((array) $inboxes, relay_control_get_relays()));
    if (is_object($activity) && method_exists($activity, 'to_json')) {
        $identity = relay_control_signature_identity($activity->to_json(), $actor_id);
        if (is_wp_error($identity)) { return $inboxes; }
        $actor_id = $identity['user_id'];
    }
    relay_control_prune_stale_relay_states('before_dispatch');
    $type = strtolower(relay_control_activity_type($activity));
    $existing_count = is_array($inboxes) ? count($inboxes) : 0;
    $relays = relay_control_get_relays();

    relay_control_log('[RELAY CONTROL] Relay dispatch entered activity=' . ($type !== '' ? $type : 'unknown') . ' actor_id=' . $actor_id . ' existing_inboxes=' . $existing_count . ' configured_relays=' . count($relays));

    // Deliberate carve-out removed 25 Sep 2026 -- see
    // dispatch_type_allowed_for_relays() for why. Delete now falls
    // through to the same allowed-type and public-visibility checks
    // every other relayed activity goes through below, rather than
    // being special-cased out before either check runs.
    if (!relay_control_dispatch_type_allowed_for_relays($type)) {
        relay_control_log('[RELAY CONTROL] Relay dispatch skipped reason=activity_type_not_allowed activity=' . ($type !== '' ? $type : 'unknown') . ' actor_id=' . $actor_id . ' existing_inboxes=' . $existing_count . ' configured_relays=' . count($relays));
        return $inboxes;
    }

    $is_public = relay_control_activity_is_public_for_relay($activity);
    relay_control_log('[RELAY CONTROL] Relay dispatch public check activity=' . ($type !== '' ? $type : 'unknown') . ' actor_id=' . $actor_id . ' public=' . ($is_public ? 'yes' : 'no') . ' existing_inboxes=' . $existing_count . ' configured_relays=' . count($relays));

    if (!$is_public) {
        relay_control_log('[RELAY CONTROL] Relay dispatch skipped reason=not_public activity=' . ($type !== '' ? $type : 'unknown') . ' actor_id=' . $actor_id . ' existing_inboxes=' . $existing_count . ' configured_relays=' . count($relays));
        return $inboxes;
    }

    $out = array_values(array_filter(array_map('relay_control_normalize_inbox', (array) $inboxes)));
    $added = array();
    $already_present = array();
    $skipped = array();

    foreach ($relays as $relay) {
        $state = relay_control_relay_state($relay);
        $status = strtolower((string) ($state['last_follow_status'] ?? ''));
        $native_status = relay_control_native_follow_state($relay, relay_control_subscription_actor_id());
        $accepted = relay_control_relay_is_accepted($relay, $actor_id);

        if (!$accepted || relay_control_inbox_is_blocked($relay, (int) $actor_id)) {
            $skipped[] = $relay . ':state=' . ($status !== '' ? $status : 'none') . ':native=' . $native_status;
            continue;
        }

        if (in_array($relay, $out, true)) {
            $already_present[] = $relay . ':state=' . ($status !== '' ? $status : 'none') . ':native=' . $native_status;
            continue;
        }

        $out[] = $relay;
        $added[] = $relay . ':state=' . ($status !== '' ? $status : 'none') . ':native=' . $native_status;
    }

    $out = array_values(array_unique($out));

    if (!empty($added)) {
        $activity_id = relay_control_activity_id_from_activity($activity);
        if ($activity_id !== '') {
            set_transient(relay_control_activity_marker_key('relay_injected', $activity_id), 1, 6 * HOUR_IN_SECONDS);
        }
        relay_control_log('[RELAY CONTROL] Relay inboxes added activity=' . $type . ' actor_id=' . $actor_id . ' added=' . implode(',', $added) . ' existing_inboxes=' . $existing_count . ' final_inboxes=' . count($out) . (!empty($already_present) ? ' already_present=' . implode(',', $already_present) : '') . (!empty($skipped) ? ' skipped=' . implode(',', $skipped) : ''));
    } elseif (!empty($already_present)) {
        relay_control_log('[RELAY CONTROL] Relay inboxes already present activity=' . $type . ' actor_id=' . $actor_id . ' already_present=' . implode(',', $already_present) . ' existing_inboxes=' . $existing_count . ' final_inboxes=' . count($out) . (!empty($skipped) ? ' skipped=' . implode(',', $skipped) : ''));
    } elseif (!empty($relays)) {
        relay_control_log('[RELAY CONTROL] Relay dispatch no accepted relays added activity=' . $type . ' actor_id=' . $actor_id . ' configured_relays=' . count($relays) . (!empty($skipped) ? ' skipped=' . implode(',', $skipped) : ''));
    } else {
        relay_control_log('[RELAY CONTROL] Relay dispatch skipped reason=no_configured_relays activity=' . $type . ' actor_id=' . $actor_id . ' existing_inboxes=' . $existing_count);
    }

    return $out;
}


function relay_control_json_field_values($data, $fields = array('to', 'cc', 'audience')) {
    $values = array();

    if (!is_array($data)) {
        return $values;
    }

    foreach ($fields as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }

        $value = $data[$field];
        if (!is_array($value)) {
            $value = ($value !== null && $value !== '') ? array($value) : array();
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? $item['href'] ?? '';
            }
            if ($item !== '') {
                $values[] = (string) $item;
            }
        }
    }

    return $values;
}

function relay_control_json_is_public($data) {
    if (!is_array($data)) {
        return false;
    }

    $public_ids = array(
        'https://www.w3.org/ns/activitystreams#Public',
        'as:Public',
        'Public',
    );

    $values = relay_control_json_field_values($data);

    if (isset($data['object']) && is_array($data['object'])) {
        $values = array_merge($values, relay_control_json_field_values($data['object']));
    }

    foreach ($values as $value) {
        if (in_array((string) $value, $public_ids, true)) {
            return true;
        }
    }

    return false;
}

function relay_control_inbox_blocklist_hosts() {
    // Hosts whose inboxes must never receive fanout/relay delivery.
    // relay.toot.io rejects by policy (400) on every delivery, so it is
    // pruned from ALL outbound paths here, including cached known-remote inboxes
    // that survive after a relay subscription is removed.
    $hosts = array('relay.toot.io');
    $hosts = apply_filters('relay_control_inbox_blocklist_hosts', $hosts);
    $clean = array();
    foreach ((array) $hosts as $h) {
        $h = strtolower(trim((string) $h));
        if ($h !== '') {
            $clean[$h] = true;
        }
    }
    return $clean;
}

function relay_control_inbox_blocklist_urls() {
    // Exact inbox URLs that must never receive delivery. Used when the dead
    // endpoint shares a host with other, still-working inboxes (e.g. multiple
    // wordpress.com sites all live under public-api.wordpress.com), so a
    // hostname-level block would be too broad.
    $urls = array(
        // wpcom site 179910553: ActivityPub deactivated on that blog, permanent
        // 404 rest_no_route on every delivery attempt (2026-07-07).
        'https://public-api.wordpress.com/wpcom/activitypub-1.0/sites/179910553/inbox',
    );
    $urls = apply_filters('relay_control_inbox_blocklist_urls', $urls);
    $clean = array();
    foreach ((array) $urls as $u) {
        $u = strtolower(trim((string) $u));
        if ($u !== '') {
            $clean[$u] = true;
        }
    }
    return $clean;
}

function relay_control_inbox_is_blocked($inbox, $user_id = 0) {
    $host = strtolower(rtrim((string) wp_parse_url((string) $inbox, PHP_URL_HOST), '.'));
    if ($host === '') { return false; }
    $domains = array_merge(array_keys(relay_control_inbox_blocklist_hosts()),
        array('lemmy.world'), (array) get_option('activitypub_site_blocked_domains', array()),
        $user_id > 0 ? (array) get_user_meta($user_id, 'activitypub_blocked_domains', true) : array());
    foreach ($domains as $domain) {
        if (!is_string($domain)) { continue; }
        $domain = strtolower(trim($domain));
        if (strpos($domain, '://') !== false) { $domain = (string) wp_parse_url($domain, PHP_URL_HOST); }
        $domain = trim(preg_replace('/^\*\./', '', $domain), '. /');
        if ($domain !== '' && ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain)) { return true; }
    }
    return isset(relay_control_inbox_blocklist_urls()[strtolower(trim((string) $inbox))]);
}

function relay_control_normalized_inbox_list($inboxes) {
    $out = array();
    foreach ((array) $inboxes as $inbox) {
        $clean = relay_control_normalize_inbox($inbox);
        if ($clean !== '' && !relay_control_inbox_is_blocked($clean)) {
            $out[] = $clean;
        }
    }

    return array_values(array_unique($out));
}

function relay_control_pre_send_direct_relay_delivery($json, $inboxes, $outbox_item_id) {
    // Relay inboxes are injected into native dispatch, with native bounded retries.
}
add_action('activitypub_pre_send_to_inboxes', 'relay_control_pre_send_direct_relay_delivery', 1, 3);

function relay_control_schedule_relay_fallback($outbox_id, $activity, $user_id = 0, $content_visibility = null) {
    relay_control_wake_outbox((int) $outbox_id);
}
add_action('post_activitypub_add_to_outbox', 'relay_control_schedule_relay_fallback', 20, 4);

// Consume previously scheduled fallback events through the normal, locked dispatcher.
add_action('relay_control_fallback_send_to_relays', 'relay_control_process_outbox', 10, 1);

/**
 * Known remote inbox fanout for public Create/Update/Announce.
 *
 * This is intentionally separate from relay delivery. ActivityPub core already
 * fans Delete out to Remote_Actors::get_inboxes(); this mirrors that broad
 * known-remote behavior for public Create/Update/Announce, but excludes the
 * inboxes already selected by ActivityPub and excludes configured relay inboxes.
 *
 * v1.7.2 changes the old serial offset queue into bounded, locked chunked workers:
 * - one parent queue per activity;
 * - many independent chunk workers per queue;
 * - optional non-blocking loopback spawn for real parallelism;
 * - WP-Cron fallback for every chunk;
 * - watchdog that requeues stale/running chunks;
 * - bounded init migration so ancient queues are not revived;
 * - option-based locks around queue/chunk state to avoid parallel lost updates.
 */
function relay_control_is_local_inbox_url($url) {
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $target_host = wp_parse_url((string) $url, PHP_URL_HOST);

    return $host && $target_host && strtolower((string) $host) === strtolower((string) $target_host);
}

function relay_control_known_remote_queue_key($activity_id) {
    return 'relay_control_known_remote_' . md5((string) $activity_id);
}

function relay_control_was_known_remote_queued($activity_id) {
    if ($activity_id === '') {
        return false;
    }

    return (bool) get_transient(relay_control_activity_marker_key('known_remote_queued', $activity_id));
}

function relay_control_mark_known_remote_queued($activity_id) {
    if ($activity_id === '') {
        return;
    }

    set_transient(relay_control_activity_marker_key('known_remote_queued', $activity_id), 1, 14 * DAY_IN_SECONDS);
}

function relay_control_get_known_remote_inboxes() {
    if (!class_exists('Activitypub\\Collection\\Remote_Actors')) {
        return array();
    }

    try {
        $inboxes = \Activitypub\Collection\Remote_Actors::get_inboxes();
    } catch (Throwable $e) {
        relay_control_log('[RELAY CONTROL] Known remote fanout failed reading Remote_Actors error=' . $e->getMessage());
        return array();
    }

    return relay_control_normalized_inbox_list((array) $inboxes);
}

function relay_control_known_remote_chunk_size() {
    $size = (int) apply_filters('relay_control_known_remote_fanout_chunk_size', 25);
    return max(1, min(50, $size));
}

function relay_control_known_remote_max_workers() {
    // These slots are shared by every fanout queue on the site.
    $workers = (int) apply_filters('relay_control_known_remote_fanout_max_workers', 5);
    return max(1, min(5, $workers));
}

function relay_control_known_remote_chunk_timeout() {
    $timeout = (int) apply_filters('relay_control_known_remote_fanout_chunk_timeout', 2 * MINUTE_IN_SECONDS);
    return max(120, $timeout);
}

function relay_control_known_remote_max_attempts() {
    $attempts = (int) apply_filters('relay_control_known_remote_fanout_chunk_max_attempts', 3);
    return max(1, min(3, $attempts));
}

function relay_control_known_remote_watchdog_interval() {
    $interval = (int) apply_filters('relay_control_known_remote_fanout_watchdog_interval', MINUTE_IN_SECONDS);
    return max(30, $interval);
}

function relay_control_known_remote_async_spawn_enabled() {
    return (bool) apply_filters('relay_control_known_remote_async_spawn_enabled', true);
}

function relay_control_known_remote_lock_key($queue_key, $chunk_index) {
    return 'relay_control_known_remote_lock_' . md5((string) $queue_key . ':' . (int) $chunk_index);
}

function relay_control_option_lock_acquire($name, $ttl = 60) {
    global $wpdb;
    $name = 'relay_control_option_lock_' . md5((string) $name);
    $value = (time() + max(10, (int) $ttl)) . ':' . wp_generate_password(24, false, false);
    if (add_option($name, $value, '', false)) {
        $GLOBALS['relay_control_owned_leases'][$name] = $value;
        return $name;
    }
    wp_cache_delete($name, 'options');
    wp_cache_delete('notoptions', 'options');
    $old = (string) get_option($name, '');
    if ($old !== '' && (int) $old < time()) {
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old));
        if ($changed === 1) {
            wp_cache_delete($name, 'options');
            $GLOBALS['relay_control_owned_leases'][$name] = $value;
            return $name;
        }
    }
    return '';
}

function relay_control_option_lock_release($name) {
    global $wpdb;
    $value = $GLOBALS['relay_control_owned_leases'][$name] ?? null;
    if ($value === null) { return; }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value));
    wp_cache_delete($name, 'options');
    unset($GLOBALS['relay_control_owned_leases'][$name]);
}

function relay_control_known_remote_queue_lock($queue_key, $ttl = 60) {
    return relay_control_option_lock_acquire('known_remote_queue:' . (string) $queue_key, $ttl);
}

function relay_control_known_remote_chunk_lock($queue_key, $chunk_index, $ttl = 900) {
    return relay_control_option_lock_acquire('known_remote_chunk:' . (string) $queue_key . ':' . (int) $chunk_index, $ttl);
}

function relay_control_known_remote_chunk_lease($queue_key, $chunk_index) {
    $key = 'relay_control_option_lock_' . md5('known_remote_chunk:' . (string) $queue_key . ':' . (int) $chunk_index);
    wp_cache_delete($key, 'options');
    wp_cache_delete('notoptions', 'options');
    return (string) get_option($key, '');
}

function relay_control_known_remote_queue_ttl() {
    // This is only the automatic rescue window for stored fanout queues.
    // Queues older than this are not deleted; they are skipped by init_scan so
    // ancient/cruft queues do not stampede the host. Published articles from
    // days ago can still be deliberately backfilled from ap_outbox below.
    $ttl = (int) apply_filters('relay_control_known_remote_fanout_queue_ttl', 14 * DAY_IN_SECONDS);
    return max(HOUR_IN_SECONDS, $ttl);
}

function relay_control_known_remote_scan_limit() {
    $limit = (int) apply_filters('relay_control_known_remote_fanout_scan_limit', 0);
    return max(0, min(5, $limit));
}

function relay_control_known_remote_raw_queue_is_scan_eligible($queue_key, $queue) {
    if (!preg_match('/^relay_control_known_remote_[a-f0-9]{32}$/', (string) $queue_key)) { return false; }
    if (!is_array($queue) || empty($queue['json']) || empty($queue['targets']) || empty($queue['activity_id'])) {
        relay_control_cancel_queue($queue_key, 'invalid_queue');
        return false;
    }
    $post = get_post((int) ($queue['outbox_id'] ?? 0));
    if (!$post || $post->post_type !== 'ap_outbox' || get_post_meta($post->ID, '_relay_control_delivery_terminal', true)) {
        relay_control_cancel_queue($queue_key, 'outbox_removed_or_retired');
        return false;
    }
    if (!in_array(strtolower((string) ($queue['type'] ?? '')), array('create', 'update', 'announce', 'delete'), true)) {
        relay_control_cancel_queue($queue_key, 'native_delivery_only');
        return false;
    }
    return true;
}

function relay_control_known_remote_make_secret() {
    if (function_exists('wp_generate_password')) {
        return wp_generate_password(32, false, false);
    }

    return md5(uniqid((string) wp_rand(), true));
}

function relay_control_known_remote_normalize_queue($queue, $queue_key = '') {
    if (!is_array($queue) || empty($queue['json']) || empty($queue['targets']) || empty($queue['activity_id'])) {
        return array();
    }

    $targets = array_values((array) $queue['targets']);
    if (empty($targets)) {
        return array();
    }

    $chunk_size = !empty($queue['chunk_size']) ? (int) $queue['chunk_size'] : relay_control_known_remote_chunk_size();
    $chunk_size = max(1, min(50, $chunk_size));
    $chunk_count = (int) ceil(count($targets) / $chunk_size);

    $chunks = isset($queue['chunks']) && is_array($queue['chunks']) ? $queue['chunks'] : array();
    $changed = false;

    for ($i = 0; $i < $chunk_count; $i++) {
        if (!isset($chunks[$i]) || !is_array($chunks[$i])) {
            $chunks[$i] = array(
                'status' => 'pending',
                'attempts' => 0,
                'position' => 0,
                'started_at' => 0,
                'completed_at' => 0,
                'last_error' => '',
            );
            $changed = true;
        } else {
            $chunks[$i] = array_merge(array(
                'status' => 'pending',
                'attempts' => 0,
                'position' => 0,
                'started_at' => 0,
                'completed_at' => 0,
                'last_error' => '',
            ), $chunks[$i]);
        }
    }

    foreach (array_keys($chunks) as $idx) {
        if ((int) $idx >= $chunk_count) {
            unset($chunks[$idx]);
            $changed = true;
        }
    }

    ksort($chunks, SORT_NUMERIC);

    $queue['targets'] = $targets;
    $queue['chunk_size'] = $chunk_size;
    $queue['chunk_count'] = $chunk_count;
    $queue['chunks'] = array_values($chunks);
    // Old queues must adopt increases as well as reductions. Keep their target
    // positions, acknowledgements and retry counters intact.
    $queue['max_workers'] = relay_control_known_remote_max_workers();

    $current_max_attempts = relay_control_known_remote_max_attempts();
    $stored_max_attempts = !empty($queue['max_attempts']) ? max(1, min(10, (int) $queue['max_attempts'])) : $current_max_attempts;
    $queue['max_attempts'] = min($stored_max_attempts, $current_max_attempts);
    $queue['secret'] = !empty($queue['secret']) ? (string) $queue['secret'] : relay_control_known_remote_make_secret();
    $queue['updated_at'] = time();

    // v1.5 compatibility: old queues used a single numeric offset. Mark all
    // chunks before that offset complete so already-attempted targets do not get
    // resent unless the queue was stalled before it could update progress.
    if (isset($queue['offset']) && empty($queue['migrated_from_serial_offset'])) {
        $offset = max(0, (int) $queue['offset']);
        if ($offset > 0) {
            $completed_chunks = (int) floor($offset / $chunk_size);
            for ($i = 0; $i < min($completed_chunks, $chunk_count); $i++) {
                $queue['chunks'][$i]['status'] = 'complete';
                $queue['chunks'][$i]['position'] = $chunk_size;
                $queue['chunks'][$i]['completed_at'] = time();
            }
            $partial_chunk = $completed_chunks;
            $partial_position = $offset % $chunk_size;
            if ($partial_position > 0 && isset($queue['chunks'][$partial_chunk])) {
                $queue['chunks'][$partial_chunk]['status'] = 'pending';
                $queue['chunks'][$partial_chunk]['position'] = $partial_position;
            }
        }
        $queue['migrated_from_serial_offset'] = 1;
        $changed = true;
    }

    // Reading/normalising must never write a whole queue outside its lock.
    // The scheduler and checkpoint writer persist the normalised value.

    return $queue;
}

function relay_control_known_remote_get_queue($queue_key) {
    $queue_key = (string) $queue_key;
    // A request can cache this option before another worker changes it. A lock
    // alone does not refresh that cache: read again before merging a checkpoint.
    wp_cache_delete($queue_key, 'options');
    wp_cache_delete('notoptions', 'options');
    $queue = get_option($queue_key, array());
    return relay_control_known_remote_normalize_queue($queue, $queue_key);
}

function relay_control_known_remote_completed_count($queue) {
    $count = 0;
    foreach ((array) ($queue['chunks'] ?? array()) as $chunk) {
        if (isset($chunk['status']) && $chunk['status'] === 'complete') {
            $count++;
        }
    }
    return $count;
}

function relay_control_known_remote_failed_count($queue) {
    $count = 0;
    foreach ((array) ($queue['chunks'] ?? array()) as $chunk) {
        if (isset($chunk['status']) && $chunk['status'] === 'failed') {
            $count++;
        }
    }
    return $count;
}

function relay_control_known_remote_terminal_count($queue) {
    return relay_control_known_remote_completed_count($queue) + relay_control_known_remote_failed_count($queue);
}

function relay_control_known_remote_running_count($queue) {
    $now = time();
    $timeout = relay_control_known_remote_chunk_timeout();
    $count = 0;

    foreach ((array) ($queue['chunks'] ?? array()) as $chunk) {
        $status = (string) ($chunk['status'] ?? 'pending');
        $started = (int) ($chunk['started_at'] ?? 0);
        $scheduled = (int) ($chunk['scheduled_at'] ?? 0);

        if ($status === 'running' && $started > 0 && ($now - $started) < $timeout) {
            $count++;
            continue;
        }

        // Treat scheduled chunks as occupied worker slots too. Otherwise repeated
        // init/watchdog passes can schedule the entire queue before the first
        // workers get a chance to start.
        if ($status === 'scheduled' && ($scheduled === 0 || ($now - $scheduled) < $timeout)) {
            $count++;
        }
    }

    return $count;
}

function relay_control_known_remote_queue_is_complete($queue) {
    $chunks = (array) ($queue['chunks'] ?? array());
    if (empty($chunks)) {
        return false;
    }

    foreach ($chunks as $chunk) {
        $status = (string) ($chunk['status'] ?? 'pending');
        if ($status !== 'complete' && $status !== 'failed') {
            return false;
        }
    }

    return true;
}

function relay_control_known_remote_rescue_stale_chunks($queue_key, $queue, $source = 'watchdog') {
    $now = time();
    $timeout = relay_control_known_remote_chunk_timeout();
    $max_attempts = (int) ($queue['max_attempts'] ?? relay_control_known_remote_max_attempts());
    $changed = false;
    $rescued = array();
    $failed = array();

    foreach ((array) ($queue['chunks'] ?? array()) as $idx => $chunk) {
        $status = (string) ($chunk['status'] ?? 'pending');
        $started = (int) ($chunk['started_at'] ?? 0);
        $attempts = (int) ($chunk['attempts'] ?? 0);
        $lease = relay_control_known_remote_chunk_lease($queue_key, $idx);
        // A live worker owns this chunk even if cron has consumed its event.
        if ($lease !== '' && (int) $lease >= $now) { continue; }

        if ($status === 'running' && $lease === '') {
            // The worker released its lease, e.g. after checkpoint contention.
            // This is a clean interruption, not a crash or a failed inbox.
            $queue['chunks'][$idx]['status'] = 'pending';
            $queue['chunks'][$idx]['started_at'] = 0;
            $queue['chunks'][$idx]['attempts'] = 0;
            $queue['chunks'][$idx]['last_error'] = 'released worker; resume saved checkpoint';
            $rescued[] = (int) $idx;
            $changed = true;
            continue;
        }

        if ($status === 'running' && $started > 0 && ($now - $started) >= $timeout) {
            if ($attempts >= $max_attempts) {
                $queue['chunks'][$idx]['status'] = 'failed';
                $queue['chunks'][$idx]['last_error'] = 'stale chunk exceeded max attempts';
                $failed[] = (int) $idx;
            } else {
                $queue['chunks'][$idx]['status'] = 'pending';
                $queue['chunks'][$idx]['started_at'] = 0;
                $queue['chunks'][$idx]['last_error'] = 'requeued by watchdog after stale running state';
                $rescued[] = (int) $idx;
            }
            $changed = true;
        }

        if ($status === 'scheduled') {
            $hook = 'relay_control_known_remote_fanout_chunk';
            $args = array((string) $queue_key, (int) $idx);
            $scheduled_at = (int) ($chunk['scheduled_at'] ?? 0);
            $event_time = wp_next_scheduled($hook, $args);
            if (false === $event_time || ($scheduled_at > 0 && ($now - $scheduled_at) >= $timeout)) {
                $queue['chunks'][$idx]['status'] = 'pending';
                $queue['chunks'][$idx]['scheduled_at'] = 0;
                $queue['chunks'][$idx]['last_error'] = false === $event_time ? 'scheduled event missing; requeued by watchdog' : 'scheduled event stale; requeued by watchdog';
                $rescued[] = (int) $idx;
                $changed = true;
            }
        }
    }

    if ($changed) {
        $queue['updated_at'] = time();
        update_option((string) $queue_key, $queue, false);
        relay_control_log('[RELAY CONTROL] Known remote fanout watchdog source=' . $source . ' outbox_id=' . (int) ($queue['outbox_id'] ?? 0) . ' activity=' . (string) ($queue['type'] ?? 'unknown') . ' activity_id=' . (string) ($queue['activity_id'] ?? '') . (!empty($rescued) ? ' requeued_chunks=' . implode(',', $rescued) : '') . (!empty($failed) ? ' failed_chunks=' . implode(',', $failed) : ''));
    }

    return relay_control_known_remote_get_queue($queue_key);
}

function relay_control_known_remote_schedule_watchdog($queue_key) {
    $hook = 'relay_control_known_remote_fanout_watchdog';
    $args = array((string) $queue_key);

    if (false === wp_next_scheduled($hook, $args)) {
        wp_schedule_single_event(time() + relay_control_known_remote_watchdog_interval(), $hook, $args);
    }
}

function relay_control_known_remote_spawn_chunk($queue_key, $chunk_index, $queue) {
    if (!relay_control_known_remote_async_spawn_enabled()) {
        return;
    }

    if (empty($queue['secret']) || !function_exists('rest_url')) {
        return;
    }

    $url = rest_url('relay-control/v1/known-remote-fanout-chunk');
    $body = wp_json_encode(array(
        'queue_key' => (string) $queue_key,
        'chunk' => (int) $chunk_index,
        'secret' => (string) $queue['secret'],
    ));

    // Non-blocking loopback gives separate PHP workers when the host allows it.
    // Cron events are still scheduled as fallback, so failure to spawn is not fatal.
    wp_remote_post($url, array(
        'timeout' => 0.01,
        'blocking' => false,
        'sslverify' => true,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        ),
        'body' => $body,
    ));
}

function relay_control_known_remote_focus_queue($queue_key) {
    // Let a sixth worker from 1.8.11 finish before admitting the five-slot build.
    $legacy = (string) get_option('relay_control_option_lock_' . md5('known_remote_global_worker_slot_5'), '');
    if ($legacy !== '' && (int) $legacy > time()) { return false; }
    // Persist the selected outbox between PHP requests. The same selection lock
    // covers both scheduling and worker admission, including old cron events.
    $lock = relay_control_option_lock_acquire('known_remote_focus_selection', 10);
    if (!$lock) { return false; }
    try {
        $key = 'relay_control_fanout_focus_queue';
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        $selected = (string) get_option($key, '');
        if ($selected !== '' && relay_control_known_remote_get_queue($selected)) {
            return $selected === (string) $queue_key;
        }
        // A removed queue can still have a request in flight. Wait for its
        // workers to release their slots before admitting another outbox.
        for ($slot = 0; $slot < 6; $slot++) {
            $slot_key = 'relay_control_option_lock_' . md5('known_remote_global_worker_slot_' . $slot);
            wp_cache_delete($slot_key, 'options');
            $lease = (string) get_option($slot_key, '');
            if ($lease !== '' && (int) $lease >= time()) { return false; }
        }
        if (!relay_control_known_remote_get_queue($queue_key)) { return false; }
        update_option($key, (string) $queue_key, false);
        return true;
    } finally {
        relay_control_option_lock_release($lock);
    }
}

function relay_control_known_remote_schedule_chunks($queue_key, $reason = 'scheduler') {
    $queue_key = (string) $queue_key;
    if (!relay_control_known_remote_focus_queue($queue_key)) {
        if (relay_control_known_remote_get_queue($queue_key)) {
            relay_control_known_remote_schedule_watchdog($queue_key);
        }
        return;
    }
    $spawn_after_unlock = array();
    $queue_for_spawn = array();

    $lock = relay_control_known_remote_queue_lock($queue_key, 90);
    if ($lock === '') {
        relay_control_log('[RELAY CONTROL] Known remote fanout scheduler skipped reason=queue_lock_busy queue=' . $queue_key . ' reason=' . (string) $reason);
        relay_control_known_remote_schedule_watchdog($queue_key);
        return;
    }

    try {
        $queue = relay_control_known_remote_get_queue($queue_key);
        if (empty($queue)) {
            delete_option($queue_key);
            return;
        }

        if ((int) ($queue['worker_checkpoint_version'] ?? 0) < 2) {
            // One-time upgrade of still-stored work. Keep accepted targets and
            // network-attempt ledgers, but retire the old one-crash queue limit.
            $queue['max_attempts'] = relay_control_known_remote_max_attempts();
            foreach ($queue['chunks'] as $idx => $chunk) {
                if (($chunk['status'] ?? '') === 'failed'
                    && in_array($chunk['last_error'] ?? '', array('stale chunk exceeded max attempts', 'max attempts reached before scheduling'), true)
                    && (int) ($chunk['deadline'] ?? (time() + 1)) > time()) {
                    $queue['chunks'][$idx]['status'] = 'pending';
                    $queue['chunks'][$idx]['attempts'] = 0;
                    $queue['chunks'][$idx]['last_error'] = 'resumed after checkpoint fix';
                }
            }
            $queue['worker_checkpoint_version'] = 2;
            update_option($queue_key, $queue, false);
        }

        if (relay_control_known_remote_queue_is_complete($queue)) {
            relay_control_log('[RELAY CONTROL] Known remote fanout complete outbox_id=' . (int) ($queue['outbox_id'] ?? 0) . ' activity=' . (string) ($queue['type'] ?? 'unknown') . ' activity_id=' . (string) ($queue['activity_id'] ?? '') . ' total=' . count((array) ($queue['targets'] ?? array())) . ' chunks=' . count((array) ($queue['chunks'] ?? array())) . ' complete_chunks=' . relay_control_known_remote_completed_count($queue) . ' failed_chunks=' . relay_control_known_remote_failed_count($queue));
            relay_control_finish_queue($queue_key, $queue);
            return;
        }

        $queue = relay_control_known_remote_rescue_stale_chunks($queue_key, $queue, $reason);
        if (empty($queue)) {
            return;
        }

        $max_workers = min(
            (int) ($queue['max_workers'] ?? relay_control_known_remote_max_workers()),
            relay_control_known_remote_max_workers()
        );
        if ((int) ($queue['max_workers'] ?? 0) !== $max_workers) {
            $queue['max_workers'] = $max_workers;
            $queue['updated_at'] = time();
            update_option($queue_key, $queue, false);
        }
        $running = relay_control_known_remote_running_count($queue);
        $slots = max(0, $max_workers - $running);
        $scheduled = array();
        $max_attempts = (int) ($queue['max_attempts'] ?? relay_control_known_remote_max_attempts());

        if ($slots > 0) {
            foreach ((array) ($queue['chunks'] ?? array()) as $idx => $chunk) {
                if ($slots <= 0) {
                    break;
                }

                $status = (string) ($chunk['status'] ?? 'pending');
                $attempts = (int) ($chunk['attempts'] ?? 0);
                if ($status !== 'pending' || (int) ($chunk['next_at'] ?? 0) > time()) {
                    continue;
                }
                if ($attempts >= $max_attempts) {
                    $queue['chunks'][$idx]['status'] = 'failed';
                    $queue['chunks'][$idx]['last_error'] = 'max attempts reached before scheduling';
                    continue;
                }

                $args = array((string) $queue_key, (int) $idx);
                if (false === wp_next_scheduled('relay_control_known_remote_fanout_chunk', $args)) {
                    wp_schedule_single_event(time() + wp_rand(20, 40), 'relay_control_known_remote_fanout_chunk', $args);
                }

                $queue['chunks'][$idx]['status'] = 'scheduled';
                $queue['chunks'][$idx]['scheduled_at'] = time();
                $scheduled[] = (int) $idx;
                $slots--;
            }

            if (!empty($scheduled)) {
                $queue['updated_at'] = time();
                update_option($queue_key, $queue, false);
                relay_control_log('[RELAY CONTROL] Known remote fanout chunks scheduled reason=' . $reason . ' outbox_id=' . (int) ($queue['outbox_id'] ?? 0) . ' activity=' . (string) ($queue['type'] ?? 'unknown') . ' activity_id=' . (string) ($queue['activity_id'] ?? '') . ' chunks=' . implode(',', $scheduled) . ' running=' . $running . ' max_workers=' . $max_workers . ' terminal=' . relay_control_known_remote_terminal_count($queue) . '/' . count((array) ($queue['chunks'] ?? array())) . ' complete=' . relay_control_known_remote_completed_count($queue) . ' failed=' . relay_control_known_remote_failed_count($queue));
                $spawn_after_unlock = $scheduled;
                $queue_for_spawn = $queue;
            } else {
                $queue['updated_at'] = time();
                update_option($queue_key, $queue, false);
            }
        }
    } finally {
        relay_control_option_lock_release($lock);
    }

    foreach ($spawn_after_unlock as $chunk_index) {
        relay_control_known_remote_spawn_chunk($queue_key, $chunk_index, $queue_for_spawn);
    }

    relay_control_known_remote_schedule_watchdog($queue_key);
}

function relay_control_known_remote_update_chunk($queue_key, $chunk_index, $patch) {
    $queue_key = (string) $queue_key;
    $lock = relay_control_known_remote_queue_lock($queue_key, 60);
    if ($lock === '') {
        relay_control_log('[RELAY CONTROL] Known remote fanout queue update skipped reason=queue_lock_busy queue=' . $queue_key . ' chunk=' . (int) $chunk_index);
        relay_control_known_remote_schedule_watchdog($queue_key);
        return array();
    }

    try {
        $queue = relay_control_known_remote_get_queue($queue_key);
        if (empty($queue) || !isset($queue['chunks'][(int) $chunk_index])) {
            return array();
        }

        foreach ((array) $patch as $key => $value) {
            $queue['chunks'][(int) $chunk_index][$key] = $value;
        }
        $queue['updated_at'] = time();
        $written = update_option($queue_key, $queue, false);
        if (!$written) {
            wp_cache_delete($queue_key, 'options');
            if (get_option($queue_key, array()) !== $queue) {
                relay_control_log('[RELAY CONTROL] Chunk checkpoint failed outbox_id='
                    . (int) ($queue['outbox_id'] ?? 0) . ' chunk=' . (int) $chunk_index);
                return array();
            }
        }
        return $queue;
    } finally {
        relay_control_option_lock_release($lock);
    }
}

function relay_control_known_remote_global_worker_enabled() {
    return (bool) apply_filters('relay_control_known_remote_global_worker_enabled', true);
}

function relay_control_known_remote_fanout_chunk_worker($queue_key, $chunk_index, $source = 'cron') {
    $queue_key = (string) $queue_key;
    $chunk_index = (int) $chunk_index;
    if (!relay_control_known_remote_focus_queue($queue_key)) {
        if (relay_control_known_remote_get_queue($queue_key)) {
            relay_control_known_remote_schedule_watchdog($queue_key);
        }
        return;
    }

    if (!relay_control_known_remote_global_worker_enabled()) {
        relay_control_known_remote_fanout_chunk_worker_unlocked($queue_key, $chunk_index, $source);
        relay_control_known_remote_schedule_chunks($queue_key, 'worker_yield');
        return;
    }

    $ttl = relay_control_known_remote_chunk_timeout() + 120;
    $global_lock = '';
    $slot_count = relay_control_known_remote_max_workers();
    for ($slot = 0; $slot < $slot_count; $slot++) {
        $global_lock = relay_control_option_lock_acquire('known_remote_global_worker_slot_' . $slot, $ttl);
        if ($global_lock !== '') {
            break;
        }
    }
    if ($global_lock === '') {
        $args = array($queue_key, $chunk_index);
        if (false === wp_next_scheduled('relay_control_known_remote_fanout_chunk', $args)) {
            wp_schedule_single_event(time() + wp_rand(20, 40), 'relay_control_known_remote_fanout_chunk', $args);
        }
        relay_control_log('[RELAY CONTROL] Known remote fanout chunk deferred reason=global_worker_busy queue=' . $queue_key . ' chunk=' . $chunk_index . ' source=' . $source);
        return;
    }

    try {
        relay_control_known_remote_fanout_chunk_worker_unlocked($queue_key, $chunk_index, $source);
    } finally {
        relay_control_option_lock_release($global_lock);
    }
    // Free this slot before launching the next worker, not afterwards.
    relay_control_known_remote_schedule_chunks($queue_key, 'worker_yield');
}

function relay_control_known_remote_fanout_chunk_worker_unlocked($queue_key, $chunk_index, $source = 'cron') {
    $raw = get_option($queue_key, array());
    if (!relay_control_known_remote_raw_queue_is_scan_eligible($queue_key, $raw)) { return; }
    $queue = relay_control_known_remote_get_queue($queue_key);
    $chunk_index = (int) $chunk_index;
    if (!isset($queue['chunks'][$chunk_index]) || !function_exists('Activitypub\\safe_remote_post')) { return; }
    $lock = relay_control_known_remote_chunk_lock($queue_key, $chunk_index, 150);
    if (!$lock) { return; }
    try {
        // Re-read after taking the lease. A duplicate cron/loopback invocation must not replay it.
        $queue = relay_control_known_remote_get_queue($queue_key);
        $chunk = $queue['chunks'][$chunk_index] ?? array();
        if (!$chunk || in_array($chunk['status'] ?? '', array('complete', 'failed'), true)
            || (int) ($chunk['next_at'] ?? 0) > time()) { return; }
        $identity = relay_control_signature_identity((string) $queue['json'], (int) $queue['user_id']);
        if (is_wp_error($identity)) {
            relay_control_known_remote_update_chunk($queue_key, $chunk_index, array('status' => 'pending', 'next_at' => time() + 120, 'last_error' => $identity->get_error_message()));
            return;
        }
        $queue['json'] = $identity['json'];
        $queue['user_id'] = $identity['user_id'];
        $chunk['status'] = 'running';
        $chunk['started_at'] = time();
        $chunk['attempts'] = (int) ($chunk['attempts'] ?? 0) + 1; // Unfinished worker leases, not delivery retries.
        $chunk['pending'] = (array) ($chunk['pending'] ?? array());
        $chunk['delivery_attempts'] = (array) ($chunk['delivery_attempts'] ?? array());
        $chunk['deadline'] = (int) ($chunk['deadline'] ?? (time() + DAY_IN_SECONDS));
        foreach (array('attempted', 'accepted', 'delivery_failed', 'deduplicated', 'deferred') as $counter) {
            $chunk[$counter] = (int) ($chunk[$counter] ?? 0);
        }
        if (!relay_control_known_remote_update_chunk($queue_key, $chunk_index, $chunk)) { return; }
        $start = $chunk_index * (int) $queue['chunk_size'];
        $length = min((int) $queue['chunk_size'], count($queue['targets']) - $start);
        $position = (int) $chunk['position'];
        $indices = $position < $length ? range($position, $length - 1) : array();
        foreach ($chunk['pending'] as $idx => $when) {
            if ((int) $when <= time() || time() >= $chunk['deadline']) { $indices[] = (int) $idx; }
        }
        $indices = array_values(array_unique($indices));
        $began = microtime(true);
        $requests = 0;
        foreach ($indices as $idx) {
            if ($requests >= 5 || microtime(true) - $began >= 15) { break; }
            // Deletion/supersession may occur while another PHP worker is running.
            if (!get_option($queue_key, false) || !get_post((int) $queue['outbox_id'])) { return; }
            $target = $queue['targets'][$start + $idx];
            $attempts = (int) ($chunk['delivery_attempts'][$idx] ?? 0);
            if ($attempts >= 3 || time() >= $chunk['deadline'] || relay_control_inbox_is_blocked($target)) {
                unset($chunk['pending'][$idx]);
                $chunk['delivery_failed']++;
            } else {
                $health = relay_control_target_health($target);
                if (!empty($health['permanent']) && (int) ($health['retry_after'] ?? 0) > time()) {
                    unset($chunk['pending'][$idx]);
                    $chunk['delivery_failed']++;
                } elseif ((int) ($health['retry_after'] ?? 0) > time()) {
                    $chunk['pending'][$idx] = min($chunk['deadline'], (int) $health['retry_after']);
                    $chunk['deferred']++;
                } else {
                    // Checkpoint BEFORE network I/O; a terminated worker cannot repeat forever.
                    $chunk['delivery_attempts'][$idx] = $attempts + 1;
                    $chunk['pending'][$idx] = time() + 60;
                    if (!relay_control_known_remote_update_chunk($queue_key, $chunk_index, $chunk)) { return; }
                    $GLOBALS['relay_control_managed_delivery'] = true;
                    try {
                        $result = \Activitypub\safe_remote_post($target, (string) $queue['json'], (int) $queue['user_id']);
                    } catch (Throwable $e) {
                        $result = new WP_Error('http_request_failed', $e->getMessage());
                    } finally {
                        unset($GLOBALS['relay_control_managed_delivery']);
                    }
                    $details = relay_control_response_details($result);
                    $code = is_wp_error($result) ? $result->get_error_code() : '';
                    if ($code === 'relay_control_already_delivered') {
                        $chunk['delivery_attempts'][$idx] = $attempts;
                        $chunk['deduplicated']++;
                        unset($chunk['pending'][$idx]);
                    } elseif (in_array($code, array('relay_control_delivery_busy', 'relay_control_delivery_cooldown', 'relay_control_signature_deferred'), true)) {
                        $chunk['delivery_attempts'][$idx] = $attempts;
                        $chunk['pending'][$idx] = time() + 60;
                        $chunk['deferred']++;
                    } elseif (in_array($code, array('relay_control_attempts_exhausted', 'relay_control_blocked', 'relay_control_target_rejected', 'relay_control_retired'), true)) {
                        $chunk['delivery_attempts'][$idx] = $attempts;
                        $chunk['delivery_failed']++;
                        unset($chunk['pending'][$idx]);
                    } else {
                        $requests++;
                        $chunk['attempted']++;
                        $status = (int) $details['status'];
                        if ($status >= 200 && $status < 300) {
                            $chunk['accepted']++;
                            unset($chunk['pending'][$idx]);
                        } elseif (relay_control_retryable($details) && $attempts + 1 < 3) {
                            $health = relay_control_target_health($target);
                            $chunk['pending'][$idx] = min($chunk['deadline'], max(time() + (60 * (2 ** $attempts)), (int) ($health['retry_after'] ?? 0)));
                        } else {
                            $chunk['delivery_failed']++;
                            unset($chunk['pending'][$idx]);
                        }
                        relay_control_log_delivery_receipt('POST', $target, $details['status'],
                            relay_control_activity_summary($queue['json']) . ' outbox_id=' . (int) $queue['outbox_id']
                            . ' source=known_remote_fanout chunk=' . $chunk_index . ' target_attempt=' . ($attempts + 1), $details['error'], $details['body']);
                    }
                }
            }
            if ($idx === $position) { $position++; }
            $chunk['position'] = $position;
            $chunk['last_seen_at'] = time();
            if (!relay_control_known_remote_update_chunk($queue_key, $chunk_index, $chunk)) { return; }
        }
        $done = $position >= $length && empty($chunk['pending']);
        $chunk['status'] = $done ? 'complete' : 'pending';
        $chunk['attempts'] = 0; // A clean yield is not a failed worker attempt.
        $chunk['started_at'] = 0;
        $chunk['next_at'] = $position < $length ? 0 : (empty($chunk['pending']) ? 0 : min($chunk['pending']));
        $chunk['completed_at'] = $done ? time() : 0;
        if (!relay_control_known_remote_update_chunk($queue_key, $chunk_index, $chunk)) { return; }
        if ($done) {
            relay_control_log('[RELAY CONTROL] Chunk finished outbox_id=' . (int) $queue['outbox_id']
                . ' chunk=' . $chunk_index . ' attempted=' . $chunk['attempted'] . ' accepted=' . $chunk['accepted']
                . ' failed_targets=' . $chunk['delivery_failed'] . ' deduplicated=' . $chunk['deduplicated']);
        }
    } finally {
        relay_control_option_lock_release($lock);
    }
}


function relay_control_known_remote_queue_activity_json($json, $outbox_item_id, $source = 'pre_send', $force = false, $current_inboxes = array()) {
    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        return array('queued' => false, 'reason' => 'invalid_json');
    }

    $type = strtolower((string) ($data['type'] ?? ''));
    if (!in_array($type, array('create', 'update', 'announce', 'delete'), true)) {
        return array('queued' => false, 'reason' => 'activity_type_not_allowed', 'activity' => $type);
    }

    $activity_id = (string) ($data['id'] ?? '');
    if ($activity_id === '') {
        return array('queued' => false, 'reason' => 'no_activity_id', 'activity' => $type);
    }

    $queue_key = relay_control_known_remote_queue_key($activity_id);
    $existing_queue = get_option($queue_key, array());
    if (is_array($existing_queue) && !empty($existing_queue['activity_id'])) {
        $queue = relay_control_known_remote_get_queue($queue_key);
        if (!empty($queue) && !relay_control_known_remote_queue_is_complete($queue)) {
            relay_control_known_remote_schedule_watchdog($queue_key);
            relay_control_known_remote_schedule_chunks($queue_key, $source . '_existing_queue');
            return array('queued' => true, 'reason' => 'existing_queue_rescheduled', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id, 'targets' => count((array) ($queue['targets'] ?? array())));
        }
        if (!empty($queue) && relay_control_known_remote_queue_is_complete($queue)) {
            return array('queued' => false, 'reason' => 'existing_queue_complete', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id);
        }
    }

    if (!$force && relay_control_was_known_remote_queued($activity_id)) {
        return array('queued' => false, 'reason' => 'recent_marker_exists', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id);
    }

    if (!relay_control_json_is_public($data)) {
        relay_control_mark_known_remote_queued($activity_id);
        return array('queued' => false, 'reason' => 'not_public', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id);
    }

    $known = relay_control_get_known_remote_inboxes();
    $current = relay_control_normalized_inbox_list($current_inboxes);
    $relays = relay_control_get_relays();
    $outbox = get_post((int) $outbox_item_id);
    $followers = ($outbox && class_exists('Activitypub\\Collection\\Followers'))
        ? \Activitypub\Collection\Followers::get_inboxes((int) $outbox->post_author) : array();
    $exclude = array_flip(array_merge($current, $relays,
        relay_control_normalized_inbox_list((array) $followers)));

    $targets = array();
    foreach ($known as $inbox) {
        if (isset($exclude[$inbox])) {
            continue;
        }
        if (relay_control_is_local_inbox_url($inbox)) {
            continue;
        }
        $targets[] = $inbox;
    }

    $targets = array_values(array_unique($targets));
    relay_control_mark_known_remote_queued($activity_id);

    if (empty($targets)) {
        return array('queued' => false, 'reason' => 'no_extra_known_inboxes', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id, 'known' => count($known), 'current' => count($current), 'relays' => count($relays));
    }

    $outbox_item = get_post((int) $outbox_item_id);
    $user_id = $outbox_item ? (int) $outbox_item->post_author : relay_control_blog_actor_id();
    $chunk_size = relay_control_known_remote_chunk_size();
    $chunk_count = (int) ceil(count($targets) / $chunk_size);

    $queue = array(
        'json' => (string) $json,
        'activity_id' => $activity_id,
        'type' => $type,
        'outbox_id' => (int) $outbox_item_id,
        'user_id' => $user_id,
        'targets' => $targets,
        'chunk_size' => $chunk_size,
        'chunk_count' => $chunk_count,
        'chunks' => array(),
        'max_workers' => relay_control_known_remote_max_workers(),
        'max_attempts' => relay_control_known_remote_max_attempts(),
        'secret' => relay_control_known_remote_make_secret(),
        'created_at' => time(),
        'updated_at' => time(),
        'source' => (string) $source,
    );

    $queue = relay_control_known_remote_normalize_queue($queue, '');
    update_option($queue_key, $queue, false);

    relay_control_log('[RELAY CONTROL] Known remote fanout queued outbox_id=' . (int) $outbox_item_id . ' activity=' . $type . ' activity_id=' . $activity_id . ' known_inboxes=' . count($known) . ' current_inboxes=' . count($current) . ' relays=' . count($relays) . ' queued_targets=' . count($targets) . ' chunks=' . $chunk_count . ' chunk_size=' . $chunk_size . ' max_workers=' . (int) $queue['max_workers'] . ' source=' . (string) $source . ($force ? ' force=1' : ''));

    relay_control_known_remote_schedule_chunks($queue_key, $source . '_queue_created');

    return array('queued' => true, 'reason' => 'queued', 'activity' => $type, 'activity_id' => $activity_id, 'outbox_id' => (int) $outbox_item_id, 'targets' => count($targets), 'chunks' => $chunk_count);
}

function relay_control_known_remote_backfill_outbox_id($outbox_id, $source = 'manual_backfill', $force = true) {
    if (!class_exists('Activitypub\\Collection\\Outbox')) {
        return array('queued' => false, 'reason' => 'outbox_class_missing', 'outbox_id' => (int) $outbox_id);
    }

    $outbox_item = get_post((int) $outbox_id);
    if (!$outbox_item || $outbox_item->post_type !== 'ap_outbox') {
        return array('queued' => false, 'reason' => 'missing_outbox', 'outbox_id' => (int) $outbox_id);
    }

    $activity = \Activitypub\Collection\Outbox::get_activity((int) $outbox_id);
    if (is_wp_error($activity)) {
        return array('queued' => false, 'reason' => 'activity_error', 'outbox_id' => (int) $outbox_id, 'error' => $activity->get_error_message());
    }

    $type = strtolower(relay_control_activity_type($activity));
    if (!relay_control_dispatch_type_allowed_for_relays($type)) {
        return array('queued' => false, 'reason' => 'activity_type_not_allowed', 'activity' => $type, 'outbox_id' => (int) $outbox_id);
    }

    if (!relay_control_activity_is_public_for_relay($activity)) {
        return array('queued' => false, 'reason' => 'not_public', 'activity' => $type, 'outbox_id' => (int) $outbox_id);
    }

    $json = $activity->to_json();
    return relay_control_known_remote_queue_activity_json($json, (int) $outbox_id, $source, (bool) $force, array());
}

function relay_control_known_remote_backfill_recent_outbox($days = 7, $limit = 10, $force = true) {
    $days = max(1, min(30, (int) $days));
    $limit = max(1, min(50, (int) $limit));

    $ids = get_posts(array(
        'post_type' => 'ap_outbox',
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'date_query' => array(
            array(
                'column' => 'post_date_gmt',
                'after' => gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)),
                'inclusive' => true,
            ),
        ),
    ));

    $results = array('scanned' => 0, 'queued' => 0, 'skipped' => 0, 'details' => array());
    foreach ((array) $ids as $id) {
        $results['scanned']++;
        $result = relay_control_known_remote_backfill_outbox_id((int) $id, 'manual_recent_backfill', (bool) $force);
        $results['details'][] = $result;
        if (!empty($result['queued'])) {
            $results['queued']++;
        } else {
            $results['skipped']++;
        }
    }

    relay_control_log('[RELAY CONTROL] Known remote fanout recent backfill scanned=' . (int) $results['scanned'] . ' queued=' . (int) $results['queued'] . ' skipped=' . (int) $results['skipped'] . ' days=' . $days . ' limit=' . $limit . ($force ? ' force=1' : ' force=0'));

    return $results;
}

function relay_control_pre_send_known_remote_fanout($json, $inboxes, $outbox_item_id) {
    relay_control_prune_stale_relay_states('before_known_remote_fanout');

    $result = relay_control_known_remote_queue_activity_json((string) $json, (int) $outbox_item_id, 'pre_send', false, (array) $inboxes);
    if (empty($result['queued'])) {
        $reason = (string) ($result['reason'] ?? 'unknown');
        $activity = (string) ($result['activity'] ?? 'unknown');
        $activity_id = (string) ($result['activity_id'] ?? '');
        if (in_array($reason, array('activity_type_not_allowed', 'recent_marker_exists', 'existing_queue_complete'), true)) {
            return;
        }
        relay_control_log('[RELAY CONTROL] Known remote fanout skipped reason=' . $reason . ' outbox_id=' . (int) $outbox_item_id . ' activity=' . $activity . ($activity_id !== '' ? ' activity_id=' . $activity_id : '') . (isset($result['known']) ? ' known_inboxes=' . (int) $result['known'] : '') . (isset($result['current']) ? ' current_inboxes=' . (int) $result['current'] : '') . (isset($result['relays']) ? ' relays=' . (int) $result['relays'] : ''));
    }
}
add_action('activitypub_pre_send_to_inboxes', 'relay_control_pre_send_known_remote_fanout', 2, 3);

// Backward-compatible hook name for old v1.5 scheduled serial-batch events.
add_action('relay_control_known_remote_fanout_batch', function ($queue_key) {
    $queue_key = (string) $queue_key;
    $raw_queue = get_option($queue_key, array());
    if (!relay_control_known_remote_raw_queue_is_scan_eligible($queue_key, $raw_queue)) {
        return;
    }
    relay_control_known_remote_schedule_chunks($queue_key, 'legacy_batch_event');
}, 10, 1);

add_action('relay_control_known_remote_fanout_chunk', function ($queue_key, $chunk_index) {
    relay_control_known_remote_fanout_chunk_worker((string) $queue_key, (int) $chunk_index, 'cron');
}, 10, 2);

add_action('relay_control_known_remote_fanout_watchdog', function ($queue_key) {
    $queue_key = (string) $queue_key;
    $raw_queue = get_option($queue_key, array());
    if (!relay_control_known_remote_raw_queue_is_scan_eligible($queue_key, $raw_queue)) {
        return;
    }

    $queue = relay_control_known_remote_get_queue($queue_key);
    if (empty($queue)) {
        delete_option($queue_key);
        return;
    }

    if (relay_control_known_remote_queue_is_complete($queue)) {
        relay_control_log('[RELAY CONTROL] Known remote fanout complete outbox_id=' . (int) ($queue['outbox_id'] ?? 0) . ' activity=' . (string) ($queue['type'] ?? 'unknown') . ' activity_id=' . (string) ($queue['activity_id'] ?? '') . ' total=' . count((array) ($queue['targets'] ?? array())) . ' chunks=' . count((array) ($queue['chunks'] ?? array())) . ' complete_chunks=' . relay_control_known_remote_completed_count($queue) . ' failed_chunks=' . relay_control_known_remote_failed_count($queue) . ' source=watchdog');
        relay_control_finish_queue($queue_key, $queue);
        return;
    }

    relay_control_known_remote_schedule_chunks($queue_key, 'watchdog');
}, 10, 1);

add_action('rest_api_init', function () {
    register_rest_route('relay-control/v1', '/known-remote-fanout-chunk', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $queue_key = (string) $request->get_param('queue_key');
            $chunk_index = (int) $request->get_param('chunk');
            $secret = (string) $request->get_param('secret');

            if (!preg_match('/^relay_control_known_remote_[a-f0-9]{32}$/', $queue_key)) { return new WP_REST_Response(array('error' => 'forbidden'), 403); }
            $raw_queue = get_option($queue_key, array());
            if (empty($raw_queue['secret']) || !hash_equals((string) $raw_queue['secret'], $secret)) { return new WP_REST_Response(array('error' => 'forbidden'), 403); }
            if (!relay_control_known_remote_raw_queue_is_scan_eligible($queue_key, $raw_queue)) {
                return new WP_REST_Response(array('error' => 'stale_queue'), 410);
            }

            $queue = relay_control_known_remote_get_queue($queue_key);
            if (empty($queue) || empty($queue['secret']) || !hash_equals((string) $queue['secret'], $secret)) {
                return new WP_REST_Response(array('error' => 'forbidden'), 403);
            }

            if (!relay_control_known_remote_async_spawn_enabled()) {
                relay_control_log('[RELAY CONTROL] Known remote fanout loopback rejected reason=loopback_disabled queue=' . $queue_key . ' chunk=' . $chunk_index);
                return new WP_REST_Response(array('error' => 'loopback_disabled'), 409);
            }

            relay_control_known_remote_fanout_chunk_worker($queue_key, $chunk_index, 'loopback');
            return new WP_REST_Response(array('ok' => true), 202);
        },
    ));
});

function relay_control_known_remote_find_queue_keys() {
    global $wpdb;

    if (!isset($wpdb) || !isset($wpdb->options)) {
        return array();
    }

    $like = $wpdb->esc_like('relay_control_known_remote_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    return is_array($keys) ? array_values(array_filter($keys, static function ($key) { return (bool) preg_match('/^relay_control_known_remote_[a-f0-9]{32}$/', $key); })) : array();
}

add_action('rest_api_init', function () {
    register_rest_route('relay-control/v1', '/proxy/(?P<key>[a-f0-9]{16})', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'relay_control_proxy_callback',
    ));
});

function relay_control_proxy_callback(WP_REST_Request $request) {
    return new WP_REST_Response(array('error' => 'proxy_retired'), 410);
}

add_action('admin_menu', function () {
    add_management_page(
        'Relay Control',
        'Relay Control',
        'manage_options',
        'relay-control',
        'relay_control_render_page'
    );
});

function relay_control_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden');
    }

    if (isset($_POST['relay_control_subscribe_now'])) {
        check_admin_referer('relay_control_subscribe_now');
        relay_control_subscribe_all(!empty($_POST['relay_control_force_resend_follow']), array(relay_control_subscription_actor_id()));
        echo '<div class="notice notice-info"><p>Site relay subscription action processed. Existing accepted subscriptions were retained. Check individual relay status and wp-content/uploads/relay-control-delivery.log for failures.</p></div>';
    }

    if (isset($_POST['relay_control_sync_native_now'])) {
        check_admin_referer('relay_control_sync_native_now');
        relay_control_reconcile_native_follow_states();
        echo '<div class="notice notice-success"><p>Display refreshed from verified native Following state.</p></div>';
    }

    if (isset($_POST['relay_control_prune_states_now'])) {
        check_admin_referer('relay_control_prune_states_now');
        relay_control_prune_stale_relay_states('manual_admin_button');
        echo '<div class="notice notice-success"><p>Stale Relay Control states pruned against the current ActivityPub relay list.</p></div>';
    }

    if (isset($_POST['relay_control_known_remote_backfill_single'])) {
        check_admin_referer('relay_control_known_remote_backfill_single');
        $outbox_id = isset($_POST['relay_control_known_remote_backfill_outbox_id']) ? (int) $_POST['relay_control_known_remote_backfill_outbox_id'] : 0;
        $force = !empty($_POST['relay_control_known_remote_backfill_single_force']);
        $result = $outbox_id > 0 ? relay_control_known_remote_backfill_outbox_id($outbox_id, 'manual_single_backfill', $force) : array('queued' => false, 'reason' => 'missing_outbox_id');
        echo '<div class="notice ' . (!empty($result['queued']) ? 'notice-success' : 'notice-warning') . '"><p>Single known-remote backfill outbox_id=<code>' . esc_html((string) $outbox_id) . '</code> result=<code>' . esc_html((string) ($result['reason'] ?? 'unknown')) . '</code>' . (!empty($result['targets']) ? ' targets=<strong>' . esc_html((string) $result['targets']) . '</strong>' : '') . '. Check wp-content/uploads/relay-control-delivery.log.</p></div>';
    }

    relay_control_reconcile_native_follow_states();

    $relays = relay_control_get_relays();
    $states = get_option('relay_control_states', array());
    if (!is_array($states)) {
        $states = array();
    }

    echo '<div class="wrap"><h1>Relay Control</h1>';
    echo '<p><strong>Detected ActivityPub version:</strong> <code>' . esc_html(defined('ACTIVITYPUB_PLUGIN_VERSION') ? ACTIVITYPUB_PLUGIN_VERSION : 'not loaded') . '</code></p>';
    echo '<p>This build uses the ActivityPub 9.3.1 native <code>Activitypub\\follow()</code>, <code>Activitypub\\Collection\\Outbox</code>, dispatcher hooks, and inbound relay Accept/Reject logging. It does not include any Delete queue tool.</p>';

    echo '<h2>Settings</h2>';
    echo '<p>One verified site subscription covers public activity from both accounts. Each activity keeps its own actor and signing key. The secondary account does not subscribe to relays. Subscription owner user ID: <code>' . esc_html((string) relay_control_subscription_actor_id()) . '</code>.</p>';

    echo '<h2>Relay subscription</h2>';
    echo '<form method="post">';
    wp_nonce_field('relay_control_subscribe_now');
    echo '<label><input type="checkbox" name="relay_control_force_resend_follow" value="1" /> Resend pending Follow only; preserve accepted site subscriptions.</label><br><br>';
    submit_button('Queue native ActivityPub Follow to all relays now', 'primary', 'relay_control_subscribe_now');
    echo '</form>';

    echo '<form method="post" style="margin-top: 1em;">';
    wp_nonce_field('relay_control_sync_native_now');
    submit_button('Refresh verified relay status', 'secondary', 'relay_control_sync_native_now', false);
    echo '</form>';

    echo '<form method="post" style="margin-top: 1em;">';
    wp_nonce_field('relay_control_prune_states_now');
    submit_button('Prune stale Relay Control states now', 'secondary', 'relay_control_prune_states_now', false);
    echo '</form>';

    echo '<h2>Known-remote fanout backfill</h2>';
    // 25 Sep 2026: the "recent" backfill button that used to live here is
    // gone -- folded into relay_control_recover_backlog()
    // as an automatic step on every recovery pass, so there is nothing
    // left to click for that case. This form is for the one remaining,
    // genuinely manual case: a specific ap_outbox ID known by number.
    echo '<p>Automatic recovery now catches recently published items missing a known-remote fanout queue on its own. Use this form only to force one specific outbox item by ID.</p>';
    echo '<form method="post" style="margin-top: 1em;">';
    wp_nonce_field('relay_control_known_remote_backfill_single');
    echo '<label>Specific ap_outbox ID: <input type="number" name="relay_control_known_remote_backfill_outbox_id" value="" min="1" /></label> ';
    echo '<label><input type="checkbox" name="relay_control_known_remote_backfill_single_force" value="1" /> Force requeue.</label><br><br>';
    submit_button('Backfill one specific outbox item', 'secondary', 'relay_control_known_remote_backfill_single', false);
    echo '</form>';

    echo '<h2>Configured relay inboxes</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Inbox</th><th>Relay actor</th><th>Dispatch target</th><th>Dispatch?</th><th>Relay state</th><th>Native ActivityPub state</th><th>Last Follow time</th><th>Last response/error</th></tr></thead><tbody>';
    if (empty($relays)) {
        echo '<tr><td colspan="8">No relays configured in ActivityPub settings.</td></tr>';
    }
    foreach ($relays as $relay) {
        $state = $states[$relay] ?? array();
        $dispatch = (relay_control_proxy_enabled() && preg_match('#^https://relay\\.intahnet\\.co\\.uk/inbox$#i', $relay)) ? relay_control_proxy_url($relay) : $relay;
        echo '<tr>';
        echo '<td><code>' . esc_html($relay) . '</code></td>';
        echo '<td><code>' . esc_html(relay_control_actor_from_inbox($relay)) . '</code></td>';
        echo '<td><code>' . esc_html($dispatch) . '</code></td>';
        echo '<td>' . (relay_control_relay_is_accepted($relay) ? '<strong>yes</strong>' : 'no') . '</td>';
        echo '<td>' . esc_html($state['last_follow_status'] ?? '') . '</td>';
        echo '<td>' . esc_html(relay_control_native_follow_state($relay)) . '</td>';
        echo '<td>' . esc_html($state['last_follow_at'] ?? '') . '</td>';
        echo '<td><code>' . esc_html(trim(($state['last_follow_error'] ?? '') . ' ' . ($state['last_follow_response'] ?? ''))) . '</code></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
/** Delivery recovery. Queue bookkeeping is separate from remote acknowledgement. */
function relay_control_verified_follow($activity, $users, $success, $type) {
    if (!$success || !is_array($activity) || !in_array(relay_control_subscription_actor_id(), array_map('intval', (array) $users), true)) { return; }
    $actor = $activity['actor'] ?? '';
    if (is_array($actor)) { $actor = $actor['id'] ?? ''; }
    $relay = relay_control_relay_actor_for_uri($actor);
    if (!$relay || !relay_control_is_current_relay($relay)) { return; }
    relay_control_save_state($relay, array(
        'last_follow_status' => relay_control_native_follow_state($relay), 'last_follow_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        'verified_user_ids' => array_map('intval', (array) $users),
    ));
}

function relay_control_target_health($url) {
    $value = get_transient('relay_control_inbox_health_' . md5(relay_control_normalize_inbox($url)));
    if (is_array($value) && in_array((int) ($value['status'] ?? 0), array(400, 401, 403, 413, 422), true)) {
        delete_transient('relay_control_inbox_health_' . md5(relay_control_normalize_inbox($url)));
        return array();
    }
    return is_array($value) ? $value : array();
}

function relay_control_retryable($details) {
    $status = is_numeric($details['status'] ?? '') ? (int) $details['status'] : 0;
    return $status === 0 || in_array($status, array(408, 425, 429, 500, 502, 503, 504), true);
}

function relay_control_http_outbox_id($args) {
    // Restrict HTTP interception to signed ActivityPub POSTs using a local outbox ID.
    if (strtoupper((string) ($args['method'] ?? '')) !== 'POST' || empty($args['key_id']) || !is_string($args['body'] ?? null)) { return 0; }
    $data = json_decode($args['body'], true);
    $id = $data['id'] ?? '';
    if (!is_string($id) || wp_parse_url($id, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) { return 0; }
    parse_str((string) wp_parse_url($id, PHP_URL_QUERY), $query);
    if (($query['post_type'] ?? '') !== 'ap_outbox' || !is_scalar($query['p'] ?? null)) { return 0; }
    $post = get_post((int) $query['p']);
    if (!$post || $post->post_type !== 'ap_outbox') { return 0; }
    if ((string) $post->guid === $id) { return (int) $post->ID; }
    $saved = json_decode($post->post_content, true);
    // An ID/GUID difference must not bypass holds, receipts or domain blocks.
    return is_array($saved) && ($saved['id'] ?? '') === $id
        && function_exists('relay_control_bridge_outbox_id_matches')
        && relay_control_bridge_outbox_id_matches($id, $post) ? (int) $post->ID : 0;
}

// Preflight and receipts share the same per-activity/per-destination record across
// native delivery, retries and custom fanout. Never return a fabricated 202.
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($pre !== false) { return $pre; }
    $id = relay_control_http_outbox_id($args);
    if (!$id) { return $pre; }
    if (get_post_meta($id, '_relay_control_identity_hold', true)) { return new WP_Error('relay_control_signature_deferred', 'Outbox identity requires correction.'); }
    if (get_post_meta($id, '_relay_control_delivery_terminal', true)) { return new WP_Error('relay_control_retired', 'Activity retired.'); }
    $hash = md5(relay_control_normalize_inbox($url));
    $record = get_post_meta($id, '_relay_control_delivery_' . $hash, true);
    $record = is_array($record) ? $record : array();
    if (($record['state'] ?? '') === 'accepted') { return new WP_Error('relay_control_already_delivered', 'Previously accepted by this inbox.'); }
    $repair = !empty($GLOBALS['relay_control_signature_recovery_active']) && (relay_control_signature_recoverable($record)
        || (!empty($record['signature_fix_1812']) && (int) ($record['attempts'] ?? 0) < 3));
    if (!$repair && (($record['state'] ?? '') === 'failed' || (int) ($record['attempts'] ?? 0) >= 3)) { return new WP_Error('relay_control_attempts_exhausted', 'Target delivery is terminal.'); }
    if (relay_control_inbox_is_blocked($url)) { return new WP_Error('relay_control_blocked', 'Blocked inbox.'); }
    $health = relay_control_target_health($url);
    if ((int) ($health['retry_after'] ?? 0) > time()) {
        return new WP_Error(!empty($health['permanent']) ? 'relay_control_target_rejected' : 'relay_control_delivery_cooldown', 'Inbox is cooling down.');
    }
    $lock = relay_control_option_lock_acquire('delivery:' . $id . ':' . $hash, 60);
    if (!$lock) { return new WP_Error('relay_control_delivery_busy', 'Another worker owns this delivery.'); }
    // Invalidate a stale per-request meta cache after acquiring the target lease.
    wp_cache_delete($id, 'post_meta');
    $record = get_post_meta($id, '_relay_control_delivery_' . $hash, true);
    $record = is_array($record) ? $record : array();
    $repair = !empty($GLOBALS['relay_control_signature_recovery_active']) && (relay_control_signature_recoverable($record)
        || (!empty($record['signature_fix_1812']) && (int) ($record['attempts'] ?? 0) < 3));
    if ($repair && empty($record['signature_fix_1812'])) {
        $record['before_signature_fix_1812'] = array('attempts' => $record['attempts'] ?? 0, 'status' => $record['status'] ?? '', 'state' => $record['state'] ?? '');
        $record['attempts'] = 0;
    }
    if (($record['state'] ?? '') === 'accepted' || (!$repair && (($record['state'] ?? '') === 'failed' || (int) ($record['attempts'] ?? 0) >= 3))) {
        relay_control_option_lock_release($lock);
        return new WP_Error(($record['state'] ?? '') === 'accepted' ? 'relay_control_already_delivered' : 'relay_control_attempts_exhausted', 'Delivery already resolved.');
    }
    if (!empty($GLOBALS['relay_control_signature_recovery_active'])) { $record['signature_fix_1812'] = true; }
    $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
    $record['state'] = 'sending';
    $record['url'] = $url;
    $record['updated_at'] = time();
    if (false === update_post_meta($id, '_relay_control_delivery_' . $hash, $record)) {
        relay_control_option_lock_release($lock);
        return new WP_Error('relay_control_signature_deferred', 'Could not persist the delivery attempt.');
    }
    $GLOBALS['relay_control_delivery_leases'][$id . ':' . $hash] = $lock;
    return false;
}, 99, 3);

add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    if ($context !== 'response') { return; }
    $id = relay_control_http_outbox_id($args);
    if (!$id) { return; }
    $hash = md5(relay_control_normalize_inbox($url));
    $lease = $GLOBALS['relay_control_delivery_leases'][$id . ':' . $hash] ?? '';
    if (!$lease) { return; }
    try {
        $details = relay_control_response_details($response);
        $status = (int) $details['status'];
        $ok = $status >= 200 && $status < 300;
        $retryable = relay_control_retryable($details);
        $record = get_post_meta($id, '_relay_control_delivery_' . $hash, true);
        $record['state'] = $ok ? 'accepted' : ($retryable && (int) $record['attempts'] < 3 ? 'retry' : 'failed');
        $record['status'] = $details['status'];
        $record['updated_at'] = time();
        update_post_meta($id, '_relay_control_delivery_' . $hash, $record);
        $key = 'relay_control_inbox_health_' . $hash;
        if ($ok || in_array($status, array(400, 401, 403, 413, 422), true)) { delete_transient($key); }
        else {
            $permanent = in_array($status, array(400, 403, 404, 405, 410, 413), true);
            $delay = $permanent ? 6 * HOUR_IN_SECONDS : ($status === 401 ? 15 * MINUTE_IN_SECONDS : 60 * (2 ** min(5, (int) $record['attempts'] - 1)));
            if ($status === 429 || $status === 503) {
                $header = wp_remote_retrieve_header($response, 'retry-after');
                if (is_scalar($header) && (string) $header !== '') {
                    $seconds = ctype_digit((string) $header) ? (int) $header : max(0, (int) strtotime((string) $header) - time());
                    $delay = max($delay, min(DAY_IN_SECONDS, $seconds));
                }
            }
            set_transient($key, array('retry_after' => time() + $delay, 'permanent' => $permanent, 'status' => $details['status']), $delay);
        }
    } finally {
        relay_control_option_lock_release($lease);
        unset($GLOBALS['relay_control_delivery_leases'][$id . ':' . $hash]);
    }
}, 5, 5);

add_filter('activitypub_remote_post_timeout', function ($timeout) { return min(6, max(1, (int) $timeout)); });
add_filter('activitypub_dispatcher_batch_size', function ($size) { return min(10, max(1, (int) $size)); });
add_filter('activitypub_dispatcher_retry_max_attempts', function () { return 3; });
add_filter('activitypub_dispatcher_retry_delay', function () { return 2 * MINUTE_IN_SECONDS; });
add_filter('activitypub_dispatcher_retry_error_codes', function ($codes) {
    return array_values(array_unique(array_merge((array) $codes, array(0, 'http_request_failed', 'relay_control_delivery_busy', 'relay_control_delivery_cooldown', 'relay_control_signature_deferred'))));
});

function relay_control_cancel_queue($key, $reason) {
    $queue = get_option($key, array());
    if (!is_array($queue)) { $queue = array(); }
    if ($queue) {
        relay_control_log('[RELAY CONTROL] Queue retired outbox_id=' . (int) ($queue['outbox_id'] ?? 0) . ' reason=' . $reason);
    }
    foreach ((array) ($queue['chunks'] ?? array()) as $i => $chunk) {
        wp_clear_scheduled_hook('relay_control_known_remote_fanout_chunk', array($key, (int) $i));
    }
    wp_clear_scheduled_hook('relay_control_known_remote_fanout_watchdog', array($key));
    wp_clear_scheduled_hook('relay_control_known_remote_fanout_batch', array($key));
    delete_option($key);
}

function relay_control_finish_queue($key, $queue) {
    $summary = array('attempted' => 0, 'accepted' => 0, 'failed_targets' => 0, 'deduplicated' => 0, 'legacy_unverified' => 0);
    foreach ((array) ($queue['chunks'] ?? array()) as $i => $chunk) {
        $summary['attempted'] += (int) ($chunk['attempted'] ?? 0);
        $summary['accepted'] += (int) ($chunk['accepted'] ?? 0);
        $summary['deduplicated'] += (int) ($chunk['deduplicated'] ?? 0);
        $summary['failed_targets'] += (int) ($chunk['delivery_failed'] ?? 0);
        $length = min((int) $queue['chunk_size'], count($queue['targets']) - $i * (int) $queue['chunk_size']);
        if (($chunk['status'] ?? '') === 'failed') {
            $summary['failed_targets'] += max(0, $length - (int) ($chunk['position'] ?? 0)) + count((array) ($chunk['pending'] ?? array()));
        } elseif (!array_key_exists('accepted', $chunk)) {
            $summary['legacy_unverified'] += $length; // Old sent=N never proved success.
        }
    }
    $summary['finished_at'] = time();
    $id = (int) ($queue['outbox_id'] ?? 0);
    if ($id && get_post($id)) { update_post_meta($id, '_relay_control_fanout_summary', $summary); }
    update_option('relay_control_last_fanout_summary', array_merge(array('outbox_id' => $id), $summary), false);
    relay_control_log('[RELAY CONTROL] Fanout finished outbox_id=' . $id . ' ' . wp_json_encode($summary));
    relay_control_cancel_queue($key, 'finished');
}

add_action('before_delete_post', function ($id, $post) {
    if (!$post || $post->post_type !== 'ap_outbox') { return; }
    relay_control_cancel_queue(relay_control_known_remote_queue_key($post->guid), 'outbox_deleted_or_superseded');
    wp_clear_scheduled_hook('relay_control_fallback_send_to_relays', array((int) $id));
}, 10, 2);

function relay_control_outbox_obsolete_reason($post) {
    if (!$post || $post->post_type !== 'ap_outbox') { return 'missing_outbox'; }
    $data = json_decode((string) $post->post_content, true);
    if (!is_array($data)) { return 'invalid_outbox_json'; }
    $type = strtolower((string) get_post_meta($post->ID, '_activitypub_activity_type', true));
    if (!$type) { $type = strtolower((string) ($data['type'] ?? '')); }
    if (!in_array($type, array('create', 'update', 'announce'), true)) { return ''; }
    if (!relay_control_json_is_public($data)) { return ''; } // Preserve private/DM delivery.
    $object = $data['object'] ?? $data;
    $object_type = is_array($object) ? strtolower((string) ($object['type'] ?? '')) : '';
    if (in_array($object_type, array('person', 'group', 'service', 'application', 'organization'), true)) { return ''; }
    $uri = is_array($object) ? ($object['id'] ?? '') : $object;
    if (!is_string($uri) || wp_parse_url($uri, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) { return ''; }
    parse_str((string) wp_parse_url($uri, PHP_URL_QUERY), $query);
    if (isset($query['author']) || strpos((string) wp_parse_url($uri, PHP_URL_PATH), '/@') !== false) { return ''; }
    $object_id = isset($query['p']) && is_scalar($query['p']) ? (int) $query['p'] : url_to_postid($uri);
    if ($object_id && (!get_post($object_id) || get_post_status($object_id) !== 'publish')) { return 'local_object_no_longer_public'; }
    return '';
}

function relay_control_retire_outbox($id, $reason) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'ap_outbox') { return; }
    $terminal = array('reason' => $reason, 'at' => time());
    update_post_meta($id, '_relay_control_delivery_terminal', $terminal);
    if (get_post_meta($id, '_relay_control_delivery_terminal', true) !== $terminal) {
        relay_control_log('[RELAY CONTROL] Cancellation checkpoint failed outbox_id=' . $id);
        return;
    }
    if (is_callable(array('Activitypub\\Scheduler', 'unschedule_events_for_item'))) {
        \Activitypub\Scheduler::unschedule_events_for_item($id);
    }
    relay_control_cancel_queue(relay_control_known_remote_queue_key($post->guid), $reason);
    // Native "publish" means processed. Retain the row and the explicit failure reason.
    wp_publish_post($id);
    relay_control_log('[RELAY CONTROL] Outbox retired outbox_id=' . $id . ' reason=' . $reason . ' delivered=no');
}

function relay_control_wake_outbox($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'ap_outbox' || $post->post_status !== 'pending') { return; }
    if (false === wp_next_scheduled('activitypub_process_outbox', array($id))) {
        wp_schedule_single_event(time() + 3, 'activitypub_process_outbox', array($id));
    }
    if (!relay_control_known_remote_async_spawn_enabled()) { return; }
    $key = 'relay_control_outbox_wake_' . $id;
    if (get_transient($key)) { return; }
    set_transient($key, 1, 30);
    $secret = get_option('relay_control_outbox_worker_secret', '');
    if (!$secret) {
        add_option('relay_control_outbox_worker_secret', wp_generate_password(48, false, false), '', false);
        $secret = get_option('relay_control_outbox_worker_secret', '');
    }
    wp_remote_post(rest_url('relay-control/v1/outbox-worker'), array(
        'timeout' => 0.01, 'blocking' => false, 'sslverify' => true,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('outbox_id' => $id, 'secret' => $secret)),
    ));
}

function relay_control_process_outbox($id) {
    $id = (int) $id;
    $post = get_post($id);
    if (!$post || $post->post_type !== 'ap_outbox' || $post->post_status !== 'pending'
        || !class_exists('Activitypub\\Dispatcher')) { return; }
    $lock = relay_control_option_lock_acquire('native_outbox_worker', 180);
    if (!$lock) { return; } // The minute recovery pump will pick it up.
    try {
        $post = get_post($id);
        if (!$post || $post->post_status !== 'pending') { return; }
        $relay_control_activity_type = strtolower((string) get_post_meta($id, '_activitypub_activity_type', true));
        if ($relay_control_activity_type === 'delete' && relay_control_was_known_remote_queued($post->guid)) {
            relay_control_retire_outbox($id, 'delivered_via_known_remote_fanout');
            return;
        }
        // Older outboxes may store the object rather than the full Activity.
        // Use exactly the reconstruction that native dispatch will use.
        $activity = \Activitypub\Collection\Outbox::get_activity($post);
        $identity_check = is_wp_error($activity) ? $activity
            : relay_control_signature_identity($activity->to_json(), (int) $post->post_author);
        if (is_wp_error($identity_check)) {
            $notice = 'relay_control_pending_defer_' . $id;
            if (!get_transient($notice)) {
                relay_control_log('[RELAY CONTROL] Pending deferred outbox_id=' . $id
                    . ' reason=' . sanitize_key($identity_check->get_error_code())
                    . ' detail=' . sanitize_key($identity_check->get_error_message()));
                set_transient($notice, 1, 10 * MINUTE_IN_SECONDS);
            }
            return;
        }
        $reason = relay_control_outbox_obsolete_reason($post);
        if ($reason) { relay_control_retire_outbox($id, $reason); return; }
        $dependency = relay_control_pending_create_dependency($post);
        if ($dependency) { relay_control_wake_outbox($dependency); return; }
        $state = get_post_meta($id, '_relay_control_outbox_recovery', true);
        $state = is_array($state) ? $state : array();
        $offset = (int) get_post_meta($id, '_activitypub_outbox_offset', true);
        // Avoid competing with a recent native batch. Lost work becomes eligible in two minutes.
        if ((int) ($state['next_at'] ?? 0) > time() || (int) ($state['last_at'] ?? 0) > time() - 120) { return; }
        $attempts = (int) ($state['offset'] ?? -1) === $offset ? (int) ($state['stalls'] ?? 0) + 1 : 1;
        // A stalled callback is not evidence that delivery is finished. Keep
        // its row pending and reduce retry frequency, retaining per-target caps.
        $delay = min(15 * MINUTE_IN_SECONDS, 120 * (2 ** min(3, max(0, $attempts - 3))));
        update_post_meta($id, '_relay_control_outbox_recovery', array('offset' => $offset, 'stalls' => $attempts,
            'last_at' => time(), 'next_at' => time() + $delay));
        $GLOBALS['relay_control_native_worker_owned'] = true;
        \Activitypub\Dispatcher::process_outbox($id);
    } catch (Throwable $e) {
        relay_control_log('[RELAY CONTROL] Native dispatch error outbox_id=' . $id . ' error=' . $e->getMessage() . ' class=' . get_class($e) . ' at=' . $e->getFile() . ':' . $e->getLine());
    } finally {
        unset($GLOBALS['relay_control_native_worker_owned']);
        relay_control_option_lock_release($lock);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('relay-control/v1', '/outbox-worker', array(
        'methods' => 'POST',
        'permission_callback' => function ($request) {
            $secret = (string) get_option('relay_control_outbox_worker_secret', '');
            $supplied = $request->get_param('secret');
            return $secret !== '' && is_string($supplied) && hash_equals($secret, $supplied);
        },
        'callback' => function ($request) {
            if (!relay_control_known_remote_async_spawn_enabled()) { return new WP_REST_Response(array('error' => 'loopback_disabled'), 409); }
            relay_control_process_outbox((int) $request->get_param('outbox_id'));
            return new WP_REST_Response(array('processed' => true), 200);
        },
    ));
});

// Keep the existing minute recovery entry point; delivery-time normalization
// handles old snapshots without rewriting stored activities or remote authors.
function relay_control_repair_stale_outbox_batch($limit = 5) {
    // Normalize at the HTTP boundary, including snapshots in existing queues.
    // Never rewrite arbitrary remote authors or mutate previously stored activities.
    relay_control_signature_recovery();
}

function relay_control_pending_recovery_ids($limit = 3) {
    global $wpdb;
    $limit = max(1, min(3, (int) $limit));
    $after = max(0, (int) get_option('relay_control_recovery_pending_cursor', 0));
    $query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ap_outbox' AND post_status = 'pending' AND ID > %d ORDER BY ID ASC LIMIT %d";
    $ids = $wpdb->get_col($wpdb->prepare($query, $after, $limit));
    if ($wpdb->last_error) { return new WP_Error('relay_control_pending_read_failed', 'Cannot read pending outbox.'); }
    if (!$ids && $after > 0) {
        $ids = $wpdb->get_col($wpdb->prepare($query, 0, $limit));
        if ($wpdb->last_error) { return new WP_Error('relay_control_pending_read_failed', 'Cannot read pending outbox.'); }
    }
    return array_map('intval', (array) $ids);
}

/** Bounded automatic repair of rows retired by the old three-stall rule. */
function relay_control_resume_stalled_retirements() {
    global $wpdb;
    $cursor = max(0, (int) get_option('relay_control_stall_repair_cursor_1815', 0));
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
         WHERE p.post_type = 'ap_outbox' AND p.post_status = 'publish' AND p.ID > %d
         AND m.meta_key = '_relay_control_delivery_terminal' AND m.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 5",
        $cursor, '%no_progress_after_three_recovery_attempts%'
    ));
    if ($wpdb->last_error) { return; }
    if (!$ids) { update_option('relay_control_stall_repair_cursor_1815', 0, false); return; }
    foreach ($ids as $id) {
        $id = (int) $id;
        update_option('relay_control_stall_repair_cursor_1815', $id, false);
        $post = get_post($id);
        $terminal = get_post_meta($id, '_relay_control_delivery_terminal', true);
        if (($terminal['reason'] ?? '') !== 'no_progress_after_three_recovery_attempts'
            || !$post || relay_control_outbox_obsolete_reason($post)) { continue; }
        $activity = \Activitypub\Collection\Outbox::get_activity($post);
        if (is_wp_error($activity) || is_wp_error(relay_control_signature_identity($activity->to_json(), (int) $post->post_author))) { continue; }
        // Clear the exact erroneous terminal marker before changing status.
        // If the status write fails, restore it so the next automatic pass retries.
        delete_post_meta($id, '_relay_control_delivery_terminal', $terminal);
        if (get_post_meta($id, '_relay_control_delivery_terminal', true)) { continue; }
        $updated = wp_update_post(array('ID' => $id, 'post_status' => 'pending'), true);
        if (!$updated || is_wp_error($updated) || get_post_status($id) !== 'pending') {
            update_post_meta($id, '_relay_control_delivery_terminal', $terminal);
            continue;
        }
        delete_post_meta($id, '_relay_control_outbox_recovery');
        // A cancelled unfinished queue's old marker must not prevent rebuilding
        // it. Recorded accepted recipients will still be deduplicated.
        if (!get_post_meta($id, '_relay_control_fanout_summary', true)) {
            delete_transient(relay_control_activity_marker_key('known_remote_queued', $activity->get_id()));
        }
        update_post_meta($id, '_relay_control_stall_repair_1815', time());
        relay_control_log('[RELAY CONTROL] Stalled outbox resumed outbox_id=' . $id . ' receipts_preserved=yes');
    }
}

function relay_control_recover_backlog() {
    if (!relay_control_ap_ready()) { return; }
    $lock = relay_control_option_lock_acquire('recovery_pump', 55);
    if (!$lock) { return; }
    try {
        // Cron and ordinary requests share this gate. Claim the minute before
        // spawning loopbacks so their own shutdown hooks cannot start a storm.
        if ((int) get_option('relay_control_recovery_last_run', 0) > time() - 60) { return; }
        update_option('relay_control_recovery_last_run', time(), false);
        relay_control_cleanup_secondary_subscriptions();
        relay_control_resume_stalled_retirements();
        relay_control_repair_stale_outbox_batch(5);
        // 25 Sep 2026: folded in what the manual "Backfill recent public
        // outbox items" admin button was for -- catching items that
        // already finished normal AP-core delivery (no longer pending)
        // but never got a known-remote fanout queue created at all,
        // which the pending-backlog scan above doesn't cover since
        // these aren't pending. Small, fixed, conservative bound on an
        // automatic pass, unlike the manual form's user-set days/limit.
        relay_control_known_remote_backfill_recent_outbox(2, 5, false);
        $ids = relay_control_pending_recovery_ids();
        if (is_wp_error($ids)) {
            relay_control_log('[RELAY CONTROL] Pending scan failed reason=database_read');
            return;
        }
        $woken = 0;
        foreach ($ids as $id) {
            // Advance even if this item is held. Revisit it after a complete cycle.
            update_option('relay_control_recovery_pending_cursor', (int) $id, false);
            $reason = relay_control_outbox_obsolete_reason(get_post($id));
            if ($reason) { relay_control_retire_outbox($id, $reason); continue; }
            relay_control_wake_outbox((int) $id); $woken++;
        }
        // Rotate through every queue. A few cooling-down queues cannot starve the rest.
        $keys = relay_control_known_remote_find_queue_keys();
        sort($keys, SORT_STRING);
        $after = (string) get_option('relay_control_recovery_queue_cursor', '');
        $later = array_values(array_filter($keys, static function ($key) use ($after) { return strcmp($key, $after) > 0; }));
        $earlier = array_values(array_filter($keys, static function ($key) use ($after) { return strcmp($key, $after) <= 0; }));
        foreach (array_slice(array_merge($later, $earlier), 0, 12) as $key) {
            update_option('relay_control_recovery_queue_cursor', $key, false);
            $queue = get_option($key, array());
            if (!relay_control_known_remote_raw_queue_is_scan_eligible($key, $queue)) { continue; }
            $reason = relay_control_outbox_obsolete_reason(get_post((int) $queue['outbox_id']));
            if ($reason) { relay_control_cancel_queue($key, $reason); continue; }
            relay_control_known_remote_schedule_chunks($key, 'backlog_recovery');
        }
        $counts = wp_count_posts('ap_outbox');
        relay_control_log('[RELAY CONTROL] Recovery pass pending='
            . (isset($counts->pending) ? (int) $counts->pending : 'unknown')
            . ' selected=' . ($ids ? implode(',', $ids) : 'none') . ' wake_requests=' . $woken
            . ' queue_records=' . count($keys));
        update_option('relay_control_recovery_last_run', time(), false);
    } finally {
        relay_control_option_lock_release($lock);
    }
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['relay_control_every_minute'] = array('interval' => 60, 'display' => 'Relay Control delivery recovery');
    return $schedules;
});
add_action('relay_control_delivery_recovery_tick', 'relay_control_recover_backlog');
function relay_control_recovery_bootstrap() {
    if (function_exists('relay_control_bridge_db_circuit_open') && relay_control_bridge_db_circuit_open()) {
        return;
    }
    remove_action('activitypub_process_outbox', array('Activitypub\\Dispatcher', 'process_outbox'));
    add_action('activitypub_process_outbox', 'relay_control_process_outbox');
    if (!wp_next_scheduled('relay_control_delivery_recovery_tick')) {
        // 25 Sep 2026: this call's return was never checked -- 41
        // "could not be saved" reschedule/unschedule failures for this
        // exact hook appear in the 24-25 Sep debug log, all silently
        // discarded. The circuit breaker above only guards against
        // *already-observed* DB trouble; a write can still fail here
        // even when the circuit hasn't tripped yet. The next init-hook
        // call already retries this naturally, so this isn't a missing
        // retry -- it's a missing record of the retry ever being needed.
        $scheduled = wp_schedule_event(time() + 5, 'relay_control_every_minute', 'relay_control_delivery_recovery_tick', array(), true);
        if (!$scheduled || is_wp_error($scheduled)) {
            relay_control_log('[RELAY CONTROL] Recovery tick reschedule failed error=' . (is_wp_error($scheduled) ? $scheduled->get_error_code() : 'schedule_write_failed'));
        }
    }
}
add_action('init', 'relay_control_recovery_bootstrap', PHP_INT_MAX);

// Also works for MU plugins, which have no activation hook. The first loaded
// request starts recovery on shutdown; later requests repair missed cron runs.
// This only scans bounded work and spawns background requests, never sends
// remote federation deliveries synchronously in the visitor's request.
// Event-driven trigger added 23 Sep 2026: same pattern as wrzky.com,
// dispatch immediately on publish instead of waiting for the next poll,
// alongside native WP-Cron (DISABLE_WP_CRON now false) rather than a
// dedicated fixed-interval external trigger.
add_action('save_post_relay_control_fedi_activity', function ($post_id, $post) {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (function_exists('relay_control_bridge_db_circuit_open') && relay_control_bridge_db_circuit_open()) {
        return;
    }
    relay_control_recovery_request_tick();
}, 20, 2);

function relay_control_recovery_request_tick() {
    if ((int) get_option('relay_control_recovery_last_run', 0) > time() - 60) { return; }
    relay_control_recover_backlog();
}
add_action('shutdown', 'relay_control_recovery_request_tick', 20);

// Replace only delivery batching's global 30-minute lock with short, bounded
// leases. Other ActivityPub background callbacks retain their native scheduler.
function relay_control_native_batch($id, $size = 10, $offset = 0) {
    $id = (int) $id;
    $post = get_post($id);
    if (!$post || $post->post_status !== 'pending') { return; }
    $args = array($id, min(10, max(1, (int) $size)), (int) $offset);
    $owned = !empty($GLOBALS['relay_control_native_worker_owned']);
    $lock = $owned ? '' : relay_control_option_lock_acquire('native_outbox_worker', 180);
    if (!$owned && !$lock) {
        wp_schedule_single_event(time() + 20, 'activitypub_send_activity', $args);
        return;
    }
    try {
        $current = (int) get_post_meta($id, '_activitypub_outbox_offset', true);
        if ((int) $offset !== $current) { return; }
        $next = \Activitypub\Dispatcher::send_to_followers($id, $args[1], $current);
        update_post_meta($id, '_relay_control_outbox_recovery', array(
            'offset' => (int) get_post_meta($id, '_activitypub_outbox_offset', true), 'stalls' => 0, 'last_at' => time(),
        ));
        if (!empty($next)) { wp_schedule_single_event(time() + 5, 'activitypub_send_activity', array_values($next)); }
    } finally {
        if ($lock) { relay_control_option_lock_release($lock); }
    }
}

function relay_control_native_retry($key, $id, $attempt = 1) {
    $post = get_post((int) $id);
    if (!$post || get_post_meta((int) $id, '_relay_control_delivery_terminal', true)) { delete_transient($key); return; }
    $lock = relay_control_option_lock_acquire('native_outbox_worker', 180);
    if (!$lock) {
        wp_schedule_single_event(time() + 20, 'activitypub_retry_activity', array($key, $id, $attempt));
        return;
    }
    try {
        \Activitypub\Dispatcher::retry_send_to_followers($key, $id, $attempt);
    } finally {
        relay_control_option_lock_release($lock);
    }
}

add_action('init', function () {
    remove_action('activitypub_send_activity', array('Activitypub\\Scheduler', 'async_batch'));
    remove_action('activitypub_retry_activity', array('Activitypub\\Scheduler', 'async_batch'));
    add_action('activitypub_send_activity', 'relay_control_native_batch', 10, 3);
    add_action('activitypub_retry_activity', 'relay_control_native_retry', 10, 3);
}, PHP_INT_MAX);

function relay_control_pending_create_dependency($post) {
    if (strtolower((string) get_post_meta($post->ID, '_activitypub_activity_type', true)) !== 'announce') { return 0; }
    $data = json_decode((string) $post->post_content, true);
    $object = $data['object'] ?? '';
    $uri = is_array($object) ? ($object['id'] ?? '') : $object;
    if (!is_string($uri) || wp_parse_url($uri, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) { return 0; }
    parse_str((string) wp_parse_url($uri, PHP_URL_QUERY), $query);
    $object_id = isset($query['p']) && is_scalar($query['p']) ? (int) $query['p'] : url_to_postid($uri);
    $uris = array($uri);
    if ($object_id) { $uris[] = home_url('/?p=' . $object_id); }
    $ids = get_posts(array('post_type' => 'ap_outbox', 'post_status' => 'pending', 'posts_per_page' => 1,
        'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'meta_query' => array(
            array('key' => '_activitypub_activity_type', 'value' => 'Create'),
            array('key' => '_activitypub_object_id', 'value' => $uris, 'compare' => 'IN'),
        )));
    return empty($ids) ? 0 : (int) $ids[0];
}

// Event-driven immediate dispatch, added 23 Sep 2026: same as wrzky.com,
// same day. Fire dispatch the instant a new fedi-activity post actually
// publishes, instead of waiting on the next poll. Works with
// DISABLE_WP_CRON now false, letting native cron fire opportunistically
// on real inbound traffic instead of needing a dedicated fixed-interval
// external trigger.
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status === 'publish' && $old_status !== 'publish' && $post->post_type === 'relay_control_fedi_activity') {
        if (function_exists('relay_control_bridge_db_circuit_open') && relay_control_bridge_db_circuit_open()) {
            return;
        }
        // Debounced, not immediate: same fix as wrzky.com, same day. A
        // burst of many posts (e.g. many boosts at once) firing that many
        // concurrent dispatch runs is exactly the burst pattern
        // abuse-protection watches for. One scheduled near-term event,
        // only if one isn't already pending, coalesces any burst.
        if (!wp_next_scheduled('relay_control_debounced_dispatch')) {
            wp_schedule_single_event(time() + 10, 'relay_control_debounced_dispatch');
        }
    }
}, 20, 3);
add_action('relay_control_debounced_dispatch', function () {
    if (function_exists('relay_control_bridge_db_circuit_open') && relay_control_bridge_db_circuit_open()) {
        return;
    }
    relay_control_recovery_request_tick();
});

/** Release only the old generic bridge hold when the saved row proves its owner. */
function relay_control_revalidate_legacy_hold($id) {
    $marker = 'Originating account and saved activity disagree.';
    if (get_post_meta($id, '_relay_control_identity_hold', true) !== $marker
        || !function_exists('relay_control_bridge_validate_saved_activity')) { return false; }
    $post = get_post($id);
    if (!$post || $post->post_type !== 'ap_outbox') { return false; }
    $saved = relay_control_bridge_validate_saved_activity($id, (int) $post->post_author);
    if (is_wp_error($saved)) {
        update_post_meta($id, '_relay_control_identity_hold_detail', $saved->get_error_code());
        return false;
    }
    update_post_meta($id, '_relay_control_originating_actor_user_id', (int) $post->post_author);
    if ((int) get_post_meta($id, '_relay_control_originating_actor_user_id', true) !== (int) $post->post_author) { return false; }
    delete_post_meta($id, '_relay_control_identity_hold', $marker);
    if (get_post_meta($id, '_relay_control_identity_hold', true)) { return false; }
    delete_post_meta($id, '_relay_control_identity_hold_detail');
    relay_control_log('[RELAY CONTROL] Legacy ownership hold released outbox_id=' . (int) $id
        . ' user_id=' . (int) $post->post_author . ' activity_id_preserved=yes');
    return true;
}

/** Bind only local, persisted Announces with unambiguous per-user ownership. */
function relay_control_bind_boost_owner($data) {
    if (($data['type'] ?? '') !== 'Announce') { return $data; }
    $id = relay_control_http_outbox_id(array(
        'method' => 'POST', 'key_id' => 'ownership-check', 'body' => wp_json_encode($data),
    ));
    if (!$id) { return $data; }
    $post = get_post($id);
    $uid = (int) $post->post_author;
    $origin = get_post_meta($id, '_relay_control_originating_actor_user_id', true);
    relay_control_revalidate_legacy_hold($id);
    if (get_post_meta($id, '_relay_control_identity_hold', true)
        || ($origin !== '' && (int) $origin !== $uid)) {
        return new WP_Error('relay_control_signature_deferred', 'Boost ownership is inconsistent.');
    }
    $site_uid = relay_control_primary_user_id();
    $secondary_uid = relay_control_secondary_user_id();
    $secondary = function_exists('relay_control_secondary_actor_url') ? relay_control_secondary_actor_url() : '';
    $site = function_exists('relay_control_bridge_posts_actor_id') ? relay_control_bridge_posts_actor_id() : '';
    $actor = $data['actor'] ?? '';
    $secondary_aliases = array_filter(array($secondary, $secondary_uid !== null ? home_url('/?author=' . $secondary_uid) : ''));
    $site_aliases = array_filter(array($site, home_url('/?author=' . $site_uid)));
    // Blog/application/other-user boosts are outside the two configured accounts.
    if (!in_array($uid, array_filter(array($secondary_uid, $site_uid), 'is_int'), true)
        && !in_array($actor, $secondary_aliases, true)) { return $data; }
    if (get_post_meta($id, '_activitypub_activity_actor', true) !== 'user') {
        return new WP_Error('relay_control_signature_deferred', 'Boost lacks a verified user outbox owner.');
    }
    if ($secondary_uid !== null && $uid === $secondary_uid && $secondary !== ''
        && in_array($actor, array_merge($secondary_aliases, $site_aliases), true)) {
        // Repair the old bridge's fixed-site-actor bug only when the saved
        // outbox owner proves this was the secondary account. Do not touch the boosted object.
        $data['actor'] = $secondary;
        return $data;
    }
    if ($uid === $site_uid && in_array($actor, $site_aliases, true)) { return $data; }
    return new WP_Error('relay_control_signature_deferred', 'Boost actor and outbox owner disagree.');
}

add_filter('activitypub_get_outbox_activity', function ($activity, $post) {
    if ($post instanceof WP_Post) {
        relay_control_revalidate_legacy_hold($post->ID);
        if (get_post_meta($post->ID, '_relay_control_identity_hold', true)) {
            $reason = get_post_meta($post->ID, '_relay_control_identity_hold_detail', true) ?: get_post_meta($post->ID, '_relay_control_identity_hold', true);
            return new WP_Error('relay_control_signature_deferred', 'Outbox held: ' . $reason);
        }
    }
    if (is_wp_error($activity) || !is_object($activity) || !method_exists($activity, 'to_array')) { return $activity; }
    $data = relay_control_bind_boost_owner($activity->to_array());
    if (is_wp_error($data)) { return $data; }
    if (($data['type'] ?? '') === 'Announce') { $activity->set_actor($data['actor']); }
    return $activity;
}, PHP_INT_MAX, 2);

/** Explicit local aliases and verified boost ownership determine the signer. */
function relay_control_signature_identity($json, $preferred = null) {
    if ((function_exists('relay_control_bridge_db_circuit_open') && relay_control_bridge_db_circuit_open())
        || !class_exists('Activitypub\\Collection\\Actors') || !is_string($json)) {
        return new WP_Error('relay_control_signature_deferred', 'ActivityPub signing is unavailable.');
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !is_string($data['actor'] ?? null)) {
        return new WP_Error('relay_control_signature_deferred', 'Activity has no unambiguous actor.');
    }
    $data = relay_control_bind_boost_owner($data);
    if (is_wp_error($data)) { return $data; }
    $bound_json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($bound_json === false) { return new WP_Error('relay_control_signature_deferred', 'Invalid activity encoding.'); }
    // Preserve original bytes unless the binding actually changed data.
    if ($data !== json_decode($json, true)) { $json = $bound_json; }
    $original = $data['actor'];
    $ids = array();
    $secondary_uid = relay_control_secondary_user_id();
    $ids[] = relay_control_primary_user_id();
    if ($secondary_uid !== null && function_exists('relay_control_secondary_actor_url')) { $ids[] = $secondary_uid; }
    if (is_numeric($preferred)) { $ids[] = (int) $preferred; }
    if (class_exists('Activitypub\\Application') && $original === \Activitypub\Application::get_id()) { $ids[] = -1; }
    foreach (array_unique($ids) as $uid) {
        try {
            $actor = $uid === -1 ? new \Activitypub\Model\Application() : \Activitypub\Collection\Actors::get_by_id($uid);
            if (is_wp_error($actor) || !is_object($actor)) { continue; }
            $canonical = (string) $actor->get_id();
            $aliases = array($canonical);
            // Only the two configured local identities have URL aliases.
            $pretty = '';
            if ($uid === relay_control_primary_user_id() && function_exists('relay_control_bridge_posts_actor_id')) {
                $pretty = relay_control_bridge_posts_actor_id();
            } elseif ($secondary_uid !== null && $uid === $secondary_uid && function_exists('relay_control_secondary_actor_url')) {
                $pretty = relay_control_secondary_actor_url();
            }
            if ($pretty !== '') {
                if ($canonical !== $pretty) { continue; }
                $aliases[] = home_url('/?author=' . $uid);
            }
            if (!in_array($original, $aliases, true)) { continue; }
            $pub = $actor->get_public_key();
            $private = $uid === -1 ? \Activitypub\Application::get_private_key() : \Activitypub\Collection\Actors::get_private_key($uid);
            if (!is_array($pub) || ($pub['id'] ?? '') !== $canonical . '#main-key'
                || ($pub['owner'] ?? '') !== $canonical || !is_string($private) || $private === '') { continue; }
            $a = @openssl_pkey_get_private($private);
            $b = @openssl_pkey_get_public($pub['publicKeyPem'] ?? '');
            $a = $a ? openssl_pkey_get_details($a) : false;
            $b = $b ? openssl_pkey_get_details($b) : false;
            if (!$a || !$b || empty($a['rsa']) || ($a['rsa']['n'] ?? null) !== ($b['rsa']['n'] ?? null)
                || ($a['rsa']['e'] ?? null) !== ($b['rsa']['e'] ?? null)) { continue; }
            $data['actor'] = $canonical;
            // Only normalize this actor's own attribution, never a boosted author's.
            if (($data['attributedTo'] ?? null) === $original) { $data['attributedTo'] = $canonical; }
            if (in_array($data['type'] ?? '', array('Create', 'Update'), true) && is_array($data['object'] ?? null)
                && ($data['object']['attributedTo'] ?? null) === $original) {
                $data['object']['attributedTo'] = $canonical;
            }
            if (($data['type'] ?? '') === 'Undo' && is_array($data['object'] ?? null)
                && ($data['object']['actor'] ?? null) === $original) { $data['object']['actor'] = $canonical; }
            $body = $original === $canonical ? $json : wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($body)) { break; }
            return array('json' => $body, 'user_id' => $uid === -1 ? null : $uid, 'actor' => $canonical,
                'key_id' => $pub['id'], 'private_key' => $private, 'public_key' => $pub['publicKeyPem']);
        } catch (Throwable $e) {
            // Do not leak key material or emit a request using another account.
            continue;
        }
    }
    return new WP_Error('relay_control_signature_deferred', 'Actor identity and the stored RSA key pair do not agree.');
}

function relay_control_signature_prepare($args, $url) {
    if (strtoupper((string) ($args['method'] ?? '')) !== 'POST' || empty($args['key_id'])) { return $args; }
    $identity = relay_control_signature_identity($args['body'] ?? null, $args['user_id'] ?? null);
    if (is_wp_error($identity)) {
        $args['_relay_control_signature_error'] = $identity;
        unset($args['private_key']); // Prevent AP core from signing invalid input at priority 0.
        return $args;
    }
    if (relay_control_inbox_is_blocked($url, $identity['user_id'])) {
        $args['_relay_control_signature_error'] = new WP_Error('relay_control_blocked', 'Blocked destination.');
        unset($args['private_key']);
        return $args;
    }
    $args['body'] = $identity['json'];
    $args['key_id'] = $identity['key_id'];
    $args['private_key'] = $identity['private_key'];
    $args['user_id'] = $identity['user_id'];
    $args['_relay_control_public_key'] = $identity['public_key'];
    $args['_relay_control_actor'] = $identity['actor'];
    $args['redirection'] = 0; // A signature bound to one path/host cannot follow a redirect.
    return $args;
}
add_filter('http_request_args', 'relay_control_signature_prepare', -1000, 2);

function relay_control_signature_finalize($args, $url) {
    if (empty($args['_relay_control_public_key']) || !empty($args['_relay_control_signature_error'])) { return $args; }
    // Sign final bytes, after ordinary HTTP filters. Use the installed AP signer.
    try {
        foreach (array_keys((array) $args['headers']) as $h) {
            if (in_array(strtolower($h), array('signature', 'signature-input', 'content-digest', 'digest', 'host', 'date'), true)) {
                unset($args['headers'][$h]);
            }
        }
        $args['headers']['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        $args = (new \Activitypub\Signature\Http_Signature_Draft())->sign($args, $url);
        $host = wp_parse_url($url, PHP_URL_HOST);
        $port = wp_parse_url($url, PHP_URL_PORT);
        $default = wp_parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        $args['headers']['Host'] = $host . ($port && $port !== $default ? ':' . $port : '');
        // AP 9.3.1's draft signer omits non-default ports from its host component.
        if ($port && $port !== $default) {
            $signed = relay_control_signature_string($args, $url);
            if (!openssl_sign($signed, $sig, $args['private_key'], OPENSSL_ALGO_SHA256)) { throw new RuntimeException('Signing failed'); }
            $args['headers']['Signature'] = preg_replace('/signature="[^"]*"/', 'signature="' . base64_encode($sig) . '"', $args['headers']['Signature']);
        }
        $args['_relay_control_signed_body'] = hash('sha256', $args['body']);
        $args['_relay_control_signed_url'] = $url;
    } catch (Throwable $e) {
        $args['_relay_control_signature_error'] = new WP_Error('relay_control_signature_deferred', 'Signing failed locally.');
    }
    return $args;
}
add_filter('http_request_args', 'relay_control_signature_finalize', PHP_INT_MAX, 2);

function relay_control_signature_string($args, $url) {
    $path = wp_parse_url($url, PHP_URL_PATH) ?: '/';
    $query = wp_parse_url($url, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') { $path .= '?' . $query; }
    $s = '(request-target): ' . strtolower($args['method']) . ' ' . $path
        . "\nhost: " . $args['headers']['Host'] . "\ndate: " . $args['headers']['Date']
        . "\ndigest: " . $args['headers']['Digest'];
    if (isset($args['headers']['Collection-Synchronization'])) { $s .= "\ncollection-synchronization: " . $args['headers']['Collection-Synchronization']; }
    return $s;
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($pre !== false) { return $pre; }
    if (!empty($args['_relay_control_signature_error'])) {
        $notice = 'relay_control_signature_notice_' . md5((string) ($args['key_id'] ?? ''));
        if (!get_transient($notice) && $args['_relay_control_signature_error']->get_error_code() === 'relay_control_signature_deferred') {
            set_transient($notice, 1, MINUTE_IN_SECONDS);
            relay_control_log('[RELAY CONTROL] Signature deferred key_id=' . (string) ($args['key_id'] ?? '') . ' reason=' . $args['_relay_control_signature_error']->get_error_message());
        }
        return $args['_relay_control_signature_error'];
    }
    if (empty($args['_relay_control_public_key'])) { return $pre; }
    $body = json_decode($args['body'], true);
    $signature = $args['headers']['Signature'] ?? '';
    preg_match('/(?:^|,)keyId="([^"]+)"/', $signature, $kid);
    preg_match('/(?:^|,)signature="([^"]+)"/', $signature, $sig);
    $expected_headers = '(request-target) host date digest' . (isset($args['headers']['Collection-Synchronization']) ? ' collection-synchronization' : '');
    if (($body['actor'] ?? '') !== $args['_relay_control_actor'] || ($kid[1] ?? '') !== $args['_relay_control_actor'] . '#main-key'
        || strpos($signature, 'headers="' . $expected_headers . '"') === false
        || ($args['_relay_control_signed_url'] ?? '') !== $url || ($args['_relay_control_signed_body'] ?? '') !== hash('sha256', $args['body'])
        || $args['headers']['Digest'] !== 'SHA-256=' . base64_encode(hash('sha256', $args['body'], true))
        || openssl_verify(relay_control_signature_string($args, $url), base64_decode($sig[1] ?? '', true) ?: '', $args['_relay_control_public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        return new WP_Error('relay_control_signature_deferred', 'Final request failed local signature verification.');
    }
    if (relay_control_inbox_is_blocked($url, (int) $args['user_id'])) { return new WP_Error('relay_control_blocked', 'Blocked destination.'); }
    return false;
}, 98, 3);

/** Durable, bounded one-time replay of RECORDED authentication failures. */
function relay_control_signature_recoverable($record) {
    return is_array($record) && ($record['state'] ?? '') === 'failed'
        && in_array((int) ($record['status'] ?? 0), array(400, 401, 403), true)
        && empty($record['signature_fix_1812']) && !empty($record['url']);
}

function relay_control_signature_recovery() {
    global $wpdb;
    $state = get_option('relay_control_signature_recovery_1812', array());
    if (!$state) {
        $ceiling = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
        if ($wpdb->last_error) { return; }
        $state = array('cursor' => 0, 'ceiling' => (int) $ceiling, 'since' => time() - WEEK_IN_SECONDS, 'jobs' => array());
    }
    // Already selected jobs survive a missing cron event or interrupted PHP worker.
    foreach ($state['jobs'] as $job => $data) {
        if (!wp_next_scheduled('relay_control_signature_retry_1812', array($job))) {
            wp_schedule_single_event(max(time() + 5, (int) ($data['next_at'] ?? 0)), 'relay_control_signature_retry_1812', array($job));
        }
    }
    if (count($state['jobs']) >= 3 || !empty($state['complete'])) { update_option('relay_control_signature_recovery_1812', $state, false); return; }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
         WHERE meta_id > %d AND meta_id <= %d AND meta_key LIKE %s
         AND meta_value LIKE %s AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
         ORDER BY meta_id ASC LIMIT 3",
        $state['cursor'], $state['ceiling'], $wpdb->esc_like('_relay_control_delivery_') . '%',
        '%"failed"%', '%400%', '%401%', '%403%'
    ));
    if ($wpdb->last_error) { return; }
    if (!$rows) { $state['complete'] = true; }
    foreach ((array) $rows as $row) {
        if (count($state['jobs']) >= 3) { break; }
        $state['cursor'] = (int) $row->meta_id;
        $record = maybe_unserialize($row->meta_value);
        $post = get_post((int) $row->post_id);
        if (!relay_control_signature_recoverable($record) || (int) ($record['updated_at'] ?? 0) < $state['since']
            || !$post || $post->post_type !== 'ap_outbox' || get_post_meta($post->ID, '_relay_control_delivery_terminal', true)) { continue; }
        $body = json_decode($post->post_content, true);
        if (!is_array($body) || !in_array($body['type'] ?? '', array('Create', 'Update', 'Announce'), true)
            || !relay_control_json_is_public($body) || relay_control_outbox_obsolete_reason($post)) { continue; }
        $job = md5($post->ID . ':' . $row->meta_key);
        $state['jobs'][$job] = array('id' => (int) $post->ID, 'url' => $record['url'], 'meta_key' => $row->meta_key,
            'deadline' => time() + 2 * DAY_IN_SECONDS, 'next_at' => time() + 5);
    }
    update_option('relay_control_signature_recovery_1812', $state, false);
    foreach ($state['jobs'] as $job => $data) {
        if (!wp_next_scheduled('relay_control_signature_retry_1812', array($job))) {
            wp_schedule_single_event(max(time() + 5, (int) $data['next_at']), 'relay_control_signature_retry_1812', array($job));
        }
    }
}

function relay_control_signature_retry_job($job) {
    // Same lock as the selection pump: never lose another job's state update.
    $lock = relay_control_option_lock_acquire('recovery_pump', 55);
    if (!$lock) { return; }
    $native = relay_control_option_lock_acquire('native_outbox_worker', 180);
    if (!$native) { relay_control_option_lock_release($lock); return; }
    try {
        $state = get_option('relay_control_signature_recovery_1812', array());
        $data = $state['jobs'][$job] ?? null;
        if (!$data || (int) $data['next_at'] > time()) { return; }
        $post = get_post((int) $data['id']);
        $record = get_post_meta($data['id'], $data['meta_key'], true);
        $done = !$post || get_post_meta($data['id'], '_relay_control_delivery_terminal', true)
            || ($record['state'] ?? '') === 'accepted' || time() >= (int) $data['deadline']
            || (!empty($record['signature_fix_1812']) && (int) ($record['attempts'] ?? 0) >= 3);
        if (!$done && relay_control_outbox_obsolete_reason($post)) { $done = true; }
        if (!$done) {
            $identity = relay_control_signature_identity($post->post_content, (int) $post->post_author);
            if (is_wp_error($identity)) {
                $result = $identity;
                relay_control_log('[RELAY CONTROL] Signature recovery deferred outbox_id=' . $data['id'] . ' reason=' . $identity->get_error_message());
            }
            elseif (relay_control_inbox_is_blocked($data['url'], $identity['user_id'])) { $done = true; }
            else {
                $GLOBALS['relay_control_signature_recovery_active'] = true;
                try { $result = \Activitypub\safe_remote_post($data['url'], $identity['json'], $identity['user_id']); }
                catch (Throwable $e) { $result = new WP_Error('relay_control_signature_deferred', 'Signature recovery interrupted.'); }
                finally { unset($GLOBALS['relay_control_signature_recovery_active']); }
                $record = get_post_meta($data['id'], $data['meta_key'], true);
                $details = relay_control_response_details($result);
                relay_control_log_delivery_receipt('POST', $data['url'], $details['status'],
                    'source=signature_recovery_1812 outbox_id=' . $data['id'], $details['error'], $details['body']);
                $done = ($record['state'] ?? '') === 'accepted' || (int) ($record['attempts'] ?? 0) >= 3
                    || (is_wp_error($result) && in_array($result->get_error_code(), array('relay_control_blocked', 'relay_control_retired', 'relay_control_already_delivered'), true));
            }
        }
        if ($done) { unset($state['jobs'][$job]); }
        else { $state['jobs'][$job]['next_at'] = time() + 10 * MINUTE_IN_SECONDS; }
        update_option('relay_control_signature_recovery_1812', $state, false);
    } finally {
        relay_control_option_lock_release($native);
        relay_control_option_lock_release($lock);
    }
}
add_action('relay_control_signature_retry_1812', 'relay_control_signature_retry_job');
// =============================================================================
// Retain the installed bridge's draft-signature compatibility setting.
// A remote rejection alone cannot establish a signature-format problem.
// The 0.9.22 correction addresses the independently reproduced ID mismatch.
add_action('init', function () {
    if ('0' !== get_option('activitypub_rfc9421_signature')) {
        update_option('activitypub_rfc9421_signature', '0', false);
    }
}, 1);

/** Only configured relay actors are infrastructure subscriptions. */
function relay_control_is_relay_target($uri) {
    if (is_array($uri)) { $uri = $uri['id'] ?? ''; }
    if (!is_string($uri) || $uri === '') { return false; }
    $uri = untrailingslashit($uri);
    return relay_control_relay_actor_for_uri($uri) !== ''
        || in_array($uri, relay_control_get_relays(), true);
}

function relay_control_secondary_relay_follow($data, $user_id = null, $destination = '') {
    if (!is_array($data) || ($data['type'] ?? '') !== 'Follow') { return false; }
    $secondary_uid = relay_control_secondary_user_id();
    if ($secondary_uid === null) { return false; }
    // Mastodon-style relay Follows can use Public as object; their recipient or
    // actual HTTP destination identifies the relay instead of the object field.
    $targets = array_merge(array($data['object'] ?? '', $destination), (array) ($data['to'] ?? array()), (array) ($data['cc'] ?? array()));
    $is_relay = false;
    foreach ($targets as $target) {
        if (relay_control_is_relay_target($target)) { $is_relay = true; break; }
    }
    if (!$is_relay) { return false; }
    $actor = $data['actor'] ?? '';
    if (is_array($actor)) { $actor = $actor['id'] ?? ''; }
    $aliases = array_filter(array(
        function_exists('relay_control_secondary_actor_url') ? relay_control_secondary_actor_url() : '',
        home_url('/?author=' . $secondary_uid),
        rest_url('activitypub/1.0/actors/' . $secondary_uid),
    ));
    return (int) $user_id === $secondary_uid || (is_string($actor) && in_array(untrailingslashit($actor), $aliases, true));
}

// Native Following adds pending metadata before creating the outbox item. Block
// both steps, including late Accepts for the now-obsolete secondary-account subscription.
function relay_control_guard_secondary_follow_meta($check, $post_id, $key, $value) {
    $secondary_uid = relay_control_secondary_user_id();
    if ($check !== null || $secondary_uid === null || !class_exists('Activitypub\\Collection\\Following')
        || !in_array($key, array(\Activitypub\Collection\Following::PENDING_META_KEY, \Activitypub\Collection\Following::FOLLOWING_META_KEY), true)
        || !is_scalar($value) || (string) $value !== (string) $secondary_uid) { return $check; }
    $post = get_post($post_id);
    return $post && relay_control_is_relay_target($post->guid) ? false : $check;
}
add_filter('add_post_metadata', 'relay_control_guard_secondary_follow_meta', 10, 4);
add_filter('update_post_metadata', 'relay_control_guard_secondary_follow_meta', 10, 4);

add_filter('wp_insert_post_empty_content', function ($empty, $postarr) {
    if ($empty || !empty($postarr['ID']) || ($postarr['post_type'] ?? '') !== 'ap_outbox') { return $empty; }
    $data = json_decode(wp_unslash((string) ($postarr['post_content'] ?? '')), true);
    return relay_control_secondary_relay_follow($data, $postarr['post_author'] ?? null) ? true : $empty;
}, 10, 2);

// Cover old queued activities and alternate callers as well as new native saves.
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($pre !== false || strtoupper((string) ($args['method'] ?? '')) !== 'POST' || !is_string($args['body'] ?? null)) { return $pre; }
    if (relay_control_secondary_relay_follow(json_decode($args['body'], true), $args['user_id'] ?? null, $url)) {
        return new WP_Error('relay_control_site_relay_subscription_only', 'Secondary-account relay Follow cancelled: relay subscriptions are site-wide.');
    }
    return $pre;
}, -100, 3);

/** Bounded local migration, retried by the existing automatic recovery pump. */
function relay_control_cleanup_secondary_subscriptions() {
    $secondary_uid = relay_control_secondary_user_id();
    if ($secondary_uid === null) { return; }
    if (!class_exists('Activitypub\\Collection\\Following') || !class_exists('Activitypub\\Collection\\Remote_Actors')) { return; }
    $relays = relay_control_get_relays();
    if (!$relays) { return; }
    $cursor = (int) get_option('relay_control_site_relay_cleanup_cursor', 0) % count($relays);
    // One relay per pass; no network discovery and no new cron/loopback worker.
    $relay = $relays[$cursor];
    update_option('relay_control_site_relay_cleanup_cursor', ($cursor + 1) % count($relays), false);
    $actor_uri = relay_control_actor_from_inbox($relay);
    $remote = \Activitypub\Collection\Remote_Actors::get_by_uri($actor_uri);
    if ($remote instanceof WP_Post) {
        delete_post_meta($remote->ID, \Activitypub\Collection\Following::PENDING_META_KEY, (string) $secondary_uid);
        delete_post_meta($remote->ID, \Activitypub\Collection\Following::FOLLOWING_META_KEY, (string) $secondary_uid);
    }
    $ids = get_posts(array(
        'post_type' => 'ap_outbox', 'post_status' => array('pending'), 'author' => $secondary_uid,
        'fields' => 'ids', 'posts_per_page' => 20, 'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => array(
            array('key' => '_activitypub_activity_type', 'value' => 'Follow'),
            array('key' => '_activitypub_object_id', 'value' => $actor_uri),
        ),
    ));
    foreach ($ids as $id) {
        $post = get_post($id);
        if ($post && relay_control_secondary_relay_follow(json_decode($post->post_content, true), $post->post_author)) {
            relay_control_retire_outbox($id, 'cancelled_secondary_relay_follow_site_subscription');
        }
    }
    // Do not send Undo(Follow): some relays unsubscribe an entire domain, which
    // could tear down the retained site subscription. Old remote state is not
    // asserted to be deleted, transferred, or accepted for another actor.
}