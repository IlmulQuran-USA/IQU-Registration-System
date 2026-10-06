# IQU Billing — প্লাগইনে বসানোর নির্দেশিকা

**তারিখ:** ৬ অক্টোবর ২০২৬
**বিলিং সংস্করণ:** 1.0.0 · **প্লাগইন:** iqu-registration 3.0.2 (সংস্করণ বদলানো হয়নি — কারণ ১১ নম্বরে)
**যাচাই:** তোমার পাঠানো zip-এর ২৪টা PHP ফাইল লাইভ সার্ভারের সাথে হুবহু মিলিয়ে দেখা হয়েছে। নিচের "পুরোনো কোড" দুই জায়গাতেই মিলবে।

---

## ০. এক নজরে

| | সংখ্যা |
|---|---|
| নতুন ফাইল | ১৫টা (১৪টা ক্লাস + `bootstrap.php`) |
| তোমার যে ফাইল বদলাবে | ৩টা — `iqu-registration.php` (+২ লাইন, ১ লাইন সংশোধন), `includes/class-iqu-notifier.php` (+৩ লাইন), `includes/class-iqu-mailer.php` (−১ লাইন) |
| তোমার অন্য কোনো ফাইল | হাত দেওয়া হয়নি |
| ডেটাবেস | এই ধাপে কিছু বদলাবে না — বিলিংয়ের ৪টা টেবিল আগে থেকেই আছে |
| `wp-config.php` | কিছু বদলাবে না — দুটো লাইন আগে থেকেই আছে |

---

## ১. প্যাকেজে কী আছে

```
iqu-registration/
├── iqu-registration.php                      ← পরিবর্তিত (+২ লাইন, ১ লাইন সংশোধন)
├── README-BILLING-BN.md                      ← এই ফাইল
├── includes/
│   ├── class-iqu-notifier.php                ← পরিবর্তিত (+৩ লাইন)
│   ├── class-iqu-mailer.php                  ← পরিবর্তিত (−১ লাইন, ১৪ নম্বর দেখো)
│   └── billing/                              ← নতুন ফোল্ডার
│       ├── bootstrap.php                     সব বিলিং ক্লাস চালু করে
│       ├── class-iqu-stripe.php              Stripe API (SDK ছাড়া), key শুধু wp-config থেকে, test/live তালা
│       ├── class-iqu-billing-db.php          ৪টা টেবিল: accounts, members, events, payments
│       ├── class-iqu-billing-pricing.php     মাসিক ফি (IQU_Pricing + পুরোনো রেকর্ডের fee_pref)
│       ├── class-iqu-billing-service.php     পরিবারের অ্যাকাউন্ট, প্রাইভেট লিংক, Stripe Checkout, পোর্টাল
│       ├── class-iqu-billing-sync.php        Stripe থেকে অবস্থা মেলানো
│       ├── class-iqu-billing-webhook.php     /wp-json/iqu/v1/stripe-webhook
│       ├── class-iqu-billing-notify.php      Telegram অ্যালার্ট (প্রতিটা পেমেন্ট, ফেল, কার্ড যোগ)
│       ├── class-iqu-billing-summary.php     রাত ৯টার Daily Summary-তে টিউশনের লাইন
│       └── class-iqu-billing-portal.php      পরিবারের পেজ /my-billing
├── admin/
│   └── billing/                              ← নতুন ফোল্ডার
│       ├── class-iqu-billing-page.php        Billing মেনু: Students, Payments ট্যাব, একসাথে পাঠানো
│       ├── class-iqu-billing-send.php        Check and send, Message স্ক্রিন, ইমেইল
│       ├── class-iqu-billing-add-student.php Add Student ট্যাব
│       ├── class-iqu-billing-import.php      Import ট্যাব + Export-এর পাশে Import CSV বাটন
│       └── class-iqu-billing-settings.php    Settings ট্যাব
└── docs/
    └── billing/
        ├── BUILD-HISTORY.md                  ধাপে ধাপে কী বানানো হলো (ইংরেজি)
        └── TECHNICAL-NOTES.md                কারিগরি নোট (ইংরেজি; sandbox-যুগের পথ উল্লেখ আছে)
```

