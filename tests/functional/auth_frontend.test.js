// Behaviour test for the e-mail verification / password-reset screens in api/js/auth.js, run with a stubbed DOM and api().
// Covers: tokens come from the URL fragment only and are scrubbed from the address bar; malformed links are ignored;
// registration never creates a session; an attacker-controlled e-mail cannot inject markup; no phone/OTP code remains.
// NOT a browser test: layout, CSS and real network behaviour are not exercised.
const fs = require("fs"), path = require("path");
const dir = process.argv[2] || path.join(__dirname, "..", "..", "api", "js");
const read = f => fs.readFileSync(path.join(dir, f), "utf8");
const esc = new Function(read("utils.js").replace(/^const /gm, "var ") + "; return esc;")();

let failures = 0;
const check = (name, ok, detail) => { if (!ok) failures++; console.log((ok ? "PASS " : "FAIL ") + name + (ok ? "" : " :: " + String(detail).slice(0, 200))); };

function sandbox(hash) {
  const els = {}, calls = [], toasts = [], store = { local: {}, session: {} }, urlChanges = [];
  const mk = () => ({ innerHTML: "", value: "", checked: false, dataset: {}, style: {}, classList: { toggle() {}, add() {}, remove() {} },
    querySelector: () => null, querySelectorAll: () => [], scrollIntoView() {}, focus() {} });
  const el = id => els[id] || (els[id] = mk());
  const apiMock = { responses: {} };
  const env = {
    esc, document: { getElementById: el, querySelectorAll: () => [], querySelector: () => null },
    api: async (ep, method, body) => { calls.push({ ep, method, body }); const r = apiMock.responses[ep.split("action=")[1]]; return typeof r === "function" ? r(body) : (r || { success: true }); },
    showToast: (m, t) => toasts.push([m, t]), showPage: () => {}, setTimeout: () => 0, requestAnimationFrame: () => 0, clearInterval: () => {}, console,
    location: { hash, pathname: "/", search: "" }, history: { replaceState: (a, b, u) => urlChanges.push(u) },
    localStorage: { setItem: (k, v) => store.local[k] = v, removeItem: () => {}, getItem: () => null },
    sessionStorage: { setItem: (k, v) => store.session[k] = v, removeItem: () => {}, getItem: () => null },
    window: {}, authToken: null, currentUser: null, customerCity: null, startInactivityWatcher: () => {}, loadCustomerDashboard: () => {},
    loadDriverDashboard: () => {},
  };
  const names = Object.keys(env);
  const api = new Function(...names, read("auth.js") + "\n;return {handleAuthFragment, renderEmailSent, renderResetForm, submitReset, submitEmailRegister, submitEmailLogin, renderAuthLogin, expandRole, typeofs: {sendOTP: typeof sendOTP, verifyOTP: typeof verifyOTP, renderPhoneForm: typeof renderPhoneForm}, getUser: () => currentUser};")(...names.map(n => env[n]));
  return { api, els, calls, toasts, store, urlChanges, apiMock, env };
}
const tick = () => new Promise(r => setImmediate(r));
const T = "ab12".repeat(16);

