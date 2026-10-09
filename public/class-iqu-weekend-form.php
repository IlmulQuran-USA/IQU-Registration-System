<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Class IQU_Weekend_Form — Weekend Ilm Program Enrollment
 *
 * Shortcode: [iqu_weekend_form]   → page /weekend-program/
 *
 * 🎁 প্রোগ্রামটি সবার জন্য সম্পূর্ণ ফ্রি — কোনো ফি বা পেমেন্ট নেই।
 *
 * নতুন ও পুরনো ছাত্র আলাদা form_type-এ যায়, শুধু রিপোর্টিংয়ের জন্য:
 *   • Existing student → form_type = weekend_existing
 *   • New student      → form_type = weekend_new
 *
 * 🔒 নিরাপত্তা স্তর: nonce → honeypot → reCAPTCHA v3 → IQU_Validator → escaping
 */
class IQU_Weekend_Form
{
    public function __construct()
    {
        add_shortcode('iqu_weekend_form', [$this, 'render_form']);
        add_action('wp_ajax_iqu_weekend_submit', [$this, 'handle_ajax_submit']);
        add_action('wp_ajax_nopriv_iqu_weekend_submit', [$this, 'handle_ajax_submit']);
    }

    // ════════════════════════════════════════════════════
    // Render
    // ════════════════════════════════════════════════════
    public function render_form(): string
    {
        ob_start();

        if (isset($_GET['iqu_submitted']) && $_GET['iqu_submitted'] === 'weekend') {
?>
<div class="iqu-form-wrapper" id="iqu-weekend-registration">
    <div class="iqu-success-card" role="status" style="max-width:560px;margin:40px auto;">
        <svg class="iqu-success-illu" width="120" height="120" viewBox="0 0 120 120" fill="none"
            xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="60" cy="60" r="56" fill="#eaf6ec" stroke="#27ae60" stroke-width="2" />
            <path d="M38 62 L54 78 L84 44" stroke="#1e8449" stroke-width="6" stroke-linecap="round"
                stroke-linejoin="round" fill="none" />
        </svg>
        <div class="iqu-success-badge">Submission Received</div>
        <h2 class="iqu-success-title">JazakAllahu Khairan!</h2>
        <p class="iqu-success-sub">Your Weekend Ilm Program enrollment has been received.</p>
        <p class="iqu-success-msg">
            We will contact you via WhatsApp within 24&ndash;48 hours, in-sha'-Allah.
        </p>
        <blockquote class="iqu-success-quote">
            "The best among you are those who learn the Qur'an and teach it."
            <cite>- Sahih al-Bukhari</cite>
        </blockquote>
    </div>
</div>
<?php
            return ob_get_clean();
        }
        ?>
<div class="iqu-form-wrapper" id="iqu-weekend-registration">

    <!-- ═══════════════════ HERO ═══════════════════ -->
    <header class="wk-hero">
        <div class="wk-hero-copy">
            <div class="wk-org">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="16" viewBox="0 0 44 39" fill="none"
                    aria-hidden="true">
                    <path d="M37.97 22.22v4.44c-5.7.8-12.74 3.72-16.29 9.35 2.04-6.28 9.79-13.79 16.29-13.79Z"
                        fill="#1E4D6B" />
                    <path
                        d="M43.35 28.59l-1.37 4.29c-1.47-.18-3.14-.3-4.94-.3-6.56 0-12.82 4.24-15.38 6.3 4.24-7.47 14.96-10.79 21.69-10.29Z"
                        fill="#F7941D" />
                    <path
                        d="M0 28.59l1.37 4.29c1.47-.18 3.14-.3 4.94-.3 6.56 0 12.82 4.24 15.38 6.3C17.45 31.41 6.73 28.09 0 28.59Z"
                        fill="#F7941D" />
                    <path d="M5.38 22.22v4.44c5.7.8 12.74 3.72 16.29 9.35-2.04-6.28-9.79-13.79-16.29-13.79Z"
                        fill="#1E4D6B" />
                    <path d="M9.63 7.65v5.5H5.85c.62-2.46 1.86-4 3.78-5.5Z" fill="#F7941D" />
                    <path d="M5.45 15.76h4.18v4.37c-1.42-.49-2.85-.75-4.24-.75v-2.41c.01-.42.03-.83.06-1.21Z"
                        fill="#1E4D6B" />
                    <path d="M16.72 3.06v21.28c-1.34-1.14-2.77-2.15-4.24-2.94V5.68l4.24-2.62Z" fill="#1E4D6B" />
                    <path
                        d="M23.8 1.31v25.82c-.78.87-1.48 1.77-2.11 2.7-.64-.93-1.35-1.83-2.13-2.7V1.3L21.68 0 23.8 1.31Z"
                        fill="#F7941D" />
                    <path d="M30.89 5.68v15.71c-1.48.8-2.9 1.81-4.24 2.95V3.08l4.24 2.6Z" fill="#1E4D6B" />
                    <path d="M37.97 17.56v1.82c-1.39 0-2.82.26-4.24.75V7.65c2.86 2.26 4.24 4.59 4.24 9.91Z"
                        fill="#1E4D6B" />
                </svg>
                ILM-UL-QURAN USA
            </div>

            <h1 class="wk-title">
                Weekend Ilm Program
                <span>For kids, ages 5&ndash;12</span>
            </h1>

            <p class="wk-lede">
                An hour of live Qur'an and Islamic studies every Saturday and Sunday, taught over
                Zoom by a team of Hafiz, Alim and Mufti. Small classes, so every child gets read to
                and heard &mdash; and it costs nothing at all.
            </p>

            <div class="wk-chips">
                <div class="wk-chip">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0e9490" stroke-width="1.9"
                        stroke-linecap="round" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="16" rx="2" />
                        <path d="M8 3v4M16 3v4M3 11h18" />
                    </svg>
                    Saturday &amp; Sunday
                </div>
                <div class="wk-chip">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0e9490" stroke-width="1.9"
                        stroke-linecap="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3.5 2" />
                    </svg>
                    10&ndash;11 AM CST
                </div>
                <div class="wk-chip">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0e9490" stroke-width="1.9"
                        stroke-linecap="round" aria-hidden="true">
                        <rect x="2" y="6" width="14" height="12" rx="2" />
                        <path d="M16 10l6-3.5v11L16 14z" />
                    </svg>
                    Live on Zoom
                </div>
            </div>

            <!-- 🎁 সম্পূর্ণ ফ্রি — হিরোর সবচেয়ে বড় হাইলাইট -->
            <div class="wk-free-banner">
                <span class="wk-free-banner-badge" aria-hidden="true">🎁</span>
                <span class="wk-free-banner-copy">
                    <strong>Fully Free</strong>
                    No tuition, no registration fee, no hidden charges &mdash; for every child.
                </span>
            </div>
        </div>
        <div class="wk-hero-art">
            <img src="<?php echo esc_url(IQU_WEEKEND_HERO_IMG); ?>"
                alt="A teacher greeting children on a live Zoom class" width="1672" height="941" loading="eager"
                decoding="async">
        </div>
    </header>

    <!-- ═══════════════════ SPLIT LAYOUT ═══════════════════ -->
    <div class="iqu-split-layout iqu-split-layout--weekend">

        <!-- ─────────── LEFT: schedule & subjects ─────────── -->
        <aside class="iqu-split-side">
            <div class="iqu-split-side-inner">
                <div class="wk-note">
                    <strong>📌 Please note:</strong> submit a separate form for each child. Don't put more than
                    one child's details in a single submission &mdash; it keeps each child's record accurate.
                    <em>JazakAllahu Khairan!</em>
                </div>
                <section class="wk-card">
                    <h2 class="wk-card-title">🗓️ Class schedule</h2>
                    <p class="wk-card-sub">Two classes a week, one hour each, live on Zoom.</p>

                    <div class="wk-meta">
                        <div class="wk-meta-row">
                            <span class="wk-meta-ico" aria-hidden="true">📅</span>
                            <div>
                                <div class="wk-meta-k">DAYS</div>
                                <div class="wk-meta-v">Every Saturday &amp; Sunday</div>
                            </div>
                        </div>
                        <div class="wk-meta-row">
                            <span class="wk-meta-ico" aria-hidden="true">🕙</span>
                            <div>
                                <div class="wk-meta-k">TIME</div>
                                <div class="wk-meta-v">10:00 &ndash; 11:00 AM (CST)</div>
                            </div>
                        </div>
                        <div class="wk-meta-row">
                            <span class="wk-meta-ico" aria-hidden="true">🎒</span>
                            <div>
                                <div class="wk-meta-k">AGES</div>
                                <div class="wk-meta-v">5 to 12 years</div>
                            </div>
                        </div>
                        <div class="wk-meta-row">
                            <span class="wk-meta-ico" aria-hidden="true">💻</span>
                            <div>
                                <div class="wk-meta-k">FORMAT</div>
                                <div class="wk-meta-v">Interactive live Zoom sessions</div>
                            </div>
                        </div>
                        <div class="wk-meta-row">
                            <span class="wk-meta-ico" aria-hidden="true">👥</span>
                            <div>
                                <div class="wk-meta-k">CLASS SIZE</div>
                                <div class="wk-meta-v">Small groups &mdash; every child is heard</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="wk-card">
                    <h2 class="wk-card-title">📚 What your child will learn</h2>
                    <p class="wk-card-sub">Five subjects across the two weekend classes.</p>

                    <div class="wk-subject wk-subject--teal">
                        <div class="wk-subject-head"><span class="wk-subject-emo">🕌</span> Basic Islam</div>
                        <ul>
                            <li>Fundamental Aqeedah</li>
                            <li>Five Pillars of Islam</li>
                            <li>Fiqh of Taharah</li>
                        </ul>
                    </div>

                    <div class="wk-subject wk-subject--teal">
                        <div class="wk-subject-head"><span class="wk-subject-emo">🤲</span> Salah</div>
                        <ul>
                            <li>Fard, Sunnah &amp; Nawafil prayers</li>
                            <li>Du'as &amp; Tasbihat</li>
                            <li>Meaning and inner significance of Du'as &amp; Tasbihat</li>
                        </ul>
                    </div>

                    <div class="wk-subject wk-subject--navy">
                        <div class="wk-subject-head"><span class="wk-subject-emo">📖</span> Quran</div>
                        <ul>
                            <li>Surah Mashq (practical exercise)</li>
                            <li>Memorization of the last 10 Surahs, with meanings and context of revelation</li>
                        </ul>
                    </div>

                    <div class="wk-subject wk-subject--navy">
                        <div class="wk-subject-head"><span class="wk-subject-emo">🌸</span> Akhlaq &amp; Adab
                            <em>(manners &amp; etiquettes)</em>
                        </div>
                        <ul>
                            <li>Etiquettes with parents &amp; elders</li>
                            <li>Rights of relatives &amp; neighbors</li>
                            <li>Good conduct within the community</li>
                        </ul>
                    </div>

                    <div class="wk-subject wk-subject--orange" style="margin-bottom:16px">
                        <div class="wk-subject-head"><span class="wk-subject-emo">📜</span> Seerah &amp; Islamic History
                        </div>
                        <ul>
                            <li>Stories of the Prophets &amp; Ashabe Rasul (PBUH)</li>
                            <li>Lessons from the lives of the Prophets &amp; Sahaba</li>
                        </ul>
                    </div>

                    <div class="wk-teachers">
                        <span class="wk-teachers-emo" aria-hidden="true">🎓</span>
                        <div>Conducted by a qualified team of <strong>Hafiz, Alim &amp; Mufti</strong>, all
                            experienced in teaching children.</div>
                    </div>
                </section>
            </div>
        </aside>

        <!-- ─────────── RIGHT: enrollment form ─────────── -->
        <div class="iqu-split-main">

            <form id="iqu-weekend-reg-form" method="POST" novalidate class="iqu-form">
                <?php wp_nonce_field('iqu_registration_nonce', '_iqu_nonce'); ?>
                <input type="hidden" name="iqu_recaptcha_token" id="iqu_weekend_recaptcha_token" value="">

                <!-- Honeypot -->
                <div class="iqu-hp-field" aria-hidden="true">
                    <label for="iqu_weekend_website">Leave this empty</label>
                    <input type="text" id="iqu_weekend_website" name="iqu_website" tabindex="-1" autocomplete="off"
                        value="">
                </div>

                <div class="iqu-form-head">
                    <h2>Enroll your child</h2>
                </div>

                <p class="iqu-required-note"><span class="req">*</span> Indicates required question</p>

                <div class="form-display-flex">
                    <div class="iqu-question">
                        <label for="wk_first_name">Participant's First Name <span class="req">*</span></label>
                        <input type="text" id="wk_first_name" name="first_name"
                            placeholder="Enter the participant's first name" maxlength="100" required>
                        <span class="iqu-error" data-field="first_name"></span>
                    </div>
                    <div class="iqu-question">
                        <label for="wk_last_name">Participant's Last Name <span class="req">*</span></label>
                        <input type="text" id="wk_last_name" name="last_name"
                            placeholder="Enter the participant's last name" maxlength="100" required>
                        <span class="iqu-error" data-field="last_name"></span>
                    </div>
                </div>

                <div class="form-display-flex">
                    <div class="iqu-question">
                        <label for="wk_email">Participant's Email <span class="req">*</span></label>
                        <p class="iqu-help">Where we send the Zoom link and updates.</p>
                        <input type="email" id="wk_email" name="email"
                            placeholder="Which email should we send updates to?" maxlength="191" autocomplete="email"
                            required>
                        <span class="iqu-error" data-field="email"></span>
                    </div>

                    <div class="iqu-question">
                        <label for="wk_age">Participant's Age <span class="req">*</span></label>
                        <p class="iqu-help">Open to children aged 5 to 12.</p>
                        <select id="wk_age" name="age" class="wk-age-select" data-wk-visible-rows="5" required>
                            <option value="">-- Select age --</option>
                            <?php for ($a = 5; $a <= 12; $a++): ?>
                            <option value="<?php echo (int) $a; ?>"><?php echo (int) $a; ?> years</option>
                            <?php endfor; ?>
                        </select>
                        <span class="iqu-error" data-field="age"></span>
                    </div>
                </div>

                <div class="form-display-flex">
                    <div class="iqu-question">
                        <label for="wk_guardian_name">Guardian's Full Name <span class="req">*</span></label>
                        <input type="text" id="wk_guardian_name" name="guardian_name"
                            placeholder="What is the guardian's full name?" maxlength="150" required>
                        <span class="iqu-error" data-field="guardian_name"></span>
                    </div>

                    <div class="iqu-question">
                        <label for="wk_referral">How did you hear about us? <span class="req">*</span></label>
                        <select id="wk_referral" name="referral" required>
                            <option value="">-- Please select an option --</option>
                            <option value="facebook">Facebook</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="youtube">YouTube</option>
                            <option value="website">Website</option>
                            <option value="friend_family">Friend/Family</option>
                            <option value="email">Email</option>
                        </select>
                        <span class="iqu-error" data-field="referral"></span>
                    </div>
                </div>

                <div class="form-display-flex">
                    <div class="iqu-question">
                        <label for="wk_country_origin">Country of Origin <span class="req">*</span></label>
                        <input type="text" id="wk_country_origin" name="country_origin"
                            placeholder="Which country is the participant from?" maxlength="100" required>
                        <span class="iqu-error" data-field="country_origin"></span>
                    </div>
                    <div class="iqu-question">
                        <label for="wk_country_res">Country of Residence <span class="req">*</span></label>
                        <input type="text" id="wk_country_res" name="country_res"
                            placeholder="Which country does the child live in?" maxlength="100" required>
                        <span class="iqu-error" data-field="country_res"></span>
                    </div>
                </div>

                <div class="form-display-flex">
                    <div class="iqu-question">
                        <label for="wk_whatsapp">WhatsApp Number (with country code) <span class="req">*</span></label>
                        <p class="iqu-help">
                            Pick your flag, then type your number.<br>
                            Example: 🇺🇸 +1, 🇧🇩 +880, 🇬🇧 +44
                        </p>
                        <input type="tel" id="wk_whatsapp" name="whatsapp" maxlength="25" required>
                        <span class="iqu-error" data-field="whatsapp"></span>
                    </div>

                    <!-- ── ছাত্রের অবস্থা — শুধু form_type ঠিক করে, ফি নেই ── -->
                    <div class="iqu-question" id="wk-status-question">
                        <label for="wk_student_status">Is your child a current student? <span
                                class="req">*</span></label>
                        <p class="iqu-help">
                            This only helps us place your child in the right group &mdash; the program is
                            free either way.
                        </p>

                        <select id="wk_student_status" name="student_status" required>
                            <option value="">-- Please select an option --</option>
                            <option value="existing">Yes &mdash; Existing student</option>
                            <option value="new">No &mdash; New student</option>
                        </select>
                        <span class="iqu-error" data-field="student_status"></span>
                    </div>
                </div>

                <!-- ── 🎁 কোনো ফি নেই — Free Enrollment কার্ডের ডিজাইনে ── -->
                <div class="iqu-question iqu-tuition-block" id="wk-tuition-block">
                    <label>Your Monthly Tuition</label>
                    <p class="iqu-help">
                        The Weekend Ilm Program is offered free of charge to every child,
                        in-sha'-Allah.
                    </p>

                    <div class="iqu-price-display wk-price-free">
                        <div class="iqu-price-amount">$0</div>
                        <div class="iqu-price-meta">
                            Fully free &mdash; no tuition, no registration fee, nothing to pay.
                        </div>
                    </div>
                </div>

                <div class="iqu-question">
                    <label class="iqu-consent">
                        <input type="checkbox" id="wk_consent" required>
                        <span>I confirm my child can attend both classes, Saturday and Sunday, from 10 to
                            11 AM CST, and that a guardian will help them join on time.</span>
                    </label>
                    <span class="iqu-error" data-field="wk_consent"></span>
                </div>

                <div class="iqu-submit-area">
                    <div class="iqu-alert iqu-server-error" id="wk-server-error" style="display:none"></div>
                    <button type="submit" id="wk-submit-btn" class="iqu-btn-submit">
                        <span class="btn-text">Submit Enrollment</span>
                        <span class="btn-loading" style="display:none">Submitting…</span>
                    </button>
                    <p class="iqu-privacy-note">
                        🔒 Your information is kept private and used only to arrange your child's classes.<br />
                        This site is protected by reCAPTCHA v3.
                    </p>
                </div>
            </form>

        </div><!-- /.iqu-split-main -->
    </div><!-- /.iqu-split-layout -->
</div>

<?php
        return ob_get_clean();
    }