`includes/` আর `admin/`-এর ভাগ তোমার প্লাগইনের নিয়ম মেনেই — সাধারণ পেজ ও webhook-এর ক্লাস `includes/`-এ, অ্যাডমিন স্ক্রিন `admin/`-এ।

---

## ২. শুরুর আগে — লোকাল repo

তোমার লোকাল কপি এখন `main` branch-এ, আর কিছু ফাইলে commit না করা পরিবর্তন আছে (`admin/class-iqu-admin.php`, `class-iqu-zeffy-admin.php`, CSS, JS ইত্যাদি)। এগুলো লাইভের সাথে হুবহু মেলে, তাই আগে এগুলো commit করে নাও — নইলে বিলিংয়ের পরিবর্তনের সাথে মিশে যাবে।

```bash
cd iqu-registration
git add -A
git commit -m "Sync with live site (v3.0.2)"
git push origin main
```

তারপর বিলিংয়ের জন্য branch:

```bash
git fetch origin
git branch -a
```

- তালিকায় `remotes/origin/stripe-billing` **থাকলে:** `git checkout stripe-billing` → `git merge main`
- **না থাকলে:** `git checkout -b stripe-billing`

---

## ৩. নতুন ফাইল কপি

প্যাকেজ থেকে এই তিনটা ফোল্ডার তোমার repo-র একই জায়গায় কপি করো:

- `includes/billing/` (১০টা ফাইল)
- `admin/billing/` (৫টা ফাইল)
- `docs/billing/` (২টা ফাইল)

---

## ৪. `iqu-registration.php` — ২ লাইন যোগ

**পুরোনো কোড** (লাইন ১০৯–১১০, Includes অংশের শেষ):

```php
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon-db.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon.php';
```

**নতুন কোড:**

```php
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon-db.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon.php';

// 💳 Monthly tuition billing (Stripe) — see includes/billing/bootstrap.php
require_once IQU_PLUGIN_DIR . 'includes/billing/bootstrap.php';
```

এই ফাইল Windows লাইন-এন্ডিং (CRLF)-এ — তোমার এডিটর যেমন আছে তেমনই রাখবে। চাইলে হাতে না বসিয়ে প্যাকেজের `iqu-registration.php` দিয়ে পুরো ফাইলটা বদলে দিতে পারো; শুধু এই ২ লাইনই আলাদা (যাচাই করা)।

---

## ৫. `includes/class-iqu-notifier.php` — ৩ লাইন যোগ

ফাংশন `send_daily_summary()`-এর ভেতরে। এতে রাত ৯টার Daily Summary-তে বিলিং নিজের লাইন যোগ করতে পারে। বিলিং না থাকলে সারসংক্ষেপ হুবহু আগের মতো থাকে।

### পরিবর্তন ক

**পুরোনো কোড** (লাইন ৩৫২–৩৫৫):

```php
        // Only nag about pending records when there are some.
        if ($pending > 0) {
            $rows['Awaiting review'] = $pending . ' pending registration' . ($pending === 1 ? '' : 's');
        }
```

**নতুন কোড:**

```php
        // Only nag about pending records when there are some.
        if ($pending > 0) {
            $rows['Awaiting review'] = $pending . ' pending registration' . ($pending === 1 ? '' : 's');
        }

        // Let other modules (e.g. IQU Billing) add their own lines.
        $rows = apply_filters('iqu_daily_summary_rows', $rows, $since);
```

### পরিবর্তন খ

**পুরোনো কোড** (লাইন ৩৫৭–৩৫৯):

```php
        $footer = ($count === 0 && $registrations === 0)
            ? 'A quiet day — nothing came in.'
            : 'Covers the last 24 hours.';
```

**নতুন কোড:**

```php
        $footer = ($count === 0 && $registrations === 0)
            ? 'A quiet day — nothing came in.'
            : 'Covers the last 24 hours.';
        $footer = apply_filters('iqu_daily_summary_footer', $footer, $since);
```

