// ── AUTH.JS ───────────────────────────────────────────────────────────────────
// Google Client ID — replace with your actual client ID
const GOOGLE_CLIENT_ID = "506985141783-svn9ilskpk1et5sr6tedv7vkg2vlio53.apps.googleusercontent.com";
// Apple Service ID — replace with your actual service ID
const APPLE_SERVICE_ID  = "YOUR_APPLE_SERVICE_ID";
const APPLE_REDIRECT    = "https://bookkam.com/api/auth.php?action=apple_callback";

let selectedRole    = null; // "customer" | "driver"
let otpUserId       = null;
let countdownTimer  = null;
let activeMethod    = null; // "phone" | "email" | "google" | "apple"
let pendingSocial   = null; // holds {google_id/apple_id, email, name, photo, role} while city picker shown

// ── Cities list ───────────────────────────────────────────────────────────────
const CITIES = ["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"];

// ── Main render ───────────────────────────────────────────────────────────────
function renderAuthLogin() {
  selectedRole   = "customer";
  otpUserId      = null;
  activeMethod   = null;
  pendingSocial  = null;
  if (countdownTimer) clearInterval(countdownTimer);

  document.getElementById("auth-content").innerHTML = `
    <div class="auth-role-cards">

      <div class="auth-role-card" id="role-card-customer" onclick="expandRole('customer')">
        <div class="auth-role-icon">
          <span class="material-icons-outlined">person_outline</span>
        </div>
        <div class="auth-role-label">Customer</div>
        <div class="auth-role-sub">I want to book a car</div>
      </div>

      <div class="auth-role-card" id="role-card-driver" onclick="expandRole('driver')">
        <div class="auth-role-icon">
          <span class="material-icons-outlined">directions_car</span>
        </div>
        <div class="auth-role-label">Driver</div>
        <div class="auth-role-sub">I want to drive</div>
      </div>

    </div>

    <div id="auth-expanded-area"></div>
  `;

  expandRole("customer");
}

// ── Expand a role card ────────────────────────────────────────────────────────
function expandRole(role) {
  selectedRole = role;
  activeMethod = null;

  // Highlight selected card
  document.getElementById("role-card-customer").classList.toggle("selected", role === "customer");
  document.getElementById("role-card-driver").classList.toggle("selected",   role === "driver");

  const isCustomer = role === "customer";

  document.getElementById("auth-expanded-area").innerHTML = `
    <div class="auth-methods-card" id="auth-methods-card">

      <div class="auth-methods-header">
        <span class="material-icons-outlined">${isCustomer ? "person_outline" : "directions_car"}</span>
        <span>${isCustomer ? "Customer" : "Driver"} Login</span>
        <button class="auth-collapse-btn" onclick="collapseRole()">
          <span class="material-icons-outlined">keyboard_arrow_up</span>
        </button>
      </div>

      <div class="auth-method-btns">
        <button class="auth-method-btn" id="method-btn-phone" onclick="selectMethod('phone')">
          <span class="material-icons-outlined">smartphone</span>
          Continue with Phone
        </button>
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
        <button class="auth-method-btn auth-method-apple" id="method-btn-apple" onclick="startAppleLogin()">
          <svg width="18" height="18" viewBox="0 0 814 1000" style="flex-shrink:0;fill:currentColor">
            <path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.5-155.5-127.4C46 790.9 0 694.5 0 601.8c0-156.2 102.9-238.6 204-238.6 52.7 0 96.5 34.5 129.7 34.5 31.8 0 81.5-36.9 143.8-36.9 22.4 0 108.3 2 172.5 79.9zm-248.1-180.8c31.1-36.9 53.1-88.1 53.1-139.3 0-7.1-.6-14.3-1.9-20.1-50.6 1.9-110.8 33.7-147.1 75.8-28.5 32.4-55.1 83.6-55.1 135.5 0 7.8 1.3 15.6 1.9 18.1 3.2.6 8.4 1.3 13.6 1.3 45.4 0 102.5-30.4 135.5-71.3z"/>
          </svg>
          Continue with Apple
        </button>

        ${isCustomer ? `
        <div class="auth-divider"><span>or</span></div>
        <button class="auth-method-btn auth-method-guest" onclick="browseAsGuest()">
          <span class="material-icons-outlined">visibility</span>
          Browse as Guest
        </button>
        ` : ""}
      </div>

      <div id="auth-method-form"></div>

      ${isCustomer ? `
      <div class="auth-driver-link">
        <button class="btn btn-ghost" style="font-size:12px;color:var(--muted)" onclick="expandRole('driver')">
          Want to be a driver? →
        </button>
      </div>` : `
      <div class="auth-driver-link">
        <button class="btn btn-ghost" style="font-size:12px;color:var(--muted)" onclick="expandRole('customer')">
          ← Back to Customer
        </button>
      </div>`}

    </div>
  `;

  // Animate in
  requestAnimationFrame(() => {
    const card = document.getElementById("auth-methods-card");
    if (card) card.classList.add("expanded");
  });
}

