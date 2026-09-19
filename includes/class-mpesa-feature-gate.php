<?php
/**
 * Marupurupu_Feature_Gate — the single place that decides whether a given
 * "premium" feature is available on this install.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS (read this before changing anything in here)
 * ============================================================================
 *
 * As of the version that introduced this file, every feature in the plugin
 * — including Reports — is free for everyone. Nothing about that changes
 * today. This class is groundwork for a *future* decision: once the plugin
 * has real user traction, some features (Reports was the one explicitly
 * named when this was planned) may become a paid/Pro tier.
 *
 * Two different ways that could be implemented were considered:
 *
 *   1. Code-level gating inside THIS SAME plugin (a license-key check that
 *      unlocks a feature already present in the free, WordPress.org-hosted
 *      zip). Fully WordPress.org-compliant as long as the gated code ships
 *      in the same package rather than being fetched from an external
 *      server at runtime — but since the whole zip is GPL and unobfuscated,
 *      anyone can read the gate and legally patch it out. It's a soft,
 *      friction-based gate, not real protection — normal for this
 *      ecosystem, but worth being clear-eyed about.
 *   2. Moving the premium feature's code out entirely into a SEPARATE Pro
 *      plugin, sold and distributed outside WordPress.org, that hooks into
 *      this one. Meaningfully stronger protection, because non-paying users
 *      never receive the Pro code at all — but real additional overhead
 *      (a second codebase, a license server, a custom update mechanism).
 *
 * This class doesn't commit to either path. Its whole job is to be the ONE
 * decision point so that whichever path gets chosen later, only this file
 * (or a filter added from a future Pro plugin) needs to change — nothing
 * else in the codebase should ever check `current_user_can(...)`-style
 * premium logic directly. Call `Marupurupu_Feature_Gate::reports_enabled()`,
 * never re-implement the check.
 *
 * ============================================================================
 * THE EXTENSION CONTRACT
 * ============================================================================
 *
 * `reports_enabled()` runs its result through the `marupurupu_reports_enabled`
 * filter before returning it. That filter is the seam a future Pro plugin
 * would hook:
 *
 *   add_filter('marupurupu_reports_enabled', function ($enabled) {
 *       return Marupurupu_Pro_License::is_valid() || $enabled;
 *   });
 *
 * Today the filter's default is hardcoded to `true` — every install gets
 * Reports, no exceptions, matching current behavior exactly. When gating
 * actually begins, that hardcoded `true` is what changes (e.g. to
 * `self::is_legacy_grandfathered()`), not the shape of this method or its
 * callers.
 *
 * ============================================================================
 * THE GRANDFATHER FLAG
 * ============================================================================
 *
 * `marupurupu_legacy_full_access` (a plain WordPress option, boolean) is set
 * once, automatically, the first time this version's code runs on a site —
 * see `marupurupu_check_legacy_grandfather()` in the main plugin file for
 * where it's actually determined and written. It records whether THIS site
 * already had the plugin configured before any Pro-tier gating existed in
 * the code, so that whenever gating does begin, sites that were already
 * using (and had come to depend on) a feature don't suddenly lose it. GPL
 * doesn't let you take back a version someone already has — but it also
 * doesn't stop someone from updating in place, which is the case this flag
 * protects: an existing install updating straight through a future
 * gated version should keep working exactly as it always has.
 *
 * This class doesn't currently *use* the flag for anything — reports_enabled()
 * ignores it, because nothing is gated yet. It's captured now, early, on
 * purpose: reconstructing "was this genuinely a pre-existing install" after
 * the fact, once gating actually ships, is much harder than having recorded
 * it in real time. See `is_legacy_grandfathered()` below, ready for a future
 * version to actually consult.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marupurupu_Feature_Gate {

    /**
     * Whether the Reports admin page (class-mpesa-reports.php: charts,
     * revenue summaries, top-customer breakdowns) should load on this site.
     *
     * Always true today — see the file-level doc comment above for why.
     * Callers should treat this as the single source of truth and never
     * duplicate the underlying logic.
     */
    public static function reports_enabled() {
        return (bool) apply_filters('marupurupu_reports_enabled', true);
    }

    /**
     * Whether this site was already an existing install before any
     * Pro-tier gating existed in the code. Not consulted by anything yet
     * (see reports_enabled()) — exposed now so a future version has it
     * ready to use without needing to reconstruct install history after
     * the fact.
     */
    public static function is_legacy_grandfathered() {
        return get_option('marupurupu_legacy_full_access', false) === true;
    }
}
