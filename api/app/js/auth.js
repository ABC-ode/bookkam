// auth-manual.js
// Manual-only auth flows (forms + social token form-submission)
// Paste near end of <body>. Replace GOOGLE_CLIENT_ID and APPLE_SERVICE_ID values.

// ---------- Config ----------
const GOOGLE_CLIENT_ID = "506985141783-svn9ilskpk1et5sr6tedv7vkg2vlio53.apps.googleusercontent.com";
const APPLE_SERVICE_ID = "YOUR_APPLE_SERVICE_ID";
const APPLE_REDIRECT = "https://bookkam.com/api/auth.php?action=apple_callback";

// ---------- State ----------
let selectedRole = null; // "customer" | "driver"
let pendingSocial = null; // used only to hold data between steps if server expects it

// ---------- Constants ----------
const CITIES = ["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"];
const ACTION_BASE = "/auth.php?action="; // server endpoint base

// ---------- Minimal helpers ----------
function esc(s){ return String(s||"").replace(/[&<>"'`=\/]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[c])); }
function showToast(msg){ try{ const el=document.createElement('div'); el.textContent=msg; Object.assign(el.style,{position:'fixed',right:'16px',bottom:'16px',padding:'10px 14px',borderRadius:'8px',background:'#333',color:'#fff',zIndex:99999}); document.body.appendChild(el); setTimeout(()=>el.remove(),3000);}catch(e){console.log(msg);} }

// ---------- Renderers (generate forms that POST to server) ----------
function renderAuthLogin(){
  selectedRole = null;
  const target = document.getElementById("auth-content");
  if (!target) return;
  target.innerHTML = `
    <div class="auth-role-cards">
      <div class="auth-role-card" id="role-card-customer" role="button" onclick="expandRole('customer')">
        <div class="auth-role-icon">👤</div>
        <div class="auth-role-label">Customer</div>
        <div class="auth-role-sub">I want to book a car</div>
      </div>
      <div class="auth-role-card" id="role-card-driver" role="button" onclick="expandRole('driver')">
        <div class="auth-role-icon">🚗</div>
        <div class="auth-role-label">Driver</div>
        <div class="auth-role-sub">I want to drive</div>
      </div>
    </div>
    <div id="auth-expanded-area"></div>
  `;
}

function expandRole(role){
  selectedRole = role;
  document.getElementById("role-card-customer")?.classList.toggle("selected", role === "customer");
  document.getElementById("role-card-driver")?.classList.toggle("selected", role === "driver");
  const isCustomer = role === "customer";
  const area = document.getElementById("auth-expanded-area");
  if (!area) return;

  // Render manual forms/buttons. All form submissions go to server endpoints.
  area.innerHTML = `
    <div class="auth-methods-card">
      <div class="auth-methods-header">
        <strong>${isCustomer ? "Customer" : "Driver"} Login</strong>
        <button type="button" onclick="collapseRole()">Close</button>
      </div>
      <div class="auth-method-btns">
        <button type="button" onclick="renderPhoneForm()">📱 Continue with Phone</button>
        <button type="button" onclick="renderEmailForm('login')">✉️ Continue with Email</button>
        <button type="button" onclick="renderGoogleSubmit()">🔘 Continue with Google</button>
        <button type="button" onclick="renderAppleStart()"> Continue with Apple</button>
        ${isCustomer ? `<div style="margin-top:8px"><button type="button" onclick="browseAsGuest()">Browse as Guest</button></div>` : ""}
      </div>
      <div id="auth-method-form"></div>
    </div>
  `;
}

function collapseRole(){
  selectedRole = null;
  document.getElementById("role-card-customer")?.classList.remove("selected");
  document.getElementById("role-card-driver")?.classList.remove("selected");
  document.getElementById("auth-expanded-area").innerHTML = "";
}

// PHONE: server-handled send_otp (form POST)
function renderPhoneForm(){
  const formArea = document.getElementById("auth-method-form");
  if (!formArea) return;
  formArea.innerHTML = `
    <form id="phone-form" action="${ACTION_BASE}send_otp" method="POST">
      <label>Phone Number</label>
      <div style="display:flex;gap:8px">
        <select name="country" id="country-select" style="min-width:96px">
          <option value="+234">🇳🇬 +234</option>
          <option value="+1">🇺🇸 +1</option>
          <option value="+44">🇬🇧 +44</option>
        </select>
        <input name="phone" id="login-phone" placeholder="8012345678" inputmode="numeric" required>
      </div>
      <input type="hidden" name="role" value="${esc(selectedRole||'customer')}">
      <label><input type="checkbox" name="remember_me" value="1"> Remember me</label>
      <div style="margin-top:12px">
        <button type="submit" class="btn">Send OTP</button>
        <button type="button" onclick="closeMethodForm()">Cancel</button>
      </div>
    </form>
  `;
  setTimeout(()=>document.getElementById("login-phone")?.focus(),40);
}

// OTP input page — server normally should render OTP page, but provide a client-side form if server redirects back to same page
function renderOTPForm(userId){
  const formArea = document.getElementById("auth-method-form");
  if (!formArea) return;
  // Note: server should ideally put user_id into the page or session. We accept an optional userId param.
  formArea.innerHTML = `
    <form id="otp-form" action="${ACTION_BASE}verify_otp" method="POST">
      <p>Enter the 6-digit code sent to your number</p>
      <input type="hidden" name="user_id" value="${esc(userId||'')}">
      <input name="otp" id="otp-input" placeholder="123456" maxlength="6" inputmode="numeric" required>
      <div style="margin-top:12px">
        <button type="submit" class="btn">Verify</button>
        <button type="button" onclick="renderPhoneForm()">Back</button>
      </div>
    </form>
  `;
  setTimeout(()=>document.getElementById("otp-input")?.focus(),40);
}

// EMAIL: login/register as plain forms
function renderEmailForm(tab = "login"){
  const formArea = document.getElementById("auth-method-form");
  if (!formArea) return;
  if (tab === "register") {
    formArea.innerHTML = `
      <form id="email-register-form" action="${ACTION_BASE}register_email" method="POST">
        <label>Full name</label><input name="name" required>
        <label>Email</label><input name="email" type="email" required>
        <label>Password</label><input name="password" type="password" required>
        <label>Confirm</label><input name="confirm" type="password" required>
        <label>City</label>
        <select name="city">${CITIES.map(c=>`<option>${esc(c)}</option>`).join("")}</select>
        <input type="hidden" name="role" value="${esc(selectedRole||'customer')}">
        <div style="margin-top:12px">
          <button type="submit" class="btn">Create Account</button>
          <button type="button" onclick="renderEmailForm('login')">Switch to Login</button>
        </div>
      </form>
    `;
  } else {
    formArea.innerHTML = `
      <form id="email-login-form" action="${ACTION_BASE}login_email" method="POST">
        <label>Email</label><input name="email" type="email" required>
        <label>Password</label><input name="password" type="password" required>
        <label><input type="checkbox" name="remember_me" value="1"> Remember me</label>
        <input type="hidden" name="role" value="${esc(selectedRole||'customer')}">
        <div style="margin-top:12px">
          <button type="submit" class="btn">Login</button>
          <button type="button" onclick="renderEmailForm('register')">Switch to Register</button>
        </div>
      </form>
    `;
  }
}

// City picker form (server-side set_city endpoint)
function renderCityPicker(displayName){
  const formArea = document.getElementById("auth-method-form");
  if (!formArea) return;
  formArea.innerHTML = `
    <form id="social-city-form" action="${ACTION_BASE}set_city" method="POST">
      <p>Welcome${displayName ? ", " + esc(displayName) : ""} — pick your city</p>
      <label>City</label>
      <select name="city">${CITIES.map(c=>`<option>${esc(c)}</option>`).join("")}</select>
      <input type="hidden" name="role" value="${esc(selectedRole||'customer')}">
      <!-- If pendingSocial contains email/name from provider, server can read them from POST -->
      <input type="hidden" name="email" value="${esc(pendingSocial?.email||'')}">
      <input type="hidden" name="name" value="${esc(pendingSocial?.name||'')}">
      <input type="hidden" name="photo" value="${esc(pendingSocial?.photo||'')}">
      <div style="margin-top:12px">
        <button type="submit" class="btn">Continue</button>
        <button type="button" onclick="closeMethodForm()">Cancel</button>
      </div>
    </form>
  `;
}

// Cancel/close
function closeMethodForm(){
  document.getElementById("auth-method-form")?.innerHTML = "";
}

// ---------- Social flows (obtain token client-side, then submit to server via hidden form) ----------

// GOOGLE: render a small message and attempt One Tap / button. When a credential arrives, submit a form to server.
let gsiInitialized = false;
function ensureGSIReady(timeout = 8000){
  return new Promise((resolve,reject)=>{
    const start = Date.now();
    (function check(){
      if (window.google && window.google.accounts && window.google.accounts.id) return resolve();
      if (Date.now() - start > timeout) return reject(new Error("GSI not loaded"));
      setTimeout(check,80);
    })();
  });
}

async function renderGoogleSubmit(){
  // Show brief UI to user, then attempt to prompt/render button. But actual credential will be sent to server via form submission.
  const area = document.getElementById("auth-method-form");
  if (!area) return;
  area.innerHTML = `<div style="padding:8px">Click the Google button below to continue as <strong>${esc(selectedRole||'customer')}</strong></div><div id="google-btn-mount"></div>`;
  try {
    await ensureGSIReady();
  } catch (e) {
    showToast("Google Sign-In not available", "error");
    return;
  }
  if (!gsiInitialized) {
    window.google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: googleIdCallback });
    gsiInitialized = true;
  }
  const mount = document.getElementById("google-btn-mount");
  if (mount) {
    mount.innerHTML = ""; // clear
    window.google.accounts.id.renderButton(mount, { theme: "outline", size: "large" });
    window.google.accounts.id.prompt(); // optional
  }
}

// The callback that GSI invokes when credential available
function googleIdCallback(response){
  if (!response || !response.credential) {
    showToast("Google sign-in failed", "error");
    return;
  }
  // Build a hidden form and submit to server
  const f = document.createElement("form");
  f.method = "POST";
  f.action = ACTION_BASE + "google_login";
  // id_token
  const t = document.createElement("input"); t.type = "hidden"; t.name = "id_token"; t.value = response.credential; f.appendChild(t);
  // role
  const r = document.createElement("input"); r.type = "hidden"; r.name = "role"; r.value = selectedRole || "customer"; f.appendChild(r);
  // remember_me
  const rm = document.createElement("input"); rm.type = "hidden"; rm.name = "remember_me"; rm.value = "1"; f.appendChild(rm);
  document.body.appendChild(f);
  f.submit();
}

// Apple: initialize and on callback submit a hidden form to server
function renderAppleStart(){
  // The page must include Apple JS SDK. This function will try to call AppleID.auth.signIn()
  const area = document.getElementById("auth-method-form");
  if (area) area.innerHTML = `<div style="padding:8px">Click the Apple button to continue as <strong>${esc(selectedRole||'customer')}</strong></div><div><button id="apple-start-btn">Sign in with Apple</button></div>`;
  const btn = document.getElementById("apple-start-btn");
  if (btn) btn.onclick = startAppleLogin;
}

function startAppleLogin(){
  if (!window.AppleID) { showToast("Apple Sign-In not loaded", "error"); return; }
  try {
    AppleID.auth.init({ clientId: APPLE_SERVICE_ID, scope: "name email", redirectURI: APPLE_REDIRECT, usePopup: true });
    AppleID.auth.signIn().then(appleResponse => {
      // appleResponse contains authorization.id_token and user info (only on first sign-in)
      const idToken = appleResponse?.authorization?.id_token || "";
      const email = appleResponse?.user?.email || "";
      const nameParts = appleResponse?.user?.name || {};
      const fullName = ((nameParts.firstName||"") + " " + (nameParts.lastName||"")).trim();
      if (!idToken) { showToast("Apple sign-in failed", "error"); return; }
      // submit hidden form
      const f = document.createElement("form");
      f.method = "POST";
      f.action = ACTION_BASE + "apple_login";
      const t = document.createElement("input"); t.type="hidden"; t.name="id_token"; t.value=idToken; f.appendChild(t);
      const r = document.createElement("input"); r.type="hidden"; r.name="role"; r.value=selectedRole || "customer"; f.appendChild(r);
      const e = document.createElement("input"); e.type="hidden"; e.name="email"; e.value=email; f.appendChild(e);
      const n = document.createElement("input"); n.type="hidden"; n.name="name"; n.value=fullName; f.appendChild(n);
      const rm = document.createElement("input"); rm.type="hidden"; rm.name="remember_me"; rm.value="1"; f.appendChild(rm);
      document.body.appendChild(f);
      f.submit();
    }).catch(err => {
      if (err?.error !== "popup_closed_by_user") showToast("Apple Sign-In failed", "error");
    });
  } catch (e) {
    showToast("Apple Sign-In initialization failed", "error");
  }
}

// submit city after social flow (form will POST to server)
function submitCityPicker(){
  const form = document.getElementById("social-city-form");
  if (form) form.submit();
}

// guest browsing - direct navigate to dashboard (server page)
function browseAsGuest(){
  // You can also set cookie/session server-side if desired by navigating with a query param
  window.location.href = "dashboard.php";
}

// ---------- Navigation helpers ----------
function safeNavigateTo(url){
  try {
    if (window.top && window.top !== window && typeof window.top.location.replace === "function") window.top.location.replace(url);
    else window.location.replace(url);
  } catch (e) { window.location.href = url; }
}

// ---------- Expose to global ----------
window.renderAuthLogin = renderAuthLogin;
window.expandRole = expandRole;
window.collapseRole = collapseRole;
window.renderPhoneForm = renderPhoneForm;
window.renderOTPForm = renderOTPForm; // optional: server may render OTP page with user_id
window.renderEmailForm = renderEmailForm;
window.renderCityPicker = renderCityPicker;
window.closeMethodForm = closeMethodForm;
window.renderGoogleSubmit = renderGoogleSubmit;
window.startAppleLogin = startAppleLogin;
window.submitCityPicker = submitCityPicker;
window.browseAsGuest = browseAsGuest;
window.safeNavigateTo = safeNavigateTo;

// Auto-init if container exists
document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("auth-content")) renderAuthLogin();
});