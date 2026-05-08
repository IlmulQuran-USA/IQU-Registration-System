/**
 * IQU Registration - Frontend JS
 *
 * Handles both the Free Enrollment form and the Summer Program form,
 * including Google reCAPTCHA v3, intl-tel-input phone fields, the
 * Zelle in-page modal, and the Zeffy redirect with prefilled amount.
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

    function wireFreeForm($form) {
      const $btn = $("#iqu-submit-btn");
      const $btnTxt = $btn.find(".btn-text");
      const $btnLoad = $btn.find(".btn-loading");
      const $srvErr = $("#iqu-server-error");

      enhanceFreeFormUI($form);
      initInternationalPhoneInputs($form);

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
      // Time slot — sync Flatpickr + timezone into hidden #time_slot field
      var $timeInput = $form.find("#time_slot");
      if ($timeInput.length) {
        $timeInput
          .addClass("iqu-time-slot-hidden")
          .attr("tabindex", "-1")
          .removeAttr("required");

        // Clear time_slot errors when any part changes
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

      const $feeQuestion = $form
        .find('[name="fee_pref"]')
        .first()
        .closest(".iqu-question");
      if ($feeQuestion.length) {
        $feeQuestion.find(".iqu-radio-label").addClass("iqu-radio-card");

        let $customFee = $feeQuestion.find(".iqu-custom-fee");
        if (!$customFee.length) {
          $customFee = $(
            '<div class="iqu-custom-fee" hidden>' +
              '<label for="iqu_fee_custom_amount">Custom Monthly Amount in USD <span class="req">*</span></label>' +
              '<p class="iqu-help">Any amount below $40 will be subsidized from zakat fund.</p>' +
              '<div class="iqu-input-prefix">' +
              "<span>$</span>" +
              '<input type="number" id="iqu_fee_custom_amount" name="fee_custom_amount" min="1" max="9999" step="1" placeholder="How much would feel manageable each month?">' +
              "</div>" +
              "</div>",
          );
          $feeQuestion.find(".iqu-radio-fee").after($customFee);
        }

        const $feeInput = $customFee.find("#iqu_fee_custom_amount");
        const $freeReason = $feeQuestion.find(".iqu-free-request-reason");
        const $freeReasonInput = $freeReason.find("#free_request_reason");

        function toggleFeeInputs() {
          const selected = $form.find('[name="fee_pref"]').val();
          const showCustom = selected === "other";
          const showFreeReason = selected === "free";

          $customFee.prop("hidden", !showCustom);
          if (!showCustom) {
            $feeInput.val("").removeClass("iqu-invalid");
          }

          $freeReason.prop("hidden", !showFreeReason);
          if (!showFreeReason) {
            $freeReasonInput.val("").removeClass("iqu-invalid");
            showFieldError($form, "free_request_reason", "");
          }
        }

        $form.on("change", '[name="fee_pref"]', function () {
          toggleFeeInputs();
          showFieldError($form, "fee_pref", "");
        });

        $feeInput.on("input", function () {
          showFieldError($form, "fee_pref", "");
          $(this).removeClass("iqu-invalid");
        });

        $freeReasonInput.on("input", function () {
          showFieldError($form, "free_request_reason", "");
          $(this).removeClass("iqu-invalid");
        });

        toggleFeeInputs();
      }
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

      function getSelectedPaymentMethod() {
        const selected = summerFormEl.querySelector(
          'input[name="payment_method"]:checked',
        );
        return selected ? selected.value : "";
      }

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
          // Complimentary — no payment needed, hide everything
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
            // Dollar amount entered → show payment options
            paymentFieldset.disabled = false;
            paymentWrap.classList.remove("is-disabled");
            paymentWrap.hidden = false;
          } else {
            // Free request or empty → hide payment options
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

        // ✅ payment_amount hidden field এ সেট করো
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

        // fee থেকে amount বের করো
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

        // ── Surrounding sections hide ──────────────────────
        $form
          .closest(".iqu-form-wrapper")
          .find(".sc-hero, .sc-body, .iqu-required-note")
          .slideUp(300);

        // ── Placeholder: popup-এর সমান height ধরে রাখে পেছনে ──
        const $placeholder = $('<div id="iqu-sp-placeholder"></div>').css({
          minHeight: "600px",
          visibility: "hidden",
        });
        $form.after($placeholder);

        $form.fadeOut(300, function () {
          // ── Keyframe styles ────────────────────────────────
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
 
        /* Zeffy redirect strip */
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

          // ── Build details rows HTML ────────────────────────
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

          // ── Zeffy redirect strip (only when zeffy_url exists) ──
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

          // zeffyHTML
          const isZelle =
            (payment.method && payment.method.toLowerCase() === "zelle") ||
            !!$form.data("iqu-payment-zelle");

          // Complimentary সিলেক্ট করা হয়েছে কিনা তা চেক করা
          const isComplimentary =
            $form.find('[name="admission_fee"]:checked').val() ===
            "complimentary";

          // Zelle অথবা Complimentary যেকোনো একটি হলেই হোমপেজে যাবে
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

          // ── Overlay markup ─────────────────────────────────
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
 
          ${
            detailsHTML
              ? `<div class="iqu-sp-details">${detailsHTML}</div>`
              : ""
          }
 
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
          // placeholder height টা popup card-এর actual height দিয়ে match করো
          const cardHeight =
            $overlay.find(".iqu-sp-card").outerHeight(true) || 600;
          $placeholder.css("minHeight", cardHeight + 80 + "px");

          window.scrollTo({ top: 0, behavior: "smooth" });
          window.scrollTo({ top: 0, behavior: "smooth" });

          // ── Zeffy redirect ─────────────────────────────────
          if (payment.zeffy_url) {
            setTimeout(function () {
              window.location.href = payment.zeffy_url;
            }, 5000);
          }

          if (shouldRedirectHome) {
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

        $ageInput.attr("min", minAge).attr("max", maxAge);
        $ageHelp.text(helpText);
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
        data: serializedData + "&action=" + action,
        dataType: "json",
        success: function (response) {
          setLoading($btn, $btnTxt, $btnLoad, false);
          if (response.success) {
            onSuccess(response.data || {});
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

        // ✅ utils লোড হওয়া পর্যন্ত অপেক্ষা
        try {
          await Promise.resolve(iti.promise);
        } catch (_) {}

        let normalized = rawValue;

        try {
          let isValid = true;

          // ✅ v23-এর সঠিক check — getValidationError() === 0 মানে valid
          // -99 মানে utils লোড হয়নি, সেক্ষেত্রে validation skip করো
          if (typeof iti.getValidationError === "function") {
            const err = iti.getValidationError();
            if (err === -99) {
              // utils লোড হয়নি — skip validation, just normalize
              isValid = true;
            } else {
              isValid = err === 0;
            }
          } else if (typeof iti.isValidNumber === "function") {
            // পুরনো version fallback
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
        // লিডিং 0 থাকলে তা রিমুভ করে কান্ট্রি কোড যোগ করুন
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

      // পুরনো block সরিয়ে এটা দাও
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

      // admission_fee radio — required if the radio group exists
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

      // Complimentary needs no payment method
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

      // if (opts.zeffyUrl) {
      //   const $redirect = $('<div class="iqu-success-redirect"></div>').text(
      //     "Redirecting you to Zeffy to complete payment...",
      //   );
      //   const $link = $('<a class="iqu-success-link"></a>')
      //     .attr("href", opts.zeffyUrl)
      //     .text("If you are not redirected automatically, click here.");
      //   $redirect.append("<br>").append($link);
      //   $el.append($redirect);
      // }

      return $el;
    }
  });
})(jQuery);
