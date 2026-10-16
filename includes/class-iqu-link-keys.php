<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Link_Keys
 *
 * One place for the keys that sign the families' private links:
 *   - the monthly billing page  /my-billing/?t=…        (IQU_Billing_Service)
 *   - the Summer payment link   /?iqu_pay=summer&r=&s=  (IQU_Summer_Pay)
 *
 * Key source:
 *   IQU_LINK_SECRET (wp-config.php)  key = HMAC-SHA256(purpose, IQU_LINK_SECRET)
 *   not set                          key = SHA-256(wp_salt('secure_auth') . '|' . purpose),
 *                                    exactly the key used before, so nothing changes until the
 *                                    constant is added. With the constant, rotating the WordPress
 *                                    salts no longer breaks family links.
 *   IQU_LINK_SECRET_PREVIOUS         links made with that earlier secret are still accepted
 *                                    (verification only; new links always use IQU_LINK_SECRET).
 *
 * Switch-over: when the active key changes (salts → secret, or a new secret), every billing
 * account's stored token_hash is recomputed once (the old hash is kept in token_hash_prev, for
 * IQU_LINK_SECRET_PREVIOUS). Only a fingerprint of the key is stored (option iqu_link_keys),
 * never the key or the secret. The Summer link stores no hash, so it needs no recompute.
 */
final class IQU_Link_Keys
{
    public const PURPOSE_BILLING = 'iqu-billing-link|v1';
    public const PURPOSE_SUMMER  = 'iqu-summer-pay|v1';

    /** ['fp', 'source', 'since', 'renewed_at', 'renewed', 'notice'] — no key material. */
    public const OPT_STATE = 'iqu_link_keys';
    private const OPT_LOCK = 'iqu_link_keys_lock';
    private const LOCK_TTL = 600;
    /** A secret shorter than this still works, but Settings asks for a longer one. */
    public const MIN_SECRET_LENGTH = 32;

    // ------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------

    private static function secret(): string
    {
        return defined('IQU_LINK_SECRET') && is_string(IQU_LINK_SECRET) ? trim(IQU_LINK_SECRET) : '';
    }

    /** The previous secret, when set and different from the current one (and a current one is set). */
    private static function previous_secret(): string
    {
        $prev = defined('IQU_LINK_SECRET_PREVIOUS') && is_string(IQU_LINK_SECRET_PREVIOUS) ? trim(IQU_LINK_SECRET_PREVIOUS) : '';
        $cur  = self::secret();
        return ($prev !== '' && $cur !== '' && !hash_equals($cur, $prev)) ? $prev : '';
    }

    public static function secret_set(): bool
    {
        return self::secret() !== '';
    }

    public static function secret_is_short(): bool
    {
        return self::secret_set() && strlen(self::secret()) < self::MIN_SECRET_LENGTH;
    }

    public static function previous_set(): bool
    {
        return self::previous_secret() !== '';
    }

    /** 'secret' (IQU_LINK_SECRET) or 'salt' (WordPress salts, the fallback). */
    public static function source(): string
    {
        return self::secret_set() ? 'secret' : 'salt';
    }

    /** The key for new links of one purpose (raw 32 bytes). */
    public static function key(string $purpose): string
    {
        $secret = self::secret();
        return $secret !== '' ? hash_hmac('sha256', $purpose, $secret, true) : self::salt_key($purpose);
    }

    /** Key from the previous secret, for checking links sent before a rotation. Null when not set. */
    public static function previous_key(string $purpose): ?string
    {
        $prev = self::previous_secret();
        return $prev !== '' ? hash_hmac('sha256', $purpose, $prev, true) : null;
    }

    /** Every key a link may be checked with: the current one first, then the previous secret's. */
    public static function verify_keys(string $purpose): array
    {
        $keys = [self::key($purpose)];
        $prev = self::previous_key($purpose);
        if ($prev !== null) $keys[] = $prev;
        return $keys;
    }

