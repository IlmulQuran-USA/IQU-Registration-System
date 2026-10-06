# IQU Billing — monthly tuition through Stripe

Built in the Novamira sandbox on the live site (Stripe test mode).
This folder plus `../iqu-billing.php` is the complete, current code.
The registration plugin (iqu-registration) has NOT been modified.

See CHANGELOG.md for the build history and patches/ for changes that still
have to be applied to iqu-registration at merge time.

---

## Files

| File | Purpose |
|---|---|
| `../iqu-billing.php` | Loader. Runs only when IQU Registration is active. Registers everything below. |
| `includes/class-iqu-stripe.php` | Stripe API client (no SDK). Key from wp-config only, test/live mode lock, idempotency keys, booleans sent as "true"/"false". |
| `includes/class-iqu-billing-db.php` | Tables: accounts, members, events, payments. Whitelisted updates, mode column on every row. DB_VERSION 1.1.0. |
| `includes/class-iqu-billing-pricing.php` | Monthly amount for an enrollment: IQU_Pricing rules; older records use the fee chosen at enrollment (fee_pref / payment_amount). |
| `includes/class-iqu-billing-service.php` | Create a family account + Stripe customer, private link (HMAC token), Stripe Checkout (subscription), customer portal. |
| `includes/class-iqu-billing-sync.php` | Reads the subscription from Stripe and updates the local record. Used by the webhook and by hand. |
| `includes/class-iqu-billing-webhook.php` | POST /wp-json/iqu/v1/stripe-webhook. Signature check, 5-minute window, dedupe, mode check, re-reads from Stripe. |
| `includes/class-iqu-billing-notify.php` | Telegram alerts: method added, every payment, payment failed, attempts finished. |
| `includes/class-iqu-billing-summary.php` | Tuition lines for the 9 PM Daily Summary (needs patches/01). |
| `includes/class-iqu-billing-portal.php` | Family page /my-billing: find my link, set up, status, history, pay now, change card. |
| `admin/class-iqu-billing-page.php` | IQU Registrations → Billing. Tabs, Students list, filters, bulk send. |
| `admin/class-iqu-billing-send.php` | Check and send screen, Message screen (copy / WhatsApp / resend / reset link), email. |
| `admin/class-iqu-billing-add-student.php` | Add Student tab. Shared validation `prepare()` used by the import too. |
| `admin/class-iqu-billing-import.php` | Import tab: sample CSV, upload + preview, confirm. Import button beside Export on Enroll for Free. |
| `admin/class-iqu-billing-settings.php` | Settings tab: key status, mode, webhook status, connection test. |

Still to do: full test of every case (new student, current student, past due date, Zakat, siblings, failed payment, lost link, reset link), then go-live.

---

## wp-config.php constants

```php
define( 'IQU_STRIPE_SECRET_KEY', 'sk_test_...' );        // live: restricted key rk_live_...
define( 'IQU_STRIPE_WEBHOOK_SECRET', 'whsec_...' );     // live endpoint has its own secret
```
Telegram uses the existing IQU_TELEGRAM_BOT_TOKEN and IQU_TELEGRAM_CHAT_ID.

WARNING: private family links are derived from the WordPress salts in
wp-config.php. Changing the salts invalidates every family's link.

---

## Stripe settings done (sandbox)

- Payment methods: Cards, ACH Direct Debit, Apple Pay, Google Pay, Link. Amazon Pay off.
- Customer portal: cancel and switch plans OFF; payment methods, invoices, customer info ON.
- Card retries: 3 days, then 5 days after. ACH retries: 2 then 3 business days.
- If all retries fail: mark subscription unpaid (never cancel).
- Emails: trial ending, upcoming renewal (7 days), expiring cards, failed card and bank payments, successful payments, refunds, ACH mandate.
- Managed Payments: NOT set up (3.5% extra). Code also sends managed_payments[enabled]=false.
- Webhook destination "IQU Tuition Billing", API 2026-07-29.dahlia, snapshot payload, 9 events:
  checkout.session.completed, customer.subscription.created/updated/deleted/paused/resumed,
  invoice.paid, invoice.payment_failed, invoice.payment_action_required.

Code pins Stripe-Version 2025-09-30.clover for its own API calls.

## Decisions

- Sibling discount: NO. Siblings pay the full fee each, but are charged together in one monthly payment.
- Zelle end date: the team sets it later in Billing > Settings.
- Stripe public business name Ilm-ul-Quran USA, legal name stays AL HASANAH FOUNDATION.

## Waiting on others

Public details in Stripe (name, support email, website, statement descriptor ILMULQURAN TUITION, terms + privacy links in Business and Customer portal), identity verification of the account representative, admin review of the published policies.

## Before go-live

1. Apply patches/01 to iqu-registration.
2. Move this code into the plugin (or its own plugin) and commit on `stripe-billing`.
3. Create a RESTRICTED live key (no refunds, payouts, transfers, balance).
4. Live webhook destination with the same 9 events; new whsec in wp-config.
5. Set site mode to live (iqu_billing_mode option) only after the security review.
6. Delete test data: registration IQU-159 "Test Family", billing account 1, the test Stripe customer/subscription.
7. /my-billing is already excluded from W3 Total Cache (done in the live config).

---

## Host notes

- The server firewall blocks request bodies containing `sleep(` — do not use it.
- PHP 8.5: dbDelta() crashes on a missing table here; tables use CREATE TABLE IF NOT EXISTS.
- OPcache may serve an old copy of a changed file for a few seconds.
