/**
 * Chart.js defaults for every IQU admin chart (Dashboard and Billing). Loaded right after
 * Chart.js and before admin.js / billing.js, so each chart inherits these instead of setting
 * its own text colours: every text the charts draw (axis labels, legends, tooltips) uses the
 * main dark text colour and the same font as the card titles, at 12px or larger. Grid lines
 * keep their light colour.
 *
 * The colours come from the admin's CSS tokens (--iqu-text1, --iqu-bdr, --iqu-bg1) and are read
 * again when the page is ready and as each chart is set up, so a palette that redefines the
 * tokens (dark mode) is followed too.
 *
 * Sharpness: the canvases are sized by Chart.js itself (responsive, whole device pixels at the
 * screen's devicePixelRatio, and redrawn when the zoom changes). No CSS may stretch a canvas
 * (no width / height: 100% !important) — that rescales the drawing and blurs the text.
 */
(function (root) {
  "use strict";
  if (!root.Chart || !root.Chart.defaults) return;

  var D = root.Chart.defaults;

  function apply() {
    var css = root.getComputedStyle(root.document.documentElement);
    var token = function (name, fallback) { return css.getPropertyValue("--iqu-" + name).trim() || fallback; };
    var ink = token("text1", "#1a1a2e");   // the card titles' colour
    var paper = token("bg1", "#ffffff");
    var line = token("bdr", "#e3ebf2");
    var family = token("font-sans", "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif");

    D.color = ink;
    D.font.family = family;
    D.font.size = 12;
    D.borderColor = line;

    D.plugins.legend.labels.color = ink;
    D.plugins.legend.labels.font = { family: family, size: 12, weight: "500" };

    // Tooltips: dark text on a light card, like the rest of the page.
    var tip = D.plugins.tooltip;
    tip.backgroundColor = paper;
    tip.borderColor = line;
    tip.borderWidth = 1;
    tip.titleColor = ink;
    tip.bodyColor = ink;
    tip.footerColor = ink;
    tip.titleFont = { family: family, size: 12, weight: "600" };
    tip.bodyFont = { family: family, size: 12 };
    tip.padding = 8;

    // Every axis (x, y, …): dark labels, light grid.
    D.scale.ticks.color = ink;
    D.scale.ticks.font = { family: family, size: 12 };
    D.scale.grid.color = line;
  }
  apply();
  // admin.js / billing.js build their charts when the page is ready; this listener is added first, so the
  // tokens are read again just before (Chart.js resolves axis options before any plugin hook runs).
  if (root.document.readyState === "loading") root.document.addEventListener("DOMContentLoaded", apply);

  // Crisp at any zoom: Chart.js draws a whole number of device pixels (e.g. 326 for a 261px box at
  // 125%), but keeps the first CSS size it set (261px = 326.25 device pixels), so the browser still
  // stretches the drawing by a fraction of a pixel. After every resize, show the canvas at exactly
  // its drawn pixels ÷ devicePixelRatio, so one canvas pixel is one screen pixel.
  // Chart.js rewrites the CSS size on every resize check, even when nothing changed (and then fires
  // no hook), so the check itself is wrapped as well as the resize / render hooks.
  root.Chart.register({
    id: "iquChartSetup",   // re-reads the colour tokens, keeps the canvas crisp
    beforeInit: function (chart) {
      apply();
      var check = chart._resize;
      if (typeof check !== "function") return;
      chart._resize = function () {
        var out = check.apply(this, arguments);
        crisp(this);
        return out;
      };
    },
    resize: function (chart) { crisp(chart); },
    afterRender: function (chart) { crisp(chart); }
  });
  function crisp(chart) {
    var c = chart.canvas, r = chart.currentDevicePixelRatio || root.devicePixelRatio || 1;
    if (!c || !c.width || !c.height) return;
    var w = c.width / r + "px", h = c.height / r + "px";
    if (c.style.width !== w) c.style.width = w;
    if (c.style.height !== h) c.style.height = h;
  }
})(window);
