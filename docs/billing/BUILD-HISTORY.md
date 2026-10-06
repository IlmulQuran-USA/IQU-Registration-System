# IQU Billing — build history

All dates 2026-10-05. Sandbox build, Stripe test mode.

1. Stripe client + Billing Settings page. Key from wp-config only, masked display, test/live mode lock (verified: live mode + test key refuses every request).
2. Database: accounts, members, events tables. Dedupe and SQL injection checks passed. dbDelta replaced by CREATE TABLE IF NOT EXISTS (PHP 8.5 crash).
3. Pricing from IQU_Pricing; agreed reduced fee and Zakat labels.
4. Add Existing Student (for students who joined before the website form). Writes a normal registration row, referral = existing_student, always billed as a current student.
5. CSV import with sample file and preview; Import button next to Export CSV. Validation shared with the single form through prepare().
6. Billing service: Stripe customer, private link (HMAC of account id with wp salts, only the hash stored), Stripe Checkout in subscription mode (trial_end for new students, billing_cycle_anchor for current students), customer portal.
7. Fixes found while testing: Managed Payments was on by default (now disabled per request in code); PHP false was sent as 0 (booleans now sent as "true"/"false"); firewall blocks `sleep(`.
8. Send screens: Check and send (siblings by same email, first charge date), Message screen (copy, Open in WhatsApp, mark sent, resend email, reset link). Test mode blocks email to anyone but our own addresses.
9. Moved billing out of the enrollment lists into its own Billing page with tabs (Students, Payments, Add Student, Import, Settings) and bulk send.
10. Older records without a course: billed at the fee chosen at enrollment (fee_pref like qaidah_50, custom_25). Summer is a one-time fee and is not billed monthly.
11. Sync from Stripe + webhook (signature, 5-minute window, dedupe, mode check, re-read from Stripe, ownership check). End-to-end test: event received and processed in 1 second.
12. Telegram alerts through IQU_Telegram: method added, payment failed, attempts finished. Payments ledger table (DB 1.1.0).
13. Every payment now sends its own Telegram message. The separate tuition summary was removed; tuition lines go into the existing 9 PM Daily Summary through filters (patches/01, not yet applied).

14. Family page /my-billing (includes/class-iqu-billing-portal.php): find-my-link, set-up view, status + payment history from Stripe, failed-payment view with Pay now, Change bank or card via customer portal. Security: token hash lookup, rate limits (20 bad links / 15 min per IP; 5 link requests per IP and 3 per email per hour), same answer for known and unknown emails, POST only with Origin check, redirects only to checkout/billing.stripe.com, no-store, noindex, DENY framing, strict CSP (no scripts at all), masked email, last four digits only.
15. Found and fixed: W3TC adds Referrer-Policy no-referrer-when-downgrade site-wide (.htaccess) — page now also sets <meta name=referrer content=no-referrer> and rel=noreferrer on forms so the private link never reaches Stripe or anyone else. An optimizer plugin injected Google Tag Manager into the page — output buffers are now cleared before sending. GoDaddy adds a monitoring script after PHP; the CSP blocks it.
16. W3 Total Cache: my-billing added to Page Cache “Never cache the following pages”.

17. Fixed: hidden billing screens said "not allowed to access this page" because they were removed from the menu before WordPress checked access. They are now hidden on admin_head, after the check. All screens re-tested as a logged-in admin over HTTP.
18. Payments tab: collected this month and last month, families on billing, expected in the next 14 days, payment problems, recent payments with a link to the invoice in Stripe.
19. Settings: Zelle end date (adds the Zelle line to every message and email when set), Sync all families. Message screen: Sync from Stripe for one family.

20. Full QA run in Stripe test mode with test clocks (QA students IQU-160 to IQU-165, billing accounts 2 to 6). Passed: new student free month then $60 charge; current student charged at once ($48); partial Zakat $64 - $34 = $30; two siblings one $100 charge; failed card: 3 attempts, Telegram on each, then unpaid (not canceled) and a contact-the-family alert; family page for Zakat, siblings and failed (Pay now); reset link (old link refused, new one works).
21. Fixed: status after the first charge depended on the server clock. Now uses Stripe's own dates (current period start vs first charge date).
22. Added a QA-only option to attach a customer to a Stripe test clock (test mode only).

23. Telegram alerts now identify the family fully: guardian, every student (full name, IQU number, course, days), full email, WhatsApp as a tap-to-chat wa.me link (plain text with "no country code" when the number has none — never guessed), card or bank brand with last four digits, which month, receipt number, the bank's decline reason, and a View in Stripe link. Fixed: apostrophes (Qa'idah) were sent as &apos;, which Telegram does not understand; now &#039;.

24. Website policies published (2026-10-06): new page /tuition-refund-policy/ (ID 1366); /terms-and-conditions/ (ID 443) updated; /privacy-policy/ (ID 3) rewritten from the WordPress template; Settings > Privacy now points to the Privacy Policy (it pointed to Terms). Previous texts are in each page's Revisions. Decisions used (standard, admin to confirm): billing on the same date monthly, paid in advance, date can move to the 1st or 15th to match payday; 7 days notice to pause/stop; no refund for a charged month except within 48 hours with no class taken; errors and duplicate charges refunded; teacher-cancelled classes made up or credited; 24 hours notice to reschedule; replies in 3 business days; no late fees; 30 days notice of fee changes. /my-billing footer and the payment email now say 'operated by AL HASANAH FOUNDATION' and the billing page links all three policies.

Test data to remove before go-live: registrations IQU-159 to IQU-165 (all info@ilmulquranus.org, marked TEST/QA), billing accounts 1 to 6, their Stripe test customers, subscriptions, 2 test clocks and the product "IQU QA tuition (test)". Options iqu_billing_qa_ids and iqu_billing_qa_state.
