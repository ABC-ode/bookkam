<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <!-- Ensure theme class is applied before CSS paints to avoid flash -->
  <script>
    (function(){
      // Use a single consistent storage key
      var t = localStorage.getItem('bookkam_theme') || 'dark';
      if (t === 'dark') document.documentElement.classList.add('dark-theme');
    })();
  </script>

  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <title>BOOKKAM — Luxury Car Rental</title>
  <meta name="description" content="Rent a car or book a ride with a professional driver in Nigeria">
  <meta name="theme-color" content="#020B18">

  <link rel="icon" type="image/png" href="/icons/favicon.png">
  <link rel="manifest" href="/manifest.json">
  <link rel="preconnect" href="https://fonts.googleapis.com">

  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css" rel="stylesheet">

  <style>
    /* (same CSS as before, including password eye overlay) */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg-main:    #F8F9FA;
      --bg-card:    #FFFFFF;
      --bg-mid:     #E9ECEF;
      --bg-light:   #F1F3F5;
      --text-main:  #0A0F1E;
      --text-muted: #5C677D;
      --muted:      #5C677D;
      --navy-mid:   #0f1724;
      --white:      #FFFFFF;
      --cognac:     #E8420A;
      --cognac-dim: #B83208;
      --cognac-glow:#F4622A;
      --border:     rgba(200, 114, 42, 0.25);
    }
    .dark-theme {
      --bg-main:    #0A0F1E;
      --bg-card:    #111827;
      --bg-mid:     #1A2338;
      --bg-light:   #1E2D47;
      --text-main:  #F0EDE8;
      --text-muted: #8A96A8;
      --muted:      #8A96A8;
      --navy-mid:   #0b1220;
      --white:      #F0EDE8;
      --border:     rgba(200, 114, 42, 0.18);
    }
    html, body {
      background: var(--bg-main);
      color: var(--text-main);
      font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      min-height: 100vh;
      overflow-x: hidden;
      transition: background 0.25s ease, color 0.25s ease;
    }
    .hero { position: relative; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content:flex-start; overflow:hidden; }
    .hero-bg { position:absolute; inset:0; background:
      radial-gradient(ellipse 80% 60% at 50% 0%, rgba(200,114,42,0.13) 0%, transparent 65%),
      radial-gradient(ellipse 60% 40% at 80% 80%, rgba(200,114,42,0.07) 0%, transparent 60%),
      linear-gradient(180deg, var(--bg-main) 0%, var(--bg-mid) 50%, var(--bg-main) 100%); z-index:0; }
    .hero-bg::after { content:''; position:absolute; inset:0; background-image:
      linear-gradient(rgba(200,114,42,0.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(200,114,42,0.04) 1px, transparent 1px); background-size:40px 40px; z-index:0; }
    .hero-content { position:relative; z-index:1; width:100%; max-width:480px; padding:0 20px; display:flex; flex-direction:column; align-items:center; }
    .topbar { width:100%; display:flex; align-items:center; justify-content:space-between; padding:18px 0 0; }
    .topbar-logo { display:flex; align-items:center; gap:10px; }
    .theme-btn { width:38px; height:38px; border-radius:50%; background:var(--bg-mid); border:1px solid var(--border); color:var(--cognac); font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:transform .2s, background .2s; }
    .theme-btn:hover { transform:scale(1.05); background:var(--bg-light); }
    .hero-headline { margin-top:48px; text-align:center; width:100%; }
    .eyebrow { display:inline-block; font-family: 'Cormorant Garamond', serif; font-size:10px; font-weight:600; letter-spacing:3.5px; text-transform:uppercase; color:var(--cognac); background:rgba(200,114,42,0.1); border:1px solid rgba(200,114,42,0.25); border-radius:100px; padding:5px 14px; margin-bottom:20px; }
    .hero-title { font-family:'Cormorant Garamond', serif; font-size: clamp(36px,10vw,52px); font-weight:800; line-height:1.05; letter-spacing:-1.5px; color:var(--text-main); margin-bottom:14px; }
    .hero-title span { color:var(--cognac); position:relative; }
    .hero-sub { font-size:14px; color:var(--text-muted); line-height:1.6; max-width:300px; margin:0 auto 36px; }
    .stat-strip { display:flex; gap:0; background:var(--bg-card); border:1px solid var(--border); border-radius:16px; overflow:hidden; width:100%; margin-bottom:40px; }
    .stat-item { flex:1; padding:16px 10px; text-align:center; position:relative; }
    .stat-item + .stat-item::before { content:''; position:absolute; left:0; top:20%; height:60%; width:1px; background:var(--border); }
    .stat-num { font-family:'Cormorant Garamond', serif; font-size:22px; font-weight:800; color:var(--cognac); display:block; line-height:1; margin-bottom:4px; }
    .stat-label { font-size:10px; color:var(--text-muted); }
    .role-label { font-family:'Cormorant Garamond', serif; font-size:10px; font-weight:600; letter-spacing:3px; text-transform:uppercase; color:var(--text-muted); margin-bottom:14px; width:100%; }
    .role-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; width:100%; margin-bottom:20px; }
    .role-card { background:var(--bg-card); border:1.5px solid rgba(255,255,255,0.06); border-radius:18px; padding:22px 16px 18px; cursor:pointer; transition:all .25s ease; text-align:left; position:relative; overflow:hidden; }
    .role-card::before { content:''; position:absolute; inset:0; border-radius:18px; background:radial-gradient(circle at 50% 0%, rgba(200,114,42,0.08), transparent 70%); opacity:0; transition:opacity .25s; }
    .role-card:hover::before, .role-card.active::before { opacity:1; }
    .role-card.active { border-color:var(--cognac); background:rgba(200,114,42,0.06); }
    .role-icon { width:42px; height:42px; background:rgba(200,114,42,0.12); border-radius:12px; display:flex; align-items:center; justify-content:center; margin-bottom:14px; font-size:20px; transition:background .25s; }
    .role-card.active .role-icon { background:rgba(200,114,42,0.22); }
    .role-name { font-family:'Inter', sans-serif; font-size:16px; font-weight:700; color:var(--text-main); margin-bottom:4px; }
    .role-desc { font-size:11px; color:var(--text-muted); line-height:1.4; }
    .auth-panel { width:100%; background:var(--bg-card); border:1px solid var(--border); border-radius:20px; overflow:hidden; margin-bottom:28px; transition:all .3s ease; }
    .auth-header { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid rgba(255,255,255,0.04); }
    .auth-header-left { display:flex; align-items:center; gap:10px; }
    .auth-header-icon { font-size:18px; color:var(--cognac); display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; }
    .auth-header-title { font-family:'Inter', sans-serif; font-size:16px; font-weight:700; color:var(--text-main); }
    .auth-body { padding:20px; display:flex; flex-direction:column; gap:10px; }
    .auth-btn { width:100%; padding:15px 18px; border-radius:13px; border:1.5px solid rgba(255,255,255,0.08); background:var(--navy-mid); color:var(--white); font-family:'Inter',sans-serif; font-size:14px; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:12px; transition:all .2s ease; text-align:left; }
    .auth-btn:hover { border-color:rgba(200,114,42,0.4); background:rgba(200,114,42,0.06); color:var(--text-main); }
    .auth-btn.primary { background:var(--cognac); border-color:var(--cognac); color:var(--white); font-weight:600; }
    .auth-btn.primary:hover { background:var(--cognac-glow); border-color:var(--cognac-glow); }
    .auth-btn.guest { border:1.5px dashed rgba(200,114,42,0.3); background:rgba(200,114,42,0.04); color:var(--muted); justify-content:center; }
    .auth-btn.guest:hover { border-color:var(--cognac); color:var(--cognac); }
    .divider { display:flex; align-items:center; gap:12px; margin:4px 0; }
    .divider-line { flex:1; height:1px; background:rgba(255,255,255,0.07); }
    .divider-text { font-size:11px; color:var(--muted); letter-spacing:1px; }
    .otp-panel { width:100%; display:none; flex-direction:column; gap:14px; align-items:center; }
    .otp-hint { font-size:13px; color:var(--muted); text-align:center; line-height:1.5; }
    .otp-hint strong { color:var(--white); font-weight:600; }
    .otp-boxes { display:flex; gap:10px; justify-content:center; }
    .otp-box { width:46px; height:54px; background:var(--navy-mid); border:1.5px solid rgba(255,255,255,0.08); border-radius:12px; font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:var(--white); text-align:center; outline:none; transition:border-color .2s; caret-color:var(--cognac); }
    .otp-box:focus { border-color:var(--cognac); background:rgba(200,114,42,0.06); }
    .resend-text { text-align:center; font-size:12px; color:var(--muted); }
    .resend-text span { color:var(--cognac); cursor:pointer; font-weight:500; }

    /* PASSWORD input with eye inside */
    .password-wrap {
      position: relative;
      display: block;
      width: 100%;
    }
    .password-wrap .input-field {
      width: 100%;
      padding-right: 44px; /* space for the eye button */
    }
    .password-toggle {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      background: transparent;
      border: none;
      color: var(--muted);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 8px;
      cursor: pointer;
      padding: 0;
      gap: 0;
    }
    .password-toggle .material-icons-outlined {
      font-size: 18px;
      line-height: 1;
    }
    .password-toggle:hover {
      color: var(--text-main);
      background: rgba(255,255,255,0.03);
    }

    .phone-input-wrap { width:100%; display:none; flex-direction:column; gap:12px; }
    .phone-field { display:flex; gap:10px; }
    .country-code { background:var(--navy-mid); border:1.5px solid rgba(255,255,255,0.08); border-radius:13px; padding:14px; color:var(--white); font-family:'Inter',sans-serif; font-size:14px; width:80px; text-align:center; outline:none; }
    .phone-number { flex:1; background:var(--navy-mid); border:1.5px solid rgba(255,255,255,0.08); border-radius:13px; padding:14px 16px; color:var(--white); font-family:'Inter',sans-serif; font-size:14px; outline:none; }
    .phone-number::placeholder { color:var(--muted); }
    .back-btn { background:transparent; border:1.5px solid var(--border); border-radius:13px; padding:13px; color:var(--cognac); font-family:'Inter',sans-serif; font-size:13px; font-weight:500; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:background .2s; }
    .back-btn:hover { background:rgba(200,114,42,0.06); }
    .verify-btn { background:linear-gradient(135deg,var(--cognac),var(--cognac-glow)); border:none; border-radius:13px; padding:16px; color:var(--white); font-family:'Inter',sans-serif; font-size:15px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; letter-spacing:0.5px; transition:opacity .2s; }
    .verify-btn:hover { opacity:.9; }
    .email-input-wrap { width:100%; display:none; flex-direction:column; gap:12px; }
    .email-input-wrap.visible { display:flex; }
    .field-row { display:flex; flex-direction:column; gap:6px; }
    .bottom-link { text-align:center; font-size:12px; color:var(--muted); padding-bottom:32px; display:none; }
    .bottom-link a { color:var(--cognac); text-decoration:none; font-weight:500; }
    .status-msg { width:100%; text-align:center; font-size:13px; color:var(--muted); min-height:18px; }
    .auth-form-slide { display:flex; flex-direction:column; gap:12px; }
    .input-group { display:flex; flex-direction:column; gap:8px; }
    .input-field { width:100%; padding:12px; border-radius:10px; border:1px solid var(--border); background:var(--bg-mid); color:var(--text-main); }
    .phone-input-wrap { display:flex; align-items:center; gap:10px; }
    .phone-prefix { padding:12px; background:var(--bg-mid); border-radius:8px; color:var(--text-main); border:1px solid var(--border); }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:12px; border-radius:10px; border:none; cursor:pointer; }
    .btn-full { width:100%; justify-content:center; }
    .btn-gold { background:var(--cognac); color:white; }
    .btn-ghost { background:transparent; color:var(--text-main); border:1px solid var(--border); }
    .auth-remember-label { font-size:13px; color:var(--text-muted); display:flex; gap:8px; align-items:center; }
    .auth-email-tabs { display:flex; gap:8px; margin-bottom:8px; }
    .auth-tab { flex:1; padding:8px; border-radius:8px; border:none; cursor:pointer; background:transparent; color:var(--text-muted); }
    .auth-tab.active { background:var(--bg-card); color:var(--text-main); }
    .auth-otp-hint { text-align:center; color:var(--text-muted); }
    .auth-otp-error { color:#f66; text-align:center; min-height:18px; }
    .shake { animation: shake 0.35s; }
    @keyframes shake { 0% { transform: translateX(0) } 25% { transform: translateX(-6px) } 50% { transform: translateX(6px) } 75% { transform: translateX(-6px) } 100% { transform: translateX(0) } }
    @keyframes fadeUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
    .hero-headline { animation: fadeUp .6s ease both; }
    .stat-strip    { animation: fadeUp .6s .15s ease both; }
    .role-cards    { animation: fadeUp .6s .25s ease both; }
    .auth-panel    { animation: fadeUp .6s .35s ease both; }
    @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
  </style>
</head>
<body>
<div id="app-loader" class="app-loader">
    <div class="loader-content">
      <img src="assets/logo.png" alt="BOOKKAM" class="loader-logo-img" id="loader-logo">
    </div>
  </div>

  <div class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">

      <div class="topbar">
        <div class="topbar-logo">
          <img src="assets/logo.png" style="width:80px;height:80px" alt="BOOKKAM"/>
        </div>
        <button class="theme-btn" id="themeToggle" title="Toggle theme">🌙</button>
      </div>

      <div class="hero-headline">
        <span class="eyebrow">Premium Transport</span>
        <h1 class="hero-title">Ride in<br><span>luxury.</span><br>Drive in style.</h1>
        <p class="hero-sub">Premium rides with professional drivers, and self-drive rentals — all in one place.</p>
      </div>

      <div class="stat-strip">
        <div class="stat-item"><span class="stat-num">50+</span><span class="stat-label">Vehicles</span></div>
        <div class="stat-item"><span class="stat-num">4.9★</span><span class="stat-label">Avg Rating</span></div>
        <div class="stat-item"><span class="stat-num">24/7</span><span class="stat-label">Available</span></div>
      </div>

      <p class="role-label">I am a</p>

      <div class="role-cards">
        <div class="role-card" id="card-customer" role="button" tabindex="0">
          <div class="role-icon"><img src="assets/avatar.png" style="width:30px;height:35px;" alt="customer" /></div>
          <div class="role-name">Customer</div>
          <div class="role-desc">I want to book a car or ride</div>
        </div>
        <div class="role-card" id="card-driver" role="button" tabindex="0">
          <div class="role-icon"><img src="assets/car.png" style="width:30px;height:28px;" alt="driver" /></div>
          <div class="role-name">Driver</div>
          <div class="role-desc">I want to drive & earn</div>
        </div>
      </div>

      <!-- AUTH PANEL (initially hidden) -->
      <div class="auth-panel" id="auth-panel" style="display:none;">
        <div class="auth-header">
          <div class="auth-header-left">
            <span id="auth-header-icon" class="auth-header-icon" aria-hidden="true"></span>
            <span id="auth-header-title" class="auth-header-title">Customer Login</span>
          </div>
          <span class="auth-chevron" id="auth-chevron" aria-hidden="true">▲</span>
        </div>

        <div class="auth-body" id="auth-body">
          <!-- container where method forms render -->
          <div id="auth-method-form"></div>

          <!-- Default auth buttons -->
          <div id="auth-buttons" style="display:flex; flex-direction:column; gap:10px;">
            <button class="auth-btn primary" id="btn-continue-phone" aria-label="Continue with phone">
              <span class="auth-btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;">
                <svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="5" y="2" width="14" height="20" rx="2" ry="2" />
                  <path d="M12 18h.01" stroke-width="3" />
                </svg>
              </span>
              Continue with Phone
            </button>

            <button class="auth-btn" id="btn-continue-email" aria-label="Continue with email">
              <span class="auth-btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;">
                <svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect width="20" height="16" x="2" y="4" rx="2"/>
                  <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                </svg>
              </span>
              Continue with Email
            </button>

            <button class="auth-btn" id="btn-google">
              <span class="auth-btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;">
                <svg class="google-icon" viewBox="0 0 24 24" width="100%" height="100%">
                  <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                  <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                  <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                  <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
              </span>
              Continue with Google
            </button>

            <button class="auth-btn" id="btn-apple">
              <span class="auth-btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;">
                <svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor">
                  <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 4.17c.66-.81 1.11-1.93.99-3.06-.96.04-2.13.64-2.82 1.45-.6.7-1.13 1.84-1.01 2.96 1.07.08 2.18-.54 2.84-1.35z"/>
                </svg>
              </span>
              Continue with Apple
            </button>

            <div class="divider">
              <div class="divider-line"></div>
              <span class="divider-text">or</span>
              <div class="divider-line"></div>
            </div>

            <button class="auth-btn guest" id="btn-guest">Browse as Guest</button>
          </div>

        </div>
      </div>

      <div class="bottom-link" id="bottom-link" style="display:none;">
        <p id="bottom-link-text">Want to drive with us? <a href="#" id="link-apply-driver">Apply as a Driver →</a></p>
      </div>

    </div>
  </div>

  <!-- Load Google Identity Services if you plan to enable Google Sign-In -->
  <!-- <script src="https://accounts.google.com/gsi/client" async defer></script> -->

<script>
    // auth-manual-full.js
// Full client-side script (no AJAX). Handles theme toggle, role UI, email/phone/OTP forms,
// OTP resend via normal form POST, Google & Apple sign-in (hidden-form POST).
// Paste at end of body. Replace GOOGLE_CLIENT_ID and APPLE_SERVICE_ID with your values.

const GOOGLE_CLIENT_ID = "506985141783-svn9ilskpk1et5sr6tedv7vkg2vlio53.apps.googleusercontent.com";
const APPLE_SERVICE_ID = "YOUR_APPLE_SERVICE_ID";
const APPLE_REDIRECT = "https://bookkam.com/api/auth.php?action=apple_callback";

let selectedRole = null;      // "customer" | "driver"
let otpCountdownTimer = null;
let otpSecondsLeft = 0;

// ---------------- Utils ----------------
function $id(id) { return document.getElementById(id); }
function showToast(msg, type = "info", duration = 3000) {
  try {
    const el = document.createElement("div");
    el.textContent = msg;
    Object.assign(el.style, {
      position: "fixed",
      right: "16px",
      bottom: "16px",
      padding: "10px 14px",
      borderRadius: "8px",
      background: type === "error" ? "#c34141" : (type === "success" ? "#2d9f5a" : "#333"),
      color: "#fff",
      zIndex: 99999
    });
    document.body.appendChild(el);
    setTimeout(() => el.remove(), duration);
  } catch (e) {
    console.log(type.toUpperCase(), msg);
  }
}

// ---------------- Theme ----------------
function applyTheme(theme) {
  if (theme === "dark") document.documentElement.classList.add("dark-theme");
  else document.documentElement.classList.remove("dark-theme");
  const btn = $id("themeToggle");
  if (btn) btn.textContent = (theme === "dark") ? "🌙" : "☀️";
  try { localStorage.setItem("bookkam_theme", theme); } catch (e) {}
}
function toggleTheme() {
  const cur = (localStorage.getItem("bookkam_theme") || (document.documentElement.classList.contains("dark-theme") ? "dark" : "light"));
  applyTheme(cur === "dark" ? "light" : "dark");
}
(function initTheme() {
  try {
    const saved = localStorage.getItem("bookkam_theme");
    if (saved) applyTheme(saved);
    else applyTheme("dark");
  } catch (e) { applyTheme("dark"); }
})();

// ---------------- Role & UI ----------------
function setRole(role) {
  selectedRole = role;
  $id("card-customer")?.classList.toggle("active", role === "customer");
  $id("card-driver")?.classList.toggle("active", role === "driver");
  const icon = $id("auth-header-icon"), title = $id("auth-header-title");
  if (icon) icon.textContent = role === "customer" ? "👤" : "🚗";
  if (title) title.textContent = role === "customer" ? "Customer Login" : "Driver Login";
  $id("auth-panel") && ($id("auth-panel").style.display = "block");
  $id("bottom-link") && ($id("bottom-link").style.display = "block");
  const bottomText = $id("bottom-link-text");
  if (bottomText) {
    bottomText.innerHTML = role === "customer"
      ? 'Want to drive with us? <a href="#" id="link-apply-driver">Apply as a Driver →</a>'
      : 'Looking to ride? <a href="#" id="link-back-customer">← Back to Customer</a>';
    setTimeout(() => {
      $id("link-apply-driver")?.addEventListener("click", e => { e.preventDefault(); setRole("driver"); });
      $id("link-back-customer")?.addEventListener("click", e => { e.preventDefault(); setRole("customer"); });
    }, 0);
  }
}

function ensureFormHasRole(form) {
  if (!form) return;
  let ri = form.querySelector('input[name="role"]');
  if (!ri) { ri = document.createElement("input"); ri.type = "hidden"; ri.name = "role"; form.appendChild(ri); }
  ri.value = selectedRole || (document.body.getAttribute("data-default-role") || "customer");
}

// ---------------- Phone formatting ----------------
function attachPhoneFormatting(inputId = "login-phone") {
  const el = $id(inputId);
  if (!el || el._fmt) return;
  el._fmt = true;
  el.addEventListener("input", () => {
    const raw = el.value.replace(/\D/g, "").slice(0, 11);
    let formatted = raw;
    if (raw.length > 7) formatted = raw.slice(0, 3) + " " + raw.slice(3, 7) + " " + raw.slice(7);
    else if (raw.length > 3) formatted = raw.slice(0, 3) + " " + raw.slice(3);
    el.value = formatted;
  });
  el.addEventListener("keypress", e => { if (e.key === "Enter") { e.preventDefault(); el.form?.submit(); } });
}

// ---------------- Render forms ----------------
function openAuthPanel() {
  $id("auth-panel") && ($id("auth-panel").style.display = "block");
  $id("bottom-link") && ($id("bottom-link").style.display = "block");
  $id("auth-buttons") && ($id("auth-buttons").style.display = "flex");
  $id("auth-method-form") && ($id("auth-method-form").innerHTML = "");
}
function collapseAuthPanel() {
  $id("auth-panel") && ($id("auth-panel").style.display = "none");
  $id("bottom-link") && ($id("bottom-link").style.display = "none");
  $id("auth-method-form") && ($id("auth-method-form").innerHTML = "");
}

function renderPhoneForm() {
  const area = $id("auth-method-form");
  if (!area) return;
  area.innerHTML = `
    <form id="phone-form" action="/auth.php?action=send_otp" method="POST" class="auth-form-slide">
      <div class="input-group">
        <label>Phone Number</label>
        <div class="phone-input-wrap" style="display:flex;gap:8px;">
          <select name="country" class="phone-prefix input-field" style="width:90px;">
            <option value="+234">+234</option>
            <option value="+1">+1</option>
            <option value="+44">+44</option>
          </select>
          <input class="input-field phone-number" name="phone" id="login-phone" placeholder="8012345678" inputmode="numeric" required>
        </div>
      </div>
      <label class="auth-remember-label"><input type="checkbox" name="remember_me" value="1"> Remember me</label>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="btn btn-gold btn-full" type="submit">Send OTP</button>
        <button type="button" class="btn btn-ghost" onclick="openAuthPanel()">Cancel</button>
      </div>
    </form>
  `;
  const f = $id("phone-form");
  if (f) f.addEventListener("submit", () => ensureFormHasRole(f));
  setTimeout(() => { attachPhoneFormatting("login-phone"); $id("login-phone")?.focus(); }, 50);
}

function renderOTPForm(userId, phoneValue) {
  const area = $id("auth-method-form");
  if (!area) return;
  const phoneHidden = phoneValue ? `<input type="hidden" name="phone" value="${phoneValue}">` : "";
  area.innerHTML = `
    <form id="otp-form" action="/auth.php?action=verify_otp" method="POST" class="auth-form-slide" ${phoneValue ? 'data-phone="'+phoneValue+'"' : ''}>
      <p class="auth-otp-hint">Enter the 6-digit code sent to your number</p>
      <div class="input-group">
        <input name="user_id" type="hidden" value="${userId || ''}">
        ${phoneHidden}
        <input name="otp" id="otp-input" maxlength="6" class="input-field" placeholder="123456" inputmode="numeric" required>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="verify-btn" type="submit">Verify</button>
        <button type="button" class="back-btn" onclick="renderPhoneForm()">Back</button>
        <div style="margin-left:auto;text-align:center">
          <div id="otp-resend-area">
            <button id="resend-otp-btn" type="button" class="btn btn-ghost">Resend</button>
            <div id="otp-countdown" style="font-size:12px;color:var(--muted);margin-top:6px"></div>
          </div>
        </div>
      </div>
    </form>
  `;
  const f = $id("otp-form");
  if (f) f.addEventListener("submit", () => ensureFormHasRole(f));
  setTimeout(() => $id("otp-input")?.focus(), 50);
  startOtpCountdown(60);
  const resendBtn = $id("resend-otp-btn");
  if (resendBtn) {
    resendBtn.addEventListener("click", () => {
      const form = $id("otp-form");
      const phoneInput = form?.querySelector('input[name="phone"]');
      const phoneFromData = form?.getAttribute("data-phone") || "";
      const phone = phoneInput?.value || phoneFromData;
      if (!phone) { showToast("Phone not known here — go back and resend from phone form", "error"); return; }
      const sf = document.createElement("form");
      sf.method = "POST"; sf.action = "/auth.php?action=send_otp";
      const p = document.createElement("input"); p.type = "hidden"; p.name = "phone"; p.value = phone; sf.appendChild(p);
      const role = document.createElement("input"); role.type = "hidden"; role.name = "role"; role.value = selectedRole || "customer"; sf.appendChild(role);
      document.body.appendChild(sf);
      sf.submit();
    });
  }
}

function renderEmailForm(tab = "login") {
  const area = $id("auth-method-form");
  if (!area) return;
  if (tab === "register") {
    area.innerHTML = `
      <form id="email-register-form" action="/auth.php?action=register_email" method="POST" class="auth-form-slide">
        <div class="input-group"><label>Full Name</label><input name="name" class="input-field" required></div>
        <div class="input-group"><label>Email</label><input name="email" type="email" class="input-field" required></div>
        <div class="input-group"><label>Password</label><input name="password" type="password" class="input-field" required></div>
        <div class="input-group"><label>Confirm Password</label><input name="confirm" type="password" class="input-field" required></div>
        <div class="input-group"><label>City</label>
          <select name="city" class="input-field">${["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"].map(c => `<option>${c}</option>`).join("")}</select>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-gold btn-full" type="submit">Create Account</button>
          <button type="button" class="btn btn-ghost" onclick="renderEmailForm('login')">Back to Login</button>
        </div>
      </form>
    `;
    const f = $id("email-register-form"); if (f) f.addEventListener("submit", () => ensureFormHasRole(f));
  } else {
    area.innerHTML = `
      <form id="email-login-form" action="/auth.php?action=login_email" method="POST" class="auth-form-slide">
        <div class="input-group"><label>Email</label><input name="email" type="email" class="input-field" required></div>
        <div class="input-group"><label>Password</label><div class="password-wrap"><input name="password" type="password" class="input-field" required><button type="button" class="password-toggle" onclick="togglePasswordInline(this)"><span>👁️</span></button></div></div>
        <label class="auth-remember-label"><input type="checkbox" name="remember_me" value="1"> Remember me</label>
        <div style="display:flex;gap:8px">
          <button class="btn btn-gold btn-full" type="submit">Login</button>
          <button type="button" class="btn btn-ghost" onclick="renderEmailForm('register')">Create Account</button>
        </div>
      </form>
    `;
    const f = $id("email-login-form"); if (f) f.addEventListener("submit", () => ensureFormHasRole(f));
    setTimeout(() => f?.querySelector('input[name="email"]')?.focus(), 50);
  }
}
function togglePasswordInline(btn) {
  const wrapper = btn.closest(".password-wrap");
  const input = wrapper?.querySelector('input[type="password"], input[type="text"]');
  if (!input) return;
  input.type = input.type === "password" ? "text" : "password";
}

// ---------------- OTP countdown ----------------
function startOtpCountdown(seconds) {
  otpSecondsLeft = seconds;
  const cdEl = $id("otp-countdown"), resendBtn = $id("resend-otp-btn");
  if (resendBtn) resendBtn.disabled = true;
  if (cdEl) cdEl.textContent = `Resend available in ${otpSecondsLeft}s`;
  if (otpCountdownTimer) clearInterval(otpCountdownTimer);
  otpCountdownTimer = setInterval(() => {
    otpSecondsLeft--;
    if (otpSecondsLeft <= 0) {
      clearInterval(otpCountdownTimer);
      otpCountdownTimer = null;
      if (cdEl) cdEl.textContent = "";
      if (resendBtn) resendBtn.disabled = false;
    } else {
      if (cdEl) cdEl.textContent = `Resend available in ${otpSecondsLeft}s`;
    }
  }, 1000);
}

// ---------------- Google & Apple (hidden-form POST) ----------------
function loadGoogleSDKOnce() {
  return new Promise((resolve, reject) => {
    if (window.google && window.google.accounts && window.google.accounts.id) return resolve(window.google);
    if (document.getElementById("gsi-client")) {
      const poll = setInterval(() => { if (window.google && window.google.accounts && window.google.accounts.id) { clearInterval(poll); resolve(window.google); } }, 100);
      setTimeout(() => { clearInterval(poll); reject(new Error("GSI timeout")); }, 10000);
      return;
    }
    const s = document.createElement("script");
    s.src = "https://accounts.google.com/gsi/client";
    s.id = "gsi-client"; s.async = true; s.defer = true;
    s.onload = () => setTimeout(() => {
      if (window.google && window.google.accounts && window.google.accounts.id) resolve(window.google);
      else reject(new Error("GSI loaded but api not ready"));
    }, 120);
    s.onerror = () => reject(new Error("Failed to load GSI"));
    document.head.appendChild(s);
  });
}
async function startGoogleLogin() {
  if (!selectedRole) { showToast("Pick Customer or Driver first", "error"); return; }
  try { await loadGoogleSDKOnce(); } catch (err) { showToast("Google failed to load", "error"); console.error(err); return; }
  if (!window._gsi_initialized) {
    window.google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: googleCredentialCallback });
    window._gsi_initialized = true;
  }
  try {
    window.google.accounts.id.prompt((notif) => {
      if (notif && (notif.isNotDisplayed?.() || notif.isSkippedMoment?.())) {
        const mount = $id("google-btn-mount");
        if (mount) { mount.innerHTML = ""; window.google.accounts.id.renderButton(mount, { theme: "outline", size: "large" }); }
        else showToast("Click Google button to sign in", "info");
      }
    });
  } catch (e) { console.warn(e); }
}
function googleCredentialCallback(resp) {
  if (!resp || !resp.credential) { showToast("Google sign-in failed", "error"); return; }
  const f = document.createElement("form");
  f.method = "POST"; f.action = "/auth.php?action=google_login";
  const t = document.createElement("input"); t.type = "hidden"; t.name = "id_token"; t.value = resp.credential; f.appendChild(t);
  const r = document.createElement("input"); r.type = "hidden"; r.name = "role"; r.value = selectedRole || "customer"; f.appendChild(r);
  const rm = document.createElement("input"); rm.type = "hidden"; rm.name = "remember_me"; rm.value = "1"; f.appendChild(rm);
  document.body.appendChild(f); f.submit();
}

