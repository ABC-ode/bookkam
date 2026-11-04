// Front-end checks for Batches 1-2 (no browser: the real js files run against a stubbed DOM / api()).
//   node tests/functional/admin_frontend.test.js
const fs = require("fs"), path = require("path");
const ROOT = path.join(__dirname, "..", "..", "api");
const read = f => fs.readFileSync(path.join(ROOT, f), "utf8");
let failed = 0, finished = false;   // a hung promise would let node exit quietly: treat that as a failure
process.on("exit", () => { if (!finished) { console.log("FAIL the test run stopped early (something never finished)"); process.exitCode = 1; } });
const check = (n, ok, d) => { console.log((ok ? "PASS " : "FAIL ") + n + (!ok && d !== undefined ? " :: " + JSON.stringify(d).slice(0, 200) : "")); if (!ok) failed++; };
const esc = new Function(read("js/utils.js").replace(/^const /gm, "var ") + ";return esc;")();
const tick = () => new Promise(r => setImmediate(r));

function sandbox(file, exportNames, over = {}) {
  const els = {}, calls = [], toasts = [], store = {}, responses = {};
  const mk = () => ({ innerHTML: "", value: "", checked: false, style: {}, classList: cl(), setAttribute(k, v) { this.attrs = Object.assign(this.attrs || {}, { [k]: v }); }, querySelector: () => null, focus() {} });
  const cl = () => { const s = new Set(); return { add: x => s.add(x), remove: x => s.delete(x), contains: x => s.has(x), toggle: (x, f) => { (f === undefined ? !s.has(x) : f) ? s.add(x) : s.delete(x); }, has: x => s.has(x) }; };
  const el = id => els[id] || (els[id] = mk());
  const env = Object.assign({
    esc, document: { getElementById: el, querySelectorAll: () => [], querySelector: () => null, addEventListener() {} }, window: { matchMedia: () => ({ matches: false }) },
    api: async (ep, m, b) => { calls.push({ ep, m, b }); const r = responses[ep.split("action=")[1]]; return typeof r === "function" ? r(b) : (r || { success: true }); },
    showToast: (m, t) => toasts.push([m, t]), showPage: p => store.page = p, setTimeout: () => 0, requestAnimationFrame: () => 0, clearInterval: () => {}, console, confirm: () => true,
    location: { hash: "", pathname: "/", search: "" }, history: { replaceState() {} }, navigator: {}, shimmerCards: () => "",
    localStorage: { setItem: (k, v) => store[k] = v, removeItem() {}, getItem: () => null }, sessionStorage: { setItem() {}, removeItem() {}, getItem: () => null },
    authToken: null, currentUser: null, customerCity: null, startInactivityWatcher() {}, loadCustomerDashboard() {}, loadDriverDashboard() {}, loadAdminDashboard: () => store.dash = true,
  }, over);
  const names = Object.keys(env);
  const mod = new Function(...names, read(file) + "\n;return {" + exportNames + ", get adminChallenge(){ return typeof adminChallenge === 'undefined' ? undefined : adminChallenge; }};")(...names.map(n => env[n]));
  return { mod, els, el, calls, toasts, store, responses };
}

