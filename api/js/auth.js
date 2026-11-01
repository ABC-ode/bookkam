// ── AUTH.JS ───────────────────────────────────────────────────────────────────
// Google Client ID — replace with your actual client ID
const GOOGLE_CLIENT_ID = "506985141783-svn9ilskpk1et5sr6tedv7vkg2vlio53.apps.googleusercontent.com";

let countdownTimer  = null;
let activeMethod    = null; // "email" | "google"
let pendingSocial   = null; // holds {pending_token, email, name, photo, role} while the city picker is shown

// ── Cities list ───────────────────────────────────────────────────────────────
const CITIES = ["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"];

// ── Main render ───────────────────────────────────────────────────────────────
// One sign-in screen for everybody. Sign-in is role-agnostic (the server answers with the account's own role and
// handleAuthSuccess routes to the right dashboard), so drivers sign in here too. People who want to drive tick
// "I want to drive with BOOKKAM" on the Register tab.
function renderAuthLogin() {
  activeMethod   = null;
  pendingSocial  = null;
  if (countdownTimer) clearInterval(countdownTimer);

  document.getElementById("auth-content").innerHTML = `
    <div id="auth-expanded-area"></div>
  `;

  renderAuthMethods();
}

function renderAuthMethods() {
  activeMethod = null;

  document.getElementById("auth-expanded-area").innerHTML = `
    <div class="auth-methods-card" id="auth-methods-card">

      <div class="auth-methods-header">
        <span class="material-icons-outlined">person_outline</span>
        <span>Sign in or create an account</span>
      </div>

      <div class="auth-method-btns">
        <button class="auth-method-btn" id="method-btn-email" onclick="selectMethod('email')">
          <span class="material-icons-outlined">mail_outline</span>
          Continue with Email
        </button>
        <button class="auth-method-btn auth-method-google" id="method-btn-google" onclick="startGoogleLogin()">
          <svg width="18" height="18" viewBox="0 0 48 48" style="flex-shrink:0">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.08 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-3.59-13.46-8.83l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          </svg>
          Continue with Google
        </button>

        <div class="auth-divider"><span>or</span></div>
        <button class="auth-method-btn auth-method-guest" onclick="browseAsGuest()">
          <span class="material-icons-outlined">visibility</span>
          Browse as Guest
        </button>
      </div>

      <div id="auth-method-form"></div>

    </div>
  `;

  // Animate in
  requestAnimationFrame(() => {
    const card = document.getElementById("auth-methods-card");
    if (card) card.classList.add("expanded");
  });
}

// ── Select login method ───────────────────────────────────────────────────────
function selectMethod(method) {
  activeMethod = method;

  // Highlight active method button
  document.querySelectorAll(".auth-method-btn").forEach(b => b.classList.remove("active"));
  const btn = document.getElementById("method-btn-" + method);
  if (btn) btn.classList.add("active");

  if (method === "email") renderEmailForm("login");
}

