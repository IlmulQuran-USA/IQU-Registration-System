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
        // Compact cards ask for the legend below, so it never squeezes the canvas.
        legend: cfg.legend === "bottom"
          ? { position: "bottom", labels: { boxWidth: 10, padding: 8, font: { size: 11 }, color: token("text1") } }
          : { position: round ? "right" : "bottom", labels: { boxWidth: 12, color: token("text1") } },
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

  /**
   * Add Student form (#iqu-add-student-form). cfg = window.IQU_BILLING_ADD:
   *   pricing  IQU_Pricing::js_config() — courses[key] = {label, minDays, maxDays, byDays[d].amount}
   *   families lowercased billing email -> {label, url} for the informational sibling notice
   * Course / day chips, live summary, lower-fee panel, inline checks with the server's own
   * messages. The server validates everything again; nothing here changes what is saved.
   */
  B.addStudent = function (form, cfg) {
    var courses = (cfg.pricing && cfg.pricing.courses) || {};
    var families = cfg.families || {};
    var summary = document.querySelector(".iqu-add-summary");
    var useAgreed = form.querySelector("#iqu-use-agreed");
    var zakat = form.querySelector('input[name="zakat"]');
    var panel = form.querySelector("#iqu-agreed-panel");
    var agreed = form.querySelector("#agreed_fee");
    var email = form.querySelector("#email");
    var note = form.querySelector("#iqu-family-note");
    var daysHint = form.querySelector("[data-days-hint]");
    var daysHintText = daysHint ? daysHint.textContent : "";

    function val(name) {
      var el = form.querySelector('input[name="' + name + '"]:checked');
      return el ? el.value : "";
    }
    function fee(course, days) {
      var c = courses[course];
      var row = c && c.byDays ? c.byDays[days] : null;
      return row ? Number(row.amount) : null;
    }
    function sum(key, text) {
      var el = summary && summary.querySelector('[data-sum="' + key + '"]');
      if (el) el.textContent = text;
    }
    function sumRow(key, on) {
      var el = summary && summary.querySelector('[data-sum-row="' + key + '"]');
      if (el) el.hidden = !on;
    }
    function setError(field, msg) {
      var box = form.querySelector("#iqu-err-" + field);
      var input = form.querySelector('[name="' + field + '"]');
      if (box) {
        box.querySelector(".iqu-fld-error-text").textContent = msg || "";
        box.hidden = !msg;
      }
      if (input && input.type !== "radio") {
        if (msg) input.setAttribute("aria-invalid", "true");
        else input.removeAttribute("aria-invalid");
      }
    }

    // Only the classes-a-week numbers the chosen course allows (Hifz: at least 3).
    function syncDays() {
      var c = courses[val("course")];
      var min = c ? Number(c.minDays) : 1;
      var max = c ? Number(c.maxDays) : 7;
      form.querySelectorAll(".iqu-chips--days .iqu-chip-opt").forEach(function (label) {
        var d = Number(label.getAttribute("data-day"));
        var input = label.querySelector("input");
        var ok = d >= min && d <= max;
        label.hidden = !ok;
        input.disabled = !ok;
        if (!ok && input.checked) input.checked = false;
      });
      if (daysHint) daysHint.textContent = c ? c.label + ": " + min + (max > min ? "–" + max : "") + " classes a week." : daysHintText;
    }

    function syncPanel() {
      var on = useAgreed.checked || (zakat && zakat.checked);
      panel.hidden = !on;
      useAgreed.setAttribute("aria-expanded", on ? "true" : "false");
    }

    function syncFamily() {
      if (!note || !email) return;
      var fam = families[String(email.value || "").trim().toLowerCase()];
      note.hidden = !fam;
      if (!fam) return;
      note.querySelector("[data-family-label]").textContent = fam.label || "";
      var link = note.querySelector("[data-family-link]");
      if (/^https?:\/\//.test(fam.url || "")) link.setAttribute("href", fam.url);
    }

    function standard() {
      return fee(val("course"), val("days"));
    }

    function agreedError() {
      if (!useAgreed.checked || agreed.value === "") return "";
      var a = Math.round(Number(agreed.value) * 100) / 100;
      var std = standard();
      if (isNaN(a)) return "";
      if (a < 0) return "The agreed fee cannot be negative.";
      if (std !== null && a > std) return "The agreed fee cannot be more than the standard fee (" + B.money(std) + ").";
      return "";
    }

    function update() {
      var first = form.querySelector("#first_name").value.trim();
      var last = form.querySelector("#last_name").value.trim();
      sum("name", (first + " " + last).trim() || "New student");
      var c = courses[val("course")];
      var days = val("days");
      sum("course", c ? c.label + (days ? " · " + days + " class" + (days === "1" ? "" : "es") + " a week" : " · choose classes a week") : "Choose a course and classes a week.");
      var std = standard();
      sum("std", std === null ? "—" : B.money(std) + " a month");
      var showAgreed = useAgreed.checked && agreed.value !== "" && !isNaN(Number(agreed.value)) && std !== null;
      var a = showAgreed ? Math.round(Number(agreed.value) * 100) / 100 : 0;
      sumRow("agreed", showAgreed);
      sumRow("diff", showAgreed && a <= std);
      if (showAgreed) {
        sum("agreed", a === 0 ? "$0.00 — full scholarship" : B.money(a) + " a month");
        sum("diff", "−" + B.money(Math.max(0, std - a)) + " a month");
      }
      sumRow("zakat", !!(zakat && zakat.checked));
    }

    // Same checks and words as the server (IQU_Billing_Add_Student::validate / prepare).
    function validate() {
      var bad = [];
      var first = form.querySelector("#first_name"), last = form.querySelector("#last_name");
      var names = first.value.trim() !== "" && last.value.trim() !== "";
      setError("first_name", names ? "" : "Enter the student's first and last name.");
      if (!last.value.trim()) last.setAttribute("aria-invalid", "true"); else last.removeAttribute("aria-invalid");
      if (!names) bad.push(first.value.trim() ? last : first);
      var mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
      setError("email", mail ? "" : "Enter a valid email address. Payment links and receipts go here.");
      if (!mail) bad.push(email);
      var wa = form.querySelector("#whatsapp");
      var waOk = wa.value.replace(/\D/g, "").length >= 7;
      setError("whatsapp", waOk ? "" : "Enter a WhatsApp number with country code.");
      if (!waOk) bad.push(wa);
      var course = val("course");
      setError("course", course ? "" : "Choose a course.");
      if (!course) bad.push(form.querySelector('input[name="course"]'));
      var days = val("days");
      setError("days", days ? "" : "Choose classes per week (1–7).");
      if (!days) bad.push(form.querySelector('.iqu-chips--days input:not([disabled])'));
      var fe = agreedError();
      setError("agreed_fee", fe);
      if (fe) bad.push(agreed);
      return bad;
    }

    form.addEventListener("change", function (e) {
      if (e.target.name === "course") { syncDays(); setError("course", ""); }
      if (e.target.name === "days") setError("days", "");
      if (e.target === useAgreed || e.target === zakat) syncPanel();
      setError("agreed_fee", agreedError());
      update();
    });
    form.addEventListener("input", function (e) {
      if (e.target === email) syncFamily();
      if (e.target === agreed) setError("agreed_fee", agreedError());
      if (e.target.id && form.querySelector("#iqu-err-" + e.target.id) && e.target !== agreed && e.target.getAttribute("aria-invalid")) setError(e.target.id, "");
      update();
    });
    form.addEventListener("submit", function (e) {
      var bad = validate();
      if (bad.length) {
        e.preventDefault();
        if (bad[0] && bad[0].focus) bad[0].focus();
      }
    });

    syncDays();
    syncPanel();
    syncFamily();
    update();
  };

  /** Human file size: "12.3 KB". */
  B.fileSize = function (n) {
    n = Number(n) || 0;
    if (n < 1024) return n + " bytes";
    if (n < 1048576) return (n / 1024).toFixed(1) + " KB";
    return (n / 1048576).toFixed(1) + " MB";
  };

  /**
   * Import drop zone ([data-dropzone]): the real file input covers the zone, so clicking,
   * keyboard (Enter / Space on the focused input) and dropping a file all work natively.
   * Shows the file name and size, warns about a non-.csv or too-large file (the server checks again).
   */
  B.importDrop = function (zone) {
    var input = zone.querySelector('input[type="file"]');
    var status = document.getElementById("iqu-csv-status");
    if (!input) return;
    function setStep(n) {
      document.querySelectorAll("[data-steps] li").forEach(function (li) {
        var s = Number(li.getAttribute("data-step"));
        li.classList.toggle("is-done", s < n);
        li.classList.toggle("is-current", s === n);
        if (s === n) li.setAttribute("aria-current", "step"); else li.removeAttribute("aria-current");
        var sr = li.querySelector(".iqu-steps-done");
        if (sr) sr.textContent = s < n ? " (done)" : "";
      });
    }
    function show() {
      var f = input.files && input.files[0];
      zone.classList.toggle("has-file", !!f);
      zone.classList.remove("is-warn");
      if (!status) return;
      if (!f) { status.textContent = ""; setStep(1); return; }
      var problem = "";
      if (!/\.csv$/i.test(f.name)) problem = "This does not look like a .csv file. In Excel or Google Sheets use \"Save as / Download as CSV\", then choose it again.";
      else if (f.size > 1048576) problem = "This file is larger than 1 MB. Split it into smaller files.";
      zone.classList.toggle("is-warn", !!problem);
      status.textContent = "Chosen: " + f.name + " (" + B.fileSize(f.size) + ")" + (problem ? " — " + problem : "");
      status.classList.toggle("is-warn", !!problem);
      setStep(2);
    }
    input.addEventListener("change", show);
    ["dragenter", "dragover"].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.add("is-drag"); });
    });
    ["dragleave", "dragend", "drop"].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.remove("is-drag"); });
    });
    if (input.files && input.files.length) show();
  };

  /** "Show only rows with problems" (checkbox[data-only-problems="TABLE_ID"]). */
  B.onlyProblems = function (box) {
    var table = document.getElementById(box.getAttribute("data-only-problems"));
    if (!table) return;
    function apply() {
      var any = false;
      table.querySelectorAll("tbody tr").forEach(function (tr) {
        if (tr.classList.contains("iqu-import-none")) return;
        var problem = tr.hasAttribute("data-problem");
        if (problem) any = true;
        tr.hidden = box.checked && !problem;
      });
      var none = table.querySelector(".iqu-import-none");
      if (none) none.hidden = !(box.checked && !any);
    }
    box.addEventListener("change", apply);
    apply();
  };

  B.init = function () {
    document.querySelectorAll("table.iqu-dt").forEach(function (t) { initSort(t); updateCount(t); });
    document.querySelectorAll("input[data-filter-for]").forEach(initFilter);
    document.querySelectorAll("button[data-csv-for]").forEach(initCsv);
    document.querySelectorAll("button[data-toggle-view]").forEach(initToggle);
    var addForm = document.getElementById("iqu-add-student-form");
    if (addForm) B.addStudent(addForm, root.IQU_BILLING_ADD || {});
    document.querySelectorAll("[data-dropzone]").forEach(B.importDrop);
    document.querySelectorAll("input[data-only-problems]").forEach(B.onlyProblems);
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
