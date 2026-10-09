/**
 * IQU Billing admin screens — tables, CSV of what is shown, charts, view-as-table.
 * Vanilla JavaScript, no jQuery. Loaded only on IQU Registrations → Billing screens,
 * after Chart.js (handle "chartjs"). Chart data arrives in window.IQU_BILLING.
 *
 * Markup hooks:
 *   table.iqu-dt                       sortable table; th > button.iqu-sort, th[data-type="num|date|text"]
 *   td[data-sort], td[data-csv]        sort key / CSV value when the visible text differs
 *   input[data-filter-for="ID"]        filter box for table #ID
 *   [data-count-for="ID"]              "N shown" counter for table #ID
 *   button[data-csv-for="ID"]          CSV of the rows currently shown (data-csv-name = file name)
 *   button[data-toggle-view="ID"]      switch chart <-> table inside card #ID
 */
(function (root) {
  "use strict";

  var B = {};

  /**
   * One CSV cell: a cell starting with = + - @ tab or CR gets a leading apostrophe so a
   * spreadsheet never runs it as a formula — except a plain number such as -30.00, which
   * cannot be a formula and must stay a number. Quoted when needed.
   */
  B.csvCell = function (value) {
    var s = value === null || value === undefined ? "" : String(value);
    if (/^[=+\-@\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = "'" + s;
    if (/[",\r\n]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
    return s;
  };

  B.toCSV = function (rows) {
    return rows.map(function (r) { return r.map(B.csvCell).join(","); }).join("\r\n");
  };

  /** Compare two sort keys of one column type. */
  B.compare = function (a, b, type) {
    if (type === "num") {
      var x = parseFloat(String(a).replace(/[^0-9.\-]/g, "")), y = parseFloat(String(b).replace(/[^0-9.\-]/g, ""));
      if (isNaN(x)) x = -Infinity;
      if (isNaN(y)) y = -Infinity;
      return x - y;
    }
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: "base" });
  };

  B.money = function (n) {
    var v = Number(n) || 0;
    return "$" + v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  function cellKey(td) {
    if (!td) return "";
    return td.hasAttribute("data-sort") ? td.getAttribute("data-sort") : td.textContent.trim();
  }

  function rowsOf(table) {
    var tb = table.tBodies[0];
    return tb ? Array.prototype.filter.call(tb.rows, function (r) { return !r.classList.contains("iqu-dt-none") && !r.classList.contains("iqu-dt-subtotal"); }) : [];
  }

  function updateCount(table) {
    var shown = rowsOf(table).filter(function (r) { return !r.hidden; }).length;
    var none = table.querySelector("tr.iqu-dt-none");
    if (none) none.classList.toggle("is-visible", shown === 0 && rowsOf(table).length > 0);
    document.querySelectorAll('[data-count-for="' + table.id + '"]').forEach(function (el) {
      el.textContent = shown + (shown === 1 ? " row" : " rows");
    });
  }

  function initSort(table) {
    var heads = table.tHead ? table.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(heads, function (th, col) {
      var btn = th.querySelector("button.iqu-sort");
      if (!btn) return;
      btn.addEventListener("click", function () {
        var dir = th.getAttribute("aria-sort") === "ascending" ? "descending" : "ascending";
        Array.prototype.forEach.call(heads, function (h) { if (h.hasAttribute("aria-sort")) h.setAttribute("aria-sort", "none"); });
        th.setAttribute("aria-sort", dir);
        var type = th.getAttribute("data-type") || "text";
        var rows = rowsOf(table);
        rows.sort(function (r1, r2) {
          var c = B.compare(cellKey(r1.cells[col]), cellKey(r2.cells[col]), type);
          return dir === "ascending" ? c : -c;
        });
        var tb = table.tBodies[0];
        rows.forEach(function (r) { tb.appendChild(r); });
        var none = tb.querySelector("tr.iqu-dt-none");
        if (none) tb.appendChild(none);
      });
    });
  }

  function initFilter(input) {
    var table = document.getElementById(input.getAttribute("data-filter-for"));
    if (!table) return;
    input.addEventListener("input", function () {
      var q = input.value.trim().toLowerCase();
      rowsOf(table).forEach(function (r) {
        r.hidden = q !== "" && r.textContent.toLowerCase().indexOf(q) === -1;
      });
      updateCount(table);
    });
    updateCount(table);
  }

  /** CSV of the visible rows: header row (columns without data-csv-skip) + shown body rows. */
  B.tableRows = function (table) {
    var heads = Array.prototype.slice.call(table.tHead.rows[0].cells);
    var keep = heads.map(function (th) { return !th.hasAttribute("data-csv-skip"); });
    var out = [heads.filter(function (th, i) { return keep[i]; }).map(function (th) { return th.textContent.trim(); })];
    rowsOf(table).forEach(function (r) {
      if (r.hidden) return;
      var line = [];
      Array.prototype.forEach.call(r.cells, function (td, i) {
        if (!keep[i]) return;
        line.push(td.hasAttribute("data-csv") ? td.getAttribute("data-csv") : td.textContent.replace(/\s+/g, " ").trim());
      });
      out.push(line);
    });
    return out;
  };

  function initCsv(btn) {
    btn.addEventListener("click", function () {
      var table = document.getElementById(btn.getAttribute("data-csv-for"));
      if (!table) return;
      var blob = new Blob(["﻿" + B.toCSV(B.tableRows(table))], { type: "text/csv;charset=utf-8" });
      var a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = btn.getAttribute("data-csv-name") || "iqu-billing.csv";
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    });
  }

  function initToggle(btn) {
    var card = document.getElementById(btn.getAttribute("data-toggle-view"));
    if (!card) return;
    var chart = card.querySelector(".iqu-chart-box"), table = card.querySelector(".iqu-chart-table");
    btn.addEventListener("click", function () {
      var showTable = btn.getAttribute("aria-expanded") !== "true";
      btn.setAttribute("aria-expanded", showTable ? "true" : "false");
      btn.textContent = showTable ? "View as chart" : "View as table";
      if (chart) chart.hidden = showTable;
      if (table) table.hidden = !showTable;
    });
  }

  // ── Charts ───────────────────────────────────────────────────
  function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue("--iqu-" + name).trim() || "#6b7280";
  }

  function tint(color, alpha) {
    var m = /^#([0-9a-f]{6})$/i.exec(color);
    if (!m) return color;
    var n = parseInt(m[1], 16);
    return "rgba(" + (n >> 16) + "," + ((n >> 8) & 255) + "," + (n & 255) + "," + alpha + ")";
  }

  function renderChart(cfg) {
    var canvas = document.getElementById(cfg.id);
    if (!canvas || typeof root.Chart === "undefined") return;
    var reduce = root.matchMedia && root.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var money = !!cfg.money;
    var fmt = function (v) { return money ? B.money(v) : String(v); };
    var round = cfg.type === "doughnut";

    var datasets = cfg.datasets.map(function (d) {
      var colors = (d.tones || [d.tone]).map(token);
      var ds = {
        label: d.label,
        data: d.data,
        type: d.kind || undefined,
        backgroundColor: round ? colors : (d.kind === "line" ? colors[0] : tint(colors[0], 0.85)),
        borderColor: round ? token("bg1") : colors[0],
        borderWidth: round ? 2 : (d.kind === "line" ? 2 : 0),
        borderRadius: d.kind === "line" || round ? 0 : 4,
        pointRadius: d.kind === "line" ? 3 : 0,
        tension: 0.25,
        stack: d.stack || undefined,
        order: d.kind === "line" ? 0 : 1
      };
      return ds;
    });

    var options = {
      responsive: true,
      maintainAspectRatio: false,
      animation: reduce ? false : { duration: 400 },
      plugins: {
        legend: { position: round ? "right" : "bottom", labels: { boxWidth: 12, color: token("text1") } },
        tooltip: { callbacks: { label: function (ctx) { return " " + (ctx.dataset.label ? ctx.dataset.label + ": " : ctx.label + ": ") + fmt(ctx.parsed.y !== undefined && !round ? ctx.parsed.y : ctx.parsed); } } }
      }
    };
    if (!round) {
      options.scales = {
        x: { stacked: !!cfg.stacked, grid: { display: false }, ticks: { color: token("text2") } },
        y: { stacked: !!cfg.stacked, beginAtZero: true, ticks: { color: token("text2"), precision: 0, callback: function (v) { return money ? "$" + Number(v).toLocaleString("en-US") : v; } }, grid: { color: token("bdr") } }
      };
    } else {
      options.cutout = "62%";
    }
    new root.Chart(canvas.getContext("2d"), { type: cfg.type === "doughnut" ? "doughnut" : "bar", data: { labels: cfg.labels, datasets: datasets }, options: options });
  }

  B.init = function () {
    document.querySelectorAll("table.iqu-dt").forEach(function (t) { initSort(t); updateCount(t); });
    document.querySelectorAll("input[data-filter-for]").forEach(initFilter);
    document.querySelectorAll("button[data-csv-for]").forEach(initCsv);
    document.querySelectorAll("button[data-toggle-view]").forEach(initToggle);
    var data = root.IQU_BILLING || {};
    (data.charts || []).forEach(function (c) {
      try { renderChart(c); } catch (e) { /* the table view still shows the numbers */ }
    });
  };

  root.IQUBilling = B;
  if (typeof document !== "undefined" && document.addEventListener) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", B.init);
    else B.init();
  }
})(typeof window !== "undefined" ? window : this);