// ── Email form ────────────────────────────────────────────────────────────────
function renderEmailForm(tab = "login") {
  const loginActive    = tab === "login"    ? "active" : "";
  const registerActive = tab === "register" ? "active" : "";

  document.getElementById("auth-method-form").innerHTML = `
    <div class="auth-form-slide">
      <div class="auth-email-tabs">
        <button class="auth-tab ${loginActive}"    onclick="renderEmailForm('login')">Login</button>
        <button class="auth-tab ${registerActive}" onclick="renderEmailForm('register')">Register</button>
      </div>

      ${tab === "login" ? `
        <div class="input-group">
          <label>Email</label>
          <input type="email" id="email-login-email" class="input-field" placeholder="you@example.com"
            onkeypress="if(event.key==='Enter') submitEmailLogin()">
        </div>
        <div class="input-group">
          <label>Password</label>
          <div class="password-wrap">
            <input type="password" id="email-login-pass" class="input-field" placeholder="••••••••"
              onkeypress="if(event.key==='Enter') submitEmailLogin()">
            <button class="password-toggle" onclick="togglePassword('email-login-pass')">
              <span class="material-icons-outlined">visibility</span>
            </button>
          </div>
        </div>
        <label class="auth-remember-label">
          <input type="checkbox" id="email-remember"> Remember me for 7 days
        </label>
        <button class="btn btn-gold btn-full" onclick="submitEmailLogin()">
          <span class="material-icons-outlined">login</span> Login
        </button>
        <button class="btn btn-ghost btn-full" onclick="renderForgotForm()">Forgot password?</button>
      ` : `
        <div class="input-group">
          <label>Full Name</label>
          <input type="text" id="reg-name" class="input-field" placeholder="Your full name">
        </div>
        <div class="input-group">
          <label>Email</label>
          <input type="email" id="reg-email" class="input-field" placeholder="you@example.com">
        </div>
        <div class="input-group">
          <label>Password</label>
          <div class="password-wrap">
            <input type="password" id="reg-pass" class="input-field" placeholder="At least 8 characters">
            <button class="password-toggle" onclick="togglePassword('reg-pass')">
              <span class="material-icons-outlined">visibility</span>
            </button>
          </div>
        </div>
        <div class="input-group">
          <label>Confirm Password</label>
          <div class="password-wrap">
            <input type="password" id="reg-confirm" class="input-field" placeholder="Repeat password">
            <button class="password-toggle" onclick="togglePassword('reg-confirm')">
              <span class="material-icons-outlined">visibility</span>
            </button>
          </div>
        </div>
        <div class="input-group">
          <label>City</label>
          <select id="reg-city" class="input-field">
            ${CITIES.map(c => `<option value="${c}">${c}</option>`).join("")}
          </select>
        </div>
        <label class="auth-remember-label">
          <input type="checkbox" id="reg-drive"> I want to drive with BOOKKAM
        </label>
        <button class="btn btn-gold btn-full" onclick="submitEmailRegister()">
          <span class="material-icons-outlined">person_add</span> Create Account
        </button>
      `}

      <button class="btn btn-ghost btn-full auth-close-btn" onclick="closeMethodForm()">
        <span class="material-icons-outlined">close</span> Close
      </button>
    </div>
  `;
  setTimeout(() => document.querySelector(".auth-form-slide input")?.focus(), 100);
}

// ── City picker (after Google first login) ───────────────────────────────
function renderCityPicker(displayName) {
  document.getElementById("auth-method-form").innerHTML = `
    <div class="auth-form-slide auth-city-picker">
      <p class="auth-welcome-msg">👋 Welcome${displayName ? ", " + displayName : ""}!</p>
      <p style="color:var(--muted);font-size:13px;margin-bottom:16px;text-align:center">One last step — pick your city</p>
      <div class="input-group">
        <label>City</label>
        <select id="social-city" class="input-field">
          ${CITIES.map(c => `<option value="${c}">${c}</option>`).join("")}
        </select>
      </div>
      <button class="btn btn-gold btn-full" onclick="submitCityPicker()">
        <span class="material-icons-outlined">arrow_forward</span> Continue
      </button>
    </div>
  `;
}

// ── Close method form ─────────────────────────────────────────────────────────
function closeMethodForm() {
  activeMethod = null;
  document.querySelectorAll(".auth-method-btn").forEach(b => b.classList.remove("active"));
  document.getElementById("auth-method-form").innerHTML = "";
}

// ── Password toggle ───────────────────────────────────────────────────────────
function togglePassword(id) {
  const input = document.getElementById(id);
  const btn   = input?.nextElementSibling?.querySelector(".material-icons-outlined");
  if (!input) return;
  input.type = input.type === "password" ? "text" : "password";
  if (btn) btn.textContent = input.type === "password" ? "visibility" : "visibility_off";
}