এই ফাইল LF লাইন-এন্ডিং-এ। এটাও চাইলে প্যাকেজের ফাইল দিয়ে পুরোটা বদলাতে পারো (শুধু এই ৩ লাইনই আলাদা)।

---

## ৬. Commit

```bash
git add includes/billing admin/billing docs/billing README-BILLING-BN.md iqu-registration.php includes/class-iqu-notifier.php includes/class-iqu-mailer.php
git status
git commit -m "Add Stripe monthly tuition billing (billing v1.0.0)"
git push -u origin stripe-billing
```

`git status`-এ ঠিক এই ফাইলগুলো ছাড়া অন্য কিছু দেখালে commit-এর আগে থামো।

---

## ৭. লাইভে বসানো — ক্রম গুরুত্বপূর্ণ

এখনো sandbox-এ বিলিংয়ের একটা কপি চলছে। দুটো কপি একসাথে লোড হলে সাইট বন্ধ হয়ে যেত — তাই sandbox-কে আগেই বলে রাখা হয়েছে "প্লাগইনের কপি থাকলে থামো"। তোমার প্লাগইন Novamira-র আগে লোড হয় (যাচাই করা), তাই প্লাগইনের কপিই চলবে।

cPanel → File Manager → `wp-content/plugins/iqu-registration/`:

1. **`includes/billing/` আর `admin/billing/` ফোল্ডার আপলোড** — এগুলো নিজে থেকে কিছু চালায় না, তাই আগে দেওয়া নিরাপদ
2. **`includes/class-iqu-notifier.php` আর `includes/class-iqu-mailer.php` বদলাও** — একা থাকলেও ক্ষতি নেই
3. **`iqu-registration.php` সবশেষে বদলাও** — এই মুহূর্তে বিলিং প্লাগইন থেকে চালু হয়, sandbox-এর কপি নিজে থেকে থেমে যায়
4. সাথে সাথে ৮ নম্বরের যাচাই

সহজ উপায়: প্যাকেজের ভেতরের `iqu-registration` ফোল্ডারটা zip করে cPanel-এ `wp-content/plugins/`-এ আপলোড → **Extract** — এতে সব একসাথে বসে যায়, আর শুধু এই ফাইলগুলোই বদলায় (প্যাকেজে তোমার অন্য কোনো ফাইল নেই)।

---

## ৮. বসানোর পর যাচাই

- [ ] হোম পেজ আর Enroll for Free পেজ খোলে
- [ ] IQU Registrations → **Billing** খোলে, Students তালিকা আগের মতো (Test Family, QA স্টুডেন্টরা)
- [ ] Billing → **Settings**: সবুজ "Ready", Webhook: "Signing secret set" → **Test connection** চাপো
- [ ] Test Family → **Message** → **Sync from Stripe** → "Card added, waiting for first charge"
- [ ] Message-এর লিংক নতুন ট্যাবে খোলো → পরিবারের পেজ আসে
- [ ] Enroll for Free তালিকায় Export-এর পাশে **Import CSV** বাটন
- [ ] রাত ৯টার Daily Summary-তে **Tuition paid [test]** ইত্যাদি লাইন (আজ রাত থেকে)

তারপর আমাকে জানাও — আমি সার্ভার থেকে নিশ্চিত করব প্লাগইনের কপি (1.0.0) চলছে, sandbox-এর (0.1.0) নয়।

---

## ৯. sandbox মোছা — যাচাইয়ের পরে

`wp-content/novamira-sandbox/` থেকে **শুধু এগুলো** মুছবে:

- `iqu-billing.php`
- `iqu-billing/` ফোল্ডার
- `_handover/` ফোল্ডার
- `iqu-billing-handover.php`

**এগুলো মুছবে না** — সাইটের অন্য কাজের: `404-cleanup.php`, `iqu-tracking-no-delay.php`, `summer-results-enqueue.php`, `theme-asset-versioning.php`

চাইলে আমাকে বললে আমি মুছে দেব।

---

## ১০. কিছু ভুল হলে — ফেরানো

