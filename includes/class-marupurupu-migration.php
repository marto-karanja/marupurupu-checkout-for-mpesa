<?php
/**
 * One-time migration from this plugin's earlier "mpesa_*" storage names to the
 * marupurupu_* names.
 *
 * Why: everything the plugin registers globally (classes, functions,
 * constants, options, transients, hooks, the transactions table) is now
 * prefixed with a name unique to this plugin instead of the generic
 * "mpesa"/"mpesa_till". Sites that ran an earlier version have data stored
 * under the old names, and losing it would be visible: dismissed admin notices
 * would reappear, the transactions table (payment history) would appear empty.
 * So the first request after updating moves everything across, once.
 *
 * Not touched, on purpose (see the note at the top of the main plugin file):
 * the gateway id "mpesa_till" and the settings option derived from it, the
 * Safaricom callback endpoint, and the credential-encryption format strings.
 *
 * Also deliberately left in place: the option "mpesa_till_credentials_reset_2026_08_25".
 * It was the one-time latch of a credential-reset routine that no longer exists.
 * Harmless to keep -- but if this plugin were ever rolled back to a version that
 * still has that routine, a missing latch would make it clear the stored API
 * credentials. Leaving it set makes a rollback safe.
 *
 * Safe to run repeatedly: it exits at once when it has already succeeded, and
 * each step is idempotent. The "done" flag is only written when the
 * transactions-table step succeeded, so a failed rename is retried on the next
 * request instead of being forgotten.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Migration {

    /** Option that records a successful migration (holds the plugin version that did it). */
    const DONE_OPTION = 'marupurupu_data_migrated';

    /** Legacy transactions table name (without the site's table prefix). */
    const OLD_TABLE = 'mpesa_till_transactions';

    /** Current transactions table name (without the site's table prefix). */
    const NEW_TABLE = 'marupurupu_transactions';

    /**
     * Option names: legacy => current. Values are copied across, then the
     * legacy option is removed.
     *
     * @var array
     */
    private static $option_map = array(
        'mpesa_till_credentials_cleared_for_encryption_upgrade' => 'marupurupu_credentials_cleared_notice',
        'mpesa_till_encryption_checked'                         => 'marupurupu_encryption_checked',
        'mpesa_till_legacy_full_access'                         => 'marupurupu_legacy_full_access',
        'mpesa_till_legacy_grandfather_checked'                 => 'marupurupu_legacy_grandfather_checked',
        'mpesa_till_encryption_degraded'                        => 'marupurupu_encryption_degraded',
        'mpesa_till_decryption_key_mismatch'                    => 'marupurupu_decryption_key_mismatch',
        'mpesa_encryption_notice_dismissed'                     => 'marupurupu_encryption_notice_dismissed',
        'mpesa_weak_key_notice_dismissed'                       => 'marupurupu_weak_key_notice_dismissed',
        'mpesa_success_count'                                    => 'marupurupu_success_count',
        'mpesa_failure_count'                                    => 'marupurupu_failure_count',
        'mpesa_feature_usage'                                    => 'marupurupu_feature_usage',
    );

    /**
     * Legacy scheduled-event hook names. The current code schedules events
     * under marupurupu_* names; anything left under these names would keep
     * firing with no callback (or, for the log-cleanup event, with a callback
     * that no longer exists).
     *
     * @var array
     */
    private static $legacy_cron_hooks = array(
        'mpesa_weekly_heartbeat',
        'mpesa_daily_stats',
        'mpesa_clean_old_logs',
    );

    /**
     * Run the migration if it has not already succeeded.
     */
    public static function run() {
        if (get_option(self::DONE_OPTION)) {
            return;
        }

        $table_ok = self::migrate_table();

        self::migrate_options();
        self::clear_legacy_scheduled_events();

        // Legacy transient (dormant telemetry metrics); expires on its own,
        // but there is no reason to leave it behind.
        delete_transient('mpesa_performance_metrics');

        if ($table_ok) {
            update_option(self::DONE_OPTION, defined('MARUPURUPU_VERSION') ? MARUPURUPU_VERSION : '1', true);
        }
    }

    /**
     * Rename the transactions table, keeping every row.
     *
     * RENAME TABLE is atomic and does not copy data. Cases:
     *  - no legacy table: fresh install (or already migrated) -> nothing to do
     *  - legacy table, no new table: rename it
     *  - both exist, new one empty: it was just created by activation before
     *    this ran; drop the empty one, then rename the legacy one
     *  - both exist and both have rows: refuse to guess; leave both and report
     *    failure so the problem is visible and retried, never data-destroying
     *
     * @return bool True if the site is in a good state (nothing left to migrate).
     */
    private static function migrate_table() {
        global $wpdb;

        $old = $wpdb->prefix . self::OLD_TABLE;
        $new = $wpdb->prefix . self::NEW_TABLE;

        $old_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old))) === $old);

        if (!$old_exists) {
            return true;
        }

        $new_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new))) === $new);

        if ($new_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix and a constant.
            $new_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$new`");

            if ($new_rows > 0) {
                error_log('Marupurupu: both ' . $old . ' and ' . $new . ' contain data; not migrating automatically.');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping the empty table created by activation so the legacy one can take its name.
            $wpdb->query("DROP TABLE `$new`");
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time atomic rename of the plugin's own table.
        $renamed = $wpdb->query("RENAME TABLE `$old` TO `$new`");

        if (false === $renamed) {
            error_log('Marupurupu: could not rename ' . $old . ' to ' . $new . ': ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Copy each legacy option to its current name (never overwriting a value
     * that already exists under the new name), then delete the legacy one.
     */
    private static function migrate_options() {
        $unset = '__marupurupu_unset__';

        foreach (self::$option_map as $old => $new) {
            $value = get_option($old, $unset);

            if ($unset === $value) {
                continue;
            }

            if ($unset === get_option($new, $unset)) {
                add_option($new, $value);
            }

            delete_option($old);
        }
    }

    /**
     * Unschedule any events still registered under the legacy hook names.
     */
    private static function clear_legacy_scheduled_events() {
        foreach (self::$legacy_cron_hooks as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }
}