// ── Email login ───────────────────────────────────────────────────────────────
async function submitEmailLogin() {
  const email      = document.getElementById("email-login-email")?.value?.trim();
  const password   = document.getElementById("email-login-pass")?.value;
  const rememberMe = !!document.getElementById("email-remember")?.checked;

  if (!email || !password) { showToast("Enter email and password", "error"); return; }

  const data = await api("/auth.php?action=login_email", "POST", { email, password, remember_me: rememberMe });
  if (data.code === "email_unverified") { renderEmailSent(email, true); return; }
  if (data.error) { showToast(data.error, "error"); return; }

  handleAuthSuccess(data);
}

// ── Email register ────────────────────────────────────────────────────────────
async function submitEmailRegister() {
  const name    = document.getElementById("reg-name")?.value?.trim();
  const email   = document.getElementById("reg-email")?.value?.trim();
  const pass    = document.getElementById("reg-pass")?.value;
  const confirm = document.getElementById("reg-confirm")?.value;
  const city    = document.getElementById("reg-city")?.value;
  const role    = document.getElementById("reg-drive")?.checked ? "driver" : "customer";

  if (!name)            { showToast("Enter your full name", "error"); return; }
  if (!email)           { showToast("Enter your email", "error"); return; }
  if (!pass)            { showToast("Enter a password", "error"); return; }
  if (pass.length < 8)  { showToast("Password must be at least 8 characters", "error"); return; }
  if (pass !== confirm) { showToast("Passwords do not match", "error"); return; }

  const data = await api("/auth.php?action=register_email", "POST", {
    name, email, password: pass, confirm, role, city
  });
  if (data.error) { showToast(data.error, "error"); return; }

  renderEmailSent(email, false);   // the account only works after the e-mailed link has been opened
}

// -- E-mail confirmation / password reset screens -------------------------------
// Links in e-mails look like  https://site/#verify_email=<64 hex>  or  #reset_password=<64 hex>. The token lives in the
// URL FRAGMENT so it is never sent to the server, written to logs or leaked in a Referer header; it is removed from the
// address bar as soon as it has been read.
function authForm(html) {
  const box = document.getElementById("auth-method-form");
  if (box) box.innerHTML = `<div class="auth-form-slide">${html}</div>`;
}

function renderEmailSent(email, wasUnverified) {
  const e = esc(email);
  authForm(`
    <p style="margin:0 0 12px">${wasUnverified
      ? "Your email address is not confirmed yet."
      : "Almost done! We sent a confirmation link to"}
      <strong>${e}</strong>.</p>
    <p style="margin:0 0 16px;opacity:.8">Open the link in that email to activate your account, then sign in.
      Check your spam folder if you cannot find it. The link works once and expires in 24 hours.</p>
    <button class="btn btn-gold btn-full" onclick="resendVerification(this.dataset.email)" data-email="${e}">
      <span class="material-icons-outlined">forward_to_inbox</span> Send the link again
    </button>
    <button class="btn btn-ghost btn-full" onclick="renderEmailForm('login')">Back to login</button>`);
}

async function resendVerification(email) {
  const data = await api("/auth.php?action=resend_verification", "POST", { email });
  showToast(data.error || data.message || "If that address can be used, we sent an email.", data.error ? "error" : "success");
}

function renderForgotForm() {
  authForm(`
    <p style="margin:0 0 12px">Enter your account email and we will send you a link to choose a new password.</p>
    <div class="input-group"><label>Email</label>
      <input type="email" id="forgot-email" class="input-field" placeholder="you@example.com"
        onkeypress="if(event.key==='Enter') submitForgot()"></div>
    <button class="btn btn-gold btn-full" onclick="submitForgot()">
      <span class="material-icons-outlined">mail</span> Send reset link</button>
    <button class="btn btn-ghost btn-full" onclick="renderEmailForm('login')">Back to login</button>`);
  setTimeout(() => document.getElementById("forgot-email")?.focus(), 100);
}

async function submitForgot() {
  const email = document.getElementById("forgot-email")?.value?.trim();
  if (!email) { showToast("Enter your email", "error"); return; }
  const data = await api("/auth.php?action=forgot_password", "POST", { email });
  if (data.error) { showToast(data.error, "error"); return; }
  authForm(`
    <p style="margin:0 0 12px">If an account exists for <strong>${esc(email)}</strong>, a reset link is on its way.
      It works once and expires in 1 hour.</p>
    <button class="btn btn-ghost btn-full" onclick="renderEmailForm('login')">Back to login</button>`);
}

