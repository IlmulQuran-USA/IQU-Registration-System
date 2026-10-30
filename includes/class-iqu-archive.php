<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Archive
 *
 * 3.7.0 Part AK. Records of anyone who was ever billed are never deleted (accounting and Zakat records):
 *
 *   Archive   — only when the student has no active billing (billing ended, never billed, or the scholarship ended).
 *               An archived student leaves every list, count, birthday list and export of active students (the
 *               shared active-student rule and IQU_Database's list queries); nothing in the row is changed or erased.
 *               Reports and the discount ledger keep counting their past months. "Restore" undoes it.
 *   Delete    — only a student who was never on a billing record in live mode (or only in test mode: its test billing
 *               members, and accounts no other student uses, go with it), and who has no discount-ledger (Zakat /
 *               Special) rows. Confirmed by typing the student's name.
 */
final class IQU_Archive
{
    public const MSG_BILLING  = 'Stop billing first.';
    public const MSG_DELETE   = 'This student has billing history. Archive instead.';
    public const MSG_LEDGER   = 'This student has Zakat / discount records. Archive instead.';

    /** '' when the student may be archived now, otherwise why not. */
    public static function archive_blocker(array $reg): string
    {
        if (!IQU_Database::archive_ready()) return 'Archiving is not available until the database update has finished.';
        if ((string) ($reg['archived_at'] ?? '') !== '') return 'Already archived.';
        if (class_exists('IQU_Billing_DB')) {
            $acc = IQU_Billing_DB::account_for_registration((int) $reg['id']);
            // a billing record that has not ended (also "ending": billing runs until its date)
            if ($acc && (string) $acc['status'] !== 'canceled' && !self::member_ended($reg)) return self::MSG_BILLING;
        }
        if (class_exists('IQU_Billing_Pricing') && (string) ($reg['form_type'] ?? '') === IQU_Database::FORM_FREE && IQU_Database::is_active_student($reg)
            && !self::member_ended($reg) && IQU_Billing_Pricing::for_registration($reg)['scholarship']) {
            return self::MSG_BILLING; // a running full scholarship: end it first
        }
        return '';
    }

    /** True when this student's own billing has ended (billing_ended_at in the past). */
    private static function member_ended(array $reg): bool
    {
        $ended = (string) ($reg['billing_ended_at'] ?? '');
        return $ended !== '' && strcmp($ended, current_time('mysql', true)) <= 0;
    }

    public static function archive(int $id, int $user): array
    {
        $reg = IQU_Database::get_registration($id);
        if (!$reg) return ['ok' => false, 'message' => 'Student not found.'];
        $why = self::archive_blocker($reg);
        if ($why !== '') return ['ok' => false, 'message' => $why];
        if (!IQU_Database::set_archived($id, $user)) return ['ok' => false, 'message' => 'The student could not be archived.'];
        self::log('archive', $reg);
        return ['ok' => true, 'message' => 'Archived. The student no longer appears in the lists and counts; nothing was deleted.'];
    }

    public static function restore(int $id): array
    {
        $reg = IQU_Database::get_registration($id);
        if (!$reg || (string) ($reg['archived_at'] ?? '') === '') return ['ok' => false, 'message' => 'This student is not archived.'];
        if (!IQU_Database::set_archived($id, null)) return ['ok' => false, 'message' => 'The student could not be restored.'];
        self::log('restore', $reg);
        return ['ok' => true, 'message' => 'Restored. The student is back in the lists.'];
    }

    private static function log(string $what, array $reg): void
    {
        if (class_exists('IQU_Billing_DB')) IQU_Billing_DB::log_action(['action' => $what, 'registration_id' => (int) $reg['id'], 'reason' => trim($reg['first_name'] . ' ' . $reg['last_name'])]);
        error_log('[IQU] Registration #' . (int) $reg['id'] . ' ' . ($what === 'archive' ? 'archived' : 'restored') . ' by user #' . get_current_user_id() . '.');
    }

    /** '' when the student may be deleted, otherwise why not. */
    public static function delete_blocker(int $id): string
    {
        global $wpdb;
        if (class_exists('IQU_Billing_DB') && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IQU_Billing_DB::members_table() . " WHERE registration_id = %d AND mode = 'live'", $id)) > 0) {
            return self::MSG_DELETE;
        }
        if (class_exists('IQU_Discount_Ledger') && IQU_Discount_Ledger::ready() && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IQU_Discount_Ledger::table() . ' WHERE registration_id = %d', $id)) > 0) {
            return self::MSG_LEDGER;
        }
        return '';
    }

    /** The name as it must be typed to confirm a delete ("First Last", spaces and case ignored). */
    public static function name_matches(array $reg, string $typed): bool
    {
        $norm = fn(string $s) => strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        $want = $norm($reg['first_name'] . ' ' . $reg['last_name']);
        return $want !== '' && $norm($typed) === $want;
    }

    /** Why the last delete() did not delete ('' after a delete, or when delete_blocker() refused). */
    private static string $error = '';

    public static function last_error(): string
    {
        return self::$error;
    }

    /**
     * Delete a student allowed by delete_blocker(): its test billing members, then accounts nobody else uses, then the row.
     * 3.7.2: before any row goes, what those test accounts still run in Stripe is closed — the test subscription cancelled
     * now, an open Checkout page expired — so nothing keeps running in Stripe with no record left here. If Stripe refuses,
     * nothing is deleted and last_error() says why.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        self::$error = '';
        if (self::delete_blocker($id) !== '') return false;
        if (class_exists('IQU_Billing_DB')) {
            $m = IQU_Billing_DB::members_table();
            $accounts = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT account_id FROM {$m} WHERE registration_id = %d AND mode = 'test'", $id)) ?: []);
            // the accounts this delete removes: no member left once this student's test members are gone
            $gone = [];
            foreach (array_unique($accounts) as $aid) {
                if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$m} WHERE account_id = %d AND NOT (registration_id = %d AND mode = 'test')", $aid, $id)) === 0) $gone[] = $aid;
            }
            foreach ($gone as $aid) {
                $acc = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IQU_Billing_DB::accounts_table() . " WHERE id = %d AND mode = 'test'", $aid), ARRAY_A);
                $err = $acc ? self::close_test_stripe($acc, $id) : '';
                if ($err !== '') {
                    self::$error = $err;
                    return false;
                }
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$m} WHERE registration_id = %d AND mode = 'test'", $id));
            foreach ($gone as $aid) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . IQU_Billing_DB::accounts_table() . " WHERE id = %d AND mode = 'test'", $aid));
            }
        }
        return (bool) $wpdb->delete($wpdb->prefix . IQU_TABLE_NAME, ['id' => $id], ['%d']);
    }

    /**
     * 3.7.2: a test account about to be deleted — cancel its Stripe test subscription immediately (allowed only here:
     * this path is test mode only) and expire its open Checkout page (and cancel the subscription a page completed
     * before its webhook linked it). '' when done, nothing to do, or already canceled / gone; otherwise the error.
     * Needs the test key: with the live key a test object is "not found", which must not pass for "already gone".
     */
    private static function close_test_stripe(array $acc, int $reg_id): string
    {
        $sub = (string) ($acc['stripe_subscription_id'] ?? '');
        $cs  = (string) ($acc['checkout_session_id'] ?? '');
        $sub = preg_match('/^sub_[A-Za-z0-9_]+$/', $sub) ? $sub : '';
        $cs  = preg_match('/^cs_[A-Za-z0-9_]+$/', $cs) ? $cs : '';
        if ($sub === '' && $cs === '') return '';
        $acct = 'billing account #' . (int) $acc['id'];
        if (!class_exists('IQU_Stripe') || IQU_Stripe::key_mode() !== 'test' || !IQU_Stripe::is_ready()) {
            return 'Not deleted: ' . $acct . ' still has a Stripe test ' . ($sub !== '' ? 'subscription' : 'Checkout page')
                . ', which can only be cancelled with the Stripe test key. Switch Billing to test mode and try again.';
        }
        $subs = $sub !== '' ? [$sub] : [];
        if ($cs !== '') {
            $r = IQU_Stripe::post('/checkout/sessions/' . $cs . '/expire', []);
            if (!$r['ok'] && !self::stripe_gone($r)) {
                // Stripe refuses to expire a page that is not open (expired / complete): only an open one is a problem
                $g = IQU_Stripe::get('/checkout/sessions/' . $cs);
                if (!$g['ok'] && !self::stripe_gone($g)) return 'Not deleted: Stripe could not expire the test Checkout page of ' . $acct . ' (' . $r['error'] . ').';
                if ($g['ok'] && ($g['data']['status'] ?? '') === 'open') return 'Not deleted: Stripe could not expire the test Checkout page of ' . $acct . ' (' . $r['error'] . ').';
                $made = $g['ok'] ? ($g['data']['subscription'] ?? '') : '';
                $made = is_array($made) ? (string) ($made['id'] ?? '') : (string) $made;
                if (preg_match('/^sub_[A-Za-z0-9_]+$/', $made) && !in_array($made, $subs, true)) $subs[] = $made;
            }
        }
        foreach ($subs as $s) {
            $r = IQU_Stripe::delete('/subscriptions/' . $s);
            if (!$r['ok'] && !self::stripe_gone($r)) {
                $g = IQU_Stripe::get('/subscriptions/' . $s);
                $done = self::stripe_gone($g) || ($g['ok'] && in_array((string) ($g['data']['status'] ?? ''), ['canceled', 'incomplete_expired'], true));
                if (!$done) return 'Not deleted: Stripe could not cancel the test subscription ' . $s . ' of ' . $acct . ' (' . $r['error'] . ').';
            }
            IQU_Billing_DB::log_action(['account_id' => (int) $acc['id'], 'registration_id' => $reg_id, 'action' => 'test_delete_cancel', 'amount' => 0,
                'reason' => 'Test data deleted: subscription cancelled now', 'stripe_ref' => $s, 'user_id' => get_current_user_id()]);
        }
        return '';
    }

    /** Stripe answered "no such object" (deleted, or never existed in this mode). */
    private static function stripe_gone(array $r): bool
    {
        return !$r['ok'] && ((int) $r['status'] === 404 || $r['code'] === 'resource_missing');
    }
}