    // ════════════════════════════════════════════════════
    // AJAX handler
    // ════════════════════════════════════════════════════
    public function handle_ajax_submit(): void
    {
        // 1. CSRF
        if (!check_ajax_referer('iqu_registration_nonce', '_iqu_nonce', false)) {
            wp_send_json_error([
                'message' => 'Security check failed. Please refresh the page and try again.',
                'code'    => 'invalid_nonce',
            ]);
        }

        // 2. Honeypot — বটকে সফলতার বার্তা দিয়ে বিদায়
        if (!empty($_POST['iqu_website'])) {
            wp_send_json_success(['message' => 'Thank you! Your enrollment has been received.']);
        }

        // 3. reCAPTCHA v3
        $token  = sanitize_text_field($_POST['iqu_recaptcha_token'] ?? '');
        $verify = IQU_Recaptcha::verify($token, 'iqu_weekend_form');
        if (!$verify['success']) {
            wp_send_json_error([
                'message' => 'Verification failed. Please try again.',
                'code'    => 'recaptcha_failed',
            ]);
        }

        // 4. Validate
        $validator = new IQU_Validator();
        if (!$validator->validate_weekend($_POST)) {
            wp_send_json_error([
                'message' => 'Please fix the errors below.',
                'errors'  => $validator->get_errors(),
                'code'    => 'validation_failed',
            ]);
        }
        $clean = $validator->get_clean();

        // US and Canada only (Billing Settings → Enrollment → Country check). Nothing is saved when blocked.
        $blocked = IQU_Eligibility::apply($clean, 'weekend');
        if ($blocked) {
            wp_send_json_error($blocked);
        }

        // 5. Insert
        $reg_id = IQU_Database::insert_registration($clean);
        if (!$reg_id) {
            error_log('[IQU] Weekend registration DB insert failed.');
            wp_send_json_error([
                'message' => 'Enrollment failed due to a server error. Please try again or contact us.',
                'code'    => 'db_error',
            ]);
        }

        // 6. Emails + Telegram
        IQU_Mailer::send_admin_notification($clean, $reg_id);
        IQU_Mailer::send_student_confirmation($clean);
        do_action('iqu_registration_created', $clean, (int) $reg_id);

        // 7. Response — কোনো পেমেন্ট ধাপ নেই, সরাসরি সাকসেস
        $response = [
            'message' => "JazakAllahu Khairan! Your enrollment has been received. We will contact you via WhatsApp within 24–48 hours, in-sha'-Allah.",
            'reg_id'  => $reg_id,
            'form'    => 'weekend',
        ];

        // 8. Meta Lead event (value 0 — ফ্রি প্রোগ্রাম)
        $event_id     = IQU_Pixel::new_event_id();
        $content_name = ($clean['form_type'] === IQU_Database::FORM_WEEKEND_NEW)
            ? 'Weekend Ilm Program (New)'
            : 'Weekend Ilm Program (Existing)';

        IQU_Pixel::send_lead($clean, $event_id, [
            'content_name' => $content_name,
            'value'        => 0,
        ]);

        $response['event_id']     = $event_id;
        $response['content_name'] = $content_name;
        $response['value']        = 0;

        wp_send_json_success($response);
    }
}