function renderResetForm(token) {
  authForm(`
    <p style="margin:0 0 12px">Choose a new password (8 to 72 characters).</p>
    <div class="input-group"><label>New password</label>
      <div class="password-wrap"><input type="password" id="reset-pass" class="input-field" autocomplete="new-password">
        <button class="password-toggle" onclick="togglePassword('reset-pass')"><span class="material-icons-outlined">visibility</span></button></div></div>
    <div class="input-group"><label>Confirm password</label>
      <div class="password-wrap"><input type="password" id="reset-confirm" class="input-field" autocomplete="new-password"
        onkeypress="if(event.key==='Enter') submitReset()">
        <button class="password-toggle" onclick="togglePassword('reset-confirm')"><span class="material-icons-outlined">visibility</span></button></div></div>
    <button class="btn btn-gold btn-full" onclick="submitReset()"><span class="material-icons-outlined">lock_reset</span> Save new password</button>`);
  window._resetToken = token;      // kept in memory only; never written to storage or the URL
}

async function submitReset() {
  const password = document.getElementById("reset-pass")?.value || "";
  const confirm  = document.getElementById("reset-confirm")?.value || "";
  if (password.length < 8)  { showToast("Password must be at least 8 characters", "error"); return; }
  if (password !== confirm) { showToast("Passwords do not match", "error"); return; }
  const data = await api("/auth.php?action=reset_password", "POST", { token: window._resetToken, password, confirm });
  if (data.error) { showToast(data.error, "error"); return; }
  window._resetToken = null;
  showToast("Password updated. Please sign in.", "success");
  renderEmailForm("login");
}

async function verifyEmailToken(token) {
  authForm(`<p style="margin:0">Confirming your email address...</p>`);
  const data = await api("/auth.php?action=verify_email", "POST", { token });
  if (data.error) {
    authForm(`<p style="margin:0 0 12px">${esc(data.error)}</p>
      <button class="btn btn-ghost btn-full" onclick="renderEmailForm('login')">Back to login</button>`);
    return;
  }
  showToast("Email confirmed. You can now sign in.", "success");
  renderEmailForm("login");
}

// Called once on page load (app.js). Returns true when the page was opened from an e-mailed link.
function handleAuthFragment() {
  const m = /^#(verify_email|reset_password)=([a-f0-9]{64})$/.exec(location.hash || "");
  if (!m) return false;
  history.replaceState(null, "", location.pathname + location.search);
  showPage("page-auth");
  renderAuthLogin();
  if (m[1] === "verify_email") verifyEmailToken(m[2]); else renderResetForm(m[2]);
  return true;
}

// ── Google login ──────────────────────────────────────────────────────────────
function startGoogleLogin() {
  if (!window.google) { showToast("Google Sign-In not loaded", "error"); return; }
  window.google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback:  handleGoogleCallback,
  });
  window.google.accounts.id.prompt(notification => {
    if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
      // Fallback to popup
      window.google.accounts.id.renderButton(
        document.createElement("div"),
        { theme: "outline", size: "large" }
      );
    }
  });
}

async function handleGoogleCallback(response) {
  const data = await api("/auth.php?action=google_login", "POST", {
    id_token: response.credential,
    role: "customer",
    remember_me: true,
  });

  if (data.error) { showToast(data.error, "error"); return; }

  if (data.needs_city) {
    pendingSocial = { type: "google", ...data };
    renderCityPicker(data.name);
    return;
  }

  handleAuthSuccess(data);
}