function startAppleLogin() {
  if (!selectedRole) { showToast("Pick Customer or Driver first", "error"); return; }
  if (!window.AppleID) { showToast("Apple SDK not loaded", "error"); return; }
  try {
    AppleID.auth.init({ clientId: APPLE_SERVICE_ID, scope: "name email", redirectURI: APPLE_REDIRECT, usePopup: true });
    AppleID.auth.signIn().then(res => {
      const idToken = res?.authorization?.id_token || "";
      const user = res?.user || null;
      const email = user?.email || "";
      const name = user && user.name ? ((user.name.firstName || "") + " " + (user.name.lastName || "")).trim() : "";
      if (!idToken) { showToast("Apple sign-in failed", "error"); return; }
      const f = document.createElement("form");
      f.method = "POST"; f.action = "/auth.php?action=apple_login";
      const t = document.createElement("input"); t.type = "hidden"; t.name = "id_token"; t.value = idToken; f.appendChild(t);
      const r = document.createElement("input"); r.type = "hidden"; r.name = "role"; r.value = selectedRole || "customer"; f.appendChild(r);
      if (email) { const e = document.createElement("input"); e.type = "hidden"; e.name = "email"; e.value = email; f.appendChild(e); }
      if (name) { const n = document.createElement("input"); n.type = "hidden"; n.name = "name"; n.value = name; f.appendChild(n); }
      const rm = document.createElement("input"); rm.type = "hidden"; rm.name = "remember_me"; rm.value = "1"; f.appendChild(rm);
      document.body.appendChild(f); f.submit();
    }).catch(err => {
      if (err?.error !== "popup_closed_by_user") showToast("Apple Sign-In failed", "error");
    });
  } catch (e) { console.error(e); showToast("Apple initialization error", "error"); }
}

