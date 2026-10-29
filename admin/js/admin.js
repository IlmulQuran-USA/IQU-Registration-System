(function ($) {
  "use strict";

  $(function () {
    if (typeof IQU_Admin === "undefined") return;

    // ══════════════════════════════════════════════════
    // CHARTS (dashboard only)
    // ══════════════════════════════════════════════════

    var $weekCanvas = $("#iqu-week-chart");
    var $revenueCanvas = $("#iqu-revenue-chart");
    var $breakdownCanvas = $("#iqu-breakdown-chart");

    // ── 📊 Enrollment breakdown — ভার্টিকেল বার ──────────
    if (
      $breakdownCanvas.length &&
      IQU_Admin.chart_data &&
      IQU_Admin.chart_data.breakdown
    ) {
      var bd = IQU_Admin.chart_data.breakdown;

      new Chart($breakdownCanvas[0].getContext("2d"), {
        type: "bar",
        data: {
          labels: bd.labels,
          datasets: [
            {
              label: "Students",
              data: bd.data,
              backgroundColor: bd.colors,
              borderRadius: 6,
              borderSkipped: false,
              maxBarThickness: 48,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  return (
                    " " +
                    ctx.parsed.y +
                    " student" +
                    (ctx.parsed.y !== 1 ? "s" : "")
                  );
                },
              },
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { precision: 0 },
              grid: { color: "rgba(0,0,0,0.04)" },
            },
            x: {
              ticks: {},
              grid: { display: false },
            },
          },
        },
      });
    }

    if ($weekCanvas.length && IQU_Admin.chart_data) {
      var cd = IQU_Admin.chart_data;

      // ── Weekly registrations line chart ──────────────
      new Chart($weekCanvas[0].getContext("2d"), {
        type: "line",
        data: {
          labels: cd.weekly.labels,
          datasets: [
            {
              label: "Registrations",
              data: cd.weekly.data,
              borderColor: "#1e5fa0",
              backgroundColor: "rgba(30,95,160,0.08)",
              fill: true,
              tension: 0.4,
              pointRadius: 5,
              pointBackgroundColor: "#1e5fa0",
              pointBorderColor: "#fff",
              pointBorderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  return (
                    " " +
                    ctx.parsed.y +
                    " registration" +
                    (ctx.parsed.y !== 1 ? "s" : "")
                  );
                },
              },
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { stepSize: 1 },
              grid: { color: "rgba(0,0,0,0.04)" },
            },
            x: {
              ticks: {},
              grid: { display: false },
            },
          },
        },
      });

      // ── Revenue doughnut chart ────────────────────────
      if ($revenueCanvas.length) {
        new Chart($revenueCanvas[0].getContext("2d"), {
          type: "doughnut",
          data: {
            labels: cd.revenue.labels,
            datasets: [
              {
                data: cd.revenue.data,
                backgroundColor: ["#aed4ff", "#a9ffd2", "#ffeeb0"],
                borderWidth: 3,
                borderColor: "#ffffff",
                hoverBorderColor: "#ffffff",
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "68%",
            plugins: {
              legend: {
                display: true,
                position: "bottom",
                labels: {
                  padding: 12,
                  boxWidth: 10,
                  boxHeight: 10,
                },
              },
              tooltip: {
                callbacks: {
                  label: function (ctx) {
                    var val = ctx.parsed;
                    var total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                    var pct =
                      total > 0 ? ((val / total) * 100).toFixed(1) : "0.0";
                    return (
                      " " +
                      ctx.label +
                      ": $" +
                      val.toFixed(0) +
                      " (" +
                      pct +
                      "%)"
                    );
                  },
                },
              },
            },
          },
        });
      }
    }

    // ══════════════════════════════════════════════════
    // LIST PAGE: inline status change
    // ══════════════════════════════════════════════════

    $(document).on("change", ".iqu-status-select", function () {
      var $sel = $(this);
      var id = $sel.data("id");
      var status = $sel.val();

      $.post(
        IQU_Admin.ajax_url,
        {
          action: "iqu_update_status",
          id: id,
          status: status,
          // no note: a status change from the list keeps the admin note
          _wpnonce: IQU_Admin.nonce_status,
        },
        function (res) {
          if (res.success) {
            var $tr = $sel.closest("tr");
            // a list searched in the browser (IQU_Pager::row_attrs) filters on the new status from now on
            var tokens = ($tr.attr("data-iqu-v-status") || "").split("\n");
            if ($tr.is("[data-iqu-v-status]")) $tr.attr("data-iqu-v-status", [status].concat(tokens.slice(1)).join("\n"));
            document.dispatchEvent(new CustomEvent("iqu:changed"));
            $tr.find("td").css("background", "#e6f5ec");
            setTimeout(function () {
              $tr.find("td").css("background", "");
            }, 1400);
          } else {
            alert("Error: " + res.data);
            // Revert to previous value on error
            $sel.val($sel.data("prev"));
          }
        },
      );
    });

    // Store previous value before change
    $(document).on("focus", ".iqu-status-select", function () {
      $(this).data("prev", $(this).val());
    });

    // ══════════════════════════════════════════════════
    // LIST PAGE: delete button
    // ══════════════════════════════════════════════════

    $(document).on("click", ".iqu-delete-btn", function () {
      if (!confirm(IQU_Admin.confirm_delete)) return;

      var $btn = $(this);
      var id = $btn.data("id");

      $btn.prop("disabled", true).text("Deleting…");

      $.post(
        IQU_Admin.ajax_url,
        {
          action: "iqu_delete_reg",
          id: id,
          _wpnonce: IQU_Admin.nonce_delete,
        },
        function (res) {
          if (res.success) {
            $btn.closest("tr").fadeOut(350, function () {
              var table = $(this).closest("table[data-iqu-pager]")[0];
              $(this).remove();
              // the chip and page numbers count one row less; the next row moves up
              if (table && window.IQUPager) window.IQUPager.update(table);
            });
            document.dispatchEvent(new CustomEvent("iqu:changed"));
          } else {
            alert("Error: " + res.data);
            $btn.prop("disabled", false).text("Delete");
          }
        },
      );
    });

    // ══════════════════════════════════════════════════
    // DETAIL PAGE: save status + note
    // ══════════════════════════════════════════════════

    $("#iqu-save-status").on("click", function () {
      var $btn = $(this);
      var id = $btn.data("id");
      var status = $("#iqu-detail-status").val();
      var note = $("#iqu-admin-note").val();
      var $msg = $("#iqu-status-msg");

      $btn.prop("disabled", true).text("Saving…");

      $.post(
        IQU_Admin.ajax_url,
        {
          action: "iqu_update_status",
          id: id,
          status: status,
          note: note,
          _wpnonce: IQU_Admin.nonce_status,
        },
        function (res) {
          $btn.prop("disabled", false).text("Save Changes");
          if (res.success) {
            $msg.text("✓ " + res.data).css("color", "#1a8a45");
          } else {
            $msg.text("✗ " + res.data).css("color", "#b03030");
          }
          setTimeout(function () {
            $msg.text("");
          }, 3000);
        },
      );
    });
  });
})(jQuery);