(async () => {
  let s = sandbox("#verify_email=" + T);
  s.els["auth-method-form"] = { innerHTML: "" };
  const handled = s.api.handleAuthFragment(); await tick();
  check("a valid #verify_email link is handled", handled === true);
  check("the token is scrubbed from the address bar immediately", s.urlChanges.length === 1 && !String(s.urlChanges[0]).includes(T) && !String(s.urlChanges[0]).includes("#"), s.urlChanges);
  check("the token goes to verify_email in the POST body only (not the URL)", s.calls.some(c => c.ep === "/auth.php?action=verify_email" && c.method === "POST" && c.body.token === T) && !s.calls.some(c => c.ep.includes(T)), s.calls);
  check("verification never stores a session or token", !Object.keys(s.store.local).length && !Object.keys(s.store.session).length);

  for (const bad of ["", "#verify_email=", "#verify_email=" + T.slice(1), "#verify_email=" + T.toUpperCase(), "#verify_email=" + T + "&x=1", "#reset_password=zz", "#evil=" + T, "#verify_email=" + T + "<script>"]) {
    const x = sandbox(bad); x.els["auth-method-form"] = { innerHTML: "" };
    if (x.api.handleAuthFragment() !== false || x.calls.length || x.urlChanges.length) { check("malformed link ignored: " + JSON.stringify(bad).slice(0, 40), false, x.calls); }
  }
  check("malformed / foreign fragments are ignored (no API call, no URL change)", true);

  s = sandbox("#reset_password=" + T); s.els["auth-method-form"] = { innerHTML: "" };
  s.api.handleAuthFragment();
  const formHtml = s.els["auth-method-form"].innerHTML;
  check("reset link shows the new-password form without echoing the token into the page", /reset-pass/.test(formHtml) && !formHtml.includes(T));
  s.els["reset-pass"] = { value: "short" }; s.els["reset-confirm"] = { value: "short" };
  await s.api.submitReset();
  check("a too-short password is rejected in the browser before any request", !s.calls.some(c => c.ep.includes("reset_password")) && s.toasts.length);
  s.els["reset-pass"] = { value: "a-good-password-1" }; s.els["reset-confirm"] = { value: "a-good-password-1" };
  s.apiMock.responses.reset_password = { success: true };
  await s.api.submitReset();
  const rc = s.calls.find(c => c.ep.includes("reset_password"));
  check("the reset request carries the token and passwords in the POST body", rc && rc.body.token === T && rc.body.password === "a-good-password-1" && rc.method === "POST", rc);
  check("no token or password is ever stored in local/session storage", !JSON.stringify(s.store).includes(T) && !JSON.stringify(s.store).includes("a-good-password"));

  // registration does not create a session
  s = sandbox(""); s.els["auth-method-form"] = { innerHTML: "" };
  Object.assign(s.els, { "reg-name": { value: "Ada" }, "reg-email": { value: "ada@example.com" }, "reg-pass": { value: "a-good-password-1" }, "reg-confirm": { value: "a-good-password-1" }, "reg-city": { value: "Calabar" } });
  s.apiMock.responses.register_email = { success: true, message: "sent" };
  await s.api.submitEmailRegister();
  check("registration shows the 'check your email' screen and creates NO session", /ada@example\.com/.test(s.els["auth-method-form"].innerHTML) && s.api.getUser() === null && !Object.keys(s.store.local).length && !Object.keys(s.store.session).length);
  s.els["reg-pass"].value = "short"; s.els["reg-confirm"].value = "short"; const before = s.calls.length;
  await s.api.submitEmailRegister();
  check("registration with a short password is stopped in the browser", s.calls.length === before);

  // unverified login -> resend screen; hostile e-mail cannot inject markup
  s = sandbox(""); s.els["auth-method-form"] = { innerHTML: "" };
  s.els["email-login-email"] = { value: "a@example.com" }; s.els["email-login-pass"] = { value: "whatever-1" }; s.els["email-remember"] = { checked: false };
  s.apiMock.responses.login_email = { error: "Please confirm", code: "email_unverified" };
  await s.api.submitEmailLogin();
  check("an unconfirmed account is sent to the resend screen (no session)", /Send the link again/.test(s.els["auth-method-form"].innerHTML) && s.api.getUser() === null);
  const evil = '"><img src=x onerror=alert(1)><script>alert(2)</script>';
  s.api.renderEmailSent(evil, false);
  const html = s.els["auth-method-form"].innerHTML;
  check("an attacker-chosen e-mail cannot inject markup into the confirmation screen", !/<img|<script/i.test(html) && html.includes("&lt;img"), html.slice(0, 200));

  check("no phone / OTP functions remain in auth.js", s.api.typeofs.sendOTP === "undefined" && s.api.typeofs.verifyOTP === "undefined" && s.api.typeofs.renderPhoneForm === "undefined" && !/otp|method-btn-phone/i.test(read("auth.js")));
  console.log(failures === 0 ? "AUTH_FRONTEND_OK" : "AUTH_FRONTEND_FAILED (" + failures + ")");
  process.exit(failures === 0 ? 0 : 1);
})();