// ---------------- Initialization ----------------
function initAuthManual() {
  $id("themeToggle")?.addEventListener("click", toggleTheme);
  $id("card-customer")?.addEventListener("click", () => setRole("customer"));
  $id("card-driver")?.addEventListener("click", () => setRole("driver"));
  $id("auth-chevron")?.addEventListener("click", () => {
    const panel = $id("auth-panel");
    if (panel && panel.style.display === "block") { panel.style.display = "none"; $id("bottom-link") && ($id("bottom-link").style.display = "none"); }
    else { panel.style.display = "block"; $id("bottom-link") && ($id("bottom-link").style.display = "block"); }
  });
  $id("btn-continue-phone")?.addEventListener("click", () => { $id("auth-buttons") && ($id("auth-buttons").style.display = "none"); renderPhoneForm(); });
  $id("btn-continue-email")?.addEventListener("click", () => { $id("auth-buttons") && ($id("auth-buttons").style.display = "none"); renderEmailForm("login"); });
  $id("btn-google")?.addEventListener("click", () => startGoogleLogin());
  $id("btn-apple")?.addEventListener("click", () => startAppleLogin());
  $id("btn-guest")?.addEventListener("click", () => { window.location.href = "dashboard.php"; });

  document.addEventListener("submit", ev => {
    const f = ev.target;
    if (f && f.tagName === "FORM") ensureFormHasRole(f);
  });

  // If a server-rendered OTP form exists at load, wire it (start countdown)
  const serverOtpForm = $id("otp-form");
  if (serverOtpForm) {
    const phoneInput = serverOtpForm.querySelector('input[name="phone"]');
    const phoneData = serverOtpForm.getAttribute("data-phone") || "";
    const userId = serverOtpForm.querySelector('input[name="user_id"]')?.value || "";
    renderOTPForm(userId, phoneInput?.value || phoneData || "");
  }
}

window.setRole = setRole;
window.startGoogleLogin = startGoogleLogin;
window.startAppleLogin = startAppleLogin;
window.renderPhoneForm = renderPhoneForm;
window.renderEmailForm = renderEmailForm;
window.renderOTPForm = renderOTPForm;
window.initAuthManual = initAuthManual;

document.addEventListener("DOMContentLoaded", initAuthManual);
</script>

</body>
</html>