// ── Referral Chart (horizontal bar) ──────────────────────
const refCtx = document.getElementById("iqu-referral-chart");
if (refCtx && IQU_Admin.chart_data && IQU_Admin.chart_data.referral) {
  const refData = IQU_Admin.chart_data.referral;

  const platformColors = {
    Facebook: { bg: "#e8f0fe", border: "#1877F2" },
    WhatsApp: { bg: "#e6f7f1", border: "#075E54" },
    LinkedIn: { bg: "#e8f3fc", border: "#0A66C2" },
    YouTube: { bg: "#fee2e2", border: "#CC0000" },
    Website: { bg: "#ede9fe", border: "#6366f1" },
    "Friend / Family": { bg: "#fef3e0", border: "#92400e" },
    Email: { bg: "#d1fae5", border: "#065f46" },
  };

  const bgColors = refData.labels.map(
    (l) => (platformColors[l] || { bg: "#f3f4f6" }).bg,
  );
  const borderColors = refData.labels.map(
    (l) => (platformColors[l] || { border: "#9ca3af" }).border,
  );

  new Chart(refCtx, {
    type: "bar",
    data: {
      labels: refData.labels,
      datasets: [
        {
          data: refData.data,
          backgroundColor: bgColors,
          borderColor: borderColors,
          borderWidth: 1.5,
          borderRadius: 5,
          borderSkipped: false,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => "  " + ctx.parsed.x + " registrations",
          },
        },
      },
      scales: {
        x: {
          grid: { color: "rgba(0,0,0,0.04)" },
          ticks: { stepSize: 1 },
          beginAtZero: true,
        },
        y: {
          grid: { display: false },
          ticks: { font: { weight: "600" } },
        },
      },
    },
  });
}

