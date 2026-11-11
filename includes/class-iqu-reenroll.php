<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Reenroll
 *
 * 3.7.9: a public form submission that matches an existing record — same program (form_type), same student name
 * (case, spaces and punctuation ignored) and same email — updates that record instead of adding a second one. The
 * record keeps its ID and original created date; the re-enrollment date is stored (reenrolled_at). Different programs
 * (Summer and Enroll for Free, Summer Level 1 and Level 2, Weekend new and existing) and siblings (same email, another
 * name) stay separate records. An archived record is matched too and restored.
 *
 *   still active (Enroll for Free with its monthly billing running in Stripe; a Summer place already paid):
 *       only the family's details are updated (contact, guardian, date of birth, schedule answers); status, course,
 *       days, fee, payment and billing stay as they are — running billing is never disturbed.
 *   not active (Left, cancelled, never billed, …): the record is updated with the whole submission, Left and the
 *       billing end date are cleared so the student returns to billing; Enroll for Free: an existing student (no second
 *       free month), Pending review, and taken off an old billing account whose billing has ended for them, so a new
 *       payment link can be sent (logged as "detached" with the old account).
 * Either way the team is told on Telegram.
 */
final class IQU_Reenroll
{
    /** Updated on an active record: the family's details only. */
    private const DETAIL_COLUMNS = [
        'whatsapp', 'guardian_name', 'guardian_contact', 'guardian_whatsapp', 'date_of_birth', 'age', 'country_origin', 'country_res',
        'preferred_days', 'time_slot', 'session_dur', 'languages', 'teacher_pref', 'device', 'memorized', 'quran_level',
        'referral', 'referral_other', 'wa_updates', 'whatsapp_group', 'future_updates', 'marketing_consent',
    ];
    /** Never taken from a submission when updating. */
    private const KEEP = ['id', 'created_at', 'created_via'];

