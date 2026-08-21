/* ══════════════════════════════════════════════════════
   IQU · Zeffy Payment Collection — admin script
   Depends on: jQuery, Chart.js 4.x, window.IQU_Zeffy
   ══════════════════════════════════════════════════════ */

(function ($) {
  "use strict";

  if (typeof window.IQU_Zeffy === "undefined") return;

  var cfg = window.IQU_Zeffy;
  var $flash = null;

  // Shared palette — kept in sync with zeffy.css
  var COLOR = {
    purple: "#5b4ce0",
    green: "#0f9d58",
    amber: "#c98a00",
    teal: "#0d9488",
    blue: "#1e5fa0",
    violet: "#9333ea",
    rose: "#d13438",
    mute: "#6b7280",
    grid: "rgba(0,0,0,0.05)",
  };

  var SERIES = [
    COLOR.purple,
    COLOR.green,
    COLOR.amber,
    COLOR.teal,
    COLOR.blue,
    COLOR.violet,
    COLOR.rose,
  ];

  // ── Helpers ─────────────────────────────────────────
  function money(value) {
    return (
      "$" +
      Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  }

  function ctxOf(id) {
    var el = document.getElementById(id);
    return el ? el.getContext("2d") : null;
  }

  function hasData(list) {
    if (!list || !list.length) return false;
    return list.some(function (n) {
      return Number(n) > 0;
    });
  }

  function showEmpty(id, message) {
    var el = document.getElementById(id);
    if (!el || !el.parentNode) return;
    var note = document.createElement("div");
    note.className = "iquz-chart-empty";
    note.textContent = message || "Not enough data yet.";
    el.parentNode.replaceChild(note, el);
  }

  var baseTicks = { font: { size: 11 }, color: COLOR.mute };

  // ── Flash messaging ─────────────────────────────────
  function flash(message, kind) {
    if (!$flash || !$flash.length) return;

    $flash
      .removeClass("iquz-flash--ok iquz-flash--bad iquz-flash--busy")
      .addClass("iquz-flash--" + (kind || "busy"))
      .text(message)
      .prop("hidden", false);

    if (kind === "ok" || kind === "bad") {
      $("html, body").animate({ scrollTop: 0 }, 200);
    }
  }

  // ── Shared AJAX runner ──────────────────────────────
  function runAction(action, $button, busyLabel, onDone) {
    var original = $button.text();

    $button.prop("disabled", true).text(busyLabel);
    flash(busyLabel, "busy");

    $.post(cfg.ajax_url, { action: action, _wpnonce: cfg.nonce_sync })
      .done(function (response) {
        var payload = (response && response.data) || {};
        var message = payload.message || "Done.";

        if (response && response.success) {
          flash(message, "ok");
          if (typeof onDone === "function") onDone();
        } else {
          flash(message, "bad");
        }
      })
      .fail(function (xhr) {
        var message = cfg.i18n.failed;
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        }
        flash(message, "bad");
      })
      .always(function () {
        $button.prop("disabled", false).text(original);
      });
  }

  function reloadSoon() {
    window.setTimeout(function () {
      window.location.reload();
    }, 1200);
  }

  // ══════════════════════════════════════════════════
  // CHART 1 — Donations per month (area line)
  // ══════════════════════════════════════════════════
  function chartMonthly() {
    var ctx = ctxOf("iquz-month-chart");
    if (!ctx) return;

    var series = cfg.chart || { labels: [], data: [] };

    var gradient = ctx.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, "rgba(91, 76, 224, 0.28)");
    gradient.addColorStop(1, "rgba(91, 76, 224, 0.02)");

    new Chart(ctx, {
      type: "line",
      data: {
        labels: series.labels,
        datasets: [
          {
            data: series.data,
            borderColor: COLOR.purple,
            backgroundColor: gradient,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: "#fff",
            pointBorderColor: COLOR.purple,
            pointBorderWidth: 2,
            pointHoverRadius: 6,
            tension: 0.35,
            fill: true,
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
              label: function (item) {
                return "  " + money(item.parsed.y);
              },
            },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: baseTicks },
          y: {
            beginAtZero: true,
            grid: { color: COLOR.grid },
            ticks: {
              font: { size: 11 },
              color: COLOR.mute,
              callback: function (v) {
                return "$" + Number(v).toLocaleString();
              },
            },
          },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 3 — Top donors (horizontal bar)
  // ══════════════════════════════════════════════════
  function chartTopDonors(a) {
    var ctx = ctxOf("iquz-top-donors");
    if (!ctx) return;

    var d = a.top_donors || { labels: [], data: [], meta: [] };
    if (!hasData(d.data)) {
      showEmpty("iquz-top-donors", "No donations in the last 3 months yet.");
      return;
    }

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: d.labels,
        datasets: [
          {
            data: d.data,
            backgroundColor: d.labels.map(function (_, i) {
              // Fade the bars down the ranking so the top giver stands out.
              var alpha = 1 - Math.min(i * 0.07, 0.55);
              return "rgba(91, 76, 224, " + alpha.toFixed(2) + ")";
            }),
            borderRadius: 5,
            borderSkipped: false,
            barThickness: 14,
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
              label: function (item) {
                var info = (d.meta && d.meta[item.dataIndex]) || {};
                var lines = ["  " + money(item.parsed.x)];
                if (info.gifts) {
                  lines.push("  " + info.gifts + (info.gifts === 1 ? " donation" : " donations"));
                }
                if (info.recurring) lines.push("  Recurring donor");
                return lines;
              },
            },
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: COLOR.grid },
            ticks: {
              font: { size: 11 },
              color: COLOR.mute,
              callback: function (v) {
                return "$" + Number(v).toLocaleString();
              },
            },
          },
          y: { grid: { display: false }, ticks: baseTicks },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 2 — This month's donors (horizontal bar)
  // ══════════════════════════════════════════════════
  function chartMonthDonors(a) {
    var d = a.month_donors || { labels: [], data: [], meta: [], total: 0, donors: 0 };
    var $summary = $("#iquz-month-summary");

    if (!hasData(d.data)) {
      showEmpty(
        "iquz-month-donors",
        "No donations yet this month. The chart fills in as donations arrive."
      );
      $summary.text("Nothing received so far in " + (d.month || "this month") + ".");
      return;
    }

    var ctx = ctxOf("iquz-month-donors");
    if (!ctx) return;

    // Summary line doubles as the card's caption, so the chart
    // does not have to carry the totals itself.
    var shown = d.data.length;
    var extra = Math.max(0, Number(d.donors) - shown);
    var text =
      money(d.total) + " from " + d.donors + (d.donors === 1 ? " donor" : " donors");
    if (extra > 0) text += " — showing the top " + shown;
    $summary.html(text.replace(money(d.total), "<strong>" + money(d.total) + "</strong>"));

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: d.labels,
        datasets: [
          {
            data: d.data,
            backgroundColor: d.meta.map(function (m) {
              // Recurring donors get the amber accent used elsewhere
              // for subscriptions, so the colour language stays consistent.
              return m && m.recurring ? COLOR.amber : COLOR.green;
            }),
            borderRadius: 5,
            borderSkipped: false,
            barThickness: 14,
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
              label: function (item) {
                var info = (d.meta && d.meta[item.dataIndex]) || {};
                var lines = ["  " + money(item.parsed.x)];
                if (info.gifts) {
                  lines.push("  " + info.gifts + (info.gifts === 1 ? " donation" : " donations"));
                }
                if (info.last) lines.push("  Last donation " + info.last);
                if (info.recurring) lines.push("  Recurring donor");
                return lines;
              },
            },
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: COLOR.grid },
            ticks: {
              font: { size: 10 },
              color: COLOR.mute,
              callback: function (v) {
                return "$" + Number(v).toLocaleString();
              },
            },
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: 10 }, color: COLOR.mute },
          },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 4 — Recurring vs one-time (doughnut)
  // ══════════════════════════════════════════════════
  function chartTypeSplit(a) {
    var ctx = ctxOf("iquz-type-split");
    if (!ctx) return;

    var d = a.type_split || { labels: [], data: [], counts: [] };
    if (!hasData(d.data)) {
      showEmpty("iquz-type-split", "No donations recorded yet.");
      return;
    }

    new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: d.labels,
        datasets: [
          {
            data: d.data,
            backgroundColor: [COLOR.amber, COLOR.purple],
            borderColor: "#fff",
            borderWidth: 3,
            hoverOffset: 6,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "62%",
        plugins: {
          legend: {
            position: "bottom",
            labels: { font: { size: 11 }, color: COLOR.mute, boxWidth: 10, padding: 10 },
          },
          tooltip: {
            callbacks: {
              label: function (item) {
                var count = (d.counts && d.counts[item.dataIndex]) || 0;
                var total = d.data.reduce(function (sum, n) {
                  return sum + Number(n);
                }, 0);
                var pct = total > 0 ? Math.round((item.parsed / total) * 100) : 0;
                return "  " + money(item.parsed) + " · " + pct + "% · " + count + " donations";
              },
            },
          },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 5 — Campaign breakdown (doughnut)
  // ══════════════════════════════════════════════════
  function chartCampaigns(a) {
    var ctx = ctxOf("iquz-campaigns");
    if (!ctx) return;

    var d = a.campaigns || { labels: [], data: [] };
    if (!hasData(d.data)) {
      showEmpty("iquz-campaigns", "No campaign data yet.");
      return;
    }

    new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: d.labels,
        datasets: [
          {
            data: d.data,
            backgroundColor: SERIES,
            borderColor: "#fff",
            borderWidth: 3,
            hoverOffset: 6,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "62%",
        plugins: {
          legend: {
            position: "bottom",
            labels: { font: { size: 11 }, color: COLOR.mute, boxWidth: 10, padding: 10 },
          },
          tooltip: {
            callbacks: {
              label: function (item) {
                return "  " + money(item.parsed);
              },
            },
          },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 6 — Donation size distribution (vertical bar)
  // ══════════════════════════════════════════════════
  function chartDonationSizes(a) {
    var ctx = ctxOf("iquz-donation-sizes");
    if (!ctx) return;

    var d = a.donation_sizes || { labels: [], data: [] };
    if (!hasData(d.data)) {
      showEmpty("iquz-donation-sizes", "No donations recorded yet.");
      return;
    }

    // Highlight the most common bracket.
    var peak = d.data.indexOf(Math.max.apply(null, d.data));

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: d.labels,
        datasets: [
          {
            data: d.data,
            backgroundColor: d.data.map(function (_, i) {
              return i === peak ? COLOR.green : "rgba(91, 76, 224, 0.55)";
            }),
            borderRadius: 5,
            borderSkipped: false,
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
              label: function (item) {
                var n = item.parsed.y;
                return "  " + n + (n === 1 ? " donation" : " donations");
              },
            },
          },
        },
        scales: {
          x: { grid: { display: false }, ticks: baseTicks },
          y: {
            beginAtZero: true,
            grid: { color: COLOR.grid },
            ticks: { font: { size: 11 }, color: COLOR.mute, precision: 0 },
          },
        },
      },
    });
  }

  // ══════════════════════════════════════════════════
  // CHART 7 — New vs returning donors (stacked bar)
  // ══════════════════════════════════════════════════
  function chartDonorMix(a) {
    var ctx = ctxOf("iquz-donor-mix");
    if (!ctx) return;

    var d = a.donor_mix || { labels: [], new: [], returning: [] };
    if (!hasData(d.new) && !hasData(d.returning)) {
      showEmpty("iquz-donor-mix", "Not enough donor history yet.");
      return;
    }

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: d.labels,
        datasets: [
          {
            label: "Returning",
            data: d.returning,
            backgroundColor: COLOR.purple,
            borderRadius: 4,
            borderSkipped: false,
          },
          {
            label: "New",
            data: d["new"],
            backgroundColor: COLOR.green,
            borderRadius: 4,
            borderSkipped: false,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: "bottom",
            labels: { font: { size: 11 }, color: COLOR.mute, boxWidth: 10, padding: 10 },
          },
          tooltip: {
            callbacks: {
              label: function (item) {
                var n = item.parsed.y;
                return "  " + item.dataset.label + ": " + n + (n === 1 ? " donor" : " donors");
              },
            },
          },
        },
        scales: {
          x: { stacked: true, grid: { display: false }, ticks: baseTicks },
          y: {
            stacked: true,
            beginAtZero: true,
            grid: { color: COLOR.grid },
            ticks: { font: { size: 11 }, color: COLOR.mute, precision: 0 },
          },
        },
      },
    });
  }

  function renderCharts() {
    if (typeof Chart === "undefined") return;

    Chart.defaults.font.family =
      "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";

    chartMonthly();

    var a = cfg.analytics;
    if (!a) return;

    chartMonthDonors(a);
    chartTopDonors(a);
    chartTypeSplit(a);
    chartCampaigns(a);
    chartDonationSizes(a);
    chartDonorMix(a);
  }

  // ── Boot ────────────────────────────────────────────
  $(function () {
    $flash = $("#iquz-flash");

    renderCharts();

    $("#iquz-sync-now").on("click", function () {
      runAction("iqu_zeffy_sync_now", $(this), cfg.i18n.syncing, reloadSoon);
    });

    $("#iquz-test").on("click", function () {
      runAction("iqu_zeffy_test_connection", $(this), cfg.i18n.testing);
    });

    $("#iquz-backfill").on("click", function () {
      if (!window.confirm(cfg.i18n.confirm_backfill)) return;
      runAction("iqu_zeffy_backfill", $(this), cfg.i18n.importing, reloadSoon);
    });

    $(".iquz-toggle-detail").on("click", function () {
      var $detail = $(this).closest("tr").next(".iquz-detail-row");
      var isHidden = $detail.prop("hidden");

      $detail.prop("hidden", !isHidden);
      $(this).text(isHidden ? "Hide" : "Details");
    });

    $("#iquz-copy-endpoint").on("click", function () {
      var $button = $(this);
      var input = document.getElementById("iquz-endpoint");
      if (!input) return;

      var done = function () {
        var label = $button.text();
        $button.text("Copied ✓");
        window.setTimeout(function () {
          $button.text(label);
        }, 1600);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(done);
      } else {
        input.select();
        document.execCommand("copy");
        done();
      }
    });
  });
})(jQuery);