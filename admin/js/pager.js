/**
 * IQU shared pager — the browser part of IQU_Pager (admin/class-iqu-pager.php).
 * Vanilla JavaScript, loaded on every IQU admin screen (before admin.js and billing.js).
 *
 * Tables filtered in the browser: table[data-iqu-pager="KEY"] with an id. The rows that match the
 * filter (rows without data-iqu-out) are shown a page at a time: default 10, 10 / 20 / 30.
 *   [data-iqu-pager-head="ID"]   filled with the "Showing 1–10 of 40" chip + 10 / 20 / 30
 *   [data-iqu-pager-foot="ID"]   filled with the page numbers (« Prev · 1 2 3 … · Next »)
 *   tfoot [data-iqu-sum]         recomputed over every matching row (all pages), never the page
 *   tfoot [data-iqu-total-label] "Total (N)" for the matching rows
 * State in the URL: KEY_pp (page size), KEY_pg (page); billing.js keeps the filter text in KEY_q.
 * Rows that are not data rows (a "no match" row, year totals, import detail rows) are skipped; a
 * year total shows while its year has a row on the page.
 */
(function (root) {
  "use strict";

  var SIZES = [10, 20, 30], DEF = 10;
  var SKIP = /(^|\s)(iqu-dt-none|iqu-dt-subtotal|iqu-import-detail|iqu-import-none|iqu-pager-skip)(\s|$)/;
  var P = { SIZES: SIZES };
  var state = {}; // table id → { per, page }

  function query() { return new URLSearchParams(root.location.search); }

  /** Change the URL's query without a new history entry (Back and shared links show this view). */
  P.setQuery = function (changes) {
    if (!root.history || !root.history.replaceState) return;
    var u = new URL(root.location.href);
    Object.keys(changes).forEach(function (k) {
      var v = changes[k];
      if (v === null || v === undefined || v === "") u.searchParams.delete(k); else u.searchParams.set(k, String(v));
    });
    root.history.replaceState(root.history.state, "", u.pathname + (u.searchParams.toString() ? "?" + u.searchParams.toString() : "") + u.hash);
  };

  function key(table) { return table.getAttribute("data-iqu-pager") || table.id; }

  /** The data rows of a table (filtered or not), in their current order. */
  P.rows = function (table) {
    var tb = table.tBodies[0];
    return tb ? Array.prototype.filter.call(tb.rows, function (r) { return !SKIP.test(r.className); }) : [];
  };

  /** The rows that match the table's filter (all pages). */
  P.matching = function (table) {
    return P.rows(table).filter(function (r) { return !r.hasAttribute("data-iqu-out"); });
  };

  P.showing = function (from, to, total) {
    return total ? "Showing " + from + "–" + to + " of " + total : "Showing 0 of 0";
  };

  function money(n) {
    return "$" + (Number(n) || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /** The page links: the same numbers paginate_links() prints (end 2, middle 3). */
  P.pageList = function (page, pages) {
    var out = [], dots = false;
    for (var n = 1; n <= pages; n++) {
      if (n <= 2 || n > pages - 2 || Math.abs(n - page) <= 3) { out.push(n); dots = false; }
      else if (!dots) { out.push(0); dots = true; }
    }
    return out;
  };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function renderHead(table, st, total, from, to) {
    document.querySelectorAll('[data-iqu-pager-head="' + table.id + '"]').forEach(function (h) {
      h.textContent = "";
      h.setAttribute("data-iqu-total", String(total));
      var chip = el("span", "iqu-card-head-badge iqu-pager-chip", P.showing(from, to, total));
      chip.setAttribute("aria-live", "polite");
      var group = el("div", "iqu-perpage-btns");
      group.setAttribute("role", "group");
      group.setAttribute("aria-label", h.getAttribute("data-iqu-pager-label") || "Rows per page");
      SIZES.forEach(function (n) {
        var b = el("button", "iqu-perpage-btn" + (st.per === n ? " iqu-perpage-btn--active" : ""), String(n));
        b.type = "button";
        if (st.per === n) b.setAttribute("aria-current", "true");
        b.addEventListener("click", function () { P.go(table, 1, n); });
        group.appendChild(b);
      });
      h.appendChild(chip);
      h.appendChild(group);
    });
  }

  function renderFoot(table, st, pages) {
    document.querySelectorAll('[data-iqu-pager-foot="' + table.id + '"]').forEach(function (f) {
      f.textContent = "";
      f.hidden = pages <= 1;
      if (pages <= 1) return;
      f.appendChild(el("span", "", "Page " + st.page + " of " + pages));
      var div = el("div");
      var add = function (label, page, cls) {
        var b = el("button", "page-numbers" + (cls ? " " + cls : ""), label);
        b.type = "button";
        b.addEventListener("click", function () { P.go(table, page, st.per); });
        div.appendChild(b);
      };
      if (st.page > 1) add("« Prev", st.page - 1, "prev");
      P.pageList(st.page, pages).forEach(function (n) {
        if (n === 0) { div.appendChild(el("span", "page-numbers dots", "…")); return; }
        if (n === st.page) {
          var cur = el("span", "page-numbers current", String(n));
          cur.setAttribute("aria-current", "page");
          div.appendChild(cur);
        } else add(String(n), n);
      });
      if (st.page < pages) add("Next »", st.page + 1, "next");
      f.appendChild(div);
    });
  }

  /** Footer totals over every matching row; the server's own text while nothing is filtered out. */
  function renderTotals(table, match, all, pages) {
    var tf = table.tFoot;
    if (!tf) return;
    var filtered = match.length !== all.length;
    tf.querySelectorAll("[data-iqu-sum], [data-iqu-sum-attr]").forEach(function (td) {
      if (!td.hasAttribute("data-iqu-orig")) td.setAttribute("data-iqu-orig", td.textContent);
      if (!filtered) { td.textContent = td.getAttribute("data-iqu-orig"); return; }
      // data-iqu-sum="COLUMN": the column's values; data-iqu-sum-attr="ATTR": a value each row carries
      // (e.g. "Collected" counts paid invoices only)
      var attr = td.getAttribute("data-iqu-sum-attr"), col = parseInt(td.getAttribute("data-iqu-sum"), 10), sum = 0;
      match.forEach(function (r) {
        var v;
        if (attr) v = parseFloat(r.getAttribute(attr) || "0");
        else {
          var c = r.cells[col];
          if (!c) return;
          v = parseFloat(c.hasAttribute("data-sort") ? c.getAttribute("data-sort") : c.textContent.replace(/[^0-9.\-]/g, ""));
        }
        if (!isNaN(v)) sum += v;
      });
      td.textContent = money(Math.round(sum * 100) / 100);
    });
    tf.querySelectorAll("[data-iqu-total-label]").forEach(function (td) {
      var base = td.getAttribute("data-iqu-total-label") || "Total";
      var count = td.hasAttribute("data-iqu-count") ? " (" + match.length + (filtered ? " matching" : "") + ")" : (filtered ? " (" + match.length + " matching)" : "");
      td.textContent = base + count + (pages > 1 ? " — all pages" : "");
    });
  }

  /** Show the current page of the matching rows and redraw the chip, buttons, numbers and totals. */
  P.update = function (table) {
    if (!table || !table.id) return;
    var st = state[table.id];
    if (!st) { P.init(table); return; }
    var all = P.rows(table), match = P.matching(table), total = match.length;
    var pages = Math.max(1, Math.ceil(total / st.per));
    if (st.page > pages) st.page = pages;
    if (st.page < 1) st.page = 1;
    var start = (st.page - 1) * st.per, end = start + st.per;
    var shown = match.slice(start, end);
    all.forEach(function (r) { r.hidden = shown.indexOf(r) === -1; });
    // a year total shows while its year has a row on this page
    var group = [];
    Array.prototype.forEach.call(table.tBodies[0] ? table.tBodies[0].rows : [], function (r) {
      if (/(^|\s)iqu-dt-subtotal(\s|$)/.test(r.className)) {
        r.hidden = !group.some(function (g) { return !g.hidden; });
        group = [];
      } else if (!SKIP.test(r.className)) group.push(r);
    });
    renderHead(table, st, total, total ? start + 1 : 0, Math.min(total, end));
    renderFoot(table, st, pages);
    renderTotals(table, match, all, pages);
    table.dispatchEvent(new CustomEvent("iqu:paged", { bubbles: true }));
  };

  /** Go to a page (and page size); the URL keeps it. */
  P.go = function (table, page, per) {
    var st = state[table.id];
    if (!st) return;
    if (per !== st.per) { st.per = per; page = 1; }
    st.page = page;
    var k = key(table), ch = {};
    ch[k + "_pp"] = st.per === DEF ? null : st.per;
    ch[k + "_pg"] = st.page === 1 ? null : st.page;
    P.setQuery(ch);
    P.update(table);
  };

  /** After the filter changed: back to page 1. */
  P.refilter = function (table) {
    var st = state[table.id];
    if (st && st.page !== 1) {
      st.page = 1;
      var ch = {}; ch[key(table) + "_pg"] = null;
      P.setQuery(ch);
    }
    P.update(table);
  };

  P.init = function (table) {
    if (!table.id) return;
    var q = query(), k = key(table);
    var per = parseInt(q.get(k + "_pp") || "", 10);
    var page = parseInt(q.get(k + "_pg") || "", 10);
    state[table.id] = { per: SIZES.indexOf(per) !== -1 ? per : DEF, page: page > 0 ? page : 1 };
    P.update(table);
  };

  /** Every pager table inside root (the page, or a part of it that was just replaced). */
  P.scan = function (scope) {
    (scope || document).querySelectorAll("table[data-iqu-pager]").forEach(P.init);
  };

  root.IQUPager = P;
  if (typeof document !== "undefined" && document.addEventListener) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { P.scan(); });
    else P.scan();
  }
})(typeof window !== "undefined" ? window : this);
