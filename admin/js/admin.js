(function ($) {
  "use strict";

  $(function () {
    if (typeof IQU_Admin === "undefined") return;

    // ══════════════════════════════════════════════════
    // CHARTS (dashboard only)
    // ══════════════════════════════════════════════════

    var $weekCanvas = $("#iqu-week-chart");
    var $revenueCanvas = $("#iqu-revenue-chart");

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
              ticks: { stepSize: 1, font: { size: 11 }, color: "#6b7280" },
              grid: { color: "rgba(0,0,0,0.04)" },
            },
            x: {
              ticks: { font: { size: 11 }, color: "#6b7280" },
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
                  font: { size: 11 },
                  color: "#6b7280",
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
          note: "",
          _wpnonce: IQU_Admin.nonce_status,
        },
        function (res) {
          if (res.success) {
            var $tr = $sel.closest("tr");
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
              $(this).remove();
            });
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
          ticks: { font: { size: 11 }, color: "#6b7280", stepSize: 1 },
          beginAtZero: true,
        },
        y: {
          grid: { display: false },
          ticks: { font: { size: 11, weight: "600" }, color: "#374151" },
        },
      },
    },
  });
}
