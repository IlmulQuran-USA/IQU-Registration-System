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
 * A table with data-iqu-pp / data-iqu-pg (IQU_Pager::local_attrs) uses those names instead (per_page,
 * paged: the server list's own). Its rows that are not on the current page wait, in their order
 * (data-iqu-i), in a hidden <tbody data-iqu-stash>, so the first <tbody> holds only the rows shown
 * (zebra stripes and the last row's border stay as on the server's page).
 * Rows that are not data rows (a "no match" row, year totals, import detail rows) are skipped; a
 * year total shows while its year has a row on the page; a detail row (iqu-pager-detail) goes with
 * the row above it.
 */
(function (root) {
  "use strict";

  var SIZES = [10, 20, 30], DEF = 10;
  var SKIP = /(^|\s)(iqu-dt-none|iqu-dt-subtotal|iqu-import-detail|iqu-import-none|iqu-pager-skip|iqu-pager-detail)(\s|$)/;
  var DETAIL = /(^|\s)iqu-pager-detail(\s|$)/;
  var P = { SIZES: SIZES };
  var state = {}; // table id → { per, page, order, stash }

  function query() { return new URLSearchParams(root.location.search); }

  /** Change the URL's query without a new history entry (Back and shared links show this view). */
  P.setQuery = function (changes) {
    var u = new URL(root.location.href);
    Object.keys(changes).forEach(function (k) {
      var v = changes[k];
      if (v === null || v === undefined || v === "") u.searchParams.delete(k); else u.searchParams.set(k, String(v));
    });
    showQuery(u.searchParams.toString());
  };

  /** This page's address with another query (replaceState). Never stops the list from updating. */
  function showQuery(search) {
    if (!root.history || !root.history.replaceState) return;
    try {
      var u = new URL(root.location.href);
      u.search = search;
      root.history.replaceState(root.history.state, "", u.pathname + u.search + u.hash);
    } catch (e) { /* the list is shown; only the address stays as it was */ }
  }

  function key(table) { return table.getAttribute("data-iqu-pager") || table.id; }

  /** The URL parameters a table keeps its page size and page in. */
  function params(table) {
    var k = key(table);
    return { pp: table.getAttribute("data-iqu-pp") || k + "_pp", pg: table.getAttribute("data-iqu-pg") || k + "_pg" };
  }

  /** A table whose hidden rows wait in a <tbody data-iqu-stash>: every data row in its order (data-iqu-i). */
  function collect(table) {
    var rows = [];
    Array.prototype.forEach.call(table.tBodies, function (tb) {
      var last = null;
      Array.prototype.forEach.call(tb.rows, function (r) {
        if (DETAIL.test(r.className)) { if (last) (last.iquDetail = last.iquDetail || []).push(r); return; }
        if (SKIP.test(r.className)) return;
        rows.push(r);
        last = r;
      });
    });
    return rows.sort(function (a, b) { return (Number(a.getAttribute("data-iqu-i")) || 0) - (Number(b.getAttribute("data-iqu-i")) || 0); });
  }

  /** The data rows of a table (filtered or not), in their current order. */
  P.rows = function (table) {
    var st = state[table.id];
    if (st && st.order) {
      st.order = st.order.filter(function (r) { return !!r.parentNode; }); // a deleted row is gone
      return st.order.slice();
    }
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

  /**
   * A pager control. A local list (data-iqu-pp) keeps the server page's own links (<a href>, the
   * same look; a new tab opens that page); other tables use buttons. A click stays on the page.
   */
  function control(table, cls, text, page, per) {
    var b;
    if (table.hasAttribute("data-iqu-pp")) {
      var p = params(table), u = new URL(root.location.href);
      if (per === DEF) u.searchParams.delete(p.pp); else u.searchParams.set(p.pp, String(per));
      if (page === 1) u.searchParams.delete(p.pg); else u.searchParams.set(p.pg, String(page));
      b = el("a", cls, text);
      b.href = u.toString();
    } else {
      b = el("button", cls, text);
      b.type = "button";
    }
    b.addEventListener("click", function (e) {
      if (b.tagName === "A" && (e.ctrlKey || e.metaKey || e.shiftKey || e.button)) return; // a new tab or window
      e.preventDefault();
      P.go(table, page, per);
    });
    return b;
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
        var b = control(table, "iqu-perpage-btn" + (st.per === n ? " iqu-perpage-btn--active" : ""), String(n), 1, n);
        if (st.per === n) b.setAttribute("aria-current", "true");
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
        div.appendChild(control(table, "page-numbers" + (cls ? " " + cls : ""), label, page, st.per));
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

  /** A stash table: the rows shown (and their detail rows) become the first <tbody>'s rows, the rest wait. */
  function arrange(table, st, all, on) {
    var tb = table.tBodies[0], shown = document.createDocumentFragment(), rest = document.createDocumentFragment();
    var none = table.querySelector("tr[data-iqu-none]");
    if (none) (none.hidden ? rest : shown).appendChild(none);
    all.forEach(function (r) {
      var here = on.has(r);
      r.hidden = !here;
      (here ? shown : rest).appendChild(r);
      (r.iquDetail || []).forEach(function (d) {
        if (!here) d.hidden = true; // a detail row that leaves the page closes
        (here ? shown : rest).appendChild(d);
      });
    });
    tb.appendChild(shown);
    st.stash.appendChild(rest);
  }

  /** Show the current page of the matching rows and redraw the chip, buttons, numbers and totals. */
  P.update = function (table) {
    if (!table || !table.id) return;
    var st = state[table.id];
    if (!st) { P.init(table); return; }
    var all = P.rows(table), match = all.filter(function (r) { return !r.hasAttribute("data-iqu-out"); }), total = match.length;
    var pages = Math.max(1, Math.ceil(total / st.per));
    if (st.page > pages) st.page = pages;
    if (st.page < 1) st.page = 1;
    var start = (st.page - 1) * st.per, end = start + st.per;
    var on = new Set(match.slice(start, end));
    if (st.stash) arrange(table, st, all, on);
    else {
      all.forEach(function (r) { r.hidden = !on.has(r); });
      // a year total shows while its year has a row on this page
      var group = [];
      Array.prototype.forEach.call(table.tBodies[0] ? table.tBodies[0].rows : [], function (r) {
        if (/(^|\s)iqu-dt-subtotal(\s|$)/.test(r.className)) {
          r.hidden = !group.some(function (g) { return !g.hidden; });
          group = [];
        } else if (!SKIP.test(r.className)) group.push(r);
      });
    }
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
    var p = params(table), ch = {};
    ch[p.pp] = st.per === DEF ? null : st.per;
    ch[p.pg] = st.page === 1 ? null : st.page;
    P.setQuery(ch);
    P.update(table);
  };

  /** After the filter changed: back to page 1. */
  P.refilter = function (table) {
    var st = state[table.id];
    if (st && st.page !== 1) {
      st.page = 1;
      var ch = {}; ch[params(table).pg] = null;
      P.setQuery(ch);
    }
    P.update(table);
  };

  /** A table's page size and page (null before it is set up). */
  P.state = function (table) {
    var st = table && state[table.id];
    return st ? { per: st.per, page: st.page } : null;
  };

  P.init = function (table) {
    if (!table.id) return;
    var q = query(), p = params(table);
    var per = parseInt(q.get(p.pp) || "", 10);
    var page = parseInt(q.get(p.pg) || "", 10);
    var stash = table.querySelector("tbody[data-iqu-stash]");
    state[table.id] = { per: SIZES.indexOf(per) !== -1 ? per : DEF, page: page > 0 ? page : 1, stash: stash, order: stash ? collect(table) : null };
    P.update(table);
  };

  /** Every pager table inside root (the page, or a part of it that was just replaced). */
  P.scan = function (scope) {
    (scope || document).querySelectorAll("table[data-iqu-pager]").forEach(P.init);
  };

  // ── Live search ───────────────────────────────────────────────
  // form[data-iqu-live="ID"] and its [data-iqu-live-region="ID"] (IQU_Pager::region_attrs). Results follow
  // the search box as you type (Enter at once) and every filter, tab and pill as soon as it changes; the URL
  // follows (replaceState); without JavaScript the form submits as before (a <noscript> button).
  //  • data-iqu-mode="local" (a list of up to IQU_Pager::LOCAL_MAX rows): every row is on the page with the
  //    values it is filtered on (IQU_Pager::row_attrs). Search, filters and pages are worked out here — no
  //    request at all, while typing, paging or changing the page size.
  //  • data-iqu-mode="remote" (a longer list): IQU_Pager::ajax() returns the region alone (nonce + capability).
  //    500 ms after the last key, a search of at least 2 characters (an empty box shows all), nothing when the
  //    query is unchanged, the last 20 answers kept in memory, at most one request a second per list, and a
  //    newer request cancels an older one. The page numbers and 10 / 20 / 30 use it too (no page reload).
  P.LOCAL_WAIT = 120;
  P.DEBOUNCE = 500;
  P.MIN_CHARS = 2;
  P.GAP = 1100; // one a second, with room for the network's jitter (the host's firewall counts arrivals)
  P.CACHE = 20;
  var WS = "[ \\t\\n\\x0B\\f\\r]";

  /** The URL for the form's current values: empty fields left out, back to page 1. */
  P.formUrl = function (form) {
    var u = new URL(form.getAttribute("action") || root.location.href, root.location.href);
    u.search = "";
    new FormData(form).forEach(function (v, k) {
      if (typeof v === "string" && v.trim() !== "") u.searchParams.append(k, v.trim());
    });
    return u;
  };

  /** sanitize_text_field(), as the server applies it to a search. */
  P.clean = function (v) {
    var s = String(v === null || v === undefined ? "" : v);
    if (s.indexOf("<") !== -1) {
      s = s.replace(/<[^>]*?((?=<)|>|$)/g, function (m) {
        return m.indexOf(">") === -1 ? m.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;") : m;
      });
      s = s.replace(/<(script|style)[^>]*?>[\s\S]*?<\/\1>/gi, "").replace(/<[^>]*>/g, "");
    }
    var trim = function (x) { return x.replace(new RegExp("^[ \\t\\n\\r\\0\\x0B]+|[ \\t\\n\\r\\0\\x0B]+$", "g"), ""); };
    s = trim(s.replace(/[\r\n\t ]+/g, " "));
    var found = false, m;
    while ((m = /%[a-f0-9]{2}/i.exec(s))) { s = s.split(m[0]).join(""); found = true; }
    if (found) s = trim(s.replace(/ +/g, " "));
    return s;
  };

  /** The database's case- and accent-insensitive comparison (utf8mb4 *_ci): "Éva" finds "eva". */
  function fold(s) {
    return String(s).normalize("NFD").replace(/[̀-ͯ]/g, "").normalize("NFC").toLowerCase();
  }

  /**
   * The filters a form applies to a local list's rows. The search box (data-iqu-search):
   *   "like"      the SQL search: a field (data-iqu-s, one per line) contains the text, any case or accent;
   *               a registration row (data-iqu-wa / data-iqu-id) also matches its WhatsApp digits and number
   *   "contains"  Student Billing's search: the row's text (lower case) contains the text
   * data-iqu-max cuts the text (Waitlist: 100); data-iqu-zero="none": "0" searches nothing (PHP empty()).
   * Every other named field compares with the rows' data-iqu-v-NAME (data-iqu-on="NAME" to use another):
   * equal to one of its lines, or data-iqu-op="ge" / "le" (dates as Y-m-d).
   */
  P.matcher = function (form) {
    var box = form.querySelector('input[type="search"]');
    var term = box ? P.clean(box.value) : "";
    var max = box ? parseInt(box.getAttribute("data-iqu-max") || "0", 10) : 0;
    if (max > 0) term = Array.from(term).slice(0, max).join("");
    var t = box && box.getAttribute("data-iqu-zero") === "none" && term === "0" ? "" : term;
    var tests = [], active = false;
    if (t !== "") {
      if ((box.getAttribute("data-iqu-search") || "like") === "contains") {
        var lc = t.toLowerCase();
        tests.push(function (r) { return (r.getAttribute("data-iqu-s") || "").indexOf(lc) !== -1; });
      } else {
        var q = fold(t), digits = t.replace(/\D+/g, "");
        var byDigits = digits.length >= 3 && new RegExp("^(?:\\d|" + WS + "|[()+\\-.])+$").test(t);
        var idm = new RegExp("^(?:iqu(?:" + WS + "|[\\-#])*|#)?(\\d{1,10})$", "i").exec(t);
        var id = idm ? String(parseInt(idm[1], 10)) : null;
        tests.push(function (r) {
          if (r.iquS === undefined) r.iquS = fold(r.getAttribute("data-iqu-s") || "");
          if (r.iquS.indexOf(q) !== -1) return true;
          if (byDigits && (r.getAttribute("data-iqu-wa") || "").indexOf(digits) !== -1) return true;
          return id !== null && r.getAttribute("data-iqu-id") === id;
        });
      }
    }
    Array.prototype.forEach.call(form.elements, function (f) {
      if (!f.name || f === box || f.disabled || f.name === "page" || f.name === "per_page" || f.name === "paged") return;
      if ((f.type === "checkbox" || f.type === "radio") && !f.checked) return;
      var v = String(f.value || "").trim();
      if (v === "") return;
      if (f.type !== "hidden") active = true;
      var attr = "data-iqu-v-" + (f.getAttribute("data-iqu-on") || f.name), op = f.getAttribute("data-iqu-op") || "eq";
      tests.push(function (r) {
        var have = r.getAttribute(attr);
        if (have === null) return true; // a list that does not filter on this field
        if (op === "ge") return have >= v;
        if (op === "le") return have <= v;
        return have.split("\n").indexOf(v) !== -1;
      });
    });
    return { term: term, active: active || t !== "", test: function (r) { return tests.every(function (fn) { return fn(r); }); } };
  };

  /** "No results for ‘xyz’" / the list's own empty text, when nothing matches (local lists). */
  function showNone(region, count, m) {
    var none = region.querySelector("[data-iqu-none]");
    if (!none) return;
    none.hidden = count !== 0;
    region.querySelectorAll("[data-iqu-none-hide]").forEach(function (e) { e.hidden = count === 0; });
    var pick = (m.term !== "" && none.querySelector("[data-iqu-none-search]"))
      || (m.active && none.querySelector("[data-iqu-none-filter]"))
      || none.querySelector("[data-iqu-none-empty]") || none.querySelector("[data-iqu-none-filter]");
    none.querySelectorAll("[data-iqu-none-search], [data-iqu-none-filter], [data-iqu-none-empty]").forEach(function (v) { v.hidden = v !== pick; });
    var text = pick && pick.hasAttribute("data-iqu-none-search") ? pick.querySelector(".iqu-no-results") : null;
    if (text) text.textContent = "No results for ‘" + m.term + "’.";
  }

  function ws(form) { return form.querySelector('input[type="search"]'); }

  function announce(form, n) {
    var status = form && form.querySelector("[data-iqu-live-status]"), box = form && ws(form), q = box ? box.value.trim() : "";
    if (status) status.textContent = n === 0 ? (q !== "" ? "No results for ‘" + q + "’" : "No results") : n + (n === 1 ? " result" : " results");
  }

  function say(form, text) {
    var status = form && form.querySelector("[data-iqu-live-status]");
    if (status) status.textContent = text;
  }

  /** "Clear filters": shown while a filter (not the page size) is set. */
  function syncClearAll(form) {
    var clearAll = form.querySelector("[data-iqu-clear-all]");
    if (clearAll) clearAll.hidden = !Array.prototype.some.call(form.elements, function (f) {
      return f.type !== "hidden" && f.name && f.name !== "per_page" && f.value !== "";
    });
  }

  /** Set (or clear) one form field from a tab / pill link (data-iqu-set="NAME=VALUE"). */
  function setField(form, spec) {
    var i = spec.indexOf("="), name = spec.slice(0, i), value = spec.slice(i + 1);
    var f = form.elements.namedItem(name);
    if (!f && value !== "") { f = document.createElement("input"); f.type = "hidden"; f.name = name; form.appendChild(f); }
    if (f) f.value = value;
    document.querySelectorAll("[data-iqu-tabs] a[data-iqu-set]").forEach(function (a) {
      var s = a.getAttribute("data-iqu-set");
      if (s.slice(0, s.indexOf("=")) !== name) return;
      var on = s === name + "=" + value, cls = a.closest("[data-iqu-tabs]").getAttribute("data-iqu-tabs");
      if (on) a.setAttribute("aria-current", "page"); else a.removeAttribute("aria-current");
      if (cls) a.classList.toggle(cls, on);
    });
  }

  /** Tab / pill links keep the current search and filters (a new tab, a copied link). */
  function syncTabLinks(form) {
    var base = P.formUrl(form);
    document.querySelectorAll("a[data-iqu-set]").forEach(function (a) {
      var s = a.getAttribute("data-iqu-set"), name = s.slice(0, s.indexOf("=")), value = s.slice(s.indexOf("=") + 1);
      var u = new URL(base.toString());
      u.searchParams.delete("paged");
      if (value === "") u.searchParams.delete(name); else u.searchParams.set(name, value);
      a.href = u.toString();
    });
  }

  function resetForm(form) {
    Array.prototype.forEach.call(form.elements, function (f) {
      if (!f.name || f.name === "page" || f.name === "per_page") return;
      if (f.tagName === "SELECT") f.selectedIndex = 0;
      else if (f.type === "checkbox" || f.type === "radio") f.checked = false;
      else f.value = "";
    });
    document.querySelectorAll("a[data-iqu-set]").forEach(function (a) {
      var s = a.getAttribute("data-iqu-set");
      if (s.slice(-1) === "=") setField(form, s);
    });
  }

  /** A local list: every row is here, nothing is requested. */
  function localList(id, form) {
    var timer = null;
    var region = function () { return document.querySelector('[data-iqu-live-region="' + id + '"]'); };

    function apply() {
      clearTimeout(timer);
      var r = region();
      if (!r) return;
      var t = r.querySelector("table[data-iqu-pager]"), m = P.matcher(form), count = 0;
      if (t) {
        P.rows(t).forEach(function (row) { if (m.test(row)) row.removeAttribute("data-iqu-out"); else row.setAttribute("data-iqu-out", ""); });
        count = P.matching(t).length;
      }
      showNone(r, count, m);
      if (t) P.refilter(t);
      var u = P.formUrl(form), st = P.state(t);
      u.searchParams.delete("per_page");
      u.searchParams.delete("paged");
      if (st && st.per !== DEF) u.searchParams.set("per_page", String(st.per));
      showQuery(u.searchParams.toString());
      announce(form, count);
      syncClearAll(form);
      syncTabLinks(form);
    }

    var box = ws(form);
    if (box) {
      box.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(apply, P.LOCAL_WAIT); });
      box.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); apply(); } });
    }
    form.addEventListener("change", function (e) { if (e.target !== box) apply(); });
    form.addEventListener("submit", function (e) { e.preventDefault(); apply(); });
    return {
      apply: apply,
      clear: function () { if (box) { box.value = ""; box.focus(); } apply(); },
      set: function (spec) { setField(form, spec); apply(); },
      reset: function () { resetForm(form); apply(); },
    };
  }

  /** A remote list: one small request for the region, at most one a second, answers kept in memory. */
  function remoteList(id, form) {
    var cache = [], ctrl = null, flying = "", sent = 0, timer = null, wait = null, want = null;
    var region = function () { return document.querySelector('[data-iqu-live-region="' + id + '"]'); };
    var box = form ? ws(form) : null;

    /** The same query whatever the order or the defaults (page 1, 10 a page). */
    function keyOf(u) {
      var p = new URLSearchParams(), src = new URL(u.toString(), root.location.href).searchParams;
      src.forEach(function (v, k) {
        if (v === "" || (k === "paged" && v === "1") || (k === "per_page" && v === String(DEF))) return;
        p.append(k, v);
      });
      p.sort();
      return p.toString();
    }
    var shown = keyOf(root.location.href);

    function remember(k, html) {
      cache = cache.filter(function (c) { return c.k !== k; });
      cache.push({ k: k, html: html });
      if (cache.length > P.CACHE) cache.shift();
    }
    function recall(k) {
      for (var i = 0; i < cache.length; i++) if (cache[i].k === k) { var c = cache.splice(i, 1)[0]; cache.push(c); return c.html; }
      return null;
    }
    var r0 = region();
    if (r0) remember(shown, r0.outerHTML);

    /** The admin URL for the form now: a search shorter than 2 characters is no search yet. */
    function wanted() {
      var u = P.formUrl(form);
      if (box && Array.from(box.value.trim()).length < P.MIN_CHARS) u.searchParams.delete(box.name);
      return u;
    }

    function busy(on) {
      var r = region();
      if (!r) return;
      if (on) r.setAttribute("aria-busy", "true"); else r.removeAttribute("aria-busy");
    }

    function show(html, u, k) {
      var fresh = new DOMParser().parseFromString(html, "text/html").querySelector('[data-iqu-live-region="' + id + '"]');
      var now = region();
      if (!fresh || !now) throw new Error("list not found");
      var node = document.importNode(fresh, true);
      now.replaceWith(node);
      shown = k;
      showQuery(u.searchParams.toString());
      if (form) {
        // the page size stays for the next search
        var per = u.searchParams.get("per_page"), f = form.elements.namedItem("per_page");
        if (per && per !== String(DEF)) {
          if (!f) { f = document.createElement("input"); f.type = "hidden"; f.name = "per_page"; form.appendChild(f); }
          f.value = per;
        } else if (f) f.parentNode.removeChild(f);
        var head = node.querySelector("[data-iqu-total]"), n = head ? parseInt(head.getAttribute("data-iqu-total"), 10) : NaN;
        if (isNaN(n)) n = node.querySelectorAll("tbody tr").length;
        announce(form, n);
        syncClearAll(form);
        syncTabLinks(form);
      }
      P.scan(node);
      document.dispatchEvent(new CustomEvent("iqu:region", { detail: { region: node, id: id } }));
    }

    function send() {
      wait = null;
      var u = want, k = keyOf(u), r = region();
      if (!r) return;
      if (ctrl) ctrl.abort();
      var mine = ctrl = new AbortController();
      flying = k;
      sent = Date.now();
      busy(true);
      say(form, "Searching…");
      var a = new URL(r.getAttribute("data-iqu-ajax"), root.location.href);
      u.searchParams.forEach(function (v, n) { a.searchParams.append(n, v); });
      a.searchParams.set("action", "iqu_list_region");
      a.searchParams.set("list", id);
      a.searchParams.set("_ajax_nonce", r.getAttribute("data-iqu-nonce") || "");
      fetch(a.toString(), { credentials: "same-origin", signal: mine.signal, headers: { "X-Requested-With": "IQUPager" } })
        .then(function (res) { if (!res.ok) throw new Error("HTTP " + res.status); return res.json(); })
        .then(function (j) {
          if (mine !== ctrl) return; // a newer request is on its way
          ctrl = null;
          flying = "";
          if (!j || !j.success || !j.data) throw new Error("no list");
          remember(k, j.data.html);
          show(j.data.html, u, k);
        })
        .catch(function (e) {
          if (e && e.name === "AbortError") return;
          if (mine === ctrl) { ctrl = null; flying = ""; }
          busy(false);
          say(form, "Could not update the list. Press Enter to try again.");
        });
    }

    /** Show the list for an admin URL: from memory, or one request (no sooner than a second after the last). */
    function request(u) {
      clearTimeout(timer);
      ["action", "list", "_ajax_nonce"].forEach(function (n) { u.searchParams.delete(n); }); // the admin page's own query only
      var k = keyOf(u);
      if (k === shown) { // nothing to change: drop what was waiting or on its way
        clearTimeout(wait); wait = null;
        if (ctrl) { ctrl.abort(); ctrl = null; flying = ""; busy(false); }
        if (form) say(form, "");
        return;
      }
      if (ctrl && k === flying) return;
      var html = recall(k);
      if (html !== null) {
        clearTimeout(wait); wait = null;
        if (ctrl) { ctrl.abort(); ctrl = null; flying = ""; }
        try { show(html, u, k); } catch (e) { say(form, "Could not update the list. Press Enter to try again."); }
        return;
      }
      want = u;
      if (wait) return; // already waiting for its turn: it sends the newest
      var gap = sent + P.GAP - Date.now();
      if (gap > 0) { wait = setTimeout(send, gap); busy(true); say(form, "Searching…"); }
      else send();
    }

    if (form) {
      if (box) {
        box.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(function () { request(wanted()); }, P.DEBOUNCE); });
        box.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); request(wanted()); } });
      }
      form.addEventListener("change", function (e) { if (e.target !== box) request(wanted()); });
      form.addEventListener("submit", function (e) { e.preventDefault(); request(wanted()); });
    }
    // the region's page numbers and 10 / 20 / 30 (server links): the same request, no page reload
    document.addEventListener("click", function (e) {
      var a = e.target.closest ? e.target.closest("a.page-numbers, a.iqu-perpage-btn") : null;
      if (!a || !a.closest('[data-iqu-live-region="' + id + '"]') || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
      e.preventDefault();
      request(new URL(a.href, root.location.href));
    });
    document.addEventListener("iqu:changed", function () { cache = []; }); // a row changed: older answers are out of date
    return {
      apply: function () { if (form) request(wanted()); },
      clear: function () { if (box) { box.value = ""; box.focus(); } request(wanted()); },
      set: function (spec) { setField(form, spec); request(wanted()); },
      reset: function () { resetForm(form); request(wanted()); },
    };
  }

  P.lists = {};

  /** Set up a list (its form may be null: Recent Submissions has page numbers only). */
  P.live = function (form, id) {
    id = id || form.getAttribute("data-iqu-live");
    var r = document.querySelector('[data-iqu-live-region="' + id + '"]');
    var mode = r ? r.getAttribute("data-iqu-mode") : "";
    if (P.lists[id] || (mode !== "local" && mode !== "remote") || (!form && mode === "local")) return;
    var list = P.lists[id] = mode === "local" ? localList(id, form) : remoteList(id, form);
    // "Clear search", tabs / pills (data-iqu-set) and "Clear filters" / "Reset" (data-iqu-reset)
    document.addEventListener("click", function (e) {
      if (!form || !e.target.closest || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
      var clear = e.target.closest("[data-iqu-clear]");
      if (clear && clear.closest('[data-iqu-live-region="' + id + '"]')) { e.preventDefault(); list.clear(); return; }
      var tab = e.target.closest("a[data-iqu-set]");
      if (tab && tab.getAttribute("data-iqu-set-for") === id) { e.preventDefault(); list.set(tab.getAttribute("data-iqu-set")); return; }
      var reset = e.target.closest("[data-iqu-reset]");
      if (reset && reset.getAttribute("data-iqu-reset") === id) { e.preventDefault(); list.reset(); }
    });
  };

  /** form[data-iqu-auto]: a choice (month, range, report) applies as soon as it changes. */
  P.auto = function (form) {
    form.addEventListener("change", function () { form.submit(); });
  };

  root.IQUPager = P;
  if (typeof document !== "undefined" && document.addEventListener) {
    var start = function () {
      P.scan();
      document.querySelectorAll("form[data-iqu-live]").forEach(function (f) { P.live(f); });
      document.querySelectorAll('[data-iqu-live-region][data-iqu-mode="remote"]').forEach(function (r) { P.live(null, r.getAttribute("data-iqu-live-region")); });
      document.querySelectorAll("form[data-iqu-auto]").forEach(P.auto);
    };
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
    else start();
  }
})(typeof window !== "undefined" ? window : this);