// ── City picker submit ────────────────────────────────────────────────────────
async function submitCityPicker() {
  const city = document.getElementById("social-city")?.value;
  if (!city || !pendingSocial) return;

  const payload = {
    city,
    role:       pendingSocial.role || "customer",
    email:      pendingSocial.email || "",
    name:       pendingSocial.name  || "",
    photo:      pendingSocial.photo || null,
    remember_me: true,
  };

  payload.pending_token = pendingSocial.pending_token;

  const data = await api("/auth.php?action=set_city", "POST", payload);
  if (data.error) { showToast(data.error, "error"); return; }

  pendingSocial = null;
  handleAuthSuccess(data);
}

// ── Guest browsing ────────────────────────────────────────────────────────────
function browseAsGuest() {
  authToken   = null;
  currentUser = { role: "guest" };
  showPage("page-customer-dashboard");
  loadCustomerDashboard();
}

// ── Handle successful auth ────────────────────────────────────────────────────
function handleAuthSuccess(data) {
  if (countdownTimer) clearInterval(countdownTimer);

  authToken   = data.token;
  currentUser = data.user;

  // Store based on remember_me
  if (data.remember_me) {
    localStorage.setItem("bookkam_token", data.token);
    localStorage.setItem("bookkam_user",  JSON.stringify(data.user));
  } else {
    sessionStorage.setItem("bookkam_token", data.token);
    sessionStorage.setItem("bookkam_user",  JSON.stringify(data.user));
    // Clear any old localStorage tokens
    localStorage.removeItem("bookkam_token");
    localStorage.removeItem("bookkam_user");
  }

  customerCity = currentUser.city || "Calabar";
  startInactivityWatcher();

  if (currentUser.role === "customer") {
    showPage("page-customer-dashboard");
    loadCustomerDashboard();
  } else if (currentUser.role === "driver") {
    if (data.driver_status === "active") {
      showPage("page-driver-dashboard");
      loadDriverDashboard();
    } else {
      showPage("page-under-review");
      renderUnderReview(data.driver_status || "pending");
    }
  }
}

// ── Admin login ───────────────────────────────────────────────────────────────
// Step 1 is the password. For an admin with two-factor on, the server answers {two_factor_required, challenge} and issues NO session;
// step 2 sends an authenticator code (or one single-use recovery code) together with that challenge. If ADMIN_2FA_REQUIRED=1 and the
// admin has not enrolled yet, the answer is {needs_2fa_setup, challenge} and the admin enrols first. The challenge lives in memory
// only (never in storage) and expires after a few minutes; the password field is cleared as soon as step 2 starts.
let adminChallenge      = null;
let adminPendingSession = null;
let lastRecoveryCodes   = [];

function adminStep(html) {
  const form = document.getElementById("admin-login-form");
  const box  = document.getElementById("admin-2fa-area");
  if (!box) return;
  if (form) form.style.display = html ? "none" : "block";
  box.style.display = html ? "block" : "none";
  box.innerHTML = html || "";
  if (html) setTimeout(() => box.querySelector("input")?.focus(), 50);
}

function resetAdminLoginSteps() {
  adminChallenge = null; adminPendingSession = null; lastRecoveryCodes = [];
  adminStep("");
  const pw = document.getElementById("admin-password");
  if (pw) pw.value = "";
}

function finishAdminLogin(data) {
  resetAdminLoginSteps();
  authToken   = data.token;
  currentUser = data.user;
  localStorage.setItem("bookkam_token", data.token);
  localStorage.setItem("bookkam_user",  JSON.stringify(data.user));
  startInactivityWatcher();
  showPage("page-admin-dashboard");
  loadAdminDashboard();
}

async function adminLogin() {
  const email = document.getElementById("admin-email").value;
  const pass  = document.getElementById("admin-password").value;
  if (!email || !pass) { showToast("Enter email and password", "error"); return; }
  const data = await api("/auth.php?action=admin_login", "POST", { email, password: pass });
  if (data.error) { showToast(data.error, "error"); return; }
  if (data.two_factor_required && data.challenge) {
    adminChallenge = data.challenge;
    document.getElementById("admin-password").value = "";
    renderAdmin2faStep(false);
    return;
  }
  if (data.needs_2fa_setup && data.challenge) {
    adminChallenge = data.challenge;
    document.getElementById("admin-password").value = "";
    renderAdmin2faSetup();
    return;
  }
  if (!data.token || !data.user) { showToast("Unexpected response from the server", "error"); return; }
  finishAdminLogin(data);
}