`iqu-registration.php` থেকে ৪ নম্বরের ২টা লাইন মুছে দাও (বা পুরোনো ফাইল ফেরত দাও)। বিলিং প্লাগইন থেকে বন্ধ হবে, আর sandbox না মুছে থাকলে সেখানকার কপি নিজে থেকে আবার চালু হবে। ডেটাবেসের কোনো তথ্য হারাবে না।

---

## ১১. যা ইচ্ছা করে বদলানো হয়নি

- **`IQU_VERSION` (3.0.2) বাড়ানো হয়নি।** এটা বাড়ালে তোমার ৩টা টেবিলে `dbDelta` আবার চলে। এই সার্ভারের PHP 8.5-এ `dbDelta` টেবিল না থাকলে ক্র্যাশ করে — টেবিল আছে বলে ঝুঁকি কম, কিন্তু বিলিং বসানোর দিনে অপ্রয়োজনীয়। বিলিংয়ের নিজস্ব সংস্করণ (`IQU_BILLING_VERSION`) আর টেবিল-সংস্করণ (`iqu_billing_db_version` = 1.1.0) আলাদা।
- **টেস্ট ডেটা** (IQU-159 থেকে 165, বিলিং অ্যাকাউন্ট ১–৬) এখনো আছে — Live-এর দিন মোছা হবে।
- **Stripe test key** — Live-এর দিন restricted live key দিয়ে বদলানো হবে।
- **Stripe-এ আবার চেষ্টার আগে অপেক্ষাটা** (`class-iqu-stripe.php`) হস্তান্তরের আগে বাদ দেওয়া হয়েছে — সার্ভারের ফায়ারওয়াল ওই শব্দ (`usleep`) দেখলে ফাইল আপলোড আটকে দিত পারত। এখন ব্যর্থ অনুরোধ সাথে সাথে একবার আবার পাঠায়।

---

## ১২. কোডের বাইরে সাইটে যা বদলানো হয়েছে (রেকর্ডের জন্য)

| জায়গা | কী |
|---|---|
| `wp-config.php` | `IQU_STRIPE_SECRET_KEY`, `IQU_STRIPE_WEBHOOK_SECRET` (তুমি বসিয়েছ) |
| ডেটাবেস টেবিল | `bvgc_iqu_billing_accounts`, `_members`, `_events`, `_payments` |
| ডেটাবেস option | `iqu_billing_db_version`; Zelle তারিখ বসালে `iqu_billing_zelle_end_date`; টেস্টের `iqu_billing_qa_ids`, `iqu_billing_qa_state` (Live-এর দিন মোছা হবে) |
| W3 Total Cache | Page Cache → Never cache: `my-billing` |
| পেজ | নতুন: Tuition, Payment & Refund Policy (ID 1366); হালনাগাদ: Terms and Conditions (443), Privacy Policy (3) — আগের লেখা Revisions-এ |
| WordPress Settings → Privacy | Privacy Policy পেজ বাছা (আগে Terms ছিল) |
| ফুটার মেনু (Navigation ID 76) | Tuition & Refund Policy যোগ, নতুন ক্রম |
| Rank Math | তিনটা পলিসি পেজের title, description, OG ইমেজ, index |
| Stripe (Sandbox) | পেমেন্ট পদ্ধতি, ব্র্যান্ডিং, পোর্টাল, ইমেইল, retry, webhook (৯টা event) |
| Stripe (Live) | retry সেটিং যাচাই করা; বাকি Live-এর দিন |

---

## ১৩. পলিশের তালিকা — প্লাগইনে আনার পরে

