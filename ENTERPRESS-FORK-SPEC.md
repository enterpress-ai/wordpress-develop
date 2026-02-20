# WordPress Fork — Implementation Spec

**Repo:** `enterpress-ai/wordpress-develop`
**Branch:** Create `feature/state-locality` from current HEAD
**Status:** 6 surgical changes to WordPress core (~490 lines total)

---

## Overview

Minimal, scalpel-like changes to WordPress core. Remove assumptions, expose hooks, get out of the way. No heavy rewrites of `wpdb` or core subsystems. The fork preserves the full WordPress plugin API — `get_option()`, `wp_cache_get()`, `file_exists()`, `wp_schedule_event()`, `$_SESSION` all work as expected from the plugin's perspective.

## Reference

- Architecture doc: `session-state-locality-fixes.md` §4 (Summary: Fork Changes)

---

## Change 1: Remove wp-cron Trigger (~50 lines)

**File:** `src/wp-includes/cron.php`

**What:** Delete the cron check from the page load path. WordPress currently checks if any scheduled task is due on every page load and fires it inline. Remove this entirely.

**How:**
- Comment out or remove the `spawn_cron()` call in `wp_cron()`
- Remove the `wp_cron()` call from `wp-settings.php` (or the function it's called from)
- Keep the function signatures intact for backward compatibility — they just become no-ops

**Why not just DISABLE_WP_CRON:** That constant disables the inline trigger but keeps the cron infrastructure. We're removing it entirely because `pg_cron` + task queue replaces it.

**Test:** Verify no cron tasks fire on page load. Verify `wp_next_scheduled()` still works (reads from task queue table).

---

## Change 2: Task Queue API Shim (~150 lines)

**File:** `src/wp-includes/cron.php` (modify existing functions)

**What:** Rewrite `wp_schedule_event()`, `wp_schedule_single_event()`, `wp_unschedule_event()`, `wp_clear_scheduled_hook()`, and `wp_next_scheduled()` to operate on a PostgreSQL task queue table instead of the `cron` option.

**How:**
```php
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
    global $wpdb;

    // Insert into wp_task_queue instead of updating the cron option
    $result = $wpdb->insert('wp_task_queue', array(
        'hook'       => $hook,
        'args'       => maybe_serialize($args),
        'schedule'   => $recurrence,
        'next_run'   => gmdate('Y-m-d H:i:s', $timestamp),
        'status'     => 'pending',
        'created_at' => current_time('mysql', true),
    ));

    if ($wp_error && false === $result) {
        return new WP_Error('could_not_schedule', 'Failed to schedule event.');
    }
    return $result !== false;
}
```

**Task Queue Table Schema** (created via `dbDelta()` during WordPress install/upgrade in `schema.php`):

> **Note:** The canonical schema uses MySQL/MariaDB syntax to match WordPress conventions and `dbDelta()` requirements. For PostgreSQL deployments (e.g. via Supabase), adapt types accordingly (`BIGSERIAL`, `TIMESTAMPTZ`, partial indexes).

```sql
CREATE TABLE {prefix}task_queue (
    id bigint(20) unsigned NOT NULL auto_increment,
    hook varchar(255) NOT NULL,
    args text,
    args_hash varchar(32) NOT NULL default '',   -- MD5 of serialized args for duplicate detection
    schedule varchar(255) default NULL,           -- 'hourly', 'daily', etc. NULL for single events
    interval_seconds int(10) unsigned default NULL, -- Recurrence interval in seconds
    next_run datetime NOT NULL default '0000-00-00 00:00:00',
    status varchar(20) NOT NULL default 'pending', -- 'pending', 'claimed', 'completed', 'failed'
    claimed_by varchar(255) default NULL,          -- Worker ID that claimed this task
    claimed_at datetime default NULL,
    completed_at datetime default NULL,
    attempts int(10) unsigned NOT NULL default '0',
    max_attempts int(10) unsigned NOT NULL default '3',
    created_at datetime NOT NULL default CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_task_queue_hook (hook),
    KEY idx_task_queue_next_run_status (next_run, status)
);
```

**Compatibility:** Plugins that call `wp_schedule_event()` or `wp_schedule_single_event()` work unchanged — the API is preserved, only the storage backend changes. Plugins that read the `cron` option directly (rare) will see it empty.

**Test:** Schedule an event via `wp_schedule_event()`, verify it appears in `wp_task_queue`. Verify `wp_next_scheduled()` reads from the table. Verify `wp_unschedule_event()` removes it.

---

## Change 3: Lazy Options Loading (~50 lines)

**File:** `src/wp-includes/option.php`

**What:** Replace the bulk `SELECT * FROM wp_options WHERE autoload = 'yes'` with lazy loading: options are fetched on first `get_option()` call and cached for the request lifetime.

**How:**
```php
// Replace wp_load_alloptions() implementation:
function wp_load_alloptions($force_db = false) {
    // Return empty array — options will be loaded lazily
    // The proxy learns access patterns and pre-fetches
    return array();
}

// Modify get_option() to lazy-load:
function get_option($option, $default_value = false) {
    // Check in-memory cache first
    $alloptions = wp_cache_get('alloptions', 'options');
    if (isset($alloptions[$option])) {
        return apply_filters("option_$option", maybe_unserialize($alloptions[$option]), $option);
    }

    // Fetch individually from DB
    $value = wp_cache_get($option, 'options');
    if (false === $value) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1",
            $option
        ));
        $value = ($row !== null) ? $row->option_value : $default_value;
        wp_cache_set($option, $value, 'options');
    }

    return apply_filters("option_$option", maybe_unserialize($value), $option);
}
```

**Risk:** Some plugins assume `wp_load_alloptions()` returns all autoloaded options. The proxy's pattern pre-fetching mitigates this — it learns which options each URL path needs and pre-fetches them in a single query before PHP asks. The safety net is that `get_option()` always falls back to a DB query.

**Test:** Verify `get_option('blogname')` works. Verify no bulk autoload query fires. Verify that frequently-accessed options are eventually served from cache.

---

## Change 4: Transient Backend Swap (~30 lines)

**File:** `src/wp-includes/option.php`

**What:** Redirect transient storage from `wp_options` to the KV store (same backend as sessions — Redis/DragonflyDB/PostgreSQL KV).

**How:**
```php
// Regular transients use cache group 'transient' (singular, matching WP core conventions).
// Site transients use cache group 'site-transient'.
// Keys are the raw transient name (no prefix), matching WordPress's existing
// external-object-cache code path.
function set_transient($transient, $value, $expiration = 0) {
    return wp_cache_set($transient, $value, 'transient', $expiration);
}

function get_transient($transient) {
    return wp_cache_get($transient, 'transient');
}

function delete_transient($transient) {
    return wp_cache_delete($transient, 'transient');
}
```

**Note:** This depends on the object cache (Redis) with native TTL support. The object cache drop-in must handle expiry — WordPress's built-in transient cleanup cron becomes unnecessary. All existing filter/action hooks (`pre_set_transient_{key}`, `set_transient`, `transient_{key}`, etc.) are preserved.

**Test:** `set_transient('test', 'value', 300)` → `get_transient('test')` returns `'value'`. After 300s, returns `false`. Verify nothing is written to `wp_options`.

---

## Change 5: Shutdown Hook (~10 lines)

**File:** `src/wp-includes/load.php` or `src/wp-settings.php`

**What:** Add a single `do_action('wp_instance_draining')` that fires when the instance receives SIGTERM.

**How:**
```php
// In shutdown handler or as a registered shutdown function:
function wp_handle_instance_drain() {
    if (defined('WP_INSTANCE_DRAINING') && WP_INSTANCE_DRAINING) {
        do_action('wp_instance_draining');
    }
}
register_shutdown_function('wp_handle_instance_drain');
```

The sidecar sets `WP_INSTANCE_DRAINING` to `true` via a flag file or HTTP request when it receives SIGTERM. WordPress reads this on the next request cycle.

**Test:** Set `WP_INSTANCE_DRAINING` → verify `wp_instance_draining` action fires. Verify normal requests don't fire it.

---

## Change 6: Heartbeat → Realtime (~200 lines)

**Files:**
- `src/wp-includes/js/heartbeat.js` → replace with Supabase Realtime WebSocket client
- `src/wp-admin/includes/heartbeat.php` → replace Heartbeat PHP endpoint with Realtime setup

**What:** Replace the Heartbeat API's poll-based mechanism with Supabase Realtime WebSocket push. Lock state, autosave, and dashboard updates are pushed via WebSocket instead of polled via AJAX.

**JavaScript side (~150 lines):**
```javascript
// Replace wp.heartbeat with:
wp.realtime = {
    channel: null,

    init: function() {
        const { createClient } = supabase;
        const client = createClient(wpRealtimeSettings.url, wpRealtimeSettings.anonKey);

        this.channel = client.channel('wp-admin')
            .on('postgres_changes', {
                event: '*',
                schema: 'public',
                table: 'wp_postmeta',
                filter: `meta_key=eq._edit_lock`
            }, (payload) => {
                // Handle lock state change
                $(document).trigger('heartbeat-tick.wp-edit-lock', payload);
            })
            .on('postgres_changes', {
                event: '*',
                schema: 'public',
                table: 'wp_posts',
                filter: `post_status=eq.auto-draft`
            }, (payload) => {
                // Handle autosave
                $(document).trigger('heartbeat-tick.wp-autosave', payload);
            })
            .subscribe();
    },

    // Backward-compatible API for plugins that hook into heartbeat
    enqueue: function(handle, data) {
        // Queue data for next sync (batched, not per-event)
    }
};
```

**PHP side (~50 lines):**
```php
// Enqueue Supabase Realtime JS client
function wp_enqueue_realtime_scripts() {
    if (is_admin()) {
        wp_enqueue_script('supabase-realtime', 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/dist/umd/supabase.min.js');
        wp_localize_script('supabase-realtime', 'wpRealtimeSettings', array(
            'url'      => SUPABASE_URL,
            'anonKey'  => SUPABASE_ANON_KEY,
        ));
    }
}
add_action('admin_enqueue_scripts', 'wp_enqueue_realtime_scripts');
```

**Backward compatibility:** The Heartbeat API (`wp.heartbeat`) remains available as a shim that maps to the Realtime channel. Plugins that hook into `heartbeat-tick` events still receive data — it's just pushed via WebSocket instead of polled via AJAX.

**Test:** Open post editor on two browser tabs. Edit on tab A → tab B receives lock notification in real-time. Verify no Heartbeat AJAX requests are made. Verify autosave pushes work.

---

## Migration

Create a database migration for the `wp_task_queue` table. This should run on first boot or via WP-CLI:

```bash
wp enterpress migrate --create-task-queue
```

---

## Branch Strategy

1. Create `feature/state-locality` from current HEAD
2. Each change gets its own commit (one commit per change above)
3. Tag as `v7.0.0-enterpress.1` after all changes
4. Maintain rebase-ability with upstream `WordPress/wordpress-develop`