// Step 2: authenticator code, or a recovery code (each works once).
function renderAdmin2faStep(useRecovery) {
  adminStep(useRecovery ? `
    <p style="margin:0 0 12px">Enter one of your recovery codes. Each code can be used once.</p>
    <div class="input-group">
      <label>Recovery code</label>
      <input type="text" id="admin-2fa-recovery" class="input-field" autocomplete="off" autocapitalize="off" spellcheck="false"
        placeholder="xxxx-xxxx-xxxx" onkeypress="if(event.key==='Enter') submitAdmin2fa(true)">
    </div>
    <button class="btn btn-gold btn-full" onclick="submitAdmin2fa(true)"><span class="material-icons-outlined">verified_user</span> Verify</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="renderAdmin2faStep(false)">Use my authenticator app instead</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="resetAdminLoginSteps()">Cancel</button>
  ` : `
    <p style="margin:0 0 12px">Enter the 6-digit code from your authenticator app.</p>
    <div class="input-group">
      <label>Authentication code</label>
      <input type="text" id="admin-2fa-code" class="input-field" inputmode="numeric" autocomplete="one-time-code" maxlength="10"
        placeholder="123456" onkeypress="if(event.key==='Enter') submitAdmin2fa(false)">
    </div>
    <button class="btn btn-gold btn-full" onclick="submitAdmin2fa(false)"><span class="material-icons-outlined">verified_user</span> Verify</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="renderAdmin2faStep(true)">Lost your phone? Use a recovery code</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="resetAdminLoginSteps()">Cancel</button>
  `);
}

async function submitAdmin2fa(useRecovery) {
  const el = document.getElementById(useRecovery ? "admin-2fa-recovery" : "admin-2fa-code");
  const v  = (el && el.value || "").trim();
  if (!v) { showToast(useRecovery ? "Enter a recovery code" : "Enter the 6-digit code", "error"); return; }
  const body = { challenge: adminChallenge };
  if (useRecovery) body.recovery_code = v; else body.code = v.replace(/\s+/g, "");
  const data = await api("/auth.php?action=admin_login_2fa", "POST", body);
  if (data.error) {
    showToast(data.error, "error");
    if (/expired|start again/i.test(data.error)) resetAdminLoginSteps();
    else if (el) { el.value = ""; if (el.focus) el.focus(); }
    return;
  }
  if (!data.token || !data.user) { showToast("Unexpected response from the server", "error"); return; }
  finishAdminLogin(data);
}

// Shared by the login enrolment (below) and Settings -> Two-factor (admin.js).
function adminSecretBlock(secret, uri) {
  const grouped = String(secret || "").replace(/(.{4})/g, "$1 ").trim();
  const link = (typeof uri === "string" && uri.startsWith("otpauth://"))
    ? `<p style="margin:8px 0 0"><a href="${esc(uri)}">Open in my authenticator app</a> (on this phone)</p>` : "";
  return `<div class="card" style="margin:12px 0">
    <div style="font-size:12px;opacity:.7;margin-bottom:6px">Setup key (type it into your authenticator app; time-based, 6 digits)</div>
    <div style="font-family:'Space Mono',monospace;font-size:16px;letter-spacing:1px;word-break:break-all">${esc(grouped)}</div>${link}
  </div>`;
}

function adminRecoveryCodesHtml(codes) {
  lastRecoveryCodes = Array.isArray(codes) ? codes.slice() : [];
  return `<div class="card" style="margin:12px 0;font-family:'Space Mono',monospace;line-height:1.9">${lastRecoveryCodes.map(c => esc(c)).join("<br>")}</div>
    <button class="btn btn-ghost btn-full" onclick="copyRecoveryCodes()"><span class="material-icons-outlined">content_copy</span> Copy the codes</button>`;
}