    /** The key used before IQU_LINK_SECRET (unchanged bytes). */
    private static function salt_key(string $purpose): string
    {
        return hash('sha256', wp_salt('secure_auth') . '|' . $purpose, true);
    }

    /** Identifies the active key without revealing it (one-way, from a purpose of its own). */
    public static function fingerprint(): string
    {
        return substr(hash_hmac('sha256', 'iqu-link-key-fingerprint', self::key('iqu-link-fingerprint|v1')), 0, 24);
    }

    // ------------------------------------------------------------
    // Switch-over
    // ------------------------------------------------------------

    public static function state(): array
    {
        $s = get_option(self::OPT_STATE, []);
        return is_array($s) ? $s : [];
    }

    /**
     * Runs on every request (init, after the billing tables are installed); does work only when
     * the active key differs from the recorded fingerprint. Then every billing account's link
     * hash is recomputed exactly once (an option lock keeps two requests from doing it twice).
     */
    public static function maybe_switch_over(): void
    {
        $fp    = self::fingerprint();
        $state = self::state();
        if (isset($state['fp']) && hash_equals((string) $state['fp'], $fp)) return;
        if (!class_exists('IQU_Billing_DB') || !class_exists('IQU_Billing_Service')) return;
        if (!IQU_Billing_DB::is_installed()) return; // tables not ready yet; next request

        // First run of this version without the constant: the stored hashes were made with this
        // very key (the salts), so only the fingerprint is recorded. Nothing is renewed.
        if (!$state && !self::secret_set()) {
            update_option(self::OPT_STATE, ['fp' => $fp, 'source' => 'salt', 'since' => time(), 'renewed_at' => 0, 'renewed' => 0, 'notice' => 0], false);
            return;
        }

        if (!add_option(self::OPT_LOCK, (string) time(), '', 'no')) {
            // Another request is renewing. A lock left by a request that died is cleared after 10 minutes.
            if (time() - (int) get_option(self::OPT_LOCK, 0) > self::LOCK_TTL) delete_option(self::OPT_LOCK);
            return;
        }
        try {
            // Re-read under the lock: a request that just finished may have recorded this key already.
            if (function_exists('wp_cache_delete')) wp_cache_delete(self::OPT_STATE, 'options');
            $now = self::state();
            if (isset($now['fp']) && hash_equals((string) $now['fp'], $fp)) return;

            $renewed = IQU_Billing_Service::renew_all_links();
            if ($renewed < 0) return; // a write failed; the fingerprint stays old so the next request tries again
            update_option(self::OPT_STATE, [
                'fp'         => $fp,
                'source'     => self::source(),
                'since'      => time(),
                'renewed_at' => time(),
                'renewed'    => $renewed,
                'notice'     => $renewed > 0 ? 1 : 0,
            ], false);
            error_log('IQU links: link key changed (' . self::source() . '); ' . $renewed . ' family billing link(s) renewed.');
        } finally {
            delete_option(self::OPT_LOCK);
        }
    }

    // ------------------------------------------------------------
    // Notices
    // ------------------------------------------------------------

    /** "Links were renewed" notice for the Billing screens, until dismissed. Echoes HTML. */
    public static function render_renewed_notice(): void
    {
        $s = self::state();
        if (empty($s['notice']) || empty($s['renewed_at'])) return;
        $when = wp_date('j F Y, g:i a', (int) $s['renewed_at']);
        ?>
        <div class="notice notice-warning iqu-link-renewed" role="status">
            <p><strong>Family links were renewed.</strong> Links sent before <?php echo esc_html($when); ?> no longer work; resend from Student Billing.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="iqu_link_notice_dismiss">
                <?php wp_nonce_field('iqu_link_notice_dismiss'); ?>
                <p><button type="submit" class="button button-small">Dismiss</button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_dismiss(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to do this.', 403);
        check_admin_referer('iqu_link_notice_dismiss');
        $s = self::state();
        if ($s) {
            $s['notice'] = 0;
            update_option(self::OPT_STATE, $s, false);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=iqu-billing'));
        exit;
    }
}
