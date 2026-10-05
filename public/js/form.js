/**
 * IQU Registration - Frontend JS
 *
 * Handles both the Free Enrollment form and the Summer Program form,
 * including Google reCAPTCHA v3, intl-tel-input phone fields, the
 * Zelle in-page modal, and the Zeffy redirect with prefilled amount.
 *
 * FIXES applied:
 *  1. Phone number Advanced Matching — intlTelInput.getNumber() ব্যবহার করে
 *     international format (+1...) নিশ্চিত করা হয়েছে।
 *  2. Double window.scrollTo — duplicate call সরানো হয়েছে।
 *  3. Redirect conflict — if/else if দিয়ে Zeffy ও Home redirect আলাদা করা হয়েছে।
 *  4. Dead code — getSelectedPaymentMethod() সরানো হয়েছে।
 */
(function ($) {
  "use strict";

  $(function () {
    if (typeof IQU_AJAX === "undefined") {
      console.error("IQU: AJAX config not loaded.");
      return;
    }

    const phoneInstances = new WeakMap();
    let preferredDaySelectionOrder = 0;

    function getRecaptchaToken(action) {
      return new Promise(function (resolve, reject) {
        if (typeof grecaptcha === "undefined" || !grecaptcha.ready) {
          return reject("recaptcha_unavailable");
        }

        grecaptcha.ready(function () {
          grecaptcha
            .execute(IQU_AJAX.recaptcha_site, { action: action })
            .then(resolve, reject);
        });
      });
    }

    const $freeForm = $("#iqu-reg-form");
    if ($freeForm.length) {
      wireFreeForm($freeForm);
    }

    const $summerForm = $("#iqu-summer-reg-form");
    if ($summerForm.length) {
      wireSummerForm($summerForm);
    }

    const $weekendForm = $("#iqu-weekend-reg-form");
    if ($weekendForm.length) {
      wireWeekendForm($weekendForm);
    }

    $(document).on(
      "input change",
      ".iqu-form input, .iqu-form select, .iqu-form textarea",
      function () {
        const name = ($(this).attr("name") || "").replace("[]", "");
        if (!name) {
          return;
        }

        const $form = $(this).closest(".iqu-form");
        showFieldError($form, name, "");
        $(this).removeClass("iqu-invalid");
      },
    );

    function initInternationalPhoneInputs($container) {
      if (typeof window.intlTelInput !== "function") {
        return;
      }

      $container.find('input[type="tel"]').each(function () {
        const input = this;
        if (phoneInstances.has(input)) {
          return;
        }

        const basePlaceholder = input.getAttribute("placeholder");

        const iti = window.intlTelInput(input, {
          utilsScript:
            "https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/utils.js",
          autoPlaceholder: "aggressive",
          countrySearch: true,
          customPlaceholder: function (exampleNumber) {
            return exampleNumber ? exampleNumber : basePlaceholder;
          },
          formatAsYouType: true,
          initialCountry: IQU_AJAX.geoip_country || "us",
          geoIpLookup: function (success) {
            var url =
              IQU_AJAX.ajax_url +
              (IQU_AJAX.ajax_url.indexOf("?") === -1 ? "?" : "&") +
              "action=iqu_geoip";
            fetch(url, { signal: AbortSignal.timeout(5000) })
              .then((r) => r.json())
              .then((d) => success(d.countryCode || "us"))
              .catch(() => success("us"));
          },
          separateDialCode: true,
          strictMode: false,
        });

        phoneInstances.set(input, iti);
        Promise.resolve(iti.promise).catch(function () {
          return null;
        });

        $(input).on("countrychange", function () {
          const name = (input.name || "").replace("[]", "");
          const $form = $(input).closest(".iqu-form");
          if ($form.length) {
            showFieldError($form, name, "");
          }
          $(input).removeClass("iqu-invalid");
        });
      });
    }

    // ── FIX 1: phone number কে intlTelInput থেকে international format-এ নেওয়া ──
    function getInternationalPhone(inputId) {
      const inputEl = document.getElementById(inputId);
      if (!inputEl) return "";
      const iti = phoneInstances.get(inputEl);
      if (iti && typeof iti.getNumber === "function") {
        return iti.getNumber() || $(inputEl).val() || "";
      }
      return $(inputEl).val() || "";
    }

    function wireFreeForm($form) {
      const $btn = $("#iqu-submit-btn");
      const $btnTxt = $btn.find(".btn-text");
      const $btnLoad = $btn.find(".btn-loading");
      const $srvErr = $("#iqu-server-error");

      enhanceFreeFormUI($form);
      initInternationalPhoneInputs($form);
      enhanceCustomSelects($form);

      $form.on("submit", function (e) {
        e.preventDefault();
        clearErrors($form);
        $srvErr.hide().text("");

        if (!basicValidate($form)) {
          scrollToFirstError($form);
          return;
        }

        setLoading($btn, $btnTxt, $btnLoad, true);

        getRecaptchaToken("iqu_free_form")
          .then(function (token) {
            $("#iqu_recaptcha_token").val(token);
            return buildSerializedData($form);
          })
          .then(function (serializedData) {
            submitForm(
              $form,
              "iqu_submit",
              serializedData,
              handleFreeSuccess,
              $srvErr,
              $btn,
              $btnTxt,
              $btnLoad,
            );
          })
          .catch(function (error) {
            setLoading($btn, $btnTxt, $btnLoad, false);
            if (error && error.validation) {
              scrollToFirstError($form);
              return;
            }

            $srvErr
              .text("Verification unavailable. Please reload and try again.")
              .addClass("iqu-alert iqu-alert-error")
              .show();
          });
      });

      function handleFreeSuccess(data) {
        $form
          .closest(".iqu-form-wrapper")
          .find(".iqu-hero, .iqu-intro-card, .iqu-required-note")
          .slideUp(300);

        $form.fadeOut(300, function () {
          $form.replaceWith(
            buildSuccessMarkup({
              title: "JazakAllahu Khairan!",
              subtitle: "Your registration has been received successfully.",
              message: data.message,
              details: [],
            }),
          );
          window.scrollTo({ top: 0, behavior: "smooth" });
        });
      }
    }

    function enhanceFreeFormUI($form) {
      var $timeInput = $form.find("#time_slot");
      if ($timeInput.length) {
        $timeInput
          .addClass("iqu-time-slot-hidden")
          .attr("tabindex", "-1")
          .removeAttr("required");

        $form.find("#iqu_time_start, #iqu_time_end").on("change", function () {
          showFieldError($form, "time_slot", "");
          $(this).removeClass("iqu-invalid");
        });
        $form.find("#iqu_time_slot_timezone").on("change", function () {
          showFieldError($form, "time_slot", "");
          $(this).removeClass("iqu-invalid");
        });
      }

      const $daysInput = $form.find("#days_per_week");
      const $preferredDayInputs = $form.find('input[name="preferred_days[]"]');

      function syncPreferredDayLimit() {
        let limit = parseInt($daysInput.val(), 10);
        if (!Number.isInteger(limit)) {
          limit = 0;
        }

        if (limit < 1) {
          $preferredDayInputs.each(function () {
            $(this)
              .prop("checked", false)
              .prop("disabled", true)
              .removeData("selectedOrder");
          });
          return;
        }

        const checkedInputs = $preferredDayInputs
          .filter(":checked")
          .toArray()
          .sort(function (left, right) {
            return (
              Number($(right).data("selectedOrder") || 0) -
              Number($(left).data("selectedOrder") || 0)
            );
          });

        if (checkedInputs.length > limit) {
          checkedInputs.slice(limit).forEach(function (input) {
            $(input).prop("checked", false).removeData("selectedOrder");
          });
        }

        const selectedCount = $preferredDayInputs.filter(":checked").length;
        $preferredDayInputs.each(function () {
          const $checkbox = $(this);
          const disableUnchecked =
            !$checkbox.is(":checked") && selectedCount >= limit;
          $checkbox.prop("disabled", disableUnchecked);
        });
      }

      $daysInput.on("input change", function () {
        showFieldError($form, "days_per_week", "");
        showFieldError($form, "preferred_days", "");
        $(this).removeClass("iqu-invalid");
        syncPreferredDayLimit();
      });

      $preferredDayInputs.on("change", function () {
        if ($(this).is(":checked")) {
          preferredDaySelectionOrder += 1;
          $(this).data("selectedOrder", preferredDaySelectionOrder);
        } else {
          $(this).removeData("selectedOrder");
        }

        showFieldError($form, "preferred_days", "");
        syncPreferredDayLimit();
      });

      syncPreferredDayLimit();

      // ══════════════════════════════════════════════════
      // 💰 Live tuition engine  +  🎟️ coupon
      // ══════════════════════════════════════════════════
      const pricing = (window.IQU_AJAX && IQU_AJAX.pricing) || null;
      if (!pricing || !pricing.courses) return;

      const $courseInput = $form.find("#course_type");
      const $priceAmount = $form.find("#iqu-price-amount");
      const $priceMeta = $form.find("#iqu-price-meta");
      const $breakdown = $form.find("#iqu-price-breakdown");
      const $origOut = $form.find("#iqu-price-original");
      const $discLabel = $form.find("#iqu-discount-label");
      const $discOut = $form.find("#iqu-discount-amount");
      const $finalOut = $form.find("#iqu-price-final");
      const $calcField = $form.find("#calculated_amount");
      const $minNote = $form.find(".iqu-min-days-note");

      const $couponInput = $form.find("#coupon_code");
      const $couponId = $form.find("#coupon_id");
      const $couponApply = $form.find("#iqu-coupon-apply");
      const $couponRemove = $form.find("#iqu-coupon-remove");
      const $couponMsg = $form.find("#iqu-coupon-feedback");
      const $declaration = $form.find("#iqu-declaration");
      const $declText = $form.find("#iqu-declaration-text");
      const $declCheck = $form.find("#zakat_declaration");

      // 🎁 স্পেশাল ডিসকাউন্ট
      const $priceStrike = $form.find("#iqu-price-strike");
      const $specialWrap = $form.find("#iqu-special-wrap");
      const $specialBtn = $form.find("#iqu-special-btn");
      const $specialApplied = $form.find("#iqu-special-applied");
      const $specialPitch = $form.find("#iqu-special-pitch-detail");
      const $specialSaved = $form.find("#iqu-special-saved");
      const $specialRemove = $form.find("#iqu-special-remove");
      const $specialField = $form.find("#special_discount");
      const $couponWrap = $form.find("#iqu-coupon-wrap");

      // প্রয়োগ হওয়া কুপনের অবস্থা
      let appliedCoupon = null;

      // ইউজার স্পেশাল ডিসকাউন্ট নিতে চেয়েছে কি না
      let specialWanted = false;

      function money(n) {
        return "$" + Number(n).toFixed(2).replace(/\.00$/, "");
      }

      /** বর্তমান কোর্স + দিন থেকে হিসাব — সার্ভারের একই ডেটা */
      function currentQuote() {
        const course = $courseInput.val();
        const days = parseInt($daysInput.val(), 10);
        if (!course || !pricing.courses[course]) return null;
        if (!Number.isInteger(days)) return null;
        const entry = pricing.courses[course].byDays[days];
        return entry ? { course: course, days: days, entry: entry } : null;
      }

      /**
       * কোর্স বদলালে দিনের ড্রপডাউনে অনুমোদিত অপশন সীমিত করো।
       * হেফজে ৩ দিনের নিচে সিলেক্টই করা যাবে না।
       */
      function syncDayOptions() {
        const course = $courseInput.val();
        const conf = course ? pricing.courses[course] : null;
        const minDays = conf ? conf.minDays : 1;

        $daysInput.find("option").each(function () {
          const v = parseInt(this.value, 10);
          if (!Number.isInteger(v)) return;
          $(this).prop("disabled", v < minDays);
        });

        // বর্তমান নির্বাচন এখন অবৈধ হলে পরিষ্কার করো
        const cur = parseInt($daysInput.val(), 10);
        if (Number.isInteger(cur) && cur < minDays) {
          $daysInput.val("");
          $daysInput.trigger("wk:sync"); // কাস্টম ড্রপডাউনের লেখা রিফ্রেশ
          syncPreferredDayLimit();
        }

        if (conf && minDays > 1) {
          $minNote
            .text(
              "⚠️ " +
                conf.label +
                " requires a minimum of " +
                minDays +
                " classes per week.",
            )
            .prop("hidden", false);
        } else {
          $minNote.prop("hidden", true).text("");
        }
      }

      /** এই কোর্স + দিনে স্পেশাল ডিসকাউন্ট আছে কি না */
      function specialFor(quote) {
        if (!quote) return 0;
        const amt = Number(quote.entry.special || 0);
        return amt > 0 && amt < Number(quote.entry.amount) ? amt : 0;
      }

      /** স্পেশাল ডিসকাউন্টের বাটন ও ব্যাজের অবস্থা ঠিক করো */
      function syncSpecialUI(quote) {
        const special = specialFor(quote);

        // যোগ্যতা না থাকলে বা কুপন প্রয়োগ থাকলে — সবকিছু বন্ধ
        if (!special || appliedCoupon) {
          specialWanted = false;
          $specialField.val(0);
          $specialWrap.prop("hidden", true);
          $specialApplied.prop("hidden", true);
          $couponWrap.prop("hidden", false);
          return 0;
        }

        if (specialWanted) {
          // ছাড় প্রয়োগ — বাটন লুকাও, কুপনের পুরো ব্লকও লুকাও
          $specialField.val(1);
          $specialWrap.prop("hidden", true);
          $specialApplied.prop("hidden", false);
          $specialSaved.text(money(quote.entry.amount - special));
          $couponWrap.prop("hidden", true);
        } else {
          // ছাড় নেওয়ার আগে — কী পাবে সেটা স্পষ্ট করে দেখাও
          const base = Number(quote.entry.amount);
          const off = base - special;
          const pct = Math.round((off / base) * 100);

          $specialPitch.text(
            "Save " +
              money(off) +
              " (" +
              pct +
              "% off) — pay " +
              money(special) +
              " a month instead of " +
              money(base) +
              ".",
          );

          $specialField.val(0);
          $specialWrap.prop("hidden", false);
          $specialApplied.prop("hidden", true);
          $couponWrap.prop("hidden", false);
        }

        return special;
      }

      /** প্রাইস ডিসপ্লে রিফ্রেশ */
      function refreshPrice() {
        const quote = currentQuote();

        if (!quote) {
          specialWanted = false;
          $specialField.val(0);
          $specialWrap.prop("hidden", true);
          $specialApplied.prop("hidden", true);
          $couponWrap.prop("hidden", false);

          $priceStrike.prop("hidden", true);
          $priceAmount.text("$0");
          $priceMeta.text(
            "Select your course and class days to see your monthly tuition.",
          );
          $breakdown.prop("hidden", true);
          $calcField.val(0);
          return;
        }

        const base = quote.entry.amount;
        $calcField.val(base);

        const special = syncSpecialUI(quote);

        const meta =
          quote.entry.classes +
          " classes per month × " +
          money(quote.entry.rate) +
          " per class";

        if (appliedCoupon) {
          // কুপন প্রয়োগ থাকলে ভাঙা হিসাব দেখাও
          $priceStrike.text(appliedCoupon.original_formatted).prop("hidden", false);
          $priceAmount.text(appliedCoupon.final_formatted);
          $priceMeta.text(meta);
          $origOut.text(appliedCoupon.original_formatted);
          $discOut.text("−" + appliedCoupon.discount_formatted);
          $discLabel.text(
            Math.round(appliedCoupon.discount_percent) +
              "% Zakat scholarship",
          );
          $finalOut.text(appliedCoupon.final_formatted);
          $breakdown.prop("hidden", false);
        } else if (specialWanted && special) {
          // 🎁 স্পেশাল ডিসকাউন্ট প্রয়োগ
          $priceStrike.text(money(base)).prop("hidden", false);
          $priceAmount.text(money(special));
          $priceMeta.text(meta);
          $origOut.text(money(base));
          $discOut.text("−" + money(base - special));
          $discLabel.text("Special discount");
          $finalOut.text(money(special));
          $breakdown.prop("hidden", false);
        } else {
          $priceStrike.prop("hidden", true);
          $priceAmount.text(money(base));
          $priceMeta.text(meta + " · per month");
          $breakdown.prop("hidden", true);
        }
      }

      /**
       * কোর্স বা দিন বদলালে আগের কুপন বাতিল —
       * নইলে সস্তা কোর্সে দামি কোর্সের ছাড় থেকে যেত।
       */
      function invalidateCoupon(reason) {
        if (!appliedCoupon) return;
        appliedCoupon = null;
        $couponId.val(0);
        $couponInput.prop("readonly", false);
        $couponApply.prop("hidden", false);
        $couponRemove.prop("hidden", true);
        $declaration.prop("hidden", true);
        $declCheck.prop("checked", false);
        showFieldError($form, "zakat_declaration", "");
        setCouponMsg(reason || "", "warn");
      }

      function setCouponMsg(text, kind) {
        $couponMsg
          .removeClass("is-ok is-err is-warn")
          .addClass(
            kind === "ok" ? "is-ok" : kind === "warn" ? "is-warn" : "is-err",
          )
          .text(text || "");
      }

      function couponLoading(on) {
        $couponApply.prop("disabled", on);
        $couponApply.find(".iqu-coupon-btn-text").prop("hidden", on);
        $couponApply.find(".iqu-coupon-btn-loading").prop("hidden", !on);
      }

      // ── 🎁 স্পেশাল ডিসকাউন্ট প্রয়োগ ──
      $specialBtn.on("click", function () {
        const quote = currentQuote();
        if (!specialFor(quote)) return;

        specialWanted = true;

        // কুপনের ঘর পরিষ্কার — দুটো ছাড় একসাথে চলবে না
        $couponInput.val("");
        setCouponMsg("", "warn");

        refreshPrice();
      });

      // ── স্পেশাল ডিসকাউন্ট সরানো ──
      $specialRemove.on("click", function () {
        specialWanted = false;
        refreshPrice();
      });

      // ── কুপন প্রয়োগ ──
      $couponApply.on("click", function () {
        const code = ($couponInput.val() || "").trim();
        if (!code) {
          setCouponMsg("Please enter your coupon code.", "err");
          return;
        }

        const quote = currentQuote();
        if (!quote) {
          setCouponMsg(
            "Please select your course and class days first.",
            "err",
          );
          return;
        }

        couponLoading(true);
        setCouponMsg("", "warn");

        $.post(IQU_AJAX.ajax_url, {
          action: "iqu_apply_coupon",
          _iqu_nonce: IQU_AJAX.nonce,
          coupon_code: code,
          course_type: quote.course,
          days_per_week: quote.days,
        })
          .done(function (res) {
            if (res && res.success) {
              appliedCoupon = res.data;
              $couponId.val(res.data.coupon_id);
              $couponInput.val(res.data.code).prop("readonly", true);
              $couponApply.prop("hidden", true);
              $couponRemove.prop("hidden", false);

              $declText.text(res.data.declaration_text);
              $declaration.prop("hidden", false);
              $declCheck.prop("checked", false);

              setCouponMsg(res.data.message, "ok");
              refreshPrice();
            } else {
              setCouponMsg(
                (res && res.data && res.data.message) ||
                  "This coupon code is not valid.",
                "err",
              );
            }
          })
          .fail(function () {
            setCouponMsg(
              "Could not verify the coupon right now. Please try again.",
              "err",
            );
          })
          .always(function () {
            couponLoading(false);
          });
      });

      // ── কুপন সরানো ──
      $couponRemove.on("click", function () {
        $couponInput.val("");
        invalidateCoupon("");
        refreshPrice();
      });

      // ── চেকবক্সে ক্লিক করলে লেখাটা লাল হবে ──
      $declCheck.on("change", function () {
        $declaration.toggleClass("is-confirmed", this.checked);
        showFieldError($form, "zakat_declaration", "");
      });

      // ── কোর্স / দিন বদল ──
      $courseInput.on("change", function () {
        showFieldError($form, "course_type", "");
        $(this).removeClass("iqu-invalid");
        syncDayOptions();
        invalidateCoupon(
          "Your course changed, so the coupon was removed. Please apply it again.",
        );
        refreshPrice();
      });

      $daysInput.on("change", function () {
        invalidateCoupon(
          "Your class days changed, so the coupon was removed. Please apply it again.",
        );
        refreshPrice();
      });

      // ── সাবমিটের আগে শেষ চেক ──
      // ⚠️ এই হ্যান্ডলারটি wireFreeForm()-এর AJAX হ্যান্ডলারের *আগে* বাঁধা হয়
      //    (enhanceFreeFormUI আগে কল হয়)। তাই একই এলিমেন্টের পরের হ্যান্ডলার
      //    থামাতে stopImmediatePropagation() লাগে — শুধু return false যথেষ্ট নয়।
      $form.on("submit", function (e) {
        if (appliedCoupon && !$declCheck.is(":checked")) {
          e.preventDefault();
          e.stopImmediatePropagation();

          showFieldError(
            $form,
            "zakat_declaration",
            "Please confirm this declaration to continue.",
          );
          $declaration.addClass("is-missing");
          $declaration[0].scrollIntoView({
            behavior: "smooth",
            block: "center",
          });
          return false;
        }
        return true;
      });

      syncDayOptions();
      refreshPrice();
    }

    function wireSummerForm($form) {
      const $btn = $("#iqu-summer-submit-btn");
      const $btnTxt = $btn.find(".btn-text");
      const $btnLoad = $btn.find(".btn-loading");
      const $srvErr = $("#iqu-summer-server-error");
      const $modal = $("#iqu-zelle-modal");
      const $mName = $("#iqu_modal_guardian_name");
      const $mPart = $("#iqu_modal_participant_name");
      const $mTx = $("#iqu_modal_txid");
      const $mAmount = $("#iqu-zelle-amount");
      const $mError = $("#iqu-modal-error");

      enhanceSummerFormUI($form);
      initInternationalPhoneInputs($form);
      enhanceCustomSelects($form);

      const summerFormEl = $form.get(0);
      const admissionInputs = summerFormEl.querySelectorAll(
        'input[name="admission_fee"]',
      );
      const paymentFieldset = summerFormEl.querySelector(
        "#iqu-payment-method-fieldset",
      );
      const paymentWrap = summerFormEl.querySelector(
        "#iqu-payment-methods-wrap",
      );
      const flexiblePanel = summerFormEl.querySelector("#iqu-flexible-panel");
      const flexibleInput = summerFormEl.querySelector("#iqu_flexible_note");
      const paymentWrapOriginalParent = paymentWrap.parentNode;
      const paymentWrapNextSibling = paymentWrap.nextSibling;

      function restorePaymentWrap() {
        if (paymentWrap.parentNode !== paymentWrapOriginalParent) {
          paymentWrapOriginalParent.insertBefore(
            paymentWrap,
            paymentWrapNextSibling,
          );
        }
      }

      function movePaymentWrapBelowFlexible() {
        if (
          flexiblePanel &&
          paymentWrap.previousElementSibling !== flexiblePanel
        ) {
          flexiblePanel.parentNode.insertBefore(
            paymentWrap,
            flexiblePanel.nextSibling,
          );
        }
      }

      const paymentInputs = summerFormEl.querySelectorAll(
        'input[name="payment_method"]',
      );
      const zeffyWarning = summerFormEl.querySelector("#iqu-zeffy-warning");
      const paymentOptionCards = summerFormEl.querySelectorAll(
        ".iqu-payment-option",
      );

      function getSelectedAdmissionFee() {
        const selected = summerFormEl.querySelector(
          'input[name="admission_fee"]:checked',
        );
        return selected ? selected.value : "";
      }

      // ── FIX 4: Dead code getSelectedPaymentMethod() সরানো হয়েছে ──

      function clearPaymentSelection() {
        paymentInputs.forEach(function (input) {
          input.checked = false;
        });

        paymentOptionCards.forEach(function (card) {
          card.classList.remove("is-selected");
        });

        if (zeffyWarning) {
          zeffyWarning.style.display = "none";
        }

        showFieldError($form, "payment_method", "");
      }

      function updatePaymentCardState() {
        paymentOptionCards.forEach(function (card) {
          const input = card.querySelector('input[type="radio"]');
          card.classList.toggle("is-selected", !!(input && input.checked));
        });
      }

      function syncAdmissionPaymentUI() {
        const fee = getSelectedAdmissionFee();
        const isPaidOption = fee === "50" || fee === "30";
        const isFlexibleOption = fee === "flexible";
        const isComplimentary = fee === "complimentary";

        if (isPaidOption) {
          restorePaymentWrap();
          paymentFieldset.disabled = false;
          paymentWrap.classList.remove("is-disabled");
          paymentWrap.hidden = false;

          flexiblePanel.hidden = true;
          if (flexibleInput) {
            flexibleInput.value = "";
          }
        } else if (isComplimentary) {
          restorePaymentWrap();
          paymentFieldset.disabled = true;
          paymentWrap.classList.add("is-disabled");
          paymentWrap.hidden = false;
          clearPaymentSelection();

          flexiblePanel.hidden = true;
          if (flexibleInput) {
            flexibleInput.value = "";
          }
        } else if (isFlexibleOption) {
          restorePaymentWrap();
          paymentFieldset.disabled = true;
          paymentWrap.classList.add("is-disabled");
          paymentWrap.hidden = true;

          clearPaymentSelection();

          flexiblePanel.hidden = false;
        } else {
          restorePaymentWrap();
          paymentFieldset.disabled = true;
          paymentWrap.classList.add("is-disabled");
          paymentWrap.hidden = false;

          clearPaymentSelection();

          flexiblePanel.hidden = true;
          if (flexibleInput) {
            flexibleInput.value = "";
          }
        }
      }

      admissionInputs.forEach(function (input) {
        input.addEventListener("change", function () {
          syncAdmissionPaymentUI();
        });
      });

      paymentInputs.forEach(function (input) {
        input.addEventListener("change", function () {
          updatePaymentCardState();

          if (zeffyWarning) {
            zeffyWarning.style.display =
              input.checked && input.value === "zeffy" ? "block" : "none";
          }

          showFieldError($form, "payment_method", "");
        });
      });

      if (flexibleInput) {
        flexibleInput.addEventListener("input", function () {
          showFieldError($form, "flexible_fee_note", "");
          flexibleInput.classList.remove("iqu-invalid");

          const val = flexibleInput.value.trim();
          const isDollarAmount = /^\$?\d+(\.\d{1,2})?$/.test(val);
          const isFreeRequest = /requesting\s*free/i.test(val);

          if (isDollarAmount && !isFreeRequest) {
            movePaymentWrapBelowFlexible();
            paymentFieldset.disabled = false;
            paymentWrap.classList.remove("is-disabled");
            paymentWrap.hidden = false;
          } else {
            paymentFieldset.disabled = true;
            paymentWrap.classList.add("is-disabled");
            paymentWrap.hidden = true;
            clearPaymentSelection();
          }
        });
      }

      syncAdmissionPaymentUI();
      updatePaymentCardState();

      $form.on("submit", function (e) {
        e.preventDefault();
        clearErrors($form);
        $srvErr.hide().text("");

        if (!basicValidate($form)) {
          scrollToFirstError($form);
          return;
        }

        const fee = $form.find('[name="admission_fee"]:checked').val();
        const method = $form.find('[name="payment_method"]:checked').val();

        const flexVal = $form.find("#iqu_flexible_note").val().trim();
        const flexAmount =
          fee === "flexible" && /^\$?(\d+(\.\d{1,2})?)$/.test(flexVal)
            ? flexVal.replace("$", "")
            : null;
        const effectiveFee = flexAmount || fee;

        const numericFee = parseFloat(String(effectiveFee).replace("$", ""));
        $form
          .find("#iqu_payment_amount")
          .val(isNaN(numericFee) ? "" : numericFee.toFixed(2));

        if (
          (effectiveFee === "50" || effectiveFee === "30" || flexAmount) &&
          method === "zelle"
        ) {
          openZelleModal(effectiveFee);
          return;
        }

        proceedSubmit();
      });

      function openZelleModal(fee) {
        $form.data("iqu-payment-zelle", true);
        $mName.val($("#guardian_name").val() || "");
        $mPart.val(
          (
            ($("#summer_first_name").val() || "") +
            " " +
            ($("#summer_last_name").val() || "")
          ).trim(),
        );
        $mTx.val("");
        $mError.hide().text("");

        const numericFee = parseFloat(String(fee).replace("$", ""));
        $mAmount.text("$" + (isNaN(numericFee) ? fee : numericFee.toFixed(2)));

        $modal.addClass("is-open").attr("aria-hidden", "false");
        document.body.style.overflow = "hidden";
      }

      function closeZelleModal() {
        $modal.removeClass("is-open").attr("aria-hidden", "true");
        document.body.style.overflow = "";
      }

      $modal.on("click", "[data-iqu-close]", closeZelleModal);
      $(document).on("keydown", function (e) {
        if (e.key === "Escape" && $modal.hasClass("is-open")) {
          closeZelleModal();
        }
      });

      $("#iqu-modal-confirm").on("click", function () {
        const guardianName = $mName.val().trim();
        const participantName = $mPart.val().trim();
        const transactionId = $mTx.val().trim();

        if (!guardianName || !participantName || !transactionId) {
          $mError
            .text("Please fill in all three fields before confirming.")
            .show();
          return;
        }

        $("#iqu_transaction_id").val(transactionId);
        $("#guardian_name").val(guardianName);
        closeZelleModal();
        proceedSubmit();
      });

      function proceedSubmit() {
        setLoading($btn, $btnTxt, $btnLoad, true);

        getRecaptchaToken("iqu_summer_form")
          .then(function (token) {
            $("#iqu_summer_recaptcha_token").val(token);
            return buildSerializedData($form);
          })
          .then(function (serializedData) {
            submitForm(
              $form,
              "iqu_summer_submit",
              serializedData,
              handleSummerSuccess,
              $srvErr,
              $btn,
              $btnTxt,
              $btnLoad,
            );
          })
          .catch(function (error) {
            setLoading($btn, $btnTxt, $btnLoad, false);
            if (error && error.validation) {
              scrollToFirstError($form);
              return;
            }

            $srvErr
              .text("Verification unavailable. Please reload and try again.")
              .addClass("iqu-alert iqu-alert-error")
              .show();
          });
      }

      function handleSummerSuccess(data) {
        const payment = data.payment || {};
        const details = [];

        if (payment.method) {
          details.push({
            label: "Payment Method",
            value: payment.method.toUpperCase(),
          });
        }
        if (payment.amount) {
          details.push({
            label: "Amount",
            value: "$" + Number(payment.amount).toFixed(2),
          });
        }
        if (data.reg_id) {
          details.push({ label: "Reference #", value: data.reg_id });
        }

        $form
          .closest(".iqu-form-wrapper")
          .find(".sc-hero, .sc-body, .iqu-required-note")
          .slideUp(300);

        const $placeholder = $('<div id="iqu-sp-placeholder"></div>').css({
          minHeight: "600px",
          visibility: "hidden",
        });
        $form.after($placeholder);

        $form.fadeOut(300, function () {
          if (!document.getElementById("iqu-success-popup-style")) {
            $(`<style id="iqu-success-popup-style">
        @keyframes iqu-fade-in   { from { opacity:0 }               to { opacity:1 } }
        @keyframes iqu-slide-up  { from { opacity:0; transform:translateY(28px) } to { opacity:1; transform:translateY(0) } }
        @keyframes iqu-check-in  { from { opacity:0; transform:scale(.5) } to { opacity:1; transform:scale(1) } }
        @keyframes iqu-spin-ccw  { to   { transform:rotate(360deg) } }
        @keyframes iqu-progress  { from { stroke-dashoffset:251 }   to { stroke-dashoffset:0 } }

        .iqu-sp-overlay {
          position: fixed; inset: 0;
          background: rgba(8, 18, 30, 0.72);
          backdrop-filter: blur(6px);
          display: flex; align-items: center; justify-content: center;
          z-index: 99999; padding: 20px;
          animation: iqu-fade-in .3s ease;
        }
        .iqu-sp-card {
          background: #fff;
          border-radius: 24px;
          padding: 40px 36px 32px;
          max-width: 460px; width: 100%;
          text-align: center;
          animation: iqu-slide-up .38s cubic-bezier(.22,1,.36,1);
          overflow: hidden;
          position: relative;
        }
        .iqu-sp-check-wrap {
          width: 88px; height: 88px;
          border-radius: 50%;
          background: #eaf6ec;
          border: 2px solid #27ae60;
          display: flex; align-items: center; justify-content: center;
          margin: 0 auto 20px;
          animation: iqu-check-in .45s cubic-bezier(.34,1.56,.64,1) .15s both;
        }
        .iqu-sp-check-wrap svg { display: block; }
        .iqu-sp-title {
          color: #1a3a52;
          font-size: 22px; font-weight: 700;
          margin: 0 0 6px; line-height: 1.3;
        }
        .iqu-sp-sub {
          color: #4a6275;
          font-size: 14.5px; line-height: 1.6;
          margin: 0 0 6px;
        }
        .iqu-sp-msg {
          color: #555;
          font-size: 13.5px; line-height: 1.6;
          margin: 0 0 22px;
        }
        .iqu-sp-details {
          background: #f5f8fc;
          border: 1px solid #d6e4f0;
          border-radius: 14px;
          padding: 16px 20px;
          margin-bottom: 20px;
          text-align: left;
        }
        .iqu-sp-details-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 13.5px;
          padding: 5px 0;
          border-bottom: 1px solid #e5edf5;
        }
        .iqu-sp-details-row:last-child { border-bottom: none; }
        .iqu-sp-detail-k { color: #6b8299; font-weight: 500; }
        .iqu-sp-detail-v { color: #1a3a52; font-weight: 700; }
        .iqu-sp-contact a { color: #1a5276; text-decoration: none; font-weight: 600; }

        .iqu-sp-redirect {
          background: #edf4ff;
          border: 1px solid #c5d9f0;
          border-radius: 12px;
          padding: 14px 16px;
          margin-bottom: 18px;
          display: flex; align-items: center; gap: 12px;
        }
        .iqu-sp-redirect-spinner {
          flex-shrink: 0;
          animation: iqu-spin-ccw .9s linear infinite;
        }
        .iqu-sp-redirect-text { text-align: left; }
        .iqu-sp-redirect-text strong { display: block; color: #1a5276; font-size: 13px; font-weight: 700; margin-bottom: 2px; }
        .iqu-sp-redirect-text a { color: #1a5276; font-size: 12px; text-decoration: underline; }
      </style>`).appendTo("head");
          }

          const detailsHTML = details.length
            ? details
                .map(
                  (d) =>
                    `<div class="iqu-sp-details-row">
            <span class="iqu-sp-detail-k">${d.label}</span>
            <span class="iqu-sp-detail-v">${d.value}</span>
          </div>`,
                )
                .join("")
            : "";

          const zeffyHTML = payment.zeffy_url
            ? `<div class="iqu-sp-redirect">
          <svg class="iqu-sp-redirect-spinner" width="28" height="28" viewBox="0 0 44 44">
            <circle cx="22" cy="22" r="18" fill="none" stroke="#d0e4f5" stroke-width="4"/>
            <path d="M22 4 A18 18 0 0 1 40 22" fill="none" stroke="#1a5276"
                  stroke-width="4" stroke-linecap="round"/>
          </svg>
          <div class="iqu-sp-redirect-text">
            <strong>Redirecting to Zeffy for payment in 5 seconds…</strong>
            <a href="${payment.zeffy_url}">Not redirected? Click here</a>
          </div>
              </div>`
            : "";

          const isZelle =
            (payment.method && payment.method.toLowerCase() === "zelle") ||
            !!$form.data("iqu-payment-zelle");

          const isComplimentary =
            $form.find('[name="admission_fee"]:checked').val() ===
            "complimentary";

          const shouldRedirectHome = isZelle || isComplimentary;

          const zelleHTML = shouldRedirectHome
            ? `<div class="iqu-sp-redirect">
              <svg class="iqu-sp-redirect-spinner" width="28" height="28" viewBox="0 0 44 44">
                <circle cx="22" cy="22" r="18" fill="none" stroke="#d0e4f5" stroke-width="4"/>
                <path d="M22 4 A18 18 0 0 1 40 22" fill="none" stroke="#1a5276"
                      stroke-width="4" stroke-linecap="round"/>
              </svg>
              <div class="iqu-sp-redirect-text">
                <strong>Redirecting to homepage in 5 seconds…</strong>
                <a href="/">Not redirected? Click here</a>
              </div>
              </div>`
            : "";

          const $overlay = $(`
      <div class="iqu-sp-overlay" role="dialog" aria-modal="true" aria-label="Registration Confirmed">
        <div class="iqu-sp-card">
          <div class="iqu-sp-check-wrap">
            <svg width="44" height="44" viewBox="0 0 44 44" fill="none">
              <path d="M10 23 L19 32 L34 14"
              stroke="#1e8449" stroke-width="4.5"
              stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>

          <p class="iqu-sp-title">JazakAllahu Khairan!</p>
          <p class="iqu-sp-sub">Your Summer Program enrollment has been received.</p>
          <p class="iqu-sp-msg">${data.message || ""}</p>

          ${detailsHTML ? `<div class="iqu-sp-details">${detailsHTML}</div>` : ""}

          ${zeffyHTML}
          ${zelleHTML}

          <div class="iqu-sp-contact">
            <strong>Need help?</strong>
            ${IQU_AJAX.contact_phone}
            · <a href="mailto:${IQU_AJAX.contact_email}">${IQU_AJAX.contact_email}</a>
          </div>

        </div>
      </div>
    `);

          $overlay.appendTo("body");
          const cardHeight =
            $overlay.find(".iqu-sp-card").outerHeight(true) || 600;
          $placeholder.css("minHeight", cardHeight + 80 + "px");

          // ── FIX 2: duplicate scrollTo সরানো হয়েছে ──
          window.scrollTo({ top: 0, behavior: "smooth" });

          // ── FIX 3: if/else if দিয়ে redirect conflict ঠিক করা হয়েছে ──
          if (payment.zeffy_url) {
            setTimeout(function () {
              window.location.href = payment.zeffy_url;
            }, 5000);
          } else if (shouldRedirectHome) {
            setTimeout(function () {
              window.location.href = "/";
            }, 5000);
          }
        });
      }
    }

    function enhanceSummerFormUI($form) {
      const $ageInput = $form.find("#summer_age");
      const $ageHelp = $form.find("#iqu-summer-age-help");
      const $levelSelect = $form.find("#enrollment_level");

      const $referralSelect = $form.find("#referral");
      const $referralOtherWrap = $form.find(".iqu-referral-other-wrap");
      const $referralOtherInput = $form.find("#referral_other");

      function syncAgeRules() {
        const level = $levelSelect.val();
        let minAge = 5;
        let maxAge = 15;
        let helpText = "Enter the student's current age.";

        if (level === "level1") {
          minAge = 5;
          maxAge = 10;
          helpText = "Level 1 is for students ages 5 to 10.";
        } else if (level === "level2") {
          minAge = 11;
          maxAge = 15;
          helpText = "Level 2 is for students ages 11 to 15.";
        }

        // basicValidate-এর পুরনো min/max চেকটা এখনও এগুলোই পড়ে
        $ageInput.attr("min", minAge).attr("max", maxAge);
        $ageHelp.text(helpText);

        // 🎂 বয়সের অপশনগুলো লেভেল অনুযায়ী নতুন করে বানাও
        const prev = $ageInput.val();
        const placeholder = $ageInput.find("option").first().text();

        $ageInput.empty();
        $("<option></option>").val("").text(placeholder).appendTo($ageInput);

        for (let a = minAge; a <= maxAge; a++) {
          $("<option></option>")
            .val(a)
            .text(a + " years")
            .appendTo($ageInput);
        }

        // আগের বয়স নতুন পরিসরে থাকলে রেখে দাও, নইলে খালি
        const keep = Number(prev);
        $ageInput.val(keep >= minAge && keep <= maxAge ? String(keep) : "");

        // কাস্টম ড্রপডাউনের লেখা রিফ্রেশ
        $ageInput.trigger("wk:sync");
      }

      function toggleOtherField($select, $wrap, $input, errorFieldName) {
        const selected = $select.val();
        const showOther = selected === "other";

        $wrap.prop("hidden", !showOther);

        if (showOther) {
          $input.attr("required", "required");
        } else {
          $input.removeAttr("required");
          $input.val("").removeClass("iqu-invalid");
          showFieldError($form, errorFieldName, "");
        }
      }

      $form.on("change", "#enrollment_level", function () {
        showFieldError($form, "age", "");
        showFieldError($form, "enrollment_level", "");
        $ageInput.removeClass("iqu-invalid");
        $(this).removeClass("iqu-invalid");
        syncAgeRules();
      });

      $form.on("change", "#referral", function () {
        showFieldError($form, "referral", "");
        showFieldError($form, "referral_other", "");
        $(this).removeClass("iqu-invalid");

        toggleOtherField(
          $referralSelect,
          $referralOtherWrap,
          $referralOtherInput,
          "referral_other",
        );
      });

      $referralOtherInput.on("input", function () {
        showFieldError($form, "referral_other", "");
        $(this).removeClass("iqu-invalid");
      });

      syncAgeRules();

      $form.on("change", "#whatsapp_group", function () {
        showFieldError($form, "whatsapp_group", "");
        $(this).removeClass("iqu-invalid");
      });

      toggleOtherField(
        $referralSelect,
        $referralOtherWrap,
        $referralOtherInput,
        "referral_other",
      );
    }

    // ══════════════════════════════════════════════════
    // 🗓️ WEEKEND ILM PROGRAM  (সম্পূর্ণ ফ্রি — কোনো পেমেন্ট ধাপ নেই)
    // ══════════════════════════════════════════════════
    function wireWeekendForm($form) {
      const $btn = $("#wk-submit-btn");
      const $btnTxt = $btn.find(".btn-text");
      const $btnLoad = $btn.find(".btn-loading");
      const $srvErr = $("#wk-server-error");

      initInternationalPhoneInputs($form);
      enhanceCustomSelects($form);

      $form.on("submit", function (e) {
        e.preventDefault();
        clearErrors($form);
        $srvErr.hide().text("");

        if (!basicValidate($form)) {
          scrollToFirstError($form);
          return;
        }

        const status = $("#wk_student_status").val();

        if (!status) {
          showFieldError(
            $form,
            "student_status",
            "Please tell us whether your child already studies with Ilm-ul-Quran USA.",
          );
          scrollToFirstError($form);
          return;
        }

        if (!$("#wk_consent").is(":checked")) {
          showFieldError(
            $form,
            "wk_consent",
            "Please confirm the class timing before submitting.",
          );
          scrollToFirstError($form);
          return;
        }

        setLoading($btn, $btnTxt, $btnLoad, true);

        getRecaptchaToken("iqu_weekend_form")
          .then(function (token) {
            $("#iqu_weekend_recaptcha_token").val(token);
            return buildSerializedData($form);
          })
          .then(function (serializedData) {
            submitForm(
              $form,
              "iqu_weekend_submit",
              serializedData,
              handleWeekendSuccess,
              $srvErr,
              $btn,
              $btnTxt,
              $btnLoad,
            );
          })
          .catch(function (error) {
            setLoading($btn, $btnTxt, $btnLoad, false);
            if (error && error.validation) {
              scrollToFirstError($form);
              return;
            }
            $srvErr
              .text("Verification unavailable. Please reload and try again.")
              .addClass("iqu-alert iqu-alert-error")
              .show();
          });
      });

      function handleWeekendSuccess() {
        const url = new URL(window.location.href);
        url.searchParams.set("iqu_submitted", "weekend");
        window.location.href = url.toString();
      }
    }

    /**
     * 🎨 কাস্টম ড্রপডাউন — তিনটি ফর্মের সব <select>-এ একই চেহারা।
     *
     * নেটিভ <select>-টা ফর্মেই থেকে যায় (serializeArray, basicValidate,
     * showFieldErrors — সবই ওটার উপর নির্ভরশীল), শুধু চোখের আড়ালে যায়।
     * তার পাশে একটা লিস্টবক্স আঁকা হয় যেটা max-height দিয়ে ৫ সারিতে
     * আটকানো — নেটিভ popup-এর উচ্চতা ব্রাউজার ঠিক করে, CSS দিয়ে বাঁধা যায় না।
     *
     * লিস্টটা প্রতিবার খোলার সময় নতুন করে তৈরি হয়, তাই কোর্স বদলালে
     * যে option গুলো disabled হয় (হেফজে ৩ দিনের নিচে) সেটাও ঠিক দেখায়।
     */
    function enhanceCustomSelects($form) {
      $form.find("select").each(function () {
        buildCustomSelect(this);
      });
    }

    function buildCustomSelect(select) {
      if (!select || select.multiple) return;
      if (select.dataset.wkReady === "1") return;
      if ($(select).closest(".iti").length) return; // ফোন ফিল্ডের নিজস্ব UI
      select.dataset.wkReady = "1";

      const $select = $(select);
      const $wrap = $('<div class="wk-select"></div>');
      const $btn = $(
        '<button type="button" class="wk-select-btn" aria-haspopup="listbox" aria-expanded="false">' +
          '<span class="wk-select-value"></span>' +
          '<span class="wk-select-caret" aria-hidden="true"></span>' +
          "</button>",
      );
      const $list = $('<ul class="wk-select-list" role="listbox" hidden></ul>');

      $select.addClass("wk-select-native").after($wrap);
      $wrap.append($btn).append($list);

      function placeholderText() {
        const $first = $select.find("option").first();
        return $first.val() === "" ? $first.text() : "";
      }

      function buildList() {
        $list.empty();
        $select.find("option").each(function () {
          const $opt = $(this);
          if ($opt.val() === "") return; // প্লেসহোল্ডার লিস্টে দেখাবে না
          const disabled = $opt.prop("disabled");
          $("<li></li>")
            .attr({
              role: "option",
              "data-value": $opt.val(),
              "aria-disabled": disabled ? "true" : "false",
            })
            .toggleClass("is-disabled", disabled)
            .text($opt.text())
            .appendTo($list);
        });
        markSelected();
      }

      function markSelected() {
        const val = String($select.val() || "");
        $list.find("li").each(function () {
          $(this).attr("aria-selected", String($(this).data("value")) === val);
        });
      }

      function paint() {
        const val = $select.val();
        const label = val ? $select.find("option:selected").text() : placeholderText();
        $btn.find(".wk-select-value").text(label);
        $btn.toggleClass("is-placeholder", !val);
        markSelected();
      }

      function open() {
        if (select.disabled) return;
        buildList();
        $list.prop("hidden", false);
        $btn.attr("aria-expanded", "true");
      }

      function close() {
        $list.prop("hidden", true);
        $btn.attr("aria-expanded", "false");
      }

      $btn.on("click", function (e) {
        e.preventDefault();
        $list.prop("hidden") ? open() : close();
      });

      $list.on("click", "li", function () {
        if ($(this).hasClass("is-disabled")) return;
        $select.val($(this).data("value")); 
        select.dispatchEvent(new Event("change", { bubbles: true }));

        paint();
        close();
        $btn.focus();
      });

      // change — ইউজারের ক্লিকে; wk:sync — কোড থেকে .val() বদলালে
      $select.on("change wk:sync", paint);

      $(document).on("click", function (e) {
        if (!$wrap.get(0).contains(e.target)) close();
      });

      $btn.on("keydown", function (e) {
        if (e.key === "Escape") close();
        if (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          open();
        }
      });

      paint();
    }

    function iquGetFbclid() {
      try {
        return new URLSearchParams(window.location.search).get("fbclid") || "";
      } catch (e) {
        return "";
      }
    }

    function submitForm(
      $form,
      action,
      serializedData,
      onSuccess,
      $srvErr,
      $btn,
      $btnTxt,
      $btnLoad,
    ) {
      $.ajax({
        url: IQU_AJAX.ajax_url,
        type: "POST",
        data:
          serializedData +
          "&action=" + action +
          "&iqu_page_url=" + encodeURIComponent(window.location.href) +
          "&iqu_fbclid=" + encodeURIComponent(iquGetFbclid()),
        dataType: "json",
        success: function (response) {
          setLoading($btn, $btnTxt, $btnLoad, false);
          if (response.success) {
            const data = response.data || {};

            // Meta browser-side Lead — server-side event-এর সাথে dedup
            if (data.event_id && typeof fbq === "function") {
              fbq(
                "track",
                "Lead",
                {
                  content_name: data.content_name || "IQU Registration",
                  content_category: "registration",
                  currency: "USD",
                  value: Number(data.value) || 0,
                },
                { eventID: data.event_id },
              );
            }

            onSuccess(data);
          } else {
            if (response.data && response.data.errors) {
              showFieldErrors($form, response.data.errors);
              scrollToFirstError($form);
            }
            const msg =
              (response.data && response.data.message) ||
              "An error occurred. Please try again.";
            $srvErr.text(msg).addClass("iqu-alert iqu-alert-error").show();
          }
        },
        error: function (xhr) {
          setLoading($btn, $btnTxt, $btnLoad, false);
          let msg = "Submission failed. Please try again.";
          if (xhr.status === 0) {
            msg =
              "No response from server. Please check your internet connection.";
          } else if (xhr.status === 403) {
            msg = "Security check failed. Please refresh the page.";
          } else if (xhr.status === 500) {
            msg = "Server error. Please contact us directly.";
          }
          $srvErr.text(msg).addClass("iqu-alert iqu-alert-error").show();
        },
      });
    }

    async function buildSerializedData($form) {
      const serialized = $form.serializeArray();
      await normalisePhoneFields($form, serialized);
      return $.param(serialized);
    }

    async function normalisePhoneFields($form, serialized) {
      const phoneInputs = $form.find('input[type="tel"]').toArray();

      for (const input of phoneInputs) {
        const $input = $(input);
        const fieldName = (input.name || "").replace("[]", "");
        const rawValue = String($input.val() || "").trim();

        if (!fieldName || !rawValue) continue;

        const iti = phoneInstances.get(input);
        if (!iti) continue;

        try {
          await Promise.resolve(iti.promise);
        } catch (_) {}

        let normalized = rawValue;

        try {
          let isValid = true;

          if (typeof iti.getValidationError === "function") {
            const err = iti.getValidationError();
            if (err === -99) {
              isValid = true;
            } else {
              isValid = err === 0;
            }
          } else if (typeof iti.isValidNumber === "function") {
            isValid = iti.isValidNumber();
          }

          if (!isValid) {
            showFieldError(
              $form,
              fieldName,
              "Please enter a valid " + labelFor($input).toLowerCase() + ".",
            );
            $input.addClass("iqu-invalid");
            throw { validation: true };
          }

          normalized =
            typeof iti.getNumber === "function"
              ? iti.getNumber() || rawValue
              : fallbackInternationalNumber(iti, rawValue);
        } catch (error) {
          if (error && error.validation) throw error;
          normalized = fallbackInternationalNumber(iti, rawValue);
        }

        serialized.forEach((entry) => {
          if (entry.name === input.name) entry.value = normalized;
        });
      }
    }

    function fallbackInternationalNumber(iti, rawValue) {
      const digits = rawValue.replace(/\D+/g, "");
      if (!digits) {
        return rawValue;
      }

      if (rawValue.trim().charAt(0) === "+") {
        return "+" + digits;
      }

      const selectedCountry =
        typeof iti.getSelectedCountryData === "function"
          ? iti.getSelectedCountryData()
          : null;

      if (selectedCountry && selectedCountry.dialCode) {
        const cleanDigits = digits.startsWith("0")
          ? digits.substring(1)
          : digits;
        return "+" + selectedCountry.dialCode + cleanDigits;
      }

      return rawValue;
    }

    function basicValidate($form) {
      let valid = true;

      $form.find("[required]").each(function () {
        const $field = $(this);
        if (
          $field.is('[type="checkbox"]') ||
          $field.is('[type="radio"]') ||
          $field.is(":disabled") ||
          $field.closest("[hidden]").length
        ) {
          return;
        }

        const value = $field.val();
        if (!value || String(value).trim() === "") {
          const name = ($field.attr("name") || "").replace("[]", "");
          showFieldError($form, name, labelFor($field) + " is required.");
          $field.addClass("iqu-invalid");
          valid = false;
        }
      });

      $form.find('input[type="email"]').each(function () {
        const value = $(this).val();
        if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          showFieldError(
            $form,
            $(this).attr("name"),
            "Please enter a valid email address.",
          );
          $(this).addClass("iqu-invalid");
          valid = false;
        }
      });

      if (
        $form.find('input[name="preferred_days[]"]').length &&
        !$form.find('input[name="preferred_days[]"]:checked').length
      ) {
        showFieldError(
          $form,
          "preferred_days",
          "Please select at least one preferred day.",
        );
        valid = false;
      }

      if (
        $form.find('input[name="languages[]"]').length &&
        !$form.find('input[name="languages[]"]:checked').length
      ) {
        showFieldError(
          $form,
          "languages",
          "Please select at least one language.",
        );
        valid = false;
      }

      const $daysInput = $form.find("#days_per_week");
      if ($daysInput.length) {
        const daysPerWeek = parseInt($daysInput.val(), 10);
        const selectedDays = $form.find(
          'input[name="preferred_days[]"]:checked',
        ).length;

        if (
          !Number.isInteger(daysPerWeek) ||
          daysPerWeek < 1 ||
          daysPerWeek > 7
        ) {
          showFieldError(
            $form,
            "days_per_week",
            "Please enter how many days a week you can join (1-7).",
          );
          $daysInput.addClass("iqu-invalid");
          valid = false;
        }

        if (
          selectedDays &&
          Number.isInteger(daysPerWeek) &&
          selectedDays > daysPerWeek
        ) {
          showFieldError(
            $form,
            "preferred_days",
            "Please choose no more than the number of days entered above.",
          );
          valid = false;
        }
      }

      const $timeInput = $form.find("#time_slot");
      const $startInput = $form.find("#iqu_time_start");
      const $endInput = $form.find("#iqu_time_end");
      const $tzSelect = $form.find("#iqu_time_slot_timezone");

      if ($timeInput.length && !$timeInput.val()) {
        showFieldError(
          $form,
          "time_slot",
          "Please choose a start time, end time, and time zone.",
        );
        $startInput.addClass("iqu-invalid");
        $endInput.addClass("iqu-invalid");
        $tzSelect.addClass("iqu-invalid");
        valid = false;
      }

      const selectedFee = $form.find('[name="fee_pref"]').val();
      const $customFee = $form.find("#iqu_fee_custom_amount");
      if (selectedFee === "other") {
        const customValue = Number($customFee.val() || 0);
        if (!customValue || customValue < 1) {
          showFieldError(
            $form,
            "fee_pref",
            "Please enter your custom monthly amount.",
          );
          $customFee.addClass("iqu-invalid");
          valid = false;
        }
      }

      const $freeReason = $form.find("#free_request_reason");
      if (
        selectedFee === "free" &&
        $freeReason.length &&
        !$freeReason.val().trim()
      ) {
        showFieldError(
          $form,
          "free_request_reason",
          "Please briefly explain your situation for free enrollment.",
        );
        $freeReason.addClass("iqu-invalid");
        valid = false;
      }

      const $summerAge = $form.find("#summer_age");
      if ($summerAge.length) {
        const enteredAge = Number($summerAge.val() || 0);
        const minAge = Number($summerAge.attr("min") || 5);
        const maxAge = Number($summerAge.attr("max") || 15);

        if (enteredAge && (enteredAge < minAge || enteredAge > maxAge)) {
          showFieldError(
            $form,
            "age",
            "Please enter a valid age (" + minAge + "-" + maxAge + ").",
          );
          $summerAge.addClass("iqu-invalid");
          valid = false;
        }
      }

      const $enrollmentLevel = $form.find("#enrollment_level");
      if ($enrollmentLevel.length && !$enrollmentLevel.val()) {
        showFieldError($form, "enrollment_level", "Please select an option.");
        $enrollmentLevel.addClass("iqu-invalid");
        valid = false;
      }

      const selectedAdmissionFee = $form
        .find('input[name="admission_fee"]:checked')
        .val();

      if (
        $form.find('input[name="admission_fee"]').length &&
        !selectedAdmissionFee
      ) {
        showFieldError(
          $form,
          "admission_fee",
          "Please choose an admission fee option.",
        );
        valid = false;
      }

      if (
        (selectedAdmissionFee === "50" || selectedAdmissionFee === "30") &&
        !$form.find('input[name="payment_method"]:checked').length
      ) {
        showFieldError(
          $form,
          "payment_method",
          "Please choose a payment method.",
        );
        valid = false;
      }

      if (selectedAdmissionFee === "flexible") {
        const flexVal = ($form.find("#iqu_flexible_note").val() || "").trim();
        const isDollarAmount = /^\$?\d+(\.\d{1,2})?$/.test(flexVal);
        if (
          isDollarAmount &&
          !$form.find('input[name="payment_method"]:checked').length
        ) {
          showFieldError(
            $form,
            "payment_method",
            "Please choose a payment method.",
          );
          valid = false;
        }
      }

      const $whatsappGroup = $form.find("#whatsapp_group");
      if ($whatsappGroup.length && !$whatsappGroup.val()) {
        showFieldError($form, "whatsapp_group", "Please select an option.");
        $whatsappGroup.addClass("iqu-invalid");
        valid = false;
      }

      const $referral = $form.find("#referral");
      if ($referral.length) {
        if (!$referral.val()) {
          showFieldError($form, "referral", "Please select an option.");
          $referral.addClass("iqu-invalid");
          valid = false;
        } else if ($referral.val() === "other") {
          const $referralOther = $form.find("#referral_other");
          if (!$referralOther.val().trim()) {
            showFieldError(
              $form,
              "referral_other",
              "Please specify how you heard about this program.",
            );
            $referralOther.addClass("iqu-invalid");
            valid = false;
          }
        }
      }

      if (selectedAdmissionFee === "flexible") {
        const $flexibleNote = $form.find("#iqu_flexible_note");
        if ($flexibleNote.length) {
          const flexVal = $flexibleNote.val().trim();
          if (!flexVal) {
            showFieldError(
              $form,
              "flexible_fee_note",
              'Please enter an amount (e.g. $20) or write "Requesting Free Enrollment".',
            );
            $flexibleNote.addClass("iqu-invalid");
            valid = false;
          } else {
            const isDollarAmount = /^\$?\d+(\.\d{1,2})?$/.test(flexVal);
            const isFreeRequest =
              flexVal.toLowerCase() === "requesting free enrollment";
            if (!isDollarAmount && !isFreeRequest) {
              showFieldError(
                $form,
                "flexible_fee_note",
                'Please enter a valid dollar amount (e.g. $20) or write exactly "Requesting Free Enrollment".',
              );
              $flexibleNote.addClass("iqu-invalid");
              valid = false;
            }
          }
        }
      }

      return valid;
    }

    // ── Flatpickr Time Picker Init ──────────────────────
    const timeConfig = {
      enableTime: true,
      noCalendar: true,
      dateFormat: "h:i K",
      time_24hr: false,
    };

    function updateTimeSlot() {
      var startEl = document.getElementById("iqu_time_start");
      var endEl = document.getElementById("iqu_time_end");
      var tzEl = document.getElementById("iqu_time_slot_timezone");
      var hiddenEl = document.getElementById("time_slot");

      if (!startEl || !endEl || !tzEl || !hiddenEl) return;

      var start = startEl.value;
      var end = endEl.value;
      var tz = tzEl.value;

      if (start && end && tz) {
        hiddenEl.value = start + " – " + end + " (" + tz + ")";
      } else {
        hiddenEl.value = "";
      }
    }

    var fpStartEl = document.getElementById("iqu_time_start");
    var fpEndEl = document.getElementById("iqu_time_end");
    var fpTzEl = document.getElementById("iqu_time_slot_timezone");

    if (fpStartEl && fpEndEl && fpTzEl) {
      flatpickr(
        fpStartEl,
        $.extend({}, timeConfig, { onChange: updateTimeSlot }),
      );
      flatpickr(
        fpEndEl,
        $.extend({}, timeConfig, { onChange: updateTimeSlot }),
      );
      fpTzEl.addEventListener("change", updateTimeSlot);
    }

    function clearErrors($form) {
      $form.find(".iqu-error").text("");
      $form.find(".iqu-invalid").removeClass("iqu-invalid");
    }

    function showFieldErrors($form, errors) {
      $.each(errors, function (field, message) {
        showFieldError($form, field, message);
        $form
          .find('[name="' + field + '"], [name="' + field + '[]"]')
          .first()
          .addClass("iqu-invalid");
      });
    }

    function showFieldError($form, field, message) {
      $form.find('[data-field="' + field + '"]').text(message);
    }

    function scrollToFirstError($form) {
      const $first = $form.find(".iqu-invalid, .iqu-error:not(:empty)").first();
      if ($first.length) {
        $("html, body").animate({ scrollTop: $first.offset().top - 100 }, 400);
        if ($first.is("input, select, textarea")) {
          $first.focus();
        }
      }
    }

    function setLoading($btn, $txt, $load, loading) {
      $btn.prop("disabled", loading);
      $txt.toggle(!loading);
      $load.toggle(loading);
    }

    function labelFor($field) {
      const id = $field.attr("id");
      if (id) {
        const $label = $('label[for="' + id + '"]')
          .first()
          .clone();
        $label.find(".req").remove();
        return $label.text().trim() || "This field";
      }

      return "This field";
    }

    function buildSuccessMarkup(opts) {
      const $el = $(
        '<div class="iqu-success-card" role="status">' +
          '<svg class="iqu-success-illu" width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
          '<circle cx="60" cy="60" r="56" fill="#eaf6ec" stroke="#27ae60" stroke-width="2"/>' +
          '<path d="M38 62 L54 78 L84 44" stroke="#1e8449" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
          '<circle cx="92" cy="30" r="4" fill="#f0b429"/>' +
          '<circle cx="26" cy="34" r="3" fill="#1a5276"/>' +
          '<circle cx="96" cy="88" r="3" fill="#1a5276"/>' +
          "</svg>" +
          '<div class="iqu-success-badge">Submission Received</div>' +
          '<h2 class="iqu-success-title"></h2>' +
          '<p class="iqu-success-sub"></p>' +
          '<p class="iqu-success-msg"></p>' +
          '<div class="iqu-success-details"></div>' +
          '<blockquote class="iqu-success-quote">' +
          '"The best among you are those who learn the Qur\'an and teach it."' +
          "<cite>- Sahih al-Bukhari</cite>" +
          "</blockquote>" +
          '<div class="iqu-success-contact">' +
          "<strong>Need help?</strong> " +
          "<span>" +
          IQU_AJAX.contact_phone +
          " · " +
          '<a href="mailto:' +
          IQU_AJAX.contact_email +
          '">' +
          IQU_AJAX.contact_email +
          "</a></span>" +
          "</div>" +
          "</div>",
      );

      $el.find(".iqu-success-title").text(opts.title || "Thank you!");
      $el.find(".iqu-success-sub").text(opts.subtitle || "");
      $el.find(".iqu-success-msg").text(opts.message || "");

      const $details = $el.find(".iqu-success-details");
      if (opts.details && opts.details.length) {
        opts.details.forEach(function (detail) {
          const $row = $('<div class="iqu-success-detail"></div>');
          $('<span class="k"></span>').text(detail.label).appendTo($row);
          $('<span class="v"></span>').text(detail.value).appendTo($row);
          $row.appendTo($details);
        });
      }

      return $el;
    }
  });
})(jQuery);