function copyRecoveryCodes() {
  const text = lastRecoveryCodes.join("\n");
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => showToast("Copied", "success"), () => showToast("Could not copy; write the codes down", "error"));
  } else showToast("Copy is not available here; write the codes down", "error");
}

// Enrolment during sign-in (only when the server requires two-factor and this admin has not set it up yet).
async function renderAdmin2faSetup() {
  const data = await api("/auth.php?action=admin_2fa_begin", "POST", { challenge: adminChallenge });
  if (data.error) { showToast(data.error, "error"); resetAdminLoginSteps(); return; }
  adminStep(`
    <p style="margin:0 0 4px">Administrator accounts must use two-factor authentication.</p>
    <p style="margin:0;opacity:.8">Add this key to an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password...), then enter the 6-digit code it shows.</p>
    ${adminSecretBlock(data.secret, data.otpauth_uri)}
    <div class="input-group">
      <label>Code from the app</label>
      <input type="text" id="admin-2fa-code" class="input-field" inputmode="numeric" autocomplete="one-time-code" maxlength="10"
        placeholder="123456" onkeypress="if(event.key==='Enter') submitAdmin2faSetup()">
    </div>
    <button class="btn btn-gold btn-full" onclick="submitAdmin2faSetup()">Turn on two-factor</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="resetAdminLoginSteps()">Cancel</button>
  `);
}

async function submitAdmin2faSetup() {
  const code = (document.getElementById("admin-2fa-code")?.value || "").replace(/\s+/g, "");
  if (!code) { showToast("Enter the 6-digit code", "error"); return; }
  const data = await api("/auth.php?action=admin_2fa_enable", "POST", { challenge: adminChallenge, code });
  if (data.error) {
    showToast(data.error, "error");
    if (/expired|unauthori/i.test(data.error)) resetAdminLoginSteps();
    return;
  }
  if (!data.token || !data.user) { showToast("Unexpected response from the server", "error"); resetAdminLoginSteps(); return; }
  adminPendingSession = { token: data.token, user: data.user };
  adminStep(`
    <p style="margin:0 0 4px"><strong>Save your recovery codes now.</strong></p>
    <p style="margin:0;opacity:.8">They are shown only once. Each works one time if you lose your phone. Store them somewhere safe, not in this browser.</p>
    ${adminRecoveryCodesHtml(data.recovery_codes)}
    <button class="btn btn-gold btn-full" style="margin-top:8px" onclick="finishAdminSetup()">I have saved them - continue</button>
  `);
}

function finishAdminSetup() {
  const sess = adminPendingSession;
  if (!sess) { resetAdminLoginSteps(); return; }
  finishAdminLogin(sess);
}

// ── Under review screen ───────────────────────────────────────────────────────
function renderUnderReview(status) {
  const states = {
    pending:   { icon: "hourglass_empty", color: "var(--orange)", title: "Under Review",       msg: "Your driver application is being reviewed. We'll notify you within 24–48 hours." },
    suspended: { icon: "block",           color: "var(--fire)",   title: "Account Suspended",  msg: "Your account has been suspended. Please contact support." },
  };
  const s = states[status] || states.pending;
  document.getElementById("under-review-content").innerHTML = `
  <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px">
    <div style="text-align:center;max-width:340px">
      <span class="material-icons-outlined" style="font-size:80px;color:${esc(s.color)};display:block;margin-bottom:20px">${s.icon}</span>
      <h2 style="font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;color:var(--text);margin-bottom:12px">${esc(s.title)}</h2>
      <p style="color:var(--muted);line-height:1.6;margin-bottom:32px">${s.msg}</p>
      <a href="https://wa.me/${typeof BOOKKAM_SUPPORT !== "undefined" ? BOOKKAM_SUPPORT : "2340000000000"}" target="_blank" class="btn btn-gold btn-full">
        <span class="material-icons-outlined">support_agent</span> Contact Support
      </a>
      <button class="btn btn-ghost btn-full" style="margin-top:12px" onclick="logout()">
        <span class="material-icons-outlined">logout</span> Logout
      </button>
    </div>
  </div>`;
}