    /** "Ali  Khan-Rahman" and "ali khan rahman" → "alikhanrahman". */
    public static function norm_name(string $first, string $last): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower($first . $last, 'UTF-8') : strtolower($first . $last);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /** The existing record this submission belongs to (newest first), or null. */
    public static function find(array $clean): ?array
    {
        global $wpdb;
        $form  = (string) ($clean['form_type'] ?? '');
        $email = strtolower(trim((string) ($clean['email'] ?? '')));
        $name  = self::norm_name((string) ($clean['first_name'] ?? ''), (string) ($clean['last_name'] ?? ''));
        if ($form === '' || $email === '' || $name === '') return null;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . $wpdb->prefix . IQU_TABLE_NAME . '` WHERE form_type = %s AND LOWER(email) = %s ORDER BY id DESC',
            $form, $email
        ), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if (self::norm_name((string) $r['first_name'], (string) $r['last_name']) === $name) return $r;
        }
        return null;
    }

    /** Still active: Enroll for Free with its monthly billing running (not Left, not ended for this student); a paid Summer place. */
    public static function is_active(array $reg): bool
    {
        if ((string) ($reg['left_at'] ?? '') !== '') return false;
        $form = (string) ($reg['form_type'] ?? '');
        if ($form === IQU_Database::FORM_SUMMER_LEVEL1 || $form === IQU_Database::FORM_SUMMER_LEVEL2) return (string) ($reg['payment_status'] ?? '') === 'paid';
        if ($form !== IQU_Database::FORM_FREE || (string) ($reg['status'] ?? '') === 'cancelled' || (string) ($reg['billing_ended_at'] ?? '') !== '') return false;
        $acc = class_exists('IQU_Billing_DB') ? IQU_Billing_DB::account_for_registration((int) $reg['id']) : null;
        return $acc && (string) $acc['stripe_subscription_id'] !== '' && (string) $acc['status'] !== 'canceled';
    }

    /** Enroll for Free, returning student: an existing student (no second free month), Pending review. */
    public static function as_returning(array $clean): array
    {
        if ((string) ($clean['form_type'] ?? '') !== IQU_Database::FORM_FREE) return $clean;
        $clean['status'] = 'pending_review';
        if (IQU_Database::student_fee_columns_ready()) $clean['existing_student'] = 1;
        $have = array_filter(explode(',', (string) ($clean['review_reason'] ?? '')));
        if (!in_array('existing_student', $have, true)) $have[] = 'existing_student';
        $clean['review_reason'] = substr(implode(',', $have), 0, 100);
        return $clean;
    }

    /**
     * Save a submission: a new record (no match), the details of an active record, or the whole submission over an
     * inactive one. @return array{id:int, mode:string} mode 'new' | 'details' | 'full'; id 0 when the write failed.
     */
    public static function save(array $clean, ?array $match): array
    {
        if (!$match) return ['id' => (int) IQU_Database::insert_registration($clean), 'mode' => 'new'];
        $id = (int) $match['id'];
        if (self::is_active($match)) {
            $ok = self::update($id, array_intersect_key($clean, array_flip(self::DETAIL_COLUMNS)));
            if ($ok) self::tell($match, 'details');
            return ['id' => $ok ? $id : 0, 'mode' => 'details'];
        }
        $set = array_diff_key(self::as_returning($clean), array_flip(self::KEEP));
        if (IQU_Database::left_ready()) $set['left_at'] = null;
        if (IQU_Database::billing_ended_at_ready()) $set['billing_ended_at'] = null;
        $ok = self::update($id, $set);
        if ($ok && (string) $match['form_type'] === IQU_Database::FORM_FREE) self::detach_ended($id);
        if ($ok) self::tell($match, 'full');
        return ['id' => $ok ? $id : 0, 'mode' => 'full'];
    }

    /** Write the fields plus the re-enrollment date; an archived record is restored. */
    private static function update(int $id, array $set): bool
    {
        global $wpdb;
        if (array_key_exists('date_of_birth', $set) && (!IQU_Database::date_of_birth_ready() || empty($set['date_of_birth']))) unset($set['date_of_birth']);
        if (IQU_Database::reenrolled_ready()) $set['reenrolled_at'] = current_time('mysql', true);
        if (IQU_Database::archive_ready()) { $set['archived_at'] = null; $set['archived_by'] = null; }
        $set['updated_at'] = current_time('mysql', true);
        return false !== $wpdb->update($wpdb->prefix . IQU_TABLE_NAME, $set, ['id' => $id]);
    }

    /**
     * Enroll for Free: take the student off a billing account (this mode) on which their billing has ended — the account
     * ended (canceled), or they were removed from it (Remove from billing) — so a new payment link can be sent.
     * The old account keeps its invoices. Logged as "detached".
     */
    private static function detach_ended(int $id): void
    {
        if (!class_exists('IQU_Billing_DB')) return;
        $acc = IQU_Billing_DB::account_for_registration($id);
        if (!$acc) return;
        $removed = false;
        foreach (IQU_Billing_DB::actions_for_account((int) $acc['id'], 'remove_student') as $a) if ((int) $a['registration_id'] === $id) $removed = true;
        if ((string) $acc['status'] !== 'canceled' && !$removed) return;
        if (!IQU_Billing_DB::remove_member((int) $acc['id'], $id)) return;
        IQU_Billing_DB::log_action(['action' => 'detached', 'account_id' => (int) $acc['id'], 'registration_id' => $id, 'reason' => 'Re-enrolled on the website', 'user_id' => 0]);
    }

    /** One Telegram line for the team (besides the usual new-enrollment notice, which carries the same IQU number). */
    private static function tell(array $reg, string $mode): void
    {
        if (!class_exists('IQU_Telegram') || !IQU_Telegram::is_configured()) return;
        IQU_Telegram::send(IQU_Telegram::compose('🔁 Re-enrollment — existing record updated', [
            'Student' => IQU_Telegram::esc(trim($reg['first_name'] . ' ' . $reg['last_name'])) . ' (IQU-' . (int) $reg['id'] . ')',
            'Program' => IQU_Telegram::esc((string) $reg['form_type']),
        ], $mode === 'details'
            ? 'Still active: only the family\'s details were updated. Status, course, fee, payment and billing are unchanged.'
            : 'The record was updated with the new submission (same IQU number)' . ((string) $reg['form_type'] === IQU_Database::FORM_FREE ? ': existing student, no free month, Pending review.' : '.')));
    }
}