// ══════════════════════════════════════════════════
// SUCCESS NOTICES (every IQU admin page)
// Success notices close by themselves after 5 seconds; errors, warnings and info stay.
// The timer pauses while the pointer is over the notice or focus is inside it, there is
// always a close button, and with "reduce motion" the notice goes without the fade.
// ══════════════════════════════════════════════════
(function () {
  "use strict";
  var DELAY = 5000, FADE = 300;
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  function closeNotice(n) {
    if (!n.parentNode || n.getAttribute("data-iqu-closing")) return;
    n.setAttribute("data-iqu-closing", "1");
    if (reduce) { n.parentNode.removeChild(n); return; }
    n.classList.add("iqu-notice-fading");
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, FADE);
  }

  function ensureCloseButton(n) {
    if (n.querySelector(".notice-dismiss")) return;
    var b = document.createElement("button");
    b.type = "button";
    b.className = "notice-dismiss";
    b.innerHTML = '<span class="screen-reader-text">Dismiss this notice.</span>';
    b.addEventListener("click", function () { closeNotice(n); });
    n.classList.add("is-dismissible");
    n.appendChild(b);
  }

  function autoClose(n) {
    if (n.getAttribute("data-iqu-auto")) return;
    n.setAttribute("data-iqu-auto", "1");
    n.setAttribute("role", "status");
    n.setAttribute("aria-live", "polite");
    ensureCloseButton(n);
    var left = DELAY, started = 0, timer = null, hover = false, focus = false;
    function start() {
      if (timer || hover || focus) return;
      started = Date.now();
      timer = setTimeout(function () { closeNotice(n); }, left);
    }
    function pause() {
      if (!timer) return;
      clearTimeout(timer);
      timer = null;
      left = Math.max(1000, left - (Date.now() - started));
    }
    n.addEventListener("mouseenter", function () { hover = true; pause(); });
    n.addEventListener("mouseleave", function () { hover = false; start(); });
    n.addEventListener("focusin", function () { focus = true; pause(); });
    n.addEventListener("focusout", function (e) { if (!n.contains(e.relatedTarget)) { focus = false; start(); } });
    start();
  }

  function init() {
    document.querySelectorAll(".iqu-admin-wrap .notice.notice-success, .iqu-admin-wrap .notice.updated").forEach(autoClose);
  }
  // After WordPress has moved notices and added its own dismiss buttons (both run on DOM ready).
  function later() { setTimeout(init, 0); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", later);
  else later();
})();
// ══════════════════════════════════════════════════
// HEADER TABS (both headers, every IQU page): when the tab row is wider than its box it scrolls
// sideways; on load, bring the active tab into view (centred) so the current page is always visible.
// ══════════════════════════════════════════════════
(function () {
  "use strict";

  function showActive(row) {
    var tab = row.querySelector(".iqu-tab-active");
    if (!tab || row.scrollWidth <= row.clientWidth + 1) return;
    var r = row.getBoundingClientRect(), t = tab.getBoundingClientRect();
    if (t.left >= r.left && t.right <= r.right) return;
    var left = t.left - r.left + row.scrollLeft - (row.clientWidth - t.width) / 2;
    row.scrollLeft = Math.max(0, Math.min(left, row.scrollWidth - row.clientWidth));
  }

  function init() {
    document.querySelectorAll(".iqu-top-bar .iqu-tabs").forEach(showActive);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
// ══════════════════════════════════════════════════
// STUDENT PAGE: "Fund for the reduction" next to the agreed fee (3.6.0). Saved at once (admin-ajax,
// nonce + capability on the server); reports only — no amount, price or subscription changes.
// ══════════════════════════════════════════════════
(function () {
  "use strict";

  document.addEventListener("change", function (e) {
    var select = e.target;
    var box = select && select.closest ? select.closest("[data-iqu-fund]") : null;
    if (!box || select.tagName !== "SELECT") return;
    var status = box.querySelector(".iqu-fund-status");
    var changed = box.querySelector(".iqu-fund-changed");
    var body = new URLSearchParams({ action: "iqu_set_agreed_fee_fund", id: box.getAttribute("data-id"), fund: select.value, _ajax_nonce: box.getAttribute("data-nonce") });
    if (status) status.textContent = "Saving…";
    select.disabled = true;
    fetch(box.getAttribute("data-ajax"), { method: "POST", credentials: "same-origin", body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (status) status.textContent = j && j.success ? j.data.message : (j && j.data && j.data.message) || "The fund could not be saved.";
        if (j && j.success && changed) changed.textContent = j.data.changed || "";
      })
      .catch(function () { if (status) status.textContent = "The fund could not be saved."; })
      .then(function () { select.disabled = false; });
  });
})();
// ══════════════════════════════════════════════════
// CONFIRMATION DIALOGS (3.7.0): "Stop billing", "Cancel enrollment", "End scholarship" … A [data-iqu-dialog] link
// opens its <dialog> (without JavaScript the link shows the same dialog open). The reason "Other" asks for a short
// text, required only then.
// ══════════════════════════════════════════════════
(function () {
  "use strict";

  function other(form) {
    var select = form.querySelector("select[name=reason]"), box = form.querySelector("[data-iqu-other]");
    if (!select || !box) return;
    var on = select.value === "other", input = box.querySelector("input");
    box.hidden = !on;
    if (input) input.required = on;
  }

  document.addEventListener("click", function (e) {
    var a = e.target && e.target.closest ? e.target.closest("[data-iqu-dialog]") : null;
    if (!a) return;
    var d = document.getElementById(a.getAttribute("data-iqu-dialog"));
    if (!d || typeof d.showModal !== "function") return; // the link opens the page with the dialog shown
    e.preventDefault();
    if (d.open) d.close();
    d.showModal();
    var first = d.querySelector("select, input:not([type=hidden])");
    if (first) first.focus();
  });

  document.addEventListener("change", function (e) {
    var form = e.target && e.target.closest ? e.target.closest("[data-iqu-reason-form]") : null;
    if (form && e.target.name === "reason") other(form);
  });

  function init() {
    document.querySelectorAll("[data-iqu-reason-form]").forEach(other);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
// ══════════════════════════════════════════════════
// REFUND (3.7.0, only for the users in IQU_REFUND_USERS — the server checks every request): "Refund {amount}"
// asks once more ("Yes, refund {amount}"), then the refund is made (admin-ajax) and the page reloads.
// ══════════════════════════════════════════════════
(function () {
  "use strict";

  function money(v) { var n = parseFloat(v); return isFinite(n) ? "$" + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",") : "$0.00"; }
  function reset(form) {
    var go = form.querySelector("[data-iqu-refund-go]"), conf = form.querySelector("[data-iqu-refund-confirm]");
    form.removeAttribute("data-confirming");
    if (conf) conf.hidden = true;
    if (go) go.textContent = "Refund " + money(form.elements.amount.value);
  }

  document.addEventListener("input", function (e) {
    var form = e.target && e.target.closest ? e.target.closest("[data-iqu-refund]") : null;
    if (form) reset(form);
  });
  document.addEventListener("change", function (e) {
    var form = e.target && e.target.closest ? e.target.closest("[data-iqu-refund]") : null;
    if (form && e.target.name === "reason") reset(form);
  });

  document.addEventListener("click", function (e) {
    var go = e.target && e.target.closest ? e.target.closest("[data-iqu-refund-go]") : null;
    if (!go) return;
    var form = go.closest("[data-iqu-refund]"), conf = form.querySelector("[data-iqu-refund-confirm]"), status = form.querySelector("[data-iqu-refund-status]");
    if (!form.reportValidity()) return;
    var amount = money(form.elements.amount.value);
    if (!form.hasAttribute("data-confirming")) { // the second step
      form.setAttribute("data-confirming", "1");
      conf.textContent = "Refund " + amount + " to the family? This cannot be undone.";
      conf.hidden = false;
      go.textContent = "Yes, refund " + amount;
      return;
    }
    go.disabled = true;
    status.textContent = "Refunding…";
    var body = new URLSearchParams({ action: "iqu_billing_refund", payment: form.getAttribute("data-payment"), amount: form.elements.amount.value,
      reason: form.elements.reason.value, reason_other: form.elements.reason_other ? form.elements.reason_other.value : "",
      op: form.getAttribute("data-op"), _ajax_nonce: form.getAttribute("data-nonce") });
    fetch(form.getAttribute("data-ajax"), { method: "POST", credentials: "same-origin", body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        status.textContent = (j && j.data && j.data.message) || "The refund could not be made.";
        if (j && j.success) setTimeout(function () { window.location.reload(); }, 1200);
        else { go.disabled = false; reset(form); }
      })
      .catch(function () { status.textContent = "The refund could not be made. Check Stripe before trying again."; go.disabled = false; });
  });
})();
