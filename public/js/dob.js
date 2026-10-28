/**
 * IQU Registration - Date of Birth control (Month | Day | Year)
 *
 * One module for the three public forms; the markup comes from IQU_Dob::render().
 * - Days follow the month and the year (29 February only in a leap year). A day that no
 *   longer exists is cleared and the field error is shown.
 * - The Year list runs from (this year − min age) down to (this year − max age).
 *   setRange() changes it (Summer level); a chosen date outside the new range keeps its
 *   value and shows the error.
 * - Once all three are chosen, the hidden `date_of_birth` input gets YYYY-MM-DD and the
 *   control fires "iqu:dob" with { iso, age, inRange }; an incomplete date fires it with iso "".
 * The server checks the date again (IQU_Validator::v_dob); this is only for the family.
 */
(function () {
  "use strict";

  const MSG_INVALID = "Please choose a valid date of birth.";

  function pad(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function isLeap(y) {
    return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
  }

  function daysIn(m, y) {
    if (m === 2) return !y || isLeap(y) ? 29 : 28; // no year yet: 29 stays possible
    return [4, 6, 9, 11].indexOf(m) !== -1 ? 30 : 31;
  }

  function today() {
    const d = new Date();
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  }

  /** Age in whole years; born on 29 February → a year older on 28 February in other years. */
  function ageOf(iso, on) {
    on = on || today();
    const y = Number(on.slice(0, 4));
    let m = Number(iso.slice(5, 7));
    let d = Number(iso.slice(8, 10));
    if (m === 2 && d === 29 && !isLeap(y)) d = 28;
    const birthday = y + "-" + pad(m) + "-" + pad(d);
    const age = y - Number(iso.slice(0, 4));
    return on < birthday ? age - 1 : age;
  }

  function parts(root) {
    return {
      month: root.querySelector(".iqu-dob-month"),
      day: root.querySelector(".iqu-dob-day"),
      year: root.querySelector(".iqu-dob-year"),
      value: root.querySelector(".iqu-dob-value"),
      error: root.querySelector('.iqu-error[data-field="date_of_birth"]'),
    };
  }

  function showError(root, msg) {
    const p = parts(root);
    if (p.error) p.error.textContent = msg;
    [p.month, p.day, p.year].forEach(function (s) {
      if (s) s.classList.toggle("iqu-invalid", !!msg);
    });
  }

  function range(root) {
    return { min: Number(root.dataset.minAge || 0), max: Number(root.dataset.maxAge || 100) };
  }

  /** The chosen date as { iso, age, inRange }; iso "" while incomplete. */
  function state(root) {
    const p = parts(root);
    const m = Number(p.month.value);
    const d = Number(p.day.value);
    const y = Number(p.year.value);
    if (!m || !d || !y) return { iso: "", age: null, inRange: false };
    const iso = y + "-" + pad(m) + "-" + pad(d);
    const r = range(root);
    const age = ageOf(iso);
    return { iso: iso, age: age, inRange: iso <= today() && age >= r.min && age <= r.max };
  }

  function rangeMessage(root) {
    const r = range(root);
    return "Age must be between " + r.min + " and " + r.max + ".";
  }

  function emit(root) {
    const s = state(root);
    parts(root).value.value = s.iso;
    root.dispatchEvent(new CustomEvent("iqu:dob", { bubbles: true, detail: s }));
    return s;
  }

  /** Rebuild the Day list for the month and year; an impossible day is cleared (true). */
  function syncDays(root) {
    const p = parts(root);
    const max = daysIn(Number(p.month.value), Number(p.year.value));
    const keep = Number(p.day.value);
    const have = p.day.options.length - 1;
    if (have !== max) {
      for (let i = have; i > max; i--) p.day.remove(i);
      for (let i = have + 1; i <= max; i++) p.day.add(new Option(String(i), String(i)));
    }
    if (keep > max) {
      p.day.value = "";
      return true;
    }
    return false;
  }

  /** Year list for [min, max]; a chosen year outside it stays (as the selected option). */
  function setYears(root) {
    const p = parts(root);
    const r = range(root);
    const now = new Date().getFullYear();
    const keep = p.year.value;
    while (p.year.options.length > 1) p.year.remove(1);
    for (let y = now - r.min; y >= now - r.max; y--) p.year.add(new Option(String(y), String(y)));
    if (keep && !Array.prototype.some.call(p.year.options, function (o) { return o.value === keep; })) {
      p.year.add(new Option(keep, keep));
    }
    p.year.value = keep;
  }

  function onChange(root) {
    const cleared = syncDays(root);
    const s = emit(root);
    if (cleared) showError(root, MSG_INVALID);
    else if (s.iso && !s.inRange) showError(root, s.iso > today() ? MSG_INVALID : rangeMessage(root));
    else showError(root, "");
  }

  function init(root) {
    if (!root || root.dataset.iquDobReady === "1") return;
    root.dataset.iquDobReady = "1";
    const p = parts(root);
    [p.month, p.day, p.year].forEach(function (s) {
      s.addEventListener("change", function () {
        onChange(root);
      });
    });
    setYears(root); // the browser's year, in case the page was cached across New Year
  }

  /** New age limits (Summer level). A chosen date outside them keeps its value and shows the error. */
  function setRange(root, min, max) {
    root.dataset.minAge = String(min);
    root.dataset.maxAge = String(max);
    setYears(root);
    syncDays(root);
    const s = emit(root);
    showError(root, s.iso && !s.inRange ? rangeMessage(root) : "");
  }

  /** At submit: every control in the form complete and in range; shows the errors. */
  function validate(form) {
    let ok = true;
    (form.querySelectorAll ? form : form[0]).querySelectorAll("[data-iqu-dob]").forEach(function (root) {
      const s = emit(root);
      if (!s.iso || s.iso > today()) {
        showError(root, MSG_INVALID);
        ok = false;
      } else if (!s.inRange) {
        showError(root, rangeMessage(root));
        ok = false;
      }
    });
    return ok;
  }

  window.IQU_DOB = { init: init, setRange: setRange, validate: validate, ageOf: ageOf, state: state };

  function initAll() {
    document.querySelectorAll("[data-iqu-dob]").forEach(init);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initAll);
  else initAll();
})();
