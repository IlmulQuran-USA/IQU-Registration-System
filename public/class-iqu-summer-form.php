<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Class IQU_Summer_Form — Summer Islamic Education Program 2025 Enrollment
 *
 * Mirrors the official Google-Form exactly: every question, label, and
 * descriptive paragraph is preserved in the same order as the screenshot.
 *
 * Handles:
 *   • Level 1 (ages 5–10) and Level 2 (ages 11–15) routing via enrollment_level
 *   • Zelle in-page modal payment (transaction ID required)
 *   • Zeffy off-site payment (amount passed via ?amount= query)
 */
class IQU_Summer_Form
{

    public function __construct()
    {
        add_shortcode('iqu_summer_form', [$this, 'render_form']);
        add_action('wp_ajax_iqu_summer_submit', [$this, 'handle_ajax_submit']);
        add_action('wp_ajax_nopriv_iqu_summer_submit', [$this, 'handle_ajax_submit']);
    }

    // ════════════════════════════════════════════════════
    // Render
    // ════════════════════════════════════════════════════
    public function render_form(): string
    {
        ob_start();
        if (isset($_GET['iqu_submitted']) && $_GET['iqu_submitted'] === 'summer') {
?>
<div class="iqu-form-wrapper" id="iqu-summer-registration">
    <div class="iqu-success-card" role="status" style="max-width:560px;margin:40px auto;">
        <svg class="iqu-success-illu" width="120" height="120" viewBox="0 0 120 120" fill="none"
            xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="60" cy="60" r="56" fill="#eaf6ec" stroke="#27ae60" stroke-width="2" />
            <path d="M38 62 L54 78 L84 44" stroke="#1e8449" stroke-width="6" stroke-linecap="round"
                stroke-linejoin="round" fill="none" />
        </svg>
        <div class="iqu-success-badge">Submission Received</div>
        <h2 class="iqu-success-title">JazakAllahu Khairan!</h2>
        <p class="iqu-success-sub">Your Summer Program enrollment has been received.</p>
        <p class="iqu-success-msg">
            We will contact you via WhatsApp within 24–48 hours, in-sha'-Allah.
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
<div class="iqu-form-wrapper" id="iqu-summer-registration">
    <!-- ===== HERO SECTION ===== -->
    <header class="sc-hero">
        <!-- Clouds SVG -->
        <svg class="sc-clouds" viewBox="0 0 800 120" xmlns="http://www.w3.org/2000/svg"
            preserveAspectRatio="xMidYMid meet">
            <ellipse cx="90" cy="38" rx="55" ry="22" fill="rgba(255,255,255,0.75)" />
            <ellipse cx="120" cy="28" rx="40" ry="18" fill="rgba(255,255,255,0.85)" />
            <ellipse cx="60" cy="30" rx="30" ry="14" fill="rgba(255,255,255,0.80)" />
            <ellipse cx="560" cy="30" rx="60" ry="22" fill="rgba(255,255,255,0.70)" />
            <ellipse cx="595" cy="20" rx="42" ry="18" fill="rgba(255,255,255,0.82)" />
            <ellipse cx="530" cy="24" rx="32" ry="14" fill="rgba(255,255,255,0.75)" />
            <ellipse cx="330" cy="18" rx="38" ry="14" fill="rgba(255,255,255,0.60)" />
            <ellipse cx="358" cy="10" rx="26" ry="12" fill="rgba(255,255,255,0.70)" />
        </svg>

        <!-- Sun -->
        <svg class="sc-sun" viewBox="0 0 70 70" xmlns="http://www.w3.org/2000/svg">
            <circle cx="35" cy="35" r="18" fill="#f5c842" />
            <g stroke="#f5c842" stroke-width="3" stroke-linecap="round">
                <line x1="35" y1="4" x2="35" y2="12" />
                <line x1="35" y1="58" x2="35" y2="66" />
                <line x1="4" y1="35" x2="12" y2="35" />
                <line x1="58" y1="35" x2="66" y2="35" />
                <line x1="13" y1="13" x2="19" y2="19" />
                <line x1="51" y1="51" x2="57" y2="57" />
                <line x1="57" y1="13" x2="51" y2="19" />
                <line x1="19" y1="51" x2="13" y2="57" />
            </g>
        </svg>

        <div class="sc-hero-inner">
            <div class="display-flex">
                <!-- ILM-UL-QURAN USA logo badge -->
                <div class="sc-logo-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 44 39" fill="none">
                        <path
                            d="M37.9712 22.2153V26.6603C32.2693 27.4602 25.2305 30.374 21.6768 36.0074C23.7221 29.7227 31.4694 22.2153 37.9712 22.2153Z"
                            fill="#1E4D6B" />
                        <path
                            d="M43.3527 28.5919L41.9815 32.8769C40.5074 32.7055 38.8391 32.5798 37.0451 32.5798C30.4862 32.5798 24.2244 36.8191 21.6648 38.8759C25.9041 31.4143 36.6223 28.0891 43.3527 28.5919Z"
                            fill="#F7941D" />
                        <path
                            d="M0 28.5919L1.3712 32.8769C2.84525 32.7055 4.51355 32.5798 6.30754 32.5798C12.8665 32.5798 19.1283 36.8191 21.6879 38.8759C17.4486 31.4143 6.73032 28.0891 0 28.5919Z"
                            fill="#F7941D" />
                        <path
                            d="M5.38184 22.2153V26.6603C11.0838 27.4602 18.1226 30.374 21.6763 36.0074C19.6309 29.7227 11.8836 22.2153 5.38184 22.2153Z"
                            fill="#1E4D6B" />
                        <path d="M9.63282 7.646V13.1537H5.85059C6.46763 10.6969 7.71314 9.15432 9.63282 7.646Z"
                            fill="#F7941D" />
                        <path
                            d="M5.45069 15.7563H9.63286V20.1328C8.21595 19.6414 6.78761 19.3786 5.39355 19.3786V16.9676C5.40498 16.5448 5.42784 16.1334 5.45069 15.7563Z"
                            fill="#1E4D6B" />
                        <path
                            d="M16.7171 3.06201V24.3385C15.3802 23.1959 13.9518 22.1903 12.4778 21.4019V5.67873L16.7171 3.06201Z"
                            fill="#1E4D6B" />
                        <path
                            d="M23.8016 1.31407V27.127C23.0245 27.9954 22.3161 28.8981 21.6876 29.8351C21.0477 28.9095 20.3393 28.0068 19.5623 27.1384V1.30264L21.6762 0L23.8016 1.31407Z"
                            fill="#F7941D" />
                        <path
                            d="M30.8863 5.68048V21.3922C29.4122 22.1921 27.9839 23.1976 26.647 24.3403V3.0752L30.8863 5.68048Z"
                            fill="#1E4D6B" />
                        <path
                            d="M37.971 17.5644V19.3812C36.5769 19.3812 35.1486 19.644 33.7317 20.1354V7.646C36.5884 9.90848 37.971 12.2395 37.971 17.5644Z"
                            fill="#1E4D6B" />
                    </svg>
                    ILM-UL-QURAN USA
                </div>

                <!-- USA only badge -->
                <div class="sc-badge-usa">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 92.3 132.3">
                        <path fill="#1a73e8"
                            d="M60.2 2.2C55.8.8 51 0 46.1 0 32 0 19.3 6.4 10.8 16.5l21.8 18.3L60.2 2.2z" />
                        <path fill="#ea4335"
                            d="M10.8 16.5C4.1 24.5 0 34.9 0 46.1c0 8.7 1.7 15.7 4.6 22l28-33.3-21.8-18.3z" />
                        <path fill="#4285f4"
                            d="M46.2 28.5c9.8 0 17.7 7.9 17.7 17.7 0 4.3-1.6 8.3-4.2 11.4 0 0 13.9-16.6 27.5-32.7-5.6-10.8-15.3-19-27-22.7L32.6 34.8c3.3-3.8 8.1-6.3 13.6-6.3" />
                        <path fill="#fbbc04"
                            d="M46.2 63.8c-9.8 0-17.7-7.9-17.7-17.7 0-4.3 1.5-8.3 4.1-11.3l-28 33.3c4.8 10.6 12.8 19.2 21 29.9l34.1-40.5c-3.3 3.9-8.1 6.3-13.5 6.3" />
                        <path fill="#34a853"
                            d="M59.1 109.2c15.4-24.1 33.3-35 33.3-63 0-7.7-1.9-14.9-5.2-21.3L25.6 98c2.6 3.4 5.3 7.3 7.9 11.3 9.4 14.5 6.8 23.1 12.8 23.1s3.4-8.7 12.8-23.2" />
                    </svg>
                    For USA Based Students Only
                </div>
            </div>

            <!-- Main Title — 38px, Poppins 800 -->
            <h2 class="sc-title">
                Summer Ilm Camp<br>
                <span>Online-2026</span>
            </h2>

            <!-- Eyebrow / Subtitle — 10px, 700, uppercase -->
            <p class="sc-subtitle">Organized by Ilm-ul-Quran USA</p>

            <!-- Stats row — numbers 22px 700, labels 10px 700 -->
            <div class="sc-stats">
                <div class="sc-stat">
                    <div class="sc-stat-num">8</div>
                    <div class="sc-stat-lbl">Weeks program</div>
                </div>
                <div class="sc-stat">
                    <div class="sc-stat-num">4</div>
                    <div class="sc-stat-lbl">Days Class</div>
                </div>
                <div class="sc-stat">
                    <div class="sc-stat-num">2</div>
                    <div class="sc-stat-lbl">Subjects per Day</div>
                </div>
                <div class="sc-stat">
                    <div class="sc-stat-num">60+</div>
                    <div class="sc-stat-lbl">Total Classes</div>
                </div>
            </div>
        </div>

        <!-- Decorative hills wave -->
        <svg class="sc-hills" viewBox="0 0 800 80" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
            <path d="M0 80 Q100 20 200 50 Q300 75 400 30 Q500 0 600 40 Q700 70 800 30 L800 80 Z" fill="#e8f7f0" />
            <path d="M0 80 Q150 50 280 65 Q400 78 500 55 Q620 30 800 60 L800 80 Z" fill="#fff" />
            <!-- Left tree -->
            <rect x="60" y="28" width="10" height="30" rx="3" fill="#8b5e30" />
            <polygon points="55,30 75,30 65,5" fill="#2d7a4e" />
            <polygon points="50,38 80,38 65,18" fill="#36ad62" />
            <!-- Right tree -->
            <rect x="700" y="22" width="10" height="36" rx="3" fill="#8b5e30" />
            <polygon points="694,24 716,24 705,-2" fill="#2d7a4e" />
            <polygon points="690,32 720,32 705,12" fill="#36ad62" />
        </svg>
    </header>
    <!-- ===== INTRO CARD SECTION ===== -->
    <section class="sc-body">
        <div class="sc-intro">

            <!-- Body text — 14px, Poppins 400 -->
            <p>
                Welcome to the registration form for the
                <strong>Summer Ilm Camp Online-2026</strong> — a free online program
                designed to make your child's summer spiritually enriching and educational. Organized by
                <strong>Ilm-ul-Quran USA</strong> and Subsidized by <strong>Al-Hasanah Foundation</strong>
                (a 501(c)(3) nonprofit), this program is open to children aged <strong>5 to 15</strong>.
            </p>

            <!-- Info grid — label 10px 700 uppercase, value 13px 500 -->
            <div class="sc-info-grid">
                <div class="sc-info-item" style="gap: 10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" shape-rendering="geometricPrecision"
                        text-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="evenodd"
                        clip-rule="evenodd" height="30" width="30" viewBox="0 0 467 512.13">
                        <path fill="gray" fill-rule="nonzero"
                            d="M424.43 512.13H42.58C19.2 512.13 0 492.93 0 469.57V283.04h467v186.53c0 23.41-19.17 42.56-42.57 42.56z" />
                        <path fill="#fff"
                            d="M47.12 498.51h372.77c18.42 0 33.47-15.28 33.47-33.5V283.04H13.65v181.98c0 18.38 15.04 33.49 33.47 33.49z" />
                        <path fill="#F13B31"
                            d="M42.58 28.46h381.84c23.39 0 42.57 19.17 42.57 42.56v212.05H0V71.02c0-23.37 19.2-42.56 42.58-42.56z" />
                        <path fill="#C72B20"
                            d="M320.35 40.47c8.39 0 16 3.41 21.5 8.91 5.49 5.5 8.9 13.1 8.9 21.5 0 8.38-3.41 15.98-8.9 21.48-5.5 5.52-13.11 8.93-21.5 8.93-8.38 0-15.98-3.41-21.49-8.91-5.51-5.52-8.92-13.12-8.92-21.5 0-8.4 3.41-16 8.9-21.5l.3-.28c5.48-5.33 12.97-8.63 21.21-8.63z" />
                        <path fill="#fff"
                            d="M320.34 46.58c13.42 0 24.3 10.88 24.3 24.29 0 13.43-10.88 24.31-24.3 24.31-13.43 0-24.31-10.88-24.31-24.31 0-13.41 10.88-24.29 24.31-24.29z" />
                        <path fill="#C72B20"
                            d="M133.77 40.47c8.4 0 16 3.41 21.5 8.91s8.91 13.1 8.91 21.5c0 8.38-3.41 15.98-8.91 21.48-5.5 5.52-13.1 8.93-21.5 8.93-8.38 0-15.98-3.41-21.48-8.91-5.52-5.52-8.93-13.12-8.93-21.5 0-8.4 3.41-16 8.91-21.5l.3-.28c5.47-5.33 12.96-8.63 21.2-8.63z" />
                        <path fill="#fff"
                            d="M133.76 46.58c13.42 0 24.3 10.88 24.3 24.29 0 13.43-10.88 24.31-24.3 24.31-13.43 0-24.31-10.88-24.31-24.31 0-13.41 10.88-24.29 24.31-24.29z" />
                        <path fill="#1A1A1A" fill-rule="nonzero"
                            d="M116.4 14.24C116.4 6.38 124.16 0 133.76 0c9.61 0 17.38 6.38 17.38 14.24v54.45c0 7.86-7.77 14.24-17.38 14.24-9.6 0-17.36-6.38-17.36-14.24V14.24zM302.98 14.24c0-7.86 7.76-14.24 17.37-14.24 9.6 0 17.37 6.38 17.37 14.24v54.45c0 7.86-7.77 14.24-17.37 14.24-9.61 0-17.37-6.38-17.37-14.24V14.24z" />
                        <path fill="#C2352C" fill-rule="nonzero"
                            d="M110.4 231.97l-2.99-23.88h12.39c3.09 0 5.05-.43 5.9-1.27.85-.85 1.27-1.92 1.27-3.21v-41.06h-12.39v-23.89h42.25v68.68c0 8.06-1.99 14.18-5.97 18.36-3.99 4.18-9.71 6.27-17.17 6.27H110.4zm86.74-93.31v70.02h10.6c3.78 0 6.37-.47 7.76-1.42 1.39-.94 2.09-3.11 2.09-6.49v-62.11h29.86v52.41c0 8.46-.55 15.27-1.64 20.45-1.1 5.17-3.14 9.55-6.13 13.14-2.98 3.58-7.06 6.07-12.24 7.46-5.17 1.4-11.89 2.09-20.15 2.09-8.26 0-14.96-.69-20.08-2.09-5.13-1.39-9.18-3.88-12.17-7.46-2.98-3.59-5.03-7.97-6.12-13.14-1.1-5.18-1.64-11.99-1.64-20.45v-52.41h29.86zm117.79 93.31l-22.84-33.14c-.8-1.1-1.3-3.48-1.5-7.17H290v40.31h-29.86v-93.31h28.07l22.84 33.15c.79 1.09 1.29 3.48 1.49 7.16h.6v-40.31H343v93.31h-28.07z" />
                        <path fill="#fff" fill-rule="nonzero"
                            d="M115.02 237.2l-2.99-23.89h12.39c3.09 0 5.05-.42 5.9-1.27.85-.85 1.27-1.92 1.27-3.21v-41.06H119.2v-23.89h42.25v68.68c0 8.07-1.99 14.19-5.97 18.37-3.98 4.18-9.71 6.27-17.17 6.27h-23.29zm86.75-93.32v70.03h10.6c3.78 0 6.37-.48 7.76-1.42 1.39-.95 2.09-3.11 2.09-6.5v-62.11h29.86v52.41c0 8.46-.54 15.28-1.64 20.45-1.09 5.18-3.14 9.56-6.12 13.14-2.99 3.59-7.07 6.07-12.24 7.47-5.18 1.39-11.9 2.09-20.16 2.09s-14.96-.7-20.08-2.09c-5.13-1.4-9.19-3.88-12.17-7.47-2.99-3.58-5.03-7.96-6.12-13.14-1.1-5.17-1.65-11.99-1.65-20.45v-52.41h29.87zm117.8 93.32l-22.84-33.15c-.8-1.09-1.3-3.48-1.5-7.16h-.59v40.31h-29.87v-93.32h28.07l22.85 33.15c.79 1.09 1.29 3.48 1.49 7.16h.6v-40.31h29.86v93.32h-28.07z" />
                        <path fill="#1A1A1A" fill-rule="nonzero"
                            d="M276.05 443.95h-85.08v-27.23h30.29v-45.09l-30.29 2.04v-27.22l39.14-8.85h26.88v79.12h19.06z" />
                    </svg>
                    <div class="sc-info-text"><strong>Duration</strong>June 1 – July 31, 2026</div>
                </div>
                <div class="sc-info-item">
                    <span class="sc-info-icon">🗓</span>
                    <div class="sc-info-text"><strong>Class Days</strong>Monday – Thursday</div>
                </div>
                <div class="sc-info-item">
                    <span class="sc-info-icon">🕙</span>
                    <div class="sc-info-text"><strong>Class Time</strong>10:00 AM – 12:00 PM (CDT)</div>
                </div>
                <div class="sc-info-item">
                    <span class="sc-info-icon">👥</span>
                    <div class="sc-info-text"><strong>Format</strong>Live Interactive Zoom Sessions</div>
                </div>
            </div>

            <!-- Level cards — tag 10px 700, name 15px 700, ages 13px 600 -->
            <div class="sc-levels">
                <div class="sc-level sc-level-1">
                    <div class="sc-level-tag">Level 1</div>
                    <div class="sc-level-name">Beginner</div>
                    <div class="sc-level-ages">Ages 5 – 10</div>
                </div>
                <div class="sc-level sc-level-2">
                    <div class="sc-level-tag">Level 2</div>
                    <div class="sc-level-name">Advanced</div>
                    <div class="sc-level-ages">Ages 11 – 15</div>
                </div>
            </div>

            <p>Taught by a team of <strong>Qualified Huffaz, Ulama, and Muftis</strong> through live Zoom sessions. To
                enroll
                your child please complete the form below. We look forward to an engaging and uplifting summer together,
                Insha-Allah!</p>
        </div>

        <!-- What Students Will Learn — H2: 18px 700 -->
        <div class="sc-subjects-title">What Students Will Learn</div>

        <!-- Subject chips — 12px 600 -->
        <div class="sc-subjects">
            <div class="sc-sub sc-sub-0"><span class="sc-sub-dot"></span>Quran &amp; Tajweed</div>
            <div class="sc-sub sc-sub-1"><span class="sc-sub-dot"></span>Aqeedah</div>
            <div class="sc-sub sc-sub-2"><span class="sc-sub-dot"></span>Fiqh</div>
            <div class="sc-sub sc-sub-3"><span class="sc-sub-dot"></span>Akhlaq</div>
            <div class="sc-sub sc-sub-4"><span class="sc-sub-dot"></span>Seerah</div>
        </div>

        <!-- Special Features heading — 15px H3 -->
        <div class="sc-subjects-title" style="font-size:18px;">Special Features</div>

        <!-- Feature pills — 11px 700 -->
        <div class="sc-features">
            <div class="sc-feat sc-feat-0">Electronic Certificate</div>
            <div class="sc-feat sc-feat-1">Awards for Students</div>
            <div class="sc-feat sc-feat-2">Website Recognition</div>
        </div>

        <!-- Important Note — 13px 400 -->
        <div class="sc-note">
            <strong>Important Note:</strong> Please submit a separate form for each student. Do not include multiple
            students'
            information in a single submission. This helps us maintain accurate records for each participant.
            <em>JazakAllahu Khairan!</em>
        </div>
    </section>
    <!-- ===== END OF SECTION ===== -->

    <form id="iqu-summer-reg-form" method="POST" novalidate class="iqu-form">
        <?php wp_nonce_field('iqu_registration_nonce', '_iqu_nonce'); ?>
        <input type="hidden" name="iqu_recaptcha_token" id="iqu_summer_recaptcha_token" value="">

        <!-- Honeypot -->
        <div class="iqu-hp-field" aria-hidden="true">
            <label for="iqu_summer_website">Leave this empty</label>
            <input type="text" id="iqu_summer_website" name="iqu_website" tabindex="-1" autocomplete="off" value="">
        </div>

        <p class="iqu-required-note"><span class="req">*</span> Indicates required question</p>

        <!-- Email -->
        <div class="iqu-question">
            <label for="summer_email">Email <span class="req">*</span></label>
            <input type="email" id="summer_email" name="email" placeholder="Which email should we send updates to?"
                maxlength="191" autocomplete="email" required>
            <span class="iqu-error" data-field="email"></span>
        </div>

        <div class="form-display-flex">
            <!-- First Name -->
            <div class="iqu-question">
                <label for="summer_first_name">Participant's First Name <span class="req">*</span></label>
                <input type="text" id="summer_first_name" name="first_name"
                    placeholder="Enter the participant's first name" maxlength="100" required>
                <span class="iqu-error" data-field="first_name"></span>
            </div>
            <!-- Last Name -->
            <div class="iqu-question">
                <label for="summer_last_name">Participant's Last Name <span class="req">*</span></label>
                <input type="text" id="summer_last_name" name="last_name"
                    placeholder="Enter the participant's last name" maxlength="100" required>
                <span class="iqu-error" data-field="last_name"></span>
            </div>
        </div>

        <div class="form-display-flex">
            <!-- Enrollment Level -->
            <div class="iqu-question">
                <label for="enrollment_level">
                    Please select the appropriate enrollment level for your child
                    <span class="req">*</span>
                </label>
                <select name="enrollment_level" id="enrollment_level" required>
                    <option value="">-- Please select an option --</option>
                    <option value="level1">Level 1 (ages 5 to 10)</option>
                    <option value="level2">Level 2 (ages 11 to 15)</option>
                </select>
                <span class="iqu-error" data-field="enrollment_level"></span>
            </div>

            <!-- Age -->
            <div class="iqu-question">
                <label for="summer_age">Participant's Age <span class="req">*</span></label>
                <p class="iqu-help" id="iqu-summer-age-help">Enter the student's current age.</p>
                <input type="number" id="summer_age" name="age" placeholder="How old is the participant?" min="5"
                    max="15" required>
                <span class="iqu-error" data-field="age"></span>
            </div>
        </div>

        <!-- Guardian Name -->
        <div class="iqu-question">
            <label for="guardian_name">Guardian's Full Name <span class="req">*</span></label>
            <input type="text" id="guardian_name" name="guardian_name" placeholder="What is the guardian's full name?"
                maxlength="150" required>
            <span class="iqu-error" data-field="guardian_name"></span>
        </div>

        <div class="form-display-flex">
            <!-- Guardian Contact Number -->
            <div class="iqu-question">
                <label for="guardian_contact">Guardian's Contact Number <span class="req">*</span></label>
                <p class="iqu-help">
                    <strong>Country code required.</strong>
                    Select your flag from the dropdown, then enter your number.<br>
                    Example: 🇺🇸 +1, 🇧🇩 +880, 🇬🇧 +44
                </p>
                <input type="tel" id="guardian_contact" name="guardian_contact" placeholder="(555) 123-4567"
                    maxlength="25" required>
                <span class="iqu-error" data-field="guardian_contact"></span>
            </div>

            <!-- Guardian WhatsApp -->
            <div class="iqu-question">
                <label for="guardian_whatsapp">Guardian's WhatsApp Contact Number <span class="req">*</span></label>
                <p class="iqu-help">
                    <strong>Country code required.</strong>
                    Select your flag from the dropdown, then enter your number.<br>
                    Example: 🇺🇸 +1, 🇧🇩 +880, 🇬🇧 +44
                </p>
                <input type="tel" id="guardian_whatsapp" name="guardian_whatsapp" placeholder="(555) 123-4567"
                    maxlength="25" required>
                <span class="iqu-error" data-field="guardian_whatsapp"></span>
            </div>
        </div>

        <div class="form-display-flex">
            <!-- ── Country of Origin ────────────────────────── -->
            <div class="iqu-question">
                <label for="country_origin">Country of Origin <span class="req">*</span></label>
                <input type="text" id="country_origin" name="country_origin"
                    placeholder="Which country is the participant from?" maxlength="100" required>
                <span class="iqu-error" data-field="country_origin"></span>
            </div>

            <!-- Country of Residence -->
            <div class="iqu-question">
                <label for="summer_country_res">Country of Residence <span class="req">*</span></label>
                <input type="text" id="summer_country_res" name="country_res"
                    placeholder="Which country does the student live in?" maxlength="100" required>
                <span class="iqu-error" data-field="country_res"></span>
            </div>
        </div>

        <div class="form-display-flex">
            <!-- WhatsApp Group -->
            <div class="iqu-question">
                <label for="whatsapp_group">
                    Stay updated via WhatsApp group?
                    <span class="req">*</span>
                </label>
                <select id="whatsapp_group" name="whatsapp_group" required>
                    <option value="">-- Please select an option --</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </select>
                <span class="iqu-error" data-field="whatsapp_group"></span>
            </div>

            <!-- Referral -->
            <div class="iqu-question">
                <label for="referral">
                    How did you hear about this program?
                    <span class="req">*</span>
                </label>

                <select id="referral" name="referral" required>
                    <option value="">-- Please select an option --</option>
                    <option value="facebook">Facebook</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="linkedin">LinkedIn</option>
                    <option value="youtube">YouTube</option>
                    <option value="website">Website</option>
                    <option value="friend_family">Friend/Family</option>
                    <option value="masjid">Masjid</option>
                </select>
                <div class="iqu-other-wrap iqu-referral-other-wrap" hidden>
                    <input type="text" id="referral_other" name="referral_other" placeholder="Please specify" />
                </div>
                <span class="iqu-error" data-field="referral"></span>
            </div>
        </div>
        <!-- Admission + Payment Layout -->
        <div class="iqu-fee-layout">
            <!-- LEFT: Admission Fee -->
            <div class="iqu-question iqu-fee-left" id="iqu-admission-fee-card">
                <label>Admission Fee (Choose One Option) <span class="req">*</span></label>

                <div class="iqu-radio-list iqu-radio-fee" id="iqu-admission-options">
                    <label class="iqu-radio-label iqu-radio-card">
                        <input type="radio" name="admission_fee" value="50" required>
                        $50 — Standard Enrollment Fee
                        <em>(Recommended) (Already discounted with 75% subsidy)</em>
                    </label>

                    <label class="iqu-radio-label iqu-radio-card">
                        <input type="radio" name="admission_fee" value="30">
                        $30 — Supported Rate
                        <em>(Available for families who may need some financial assistance)</em>
                    </label>

                    <label class="iqu-radio-label iqu-radio-card">
                        <input type="radio" name="admission_fee" value="complimentary">
                        Existing Student of Ilm-ul-Quran USA —
                        <em>Complimentary</em>
                    </label>

                    <label class="iqu-radio-label iqu-radio-card">
                        <input type="radio" name="admission_fee" value="flexible">
                        Flexible / Free Option
                        <em>— Enter any amount you are comfortable paying. If needed, you may write "Requesting Free
                            Enrollment" —
                            no student will be turned away.</em>
                    </label>
                </div>

                <span class="iqu-error" data-field="admission_fee"></span>
            </div>

            <!-- RIGHT: Payment / Custom Area -->
            <div class="iqu-question iqu-fee-right" id="iqu-payment-panel">
                <div id="iqu-payment-methods-wrap" class="iqu-payment-methods-wrap is-disabled">
                    <label>How would you like to pay? <span class="req">*</span></label>
                    <p class="iqu-help">Select your preferred payment option:</p>

                    <fieldset id="iqu-payment-method-fieldset" disabled>
                        <div class="iqu-payment-options">
                            <label class="iqu-radio-label iqu-payment-option" data-provider="zelle">
                                <div class="display-flex-payment">
                                    <input type="radio" name="payment_method" value="zelle">
                                    <span class="iqu-payment-option-media">
                                        <img src="<?php echo esc_url(IQU_ZELLE_LOGO_URL); ?>" alt="Zelle logo"
                                            loading="lazy">
                                    </span>
                                </div>
                                <span class="iqu-payment-option-copy">
                                    <small>Pay directly from your bank app using our Zelle phone or email.</small>
                                </span>
                            </label>

                            <label class="iqu-radio-label iqu-payment-option" data-provider="zeffy">
                                <div class="display-flex-payment">
                                    <input type="radio" name="payment_method" value="zeffy">
                                    <span class="iqu-payment-option-media">
                                        <img src="<?php echo esc_url(IQU_ZEFFY_LOGO_URL); ?>" alt="Zeffy logo"
                                            loading="lazy">
                                    </span>
                                </div>
                                <span class="iqu-payment-option-copy">
                                    <small>Pay online by card or digital wallet through our secure Zeffy page.</small>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <span class="iqu-error" data-field="payment_method"></span>
                </div>

                <!-- Flexible / Free custom field -->
                <div id="iqu-flexible-panel" class="iqu-flexible-panel" hidden>
                    <label for="iqu_flexible_note">Flexible / Free Request <span class="req">*</span></label>
                    <p class="iqu-help">Enter any amount, or write “Requesting Free Enrollment”.</p>
                    <input type="text" id="iqu_flexible_note" name="flexible_fee_note"
                        placeholder='Example: $20 or Requesting Free Enrollment'>
                    <span class="iqu-error" data-field="flexible_fee_note"></span>
                </div>
            </div>
        </div>

        <!-- Hidden fields that will be filled by the Zelle modal -->
        <input type="hidden" name="transaction_id" id="iqu_transaction_id" value="">
        <input type="hidden" name="payment_amount" id="iqu_payment_amount" value="">
        <div class="iqu-zeffy-warning" id="iqu-zeffy-warning" style="display:none">
            <strong>⚠ Please note:</strong> When completing your payment through Zeffy, they may ask for a <em>small
                optional
                contribution</em> to support their platform.
            This is <strong>completely optional</strong> — to skip it, simply
            <strong>select "Other"</strong> from the contribution dropdown and
            <strong>leave the input field empty</strong>.
            Your full payment will be processed normally with <em>no extra charge</em>.
        </div>

        <div class="iqu-submit-area">
            <div class="iqu-alert iqu-server-error" id="iqu-summer-server-error" style="display:none"></div>
            <button type="submit" id="iqu-summer-submit-btn" class="iqu-btn-submit">
                <span class="btn-text">Submit Enrollment</span>
                <span class="btn-loading" style="display:none">Submitting…</span>
            </button>
            <p class="iqu-privacy-note">
                🔒 Your information is kept private. This site is protected by reCAPTCHA v3.
            </p>
        </div>
    </form>
</div>

<!-- ───────────────────────── Zelle Payment Modal ───────────────────────── -->
<div class="iqu-modal" id="iqu-zelle-modal" aria-hidden="true" role="dialog" aria-labelledby="iqu-zelle-title">
    <div class="iqu-modal-backdrop" data-iqu-close></div>
    <div class="iqu-modal-dialog" role="document">
        <button type="button" class="iqu-modal-close" aria-label="Close" data-iqu-close>&times;</button>
        <div class="iqu-modal-header">
            <div class="iqu-modal-icon">💸</div>
            <h3 id="iqu-zelle-title">Complete Your Zelle Payment</h3>
            <p class="iqu-modal-subtitle">Please send your admission fee using the details below, then enter your
                transaction
                ID.</p>
        </div>

        <div class="iqu-modal-body">
            <div class="iqu-zelle-box">
                <div class="iqu-zelle-row">
                    <span class="iqu-zelle-label">Amount</span>
                    <span class="iqu-zelle-val" id="iqu-zelle-amount">$50.00</span>
                </div>
                <div class="iqu-zelle-row">
                    <span class="iqu-zelle-label">Zelle Phone</span>
                    <span class="iqu-zelle-val"><?php echo esc_html(IQU_ZELLE_PHONE); ?></span>
                </div>
                <div class="iqu-zelle-row">
                    <span class="iqu-zelle-label">Zelle Email</span>
                    <span class="iqu-zelle-val"><?php echo esc_html(IQU_ZELLE_EMAIL); ?></span>
                </div>
            </div>

            <div class="iqu-question">
                <label for="iqu_modal_guardian_name">Guardian's Full Name <span class="req">*</span></label>
                <input type="text" id="iqu_modal_guardian_name" placeholder="Enter the guardian's full name"
                    maxlength="150" required>
            </div>
            <div class="iqu-question">
                <label for="iqu_modal_participant_name">Participant's Full Name <span class="req">*</span></label>
                <input type="text" id="iqu_modal_participant_name" placeholder="Enter the participant's full name"
                    maxlength="200" required>
            </div>
            <div class="iqu-question">
                <label for="iqu_modal_txid">Zelle Transaction ID / Confirmation # <span class="req">*</span></label>
                <input type="text" id="iqu_modal_txid" placeholder="Paste the Zelle confirmation number here"
                    maxlength="100" required>
            </div>

            <div class="iqu-modal-error" id="iqu-modal-error" style="display:none"></div>
        </div>

        <div class="iqu-modal-footer">
            <button type="button" class="iqu-btn-secondary" data-iqu-close>Cancel</button>
            <button type="button" class="iqu-btn-submit iqu-btn-compact" id="iqu-modal-confirm">Confirm &amp;
                Submit</button>
        </div>
    </div>
</div>
<?php
        return ob_get_clean();
    }

    // ════════════════════════════════════════════════════
    // AJAX handler
    // ════════════════════════════════════════════════════
    public function handle_ajax_submit(): void
    {
        // CSRF
        if (!check_ajax_referer('iqu_registration_nonce', '_iqu_nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.', 'code' => 'invalid_nonce']);
        }

        // Honeypot
        if (!empty($_POST['iqu_website'])) {
            wp_send_json_success(['message' => 'Thank you! Your registration has been received.']);
        }

        // reCAPTCHA v3
        $token = sanitize_text_field($_POST['iqu_recaptcha_token'] ?? '');
        $verify = IQU_Recaptcha::verify($token, 'iqu_summer_form');
        if (!$verify['success']) {
            wp_send_json_error(['message' => 'Verification failed. Please try again.', 'code' => 'recaptcha_failed']);
        }

        // Validate
        $validator = new IQU_Validator();
        if (!$validator->validate_summer($_POST)) {
            wp_send_json_error([
                'message' => 'Please fix the errors below.',
                'errors' => $validator->get_errors(),
                'code' => 'validation_failed',
            ]);
        }
        $clean = $validator->get_clean();

        // Duplicate email check — scoped to this form_type
        // if (IQU_Database::email_exists($clean['email'], $clean['form_type'])) {
        //   wp_send_json_error([
        //     'message' => 'This email is already registered for the Summer Program at this level. Please use a different email or contact us.',
        //     'errors' => ['email' => 'This email is already registered for this level.'],
        //     'code' => 'duplicate_email',
        //   ]);
        // }

        // Insert
        $reg_id = IQU_Database::insert_registration($clean);
        if (!$reg_id) {
            error_log('[IQU] Summer registration DB insert failed.');
            wp_send_json_error(['message' => 'Enrollment failed due to a server error. Please try again or contact us.', 'code' => 'db_error']);
        }

        // Emails
        IQU_Mailer::send_admin_notification($clean, $reg_id);
        IQU_Mailer::send_student_confirmation($clean);

        // payment_amount — validator miss করলেও directly $_POST থেকে নাও
        $payment_method = !empty($clean['payment_method'])
            ? $clean['payment_method']
            : sanitize_text_field($_POST['payment_method'] ?? '');

        // Flexible + dollar amount case — validator miss করলেও নিশ্চিত করো
        if (
            empty($payment_method) &&
            !empty($clean['admission_fee']) &&
            $clean['admission_fee'] === 'flexible'
        ) {
            $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        }
        // Build response — include a Zeffy redirect URL with prefilled amount
        // Dollar sign সহ যেকোনো value থেকে numeric বের করো
        $raw_amount = !empty($clean['payment_amount'])
            ? $clean['payment_amount']
            : sanitize_text_field($_POST['payment_amount'] ?? 0);

        $payment_amount = (float) preg_replace('/[^0-9.]/', '', (string) $raw_amount);

        $response = [
            'message' => "JazakAllahu Khairan! Your enrollment has been received. We will contact you via WhatsApp within 24–48 hours, in-sha'-Allah.",
            'reg_id' => $reg_id,
            'form' => 'summer',
            'level' => $clean['enrollment_level'],
            'payment' => [
                'method' => $payment_method,
                'amount' => $payment_amount,
                'status' => $clean['payment_status'] ?? '',
            ],
        ];

        if ($payment_method === 'zeffy' && $payment_amount > 0) {
            $response['payment']['zeffy_url'] = add_query_arg('amount', (int) $payment_amount, IQU_ZEFFY_URL);
        }
        
        error_log('[IQU Summer] payment_method=' . $payment_method . ' | payment_amount=' . $payment_amount . ' | clean_amount=' . ($clean['payment_amount'] ?? 'NOT_SET'));
        
        wp_send_json_success($response);
    }
}