(async () => {
  // ---------------- Batch 1: sign-in screen ----------------
  const auth = read("js/auth.js"), index = read("index.php"), css = read("css/style.css"), sw = read("sw.js"), admin = read("js/admin.js");
  check("B1 auth.js has no Apple sign-in code left", !/apple/i.test(auth));
  check("B1 the page no longer loads Apple's script", !/appleid|AppleID|startAppleLogin|method-btn-apple/i.test(index));
  check("B1 no role cards (the Driver/Customer chooser) in markup or CSS", !/auth-role-card|expandRole|collapseRole|selectedRole/.test(auth + css + index));
  let s = sandbox("js/auth.js", "renderAuthLogin, renderAuthMethods, selectMethod, renderEmailForm, submitEmailRegister, startGoogleLogin");
  s.mod.renderAuthLogin();
  const html = s.el("auth-expanded-area").innerHTML;
  check("B1 sign-in shows exactly: Email, Google, Guest", /method-btn-email/.test(html) && /method-btn-google/.test(html) && /browseAsGuest/.test(html) && !/driver/i.test(html) && !/method-btn-apple/.test(html), html.slice(0, 200));
  s.mod.selectMethod("email"); s.mod.renderEmailForm("register");
  const form = s.el("auth-method-form").innerHTML;
  check("B1 the Register tab offers 'I want to drive with BOOKKAM'", /id="reg-drive"/.test(form) && /drive with BOOKKAM/.test(form), form.slice(0, 120));
  // driver sign-IN is unchanged: login is role-agnostic, so the screen has nothing driver-specific to pick
  s.mod.renderEmailForm("login"); const lform = s.el("auth-method-form").innerHTML;
  check("B1 the Sign-in form has email + password and NO driver/role choice (the server decides the role)", /submitEmailLogin/.test(lform) && !/reg-drive|driver/i.test(lform), lform.slice(0, 150));
  for (const [ticked, role] of [[false, "customer"], [true, "driver"]]) {
    const x = sandbox("js/auth.js", "submitEmailRegister");
    x.el("reg-name").value = "Ada"; x.el("reg-email").value = "ada@x.example"; x.el("reg-pass").value = "long-enough-1"; x.el("reg-confirm").value = "long-enough-1"; x.el("reg-city").value = "Calabar"; x.el("reg-drive").checked = ticked;
    x.responses.register_email = { success: true }; await x.mod.submitEmailRegister();
    const call = x.calls.find(c => c.ep.includes("register_email"));
    check("B1 register sends role=" + role + (ticked ? " when the driver box is ticked" : " by default"), call && call.b.role === role && call.b.city === "Calabar", call);
  }
  // ---------------- Batch 1: static page checks ----------------
  check("B1 Hide Event: an EMPTY card list from the API is honoured (Array.isArray), not replaced by the built-in fallback", /Array\.isArray\(data\.cards\)/.test(index) && !/data\.cards && data\.cards\.length/.test(index));
  const tagVer = {}; for (const m of index.matchAll(/(?:src|href)="(\/(?:js|css)\/[a-z-]+\.(?:js|css))\?v=(\d+)"/g)) tagVer[m[1]] = m[2];
  const assets = {}; for (const m of sw.matchAll(/'(\/(?:js|css)\/[a-z-]+\.(?:js|css))\?v=(\d+)'/g)) assets[m[1]] = m[2];
  const missing = Object.keys(tagVer).filter(k => assets[k] !== tagVer[k]);
  check("B1 every local css/js tag carries ?v= and the service worker lists the SAME version", Object.keys(tagVer).length >= 8 && !missing.length, { tagVer, assets, missing });
  check("B1 no local css/js tag is missing its ?v=", !/(?:src|href)="\/(?:js|css)\/[a-z-]+\.(?:js|css)"/.test(index));
  check("B1 the service-worker cache name was bumped past v6", /CACHE_NAME = 'bookkam-v([7-9]|\d\d)'/.test(sw), sw.split("\n")[0]);
  check("B1 admin sidebar CSS: pinned-open class, mobile drawer, backdrop", /\.sidebar:is\(:hover, \.open\)/.test(css) && /#admin-sidebar\.open/.test(css) && /\.sidebar-backdrop\.show/.test(css) && !/\.sidebar:hover/.test(css));
  check("B1 the admin page has the sidebar backdrop element", /id="admin-sidebar-backdrop"/.test(index) && /id="tab-admin-settings"/.test(index));
  // ---------------- Batch 1: admin sidebar behaviour ----------------
  const a = sandbox("js/admin.js", "setAdminSidebar, toggleAdminSidebar, closeAdminSidebar, adminSwitchesHtml, adminTwoFactorCardHtml, setAdminSwitch, ADMIN_SWITCHES");
  const sb = a.el("admin-sidebar"), bd = a.el("admin-sidebar-backdrop"), btn = a.el("admin-menu-btn");
  a.mod.toggleAdminSidebar();
  check("B1 the toggle opens the sidebar, shows the backdrop and updates aria-expanded", sb.classList.contains("open") && bd.classList.contains("show") && btn.attrs["aria-expanded"] === "true");
  a.mod.toggleAdminSidebar();
  check("B1 a second tap closes it again", !sb.classList.contains("open") && !bd.classList.contains("show") && btn.attrs["aria-expanded"] === "false");
  a.mod.setAdminSidebar(true); a.mod.closeAdminSidebar(); check("B1 closeAdminSidebar (backdrop / ESC / tab pick) closes it", !sb.classList.contains("open"));

  // ---------------- Batch 2: switches + 2FA card ----------------
  const h = a.mod.adminSwitchesHtml({ bookings_enabled: true, payments_enabled: false, mapbox_enabled: false });
  check("B2 switches show ON/OFF and send explicit true/false", /setAdminSwitch\('bookings_enabled', false\)/.test(h) && /setAdminSwitch\('payments_enabled', true\)/.test(h) && /Mapbox/.test(h));
  check("B2 the switch text says what keeps working while OFF", /cancellations, refunds and payment verification keep working/.test(h) || /refunds and cancellations keep working/.test(h));
  check("B2 an error from the server is shown as text, never as markup", !/<b>/.test(a.mod.adminSwitchesHtml({ error: "x<b>boom" })) && /Try again/.test(a.mod.adminSwitchesHtml({ error: "x" })));
  await a.mod.setAdminSwitch("bookings_enabled", false);
  const sc = a.calls.find(c => c.ep.includes("set_app_setting")); check("B2 turning OFF sends a real boolean false", sc && sc.b.enabled === false && sc.b.key === "bookings_enabled", sc);
  const n = a.calls.length; await a.mod.setAdminSwitch("evil'; DROP", false); check("B2 an unknown switch key sends nothing", a.calls.length === n);
  const b = sandbox("js/admin.js", "setAdminSwitch", { confirm: () => false }); await b.mod.setAdminSwitch("bookings_enabled", false);
  check("B2 declining the 'turn OFF?' confirmation sends nothing", b.calls.length === 0);
  check("B2 2FA card: OFF shows Set up, ON shows Disable + recovery codes left", /startAdmin2faSetup/.test(a.mod.adminTwoFactorCardHtml({ enabled: false, required: false })) && /disableAdmin2fa/.test(a.mod.adminTwoFactorCardHtml({ enabled: true, recovery_codes_left: 3 })) && /Recovery codes left: 3/.test(a.mod.adminTwoFactorCardHtml({ enabled: true, recovery_codes_left: 3 })));
  check("B2 the Settings tab is wired into the sidebar and the tab handlers", /adminTab\('settings'/.test(admin) && /settings:\s+loadAdminSettings/.test(admin));

  // ---------------- Batch 2: admin sign-in step 2 ----------------
  const L = sandbox("js/auth.js", "adminLogin, submitAdmin2fa, renderAdmin2faStep, submitAdmin2faSetup, finishAdminSetup, adminSecretBlock, adminRecoveryCodesHtml, resetAdminLoginSteps");
  L.el("admin-email").value = "a@b.example"; L.el("admin-password").value = "pw";
  L.responses.admin_login = { two_factor_required: true, challenge: "CH1" }; await L.mod.adminLogin();
  check("B2 password OK + two_factor_required -> step 2 is shown, NO session, password field cleared", !L.store.dash && !L.store.bookkam_token && L.mod.adminChallenge === "CH1" && /admin-2fa-code/.test(L.el("admin-2fa-area").innerHTML) && L.el("admin-password").value === "");
  L.el("admin-2fa-code").value = "123 456"; L.responses.admin_login_2fa = { error: "Invalid code" }; await L.mod.submitAdmin2fa(false);
  check("B2 a wrong code keeps the challenge and clears the field", L.mod.adminChallenge === "CH1" && !L.store.dash && L.el("admin-2fa-code").value === "");
  L.el("admin-2fa-code").value = "123456"; L.responses.admin_login_2fa = { error: "Sign-in expired. Please start again." }; await L.mod.submitAdmin2fa(false);
  check("B2 an expired challenge sends the admin back to the password step", L.mod.adminChallenge === null && L.el("admin-2fa-area").style.display === "none");
  L.el("admin-password").value = "pw"; L.responses.admin_login = { two_factor_required: true, challenge: "CH2" }; await L.mod.adminLogin();
  L.el("admin-2fa-recovery").value = " abcd-1234-ef56 "; L.responses.admin_login_2fa = { token: "T", user: { role: "admin" } }; await L.mod.submitAdmin2fa(true);
  const rc = L.calls.filter(c => c.ep.includes("admin_login_2fa")).pop();
  check("B2 a recovery code is sent as recovery_code (trimmed) with the challenge", rc.b.recovery_code === "abcd-1234-ef56" && rc.b.challenge === "CH2" && !("code" in rc.b), rc);
  check("B2 the session is stored only after step 2 and the challenge is dropped", L.store.dash === true && L.store.bookkam_token === "T" && L.mod.adminChallenge === null);
  const codeCall = L.calls.find(c => c.b && c.b.code); check("B2 spaces inside an authenticator code are stripped", codeCall && codeCall.b.code === "123456", codeCall);
  check("B2 the challenge is never written to storage", !Object.values(L.store).some(v => String(v).includes("CH1") || String(v).includes("CH2")));
  check("B2 recovery codes are HTML-escaped", !/<img/.test(L.mod.adminRecoveryCodesHtml(["<img src=x onerror=1>"])));
  check("B2 only an otpauth:// link is ever rendered as a link", !/href/.test(L.mod.adminSecretBlock("ABCD", "javascript:alert(1)")) && /href="otpauth:/.test(L.mod.adminSecretBlock("ABCD", "otpauth://totp/x?secret=ABCD")));
  const S = sandbox("js/auth.js", "adminLogin, submitAdmin2faSetup, finishAdminSetup");
  S.el("admin-email").value = "a@b.example"; S.el("admin-password").value = "pw"; S.responses.admin_login = { needs_2fa_setup: true, challenge: "SC" };
  S.responses.admin_2fa_begin = { secret: "JBSWY3DPEHPK3PXP", otpauth_uri: "otpauth://totp/BOOKKAM:a?secret=JBSWY3DPEHPK3PXP" }; await S.mod.adminLogin(); await tick();
  check("B2 forced enrolment: begin is called with the challenge and the setup key is shown", S.calls.some(c => c.ep.includes("admin_2fa_begin") && c.b.challenge === "SC") && /JBSW Y3DP EHPK 3PXP/.test(S.el("admin-2fa-area").innerHTML) && !S.store.dash);
  S.el("admin-2fa-code").value = "654321"; S.responses.admin_2fa_enable = { success: true, recovery_codes: ["aaaa-bbbb-cccc"], token: "T2", user: { role: "admin" } }; await S.mod.submitAdmin2faSetup();
  check("B2 after enrolment the recovery codes are shown and the session starts only when the admin confirms they saved them", /aaaa-bbbb-cccc/.test(S.el("admin-2fa-area").innerHTML) && !S.store.dash);
  S.mod.finishAdminSetup(); check("B2 ...then the dashboard opens", S.store.dash === true && S.store.bookkam_token === "T2");

  finished = true; console.log(failed ? "ADMIN_FRONTEND_FAILED (" + failed + ")" : "ADMIN_FRONTEND_OK"); process.exit(failed ? 1 : 0);
})();