function collapseRole() {
  selectedRole = null;
  activeMethod = null;
  document.getElementById("role-card-customer").classList.remove("selected");
  document.getElementById("role-card-driver").classList.remove("selected");
  document.getElementById("auth-expanded-area").innerHTML = "";
}

// ── Select login method ───────────────────────────────────────────────────────
function selectMethod(method) {
  activeMethod = method;

  // Highlight active method button
  document.querySelectorAll(".auth-method-btn").forEach(b => b.classList.remove("active"));
  const btn = document.getElementById("method-btn-" + method);
  if (btn) btn.classList.add("active");

  if (method === "phone") renderPhoneForm();
  if (method === "email") renderEmailForm("login");
}

// ── Phone OTP form ────────────────────────────────────────────────────────────
function renderPhoneForm() {
  document.getElementById("auth-method-form").innerHTML = `
    <div class="auth-form-slide">
      <div class="input-group">
        <label>Phone Number</label>
        <div class="phone-input-wrap">
          <span class="phone-prefix">+234</span>
          <input class="input-field phone-input" id="login-phone" type="tel"
            placeholder="8012345678" inputmode="numeric" maxlength="11"
            onkeypress="if(event.key==='Enter') sendOTP()">
        </div>
      </div>
      <label class="auth-remember-label">
        <input type="checkbox" id="phone-remember"> Remember me for 7 days
      </label>
      <button class="btn btn-gold btn-full" onclick="sendOTP()">
        <span class="material-icons-outlined">arrow_forward</span> Send OTP
      </button>
      <button class="btn btn-ghost btn-full auth-close-btn" onclick="closeMethodForm()">
        <span class="material-icons-outlined">close</span> Close
      </button>
    </div>
  `;
  setTimeout(() => document.getElementById("login-phone")?.focus(), 100);
}