1. **বিলিংয়ে হাতে লেখা `info@ilmulquranus.org`-এর বদলে `IQU_CONTACT_EMAIL` constant** ব্যবহার করা (১৪ নম্বরে constant ঠিক করা হয়েছে), যাতে ঠিকানা এক জায়গায় বদলালে সবখানে বদলায়।
2. **ফুটারের ভাষা এক করা:** সাইটের ফুটারে "operating name of", বিলিং ও পলিসিতে "operated by"।
3. **`IQU_VERSION` → 3.1.0** — তার আগে তিনটা টেবিলের `create_table()`-কে PHP 8.5-নিরাপদ করা (নতুন ইনস্টলে টেবিল না থাকলে `dbDelta` ক্র্যাশ করে)।
4. বিলিং স্ক্রিনের inline CSS → `admin/css/` ফাইলে, তোমার বাকি অ্যাডমিনের ডিজাইনের সাথে মিলিয়ে।
5. পরিবারের পেজ (`/my-billing`) আর ইমেইলে লোগো ও সাইটের রং।
6. `.claude/settings.local.json` → `.gitignore`-এ।
7. লোড হয় না এমন পুরোনো ফাইল: `class-iqu-mailer-old.php`, `class-iqu-notifier-v1.php`, `class-iqu-validator-old.php` — মুছে ফেলা যায়।

---

## ১৪. ইমেইল ঠিকানার সংশোধন (এই প্যাকেজেই আছে)

`ilmulquranusa.org` ডোমেইনটাই নেই (DNS-এ ইমেইল বা ওয়েবসাইট কিছুই নেই, যাচাই করা)। সঠিক ডোমেইন `ilmulquranus.org` (Yahoo Business ইমেইল চালু)। ফেসবুক, লিংকডইনের `ilmulquranusa` হ্যান্ডেল আর `ilmulquranusa@gmail.com` ঠিক — ওগুলো ডোমেইন নয়।

### ক. `iqu-registration.php`, লাইন ৫৭

এই ঠিকানা পরিবার দেখে — রেজিস্ট্রেশনের ইমেইলের নিচে আর এনরোলমেন্ট ফর্মে।

**পুরোনো কোড:**

```php
define('IQU_CONTACT_EMAIL', 'info@ilmulquranusa.org'); 
```

**নতুন কোড:**

```php
define('IQU_CONTACT_EMAIL', 'info@ilmulquranus.org');
```

### খ. `includes/class-iqu-mailer.php`, লাইন ৬৪–৬৭ (`get_headers()`)

অস্তিত্বহীন প্রেরক বাদ। FluentSMTP নিজেই প্রেরক বসাবে (নাম "Ilm-ul-Quran USA", ঠিকানা ilmulquranusa@gmail.com), আর পরিবারের উত্তর Gmail-এ যাবে। ভবিষ্যতে FluentSMTP-এর সংযোগ বদলালেও কোড বদলাতে হবে না।

**পুরোনো কোড:**

```php
    return [
      'Content-Type: text/html; charset=UTF-8',
      'From: Ilm-ul-Quran USA <noreply@ilmulquranusa.org>',
    ];
```

**নতুন কোড:**

```php
    return [
      'Content-Type: text/html; charset=UTF-8',
    ];
```

### গ. `includes/class-iqu-mailer-old.php`

একই ভুল আছে, কিন্তু ফাইলটা কোথাও লোড হয় না — মুছে দাও (`git rm includes/class-iqu-mailer-old.php`)।

### ঘ. তোমার কাজ — কোডের বাইরে

1. **Yahoo Business Mail-এ ফরোয়ার্ডিং:** info@ilmulquranus.org → ilmulquranusa@gmail.com। পলিসি, বিলিং পেজ, Stripe-এর রসিদ আর রেজিস্ট্রেশন ইমেইলে পরিবার এই ঠিকানাই দেখে; ফরোয়ার্ডিং না থাকলে তাদের ইমেইল অনুত্তরিত থাকবে।
2. **FluentSMTP → সংযোগ Edit → Sender Name:** "Ilm-Ul-Quran USA" → "Ilm-ul-Quran USA" (বাকি সব জায়গার সাথে মিলিয়ে)।
3. **পরে ভাবার মতো:** প্রতিষ্ঠানের ইমেইল Gmail থেকে না পাঠিয়ে info@ilmulquranus.org (Yahoo SMTP) থেকে পাঠালে প্রেরক ওয়েবসাইটের সাথে মিলবে — আস্থা বাড়ে, স্প্যামের সম্ভাবনা কমে। DNS-এ Yahoo-র SPF আগে থেকেই আছে।