function renderOTPForm() {
  document.getElementById("auth-method-form").innerHTML = `
    <div class="auth-form-slide">
      <p class="auth-otp-hint">Enter the 6-digit code sent to your number</p>
      <div class="otp-boxes" id="otp-boxes">
        ${Array(6).fill('<input class="otp-box" type="tel" maxlength="1" inputmode="numeric">').join("")}
      </div>
      <p id="otp-error" class="auth-otp-error"></p>
      <button class="btn btn-gold btn-full" style="margin-top:12px" onclick="verifyOTP()">
        <span class="material-icons-outlined">check_circle</span> Verify
      </button>
      <div class="auth-otp-resend">
        <span id="otp-countdown" style="color:var(--muted);font-size:12px"></span>
        <button id="resend-btn" class="btn btn-ghost" style="display:none;font-size:12px" onclick="sendOTP(true)">Resend OTP</button>
      </div>
      <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="renderPhoneForm()">
        <span class="material-icons-outlined">arrow_back</span> Back
      </button>
    </div>
  `;
  initOTPBoxes();
  setTimeout(() => document.querySelector(".otp-box")?.focus(), 100);
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
            <input type="password" id="reg-pass" class="input-field" placeholder="Min 6 characters">
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

// ── City picker (after Google/Apple first login) ───────────────────────────────
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

// ── OTP boxes ─────────────────────────────────────────────────────────────────
function initOTPBoxes() {
  const boxes = [...document.querySelectorAll(".otp-box")];
  boxes.forEach((box, i) => {
    box.addEventListener("input", e => {
      if (e.target.value) { if (i < 5) boxes[i + 1].focus(); }
      if (boxes.every(b => b.value)) verifyOTP();
    });
    box.addEventListener("keydown", e => {
      if (e.key === "Backspace" && !e.target.value && i > 0) boxes[i - 1].focus();
    });
    box.addEventListener("paste", e => {
      const text = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
      text.split("").forEach((c, j) => { if (boxes[j]) boxes[j].value = c; });
      boxes[Math.min(text.length, 5)].focus();
      e.preventDefault();
    });
  });
}

// ── Send OTP ──────────────────────────────────────────────────────────────────
async function sendOTP(resend = false) {
  let phone = document.getElementById("login-phone")?.value?.trim();
  if (!phone || phone.length < 10) { showToast("Enter a valid phone number", "error"); return; }

  // Normalise: strip leading 0 so we don't double the country code in the DB.
  // User types: 08012345678 → we store: 2348012345678
  if (phone.startsWith("0")) phone = phone.slice(1);
  const fullPhone = "234" + phone;

  const data = await api("/auth.php?action=send_otp", "POST", { phone: fullPhone, role: selectedRole });
  if (data.error) { showToast(data.error, "error"); return; }

  otpUserId = data.user_id;
  renderOTPForm();
  showToast(resend ? "New OTP sent!" : "OTP sent!", "success");

  // Countdown
  let secs = 60;
  const countdown = document.getElementById("otp-countdown");
  const resendBtn = document.getElementById("resend-btn");
  if (countdownTimer) clearInterval(countdownTimer);
  if (resendBtn) resendBtn.style.display = "none";
  countdownTimer = setInterval(() => {
    secs--;
    if (countdown) countdown.textContent = `Resend in ${secs}s`;
    if (secs <= 0) {
      clearInterval(countdownTimer);
      if (countdown) countdown.textContent = "";
      if (resendBtn) resendBtn.style.display = "inline-block";
    }
  }, 1000);
}

// ── Verify OTP ────────────────────────────────────────────────────────────────
async function verifyOTP() {
  const boxes      = [...document.querySelectorAll(".otp-box")];
  const otp        = boxes.map(b => b.value).join("");
  const rememberMe = !!document.getElementById("phone-remember")?.checked;
  if (otp.length < 6) { showToast("Enter all 6 digits", "error"); return; }

  const data = await api("/auth.php?action=verify_otp", "POST", { user_id: otpUserId, otp, remember_me: rememberMe });
  if (data.error) {
    boxes.forEach(b => b.classList.add("shake"));
    setTimeout(() => boxes.forEach(b => b.classList.remove("shake")), 400);
    document.getElementById("otp-error").textContent = data.error;
    return;
  }

  handleAuthSuccess(data);
}

// ── Email login ───────────────────────────────────────────────────────────────
async function submitEmailLogin() {
  const email      = document.getElementById("email-login-email")?.value?.trim();
  const password   = document.getElementById("email-login-pass")?.value;
  const rememberMe = !!document.getElementById("email-remember")?.checked;

  if (!email || !password) { showToast("Enter email and password", "error"); return; }

  const data = await api("/auth.php?action=login_email", "POST", { email, password, remember_me: rememberMe });
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

  if (!name)            { showToast("Enter your full name", "error"); return; }
  if (!email)           { showToast("Enter your email", "error"); return; }
  if (!pass)            { showToast("Enter a password", "error"); return; }
  if (pass !== confirm) { showToast("Passwords do not match", "error"); return; }

  const data = await api("/auth.php?action=register_email", "POST", {
    name, email, password: pass, confirm, role: selectedRole, city
  });
  if (data.error) { showToast(data.error, "error"); return; }

  handleAuthSuccess(data);
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
    role: selectedRole,
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

// ── Apple login ───────────────────────────────────────────────────────────────
function startAppleLogin() {
  if (!window.AppleID) { showToast("Apple Sign-In not loaded", "error"); return; }
  AppleID.auth.init({
    clientId:    APPLE_SERVICE_ID,
    scope:       "name email",
    redirectURI: APPLE_REDIRECT,
    usePopup:    true,
  });
  AppleID.auth.signIn().then(handleAppleCallback).catch(err => {
    if (err.error !== "popup_closed_by_user") showToast("Apple Sign-In failed", "error");
  });
}

async function handleAppleCallback(response) {
  const idToken = response.authorization?.id_token ?? "";
  const email   = response.user?.email ?? "";
  const name    = response.user?.name
    ? ((response.user.name.firstName || "") + " " + (response.user.name.lastName || "")).trim()
    : "";

  const data = await api("/auth.php?action=apple_login", "POST", {
    id_token: idToken,
    role: selectedRole,
    email,
    name,
    remember_me: true,
  });

  if (data.error) { showToast(data.error, "error"); return; }

  if (data.needs_city) {
    pendingSocial = { type: "apple", ...data };
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
    role:       pendingSocial.role || selectedRole,
    email:      pendingSocial.email || "",
    name:       pendingSocial.name  || "",
    photo:      pendingSocial.photo || null,
    remember_me: true,
  };

  if (pendingSocial.type === "google") payload.google_id = pendingSocial.google_id;
  if (pendingSocial.type === "apple")  payload.apple_id  = pendingSocial.apple_id;

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
async function adminLogin() {
  const email = document.getElementById("admin-email").value;
  const pass  = document.getElementById("admin-password").value;
  if (!email || !pass) { showToast("Enter email and password", "error"); return; }
  const data = await api("/auth.php?action=admin_login", "POST", { email, password: pass });
  if (data.error) { showToast(data.error, "error"); return; }
  authToken   = data.token;
  currentUser = data.user;
  localStorage.setItem("bookkam_token", data.token);
  localStorage.setItem("bookkam_user",  JSON.stringify(data.user));
  startInactivityWatcher();
  showPage("page-admin-dashboard");
  loadAdminDashboard();
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
      <span class="material-icons-outlined" style="font-size:80px;color:${s.color};display:block;margin-bottom:20px">${s.icon}</span>
      <h2 style="font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;color:var(--text);margin-bottom:12px">${s.title}</h2>
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
