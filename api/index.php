<?php
// Admin portal — accessed only via secret URL param
// URL: bookkam.com/?bkp=YOUR_SECRET_KEY
// Change the key below to something only you know
define('ADMIN_PORTAL_KEY', 'bk_adm_2026');
$adminPortal = isset($_GET['bkp']) && hash_equals(ADMIN_PORTAL_KEY, $_GET['bkp']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <!-- Theme must be set before CSS paints to avoid flash -->
  <script>
    (function(){
      var t = localStorage.getItem('bookkam_theme') || 'dark';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>BOOKKAM — Luxury Car Rental</title>
  <meta name="description" content="Rent a car or book a ride with a professional driver in Nigeria">
  <meta name="theme-color" content="#020B18">
  
  
  

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="/icons/favicon.png">
  <link rel="shortcut icon" type="image/png" href="/icons/favicon.png">
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.7.0/mapbox-gl.css" rel="stylesheet">
     
  <!-- PWA -->
  <link rel="manifest" href="/manifest.json">
  <link rel="apple-touch-icon" href="/icons/icon-192.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="BOOKKAM">

  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=Space+Mono:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/material-icons-outlined.css">
  <link href="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css" rel="stylesheet">
  <link rel="stylesheet" href="/css/style.css?v=4">
  <script>window.ADMIN_PORTAL = <?= $adminPortal ? 'true' : 'false' ?>;</script>
  </head>
<body>

<!-- ── LOADING SCREEN ──────────────────────────────────────────────────────── -->
<div id="app-loader" class="app-loader">
  <div class="loader-content">
    <img src="app/assets/logo.png" alt="BOOKKAM" class="loader-logo-img" id="loader-logo">
  </div>
</div>

<!-- ── MODAL ─────────────────────────────────────────────────────────────── -->
<div id="modal-overlay" class="modal-overlay" style="display:none">
  <div id="modal-content" class="modal-box"></div>
</div>




<!-- ── TOAST ─────────────────────────────────────────────────────────────── -->
<div id="toast-container"></div>



<!-- ── MODE ORB (light/dark toggle) ──────────────────────────────────────── -->
<button id="mode-orb" data-tip="THEME" onclick="toggleTheme()" aria-label="Toggle theme">
  <span class="material-icons-outlined" id="theme-icon">dark_mode</span>
</button>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE: AUTH                                                                 -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="page-auth" class="page active">
  <aside class="home-booking-dock" aria-label="Event booking">
    <div class="home-booking-list" id="home-booking-list"></div>
  </aside>

  <div class="login-wrap">
       
    <div class="login-card">
      <div class="auth-logo-wrap">
        <img src="app/assets/logo.png" alt="BOOKKAM" class="auth-logo-img" id="auth-logo">
      </div>
      <div id="auth-content"></div>
      <div id="admin-login-area" style="display:none;margin-top:16px">
        <div class="input-group">
          <label>Admin Email</label>
          <input type="email" id="admin-email" class="input-field" placeholder="admin@bookkam.com">
        </div>
        <div class="input-group">
          <label>Password</label>
          <input type="password" id="admin-password" class="input-field" placeholder="••••••••"
            onkeypress="if(event.key==='Enter') adminLogin()">
        </div>
        <button class="btn btn-gold btn-full" onclick="adminLogin()">
          <span class="material-icons-outlined">admin_panel_settings</span> Login as Admin
        </button>
        <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="hideAdminLogin()">
          <span class="material-icons-outlined">close</span> Cancel
        </button>
      </div>
    </div>
     
  </div>


<!-- ============================================================
     MODAL 1 — GROVVE YARD
     ============================================================ -->
<div class="gy-overlay" id="gyOverlay-grovveyard" data-event-key="grovveyard">
  <div class="gy-modal" role="dialog" aria-modal="true" aria-labelledby="gyTitle-grovveyard">
    <button type="button" class="gy-close" aria-label="Close event booking popup">&#10005;</button>

    <div class="gy-modal-inner">
<!-- Access gate (hidden unless config.requires_access_code is true) -->


   <!-- Main booking view -->
      <div class="gy-booking-view">
        <header class="gy-header">
          <span class="gy-eyebrow gy-dyn-eyebrow">Mon, Jul 13 · Event shuttle</span>
          <h2 id="gyTitle-grovveyard" class="gy-title gy-dyn-title">Event Transport – Grovve Yard</h2>
          <p class="gy-subtitle">We pick you up, you enjoy the night. Choose your package below.</p>
          <p class="gy-bonus-banner">🎉 10% bonus on all rides — first 10 bookings only</p>
        </header>

        <div class="gy-map-wrap">
          <div class="gy-map gy-map-canvas" aria-label="Map showing event location"></div>
          <div class="gy-map-caption">
            <span class="gy-map-label gy-dyn-maplabel">Grovve Yard</span>
            <span class="gy-map-note">Event venue · pickup &amp; drop-off point</span>
          </div>
        </div>

        <form class="gy-form" novalidate>
          <div class="gy-field gy-autocomplete-field">
            <label>Pickup location</label>
            <input type="text" class="gy-pickup-input" placeholder="Start typing your address…" autocomplete="off" required />
            <input type="hidden" class="gy-pickup-lng" />
            <input type="hidden" class="gy-pickup-lat" />
            <ul class="gy-suggestions" hidden></ul>
          </div>

          <div class="gy-field">
            <label>Drop-off location</label>
            <input type="text" class="gy-dropoff-input" placeholder="Where should we drop you?" autocomplete="street-address" required />
          </div>

          <div class="gy-field">
            <label>Pickup zone <span class="gy-label-note">(type or choose a suggestion)</span></label>
            <input type="text" class="gy-zone-input" list="gyZones-grovveyard" placeholder="Municipal, Calabar South, 8 Miles..." autocomplete="off" required />
            <datalist id="gyZones-grovveyard">
              <option value="Municipal & Calabar South"></option>
              <option value="8 Miles Route"></option>
              <option value="Marian"></option>
              <option value="Etta Agbor"></option>
              <option value="Calabar South"></option>
              <option value="MCC"></option>
            </datalist>
          </div>

          <div class="gy-row gy-row-2">
            <div class="gy-field">
              <label>Event date</label>
              <input type="text" class="gy-date-display" readonly />
              <input type="hidden" class="gy-date-value" />
            </div>
            <div class="gy-field">
              <label>Pickup time</label>
              <input type="time" class="gy-time-input" required />
            </div>
          </div>

          <!-- Cars from the system (rendered dynamically) -->
          <div class="gy-field gy-package-section">
            <label>Car <span class="gy-label-note">(from available fleet)</span></label>
            <div class="gy-package-list" role="radiogroup" aria-label="Select a car"></div>
          </div>

          <div class="gy-field">
            <label>Number of passengers</label>
            <input type="number" class="gy-passengers-input" min="1" max="6" value="1" required />
          </div>

          <button type="submit" class="gy-submit gy-submit-btn">Book Now</button>
          <p class="gy-success gy-booking-success" role="status" aria-live="polite"></p>
        </form>
      </div>

      <!-- Car detail / picker view -->
      <div class="gy-car-detail-view" hidden>
        <button type="button" class="gy-back-btn">&larr; Back to booking</button>
        <h3 class="gy-detail-title"></h3>
        <p class="gy-detail-sub">Tap a car to select it for this package.</p>
        <div class="gy-detail-cars"></div>
      </div>

    </div>
  </div>
</div>

<!-- ============================================================
     MODAL 2 — CARRIBBEAN VIBES
     Structure is identical to Modal 1 — only IDs/names differ.
     ============================================================ -->
<div class="gy-overlay" id="gyOverlay-carribbeanvibes" data-event-key="carribbeanvibes">
  <div class="gy-modal" role="dialog" aria-modal="true" aria-labelledby="gyTitle-carribbeanvibes">
    <button type="button" class="gy-close" aria-label="Close event booking popup">&#10005;</button>

    <div class="gy-modal-inner">

      <div class="gy-access-gate">
        <h2 class="gy-title">Enter your access code</h2>
        <p class="gy-subtitle">This event requires a code to book transport.</p>
        <div class="gy-field">
          <label>Access code</label>
          <input type="text" class="gy-code-input" placeholder="e.g. CARIB2026" autocomplete="off" />
        </div>
        <button type="button" class="gy-submit gy-code-submit">Unlock booking</button>
        <p class="gy-success gy-code-error" role="status" aria-live="polite"></p>
      </div>

      <div class="gy-booking-view" hidden>
        <header class="gy-header">
          <span class="gy-eyebrow gy-dyn-eyebrow">Sun, Jul 26 · Event shuttle</span>
          <h2 id="gyTitle-carribbeanvibes" class="gy-title gy-dyn-title">Event Transport – Carribbean Vibes</h2>
          <p class="gy-subtitle">We pick you up, you enjoy the night. Choose your package below.</p>
          <p class="gy-bonus-banner gy-discount-banner" hidden></p>
        </header>

        <div class="gy-map-wrap">
          <div class="gy-map gy-map-canvas" aria-label="Map showing event location"></div>
          <div class="gy-map-caption">
            <span class="gy-map-label gy-dyn-maplabel">Carribbean Vibes</span>
            <span class="gy-map-note">Event venue · pickup &amp; drop-off point</span>
          </div>
        </div>

        <form class="gy-form" novalidate>
          <div class="gy-field gy-autocomplete-field">
            <label>Pickup location</label>
            <input type="text" class="gy-pickup-input" placeholder="Start typing your address…" autocomplete="off" required />
            <input type="hidden" class="gy-pickup-lng" />
            <input type="hidden" class="gy-pickup-lat" />
            <ul class="gy-suggestions" hidden></ul>
          </div>

          <div class="gy-field">
            <label>Drop-off location</label>
            <input type="text" class="gy-dropoff-input" placeholder="Where should we drop you?" autocomplete="street-address" required />
          </div>

          <div class="gy-field">
            <label>Pickup zone <span class="gy-label-note">(type or choose a suggestion)</span></label>
            <input type="text" class="gy-zone-input" list="gyZones-carribbeanvibes" placeholder="Municipal, Calabar South, 8 Miles..." autocomplete="off" required />
            <datalist id="gyZones-carribbeanvibes">
              <option value="Municipal & Calabar South"></option>
              <option value="8 Miles Route"></option>
              <option value="Marian"></option>
              <option value="Etta Agbor"></option>
              <option value="Calabar South"></option>
              <option value="MCC"></option>
            </datalist>
          </div>

          <div class="gy-row gy-row-2">
            <div class="gy-field">
              <label>Event date</label>
              <input type="text" class="gy-date-display" readonly />
              <input type="hidden" class="gy-date-value" />
            </div>
            <div class="gy-field">
              <label>Pickup time</label>
              <input type="time" class="gy-time-input" required />
            </div>
          </div>

          <div class="gy-field gy-package-section">
            <label>Car <span class="gy-label-note">(from available fleet)</span></label>
            <div class="gy-package-list" role="radiogroup" aria-label="Select a car"></div>
          </div>

          <div class="gy-field">
            <label>Number of passengers</label>
            <input type="number" class="gy-passengers-input" min="1" max="6" value="1" required />
          </div>

          <button type="submit" class="gy-submit gy-submit-btn">Book Now</button>
          <p class="gy-success gy-booking-success" role="status" aria-live="polite"></p>
        </form>
      </div>

      <div class="gy-car-detail-view" hidden>
        <button type="button" class="gy-back-btn">&larr; Back to booking</button>
        <h3 class="gy-detail-title"></h3>
        <p class="gy-detail-sub">Tap a car to select it for this package.</p>
        <div class="gy-detail-cars"></div>
      </div>

    </div>
  </div>
</div>

<style>
  /* ===== Grovve Yard / Carribbean Vibes popups — scoped with gy- prefix ===== */

  .home-booking-dock {
    position: relative;
    z-index: 20;
    width: min(100% - 32px, 420px);
    margin: 72px auto 0;
  }

  .home-booking-list {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
  }

  .gy-trigger-card {
    position: relative;
    display: block;
    width: 100%;
    max-width: 420px;
    height: 200px;
    border: none;
    border-radius: 16px;
    overflow: hidden;
    cursor: pointer;
    background-color: #131a2e;
    background-size: cover;
    background-position: center;
    text-align: left;
    padding: 0;
    font-family: inherit;
    margin-bottom: 1rem;
    transition: transform 0.15s ease;
  }
  .gy-trigger-card:hover { transform: translateY(-2px); }
  .gy-trigger-card:active { transform: translateY(0); }
  .gy-trigger-card:focus-visible { outline: 3px solid #E8420A; outline-offset: 3px; }

  .gy-trigger-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(10,15,30,0.15) 0%, rgba(10,15,30,0.9) 100%);
  }

  .gy-trigger-content {
    position: absolute;
    left: 1.25rem; right: 1.25rem; bottom: 1.1rem;
    display: flex; flex-direction: column; gap: 0.15rem;
  }
  .gy-trigger-eyebrow { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: #E8420A; font-weight: 700; }
  .gy-trigger-title { font-size: 1.4rem; font-weight: 800; color: #fff; }
  .gy-trigger-sub { font-size: 0.85rem; color: #C7CADA; }

  @media (min-width: 1180px) {
    .home-booking-dock {
      position: fixed;
      top: 88px;
      right: 28px;
      width: 320px;
      margin: 0;
      z-index: 100;
    }

    .home-booking-dock .gy-trigger-card {
      height: 184px;
      max-width: none;
      margin-bottom: 0;
      box-shadow: 0 18px 44px rgba(0,0,0,0.34);
    }
  }

  .gy-overlay {
    position: fixed; inset: 0;
    background: rgba(10, 15, 30, 0.72);
    backdrop-filter: blur(3px);
    display: none; align-items: center; justify-content: center;
    padding: 1rem; z-index: 9999;
  }
  .gy-overlay.gy-open { display: flex; }

  .gy-modal {
    background: #0A0F1E; color: #F2F3F7;
    width: 100%; max-width: 560px; max-height: 90vh;
    overflow-y: auto; border-radius: 16px; position: relative;
    border: 1px solid rgba(232, 66, 10, 0.25);
    box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    animation: gy-pop 0.22s ease;
  }
  @keyframes gy-pop {
    from { opacity: 0; transform: translateY(12px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }

  .gy-modal-inner {
    padding: 2rem 1.75rem 1.75rem;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }

  .gy-close {
    position: absolute; top: 1rem; right: 1rem;
    background: rgba(255,255,255,0.06); border: none; color: #F2F3F7;
    width: 36px; height: 36px; border-radius: 50%; font-size: 1rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background 0.2s ease; z-index: 5;
  }
  .gy-close:hover { background: rgba(232, 66, 10, 0.35); }
  .gy-close:focus-visible { outline: 2px solid #E8420A; outline-offset: 2px; }

  .gy-header { margin-bottom: 1.25rem; }
  .gy-eyebrow { display: inline-block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: #E8420A; font-weight: 700; margin-bottom: 0.5rem; }
  .gy-title { font-size: 1.5rem; line-height: 1.25; margin: 0 0 0.4rem; font-weight: 700; color: #fff; }
  .gy-subtitle { font-size: 0.9rem; color: #9BA1B4; margin: 0; line-height: 1.4; }

  .gy-bonus-banner {
    margin: 0.75rem 0 0; font-size: 0.8rem; font-weight: 600; color: #FFD27A;
    background: rgba(255, 210, 122, 0.1); border: 1px solid rgba(255, 210, 122, 0.25);
    border-radius: 8px; padding: 0.5rem 0.75rem; display: inline-block;
  }
  .gy-label-note { font-weight: 400; color: #7C8299; text-transform: none; letter-spacing: 0; }

  .gy-map-wrap { margin-bottom: 1.5rem; }
  .gy-map { position: relative; height: 180px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.07); overflow: hidden; background: #131a2e; }
  .gy-map .mapboxgl-ctrl-logo, .gy-map .mapboxgl-ctrl-attrib { font-size: 10px; }
  .gy-map-caption { display: flex; align-items: baseline; gap: 0.5rem; margin-top: 0.6rem; }
  .gy-map-label { font-weight: 700; font-size: 1rem; color: #fff; }
  .gy-map-note { font-size: 0.75rem; color: #7C8299; }
  .gy-pin-marker { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: #E8420A; filter: drop-shadow(0 2px 5px rgba(0,0,0,0.5)); }

  .gy-form { display: flex; flex-direction: column; gap: 1rem; }
  .gy-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .gy-field { display: flex; flex-direction: column; gap: 0.4rem; }
  .gy-field label { font-size: 0.8rem; font-weight: 600; color: #C7CADA; }
  .gy-field-hint { margin: -0.4rem 0 0; font-size: 0.75rem; color: #7C8299; line-height: 1.4; }

  .gy-field input, .gy-field select {
    font-family: inherit; background: #141a2c; border: 1px solid rgba(255,255,255,0.12);
    color: #F2F3F7; border-radius: 8px; padding: 0.65rem 0.75rem; font-size: 0.9rem;
    width: 100%; box-sizing: border-box;
  }
  .gy-field input[readonly] {
    color: #E8420A; font-weight: 600; background: rgba(232, 66, 10, 0.08);
    border-color: rgba(232, 66, 10, 0.3); cursor: not-allowed;
  }
  .gy-field input:focus-visible, .gy-field select:focus-visible {
    outline: 2px solid #E8420A; outline-offset: 1px; border-color: #E8420A;
  }

  /* Autocomplete */
  .gy-autocomplete-field { position: relative; }
  .gy-suggestions {
    position: absolute; top: 100%; left: 0; right: 0; margin-top: 0.3rem;
    background: #141a2c; border: 1px solid rgba(255,255,255,0.14); border-radius: 10px;
    list-style: none; padding: 0.3rem; z-index: 20; max-height: 220px; overflow-y: auto;
    box-shadow: 0 12px 30px rgba(0,0,0,0.45);
  }
  .gy-suggestions[hidden] { display: none; }
  .gy-suggestions li { padding: 0.55rem 0.6rem; font-size: 0.85rem; border-radius: 6px; cursor: pointer; color: #E8EAF2; }
  .gy-suggestions li:hover, .gy-suggestions li.gy-active { background: rgba(232, 66, 10, 0.18); }

  /* Zone / ride-type toggle */
  .gy-zone-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
  .gy-zone-option {
    position: relative; display: flex; align-items: center; justify-content: center; text-align: center;
    padding: 0.6rem 0.5rem; border: 1.5px solid rgba(255,255,255,0.12); border-radius: 10px;
    background: #141a2c; font-size: 0.82rem; font-weight: 600; color: #C7CADA; cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
  }
  .gy-zone-option input { position: absolute; opacity: 0; pointer-events: none; }
  .gy-zone-option:has(input:checked) { border-color: #E8420A; background: rgba(232, 66, 10, 0.12); color: #fff; }
  .gy-zone-option:has(input:focus-visible) { outline: 2px solid #E8420A; outline-offset: 2px; }

  /* Package / bus-route cards */
  .gy-package-list { display: flex; flex-direction: column; gap: 0.65rem; }
  .gy-package-card {
    position: relative; display: flex; flex-direction: column; gap: 0.25rem;
    padding: 0.85rem 0.95rem; border: 1.5px solid rgba(255,255,255,0.12); border-radius: 12px;
    background: #141a2c; cursor: pointer; transition: border-color 0.15s ease, background 0.15s ease;
  }
  .gy-package-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
  .gy-package-card.gy-selected { border-color: #E8420A; background: rgba(232, 66, 10, 0.1); }
  .gy-package-card:focus-within { outline: 2px solid #E8420A; outline-offset: 2px; }
  .gy-package-top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
  .gy-package-name { font-size: 1rem; font-weight: 800; color: #fff; }
  .gy-package-price { font-size: 0.95rem; font-weight: 800; color: #E8420A; white-space: nowrap; }
  .gy-package-tagline { font-size: 0.78rem; color: #9BA1B4; }
  .gy-package-cars-line { font-size: 0.75rem; color: #7C8299; }
  .gy-package-selected-car { font-size: 0.78rem; color: #FFD27A; font-weight: 600; }
  .gy-empty-state {
    display: flex; flex-direction: column; align-items: center; gap: 0.55rem;
    padding: 1.1rem; border: 1.5px dashed rgba(255,255,255,0.16); border-radius: 12px;
    background: rgba(255,255,255,0.035); text-align: center; color: #9BA1B4;
  }
  .gy-empty-state strong { color: #fff; font-size: 0.95rem; }
  .gy-empty-state span { font-size: 0.78rem; line-height: 1.45; }
  .gy-empty-state a {
    color: #E8420A; font-size: 0.78rem; font-weight: 800; text-decoration: none;
  }

  .gy-view-cars-btn {
    align-self: flex-start; margin-top: 0.3rem; background: none; border: 1px solid rgba(232,66,10,0.4);
    color: #E8420A; font-size: 0.72rem; font-weight: 700; padding: 0.3rem 0.65rem; border-radius: 6px;
    cursor: pointer; text-transform: uppercase; letter-spacing: 0.03em;
  }
  .gy-view-cars-btn:hover { background: rgba(232,66,10,0.12); }

  /* Car detail / picker view */
  .gy-car-detail-view { }
  .gy-back-btn {
    display: flex; align-items: center; gap: 0.5rem; width: 100%;
    background: rgba(232, 66, 10, 0.12); border: 1.5px solid rgba(232, 66, 10, 0.4);
    color: #fff; font-size: 1.05rem; font-weight: 800; padding: 1rem; border-radius: 12px;
    cursor: pointer; margin-bottom: 1.25rem; transition: background 0.15s ease;
  }
  .gy-back-btn:hover { background: rgba(232, 66, 10, 0.22); }
  .gy-back-btn:focus-visible { outline: 2px solid #E8420A; outline-offset: 2px; }

  .gy-detail-title { font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0 0 0.25rem; }
  .gy-detail-sub { font-size: 0.85rem; color: #9BA1B4; margin: 0 0 1rem; }

  .gy-detail-cars { display: flex; flex-direction: column; gap: 0.75rem; }
  .gy-detail-car-card {
    display: flex; align-items: center; gap: 0.85rem; padding: 0.7rem;
    border: 1.5px solid rgba(255,255,255,0.12); border-radius: 12px; background: #141a2c;
    cursor: pointer; text-align: left; width: 100%; color: inherit; font-family: inherit;
    transition: border-color 0.15s ease, background 0.15s ease;
  }
  .gy-detail-car-card:hover, .gy-detail-car-card.gy-selected { border-color: #E8420A; background: rgba(232, 66, 10, 0.1); }
  .gy-detail-car-thumb {
    width: 72px; height: 54px; border-radius: 8px; flex-shrink: 0; background-color: rgba(255,255,255,0.06);
    background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: #E8420A;
  }
  .gy-detail-car-info { display: flex; flex-direction: column; gap: 0.1rem; }
  .gy-detail-car-name { font-weight: 700; color: #fff; font-size: 0.92rem; }
  .gy-detail-car-year { font-size: 0.75rem; color: #7C8299; }

  .gy-success { margin: 0; font-size: 0.85rem; color: #6EE7A8; font-weight: 600; min-height: 1.2em; }
  .gy-success.gy-error { color: #FF8A80; }

  .gy-submit {
    margin-top: 0.5rem; background: #E8420A; color: #fff; border: none; padding: 0.9rem;
    font-size: 1rem; font-weight: 700; border-radius: 9px; cursor: pointer;
    transition: background 0.2s ease, transform 0.1s ease;
  }
  .gy-submit:hover { background: #cf3a08; }
  .gy-submit:active { transform: translateY(1px); }
  .gy-submit:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
  .gy-submit:disabled { opacity: 0.6; cursor: not-allowed; }

  .gy-access-gate { display: flex; flex-direction: column; gap: 1rem; }

  @media (max-width: 480px) {
    .gy-row-2 { grid-template-columns: 1fr; }
    .gy-modal-inner { padding: 1.5rem 1.25rem 1.25rem; }
    .gy-title { font-size: 1.3rem; }
    .gy-trigger-card { max-width: 100%; height: 170px; }
  }
</style>

<script>
(function () {
  // ── Fallback config, used only if the /api/event-config.php fetch fails ──
  var DEFAULT_CONFIG = {
    grovveyard: {
      name: 'Grovve Yard', eyebrow: 'Mon, Jul 13 · Event shuttle',
      date: '2026-07-13', date_display: 'Monday, July 13, 2026',
      venue_lng: 8.3150036, venue_lat: 5.0423748, requires_access_code: false,
      party_bus: { enabled: false, routes: [] },
      packages: [
        { id: 'diamond', name: 'Diamond', tagline: 'Premium luxury experience', price_municipal: 10000, price_8miles: 15000,
          cars: [{model:'Toyota Land Cruiser',year:2022,photo:''},{model:'Mazda CX-9',year:2021,photo:''},{model:'Mercedes-Benz C300',year:2020,photo:''}] },
        { id: 'gold', name: 'Gold', tagline: 'Comfort meets class', price_municipal: 8000, price_8miles: 10000,
          cars: [{model:'Toyota Prado',year:2015,photo:''},{model:'Toyota Camry',year:2018,photo:''},{model:'Suzuki',year:2019,photo:''}] },
        { id: 'silver', name: 'Silver', tagline: 'Affordable & reliable', price_municipal: 5000, price_8miles: 7000,
          cars: [{model:'Toyota Camry Spider',year:2012,photo:''},{model:'Honda Civic',year:2015,photo:''},{model:'Hyundai Sonata',year:2016,photo:''}] }
      ]
    },
    carribbeanvibes: {
      name: 'Carribbean Vibes', eyebrow: 'Sun, Jul 26 · Event shuttle',
      date: '2026-07-26', date_display: 'Sunday, July 26, 2026',
      venue_lng: 8.3150036, venue_lat: 5.0423748, requires_access_code: true,
      party_bus: { enabled: true, routes: [
        { id:'bus1', name:'Bus 1 — Municipal', stops:['Marian Market','Etta Agbor','Target'], departure_time:'20:00', return_time:'02:00', price:3000 },
        { id:'bus2', name:'Bus 2 — Calabar South / 8 Miles', stops:['Mobil','MCC','8 Miles'], departure_time:'20:00', return_time:'02:00', price:3500 }
      ]},
      packages: [
        { id: 'diamond', name: 'Diamond', tagline: 'Premium luxury experience', price_municipal: 10000, price_8miles: 15000, cars: [] },
        { id: 'gold', name: 'Gold', tagline: 'Comfort meets class', price_municipal: 8000, price_8miles: 10000, cars: [] },
        { id: 'silver', name: 'Silver', tagline: 'Affordable & reliable', price_municipal: 5000, price_8miles: 7000, cars: [] }
      ]
    }
  };

  var CONFIG_ENDPOINT = '/api/event-config.php';
  var CODE_ENDPOINT = '/api/validate-access-code.php';
  var BOOKING_ENDPOINT = '/api/event-booking.php';
  var GY_TOKEN = "pk.eyJ1IjoiYm9va2thbSIsImEiOiJjbW5uYXRyaXYxZm9lMnByNjc1OHNycG5vIn0.zUAwUDojhM0ROm2l58J4kg";

  var unlockedEvents = {};   // eventKey -> true once a valid code has been entered
  var discountByEvent = {};  // eventKey -> discount_percent from the code used
  var loadedConfigs = {};    // eventKey -> config object once fetched
  var mapInstances = {};     // overlayId -> mapbox map instance
  var lastFocusedEl = null;
  var activeOverlay = null;
  var FALLBACK_HOME_BOOKING_CARDS = [
    {
      eventKey: 'grovveyard',
      modalId: 'gyOverlay-grovveyard',
      title: 'Tap Me To Book',
      subtitle: 'Reserve your ride to & from the venue',
      eyebrow: 'Grovve Yard · Event shuttle',
      event_date: '2026-07-13',
      image: ''
    }
  ];

  function formatNaira(n) { return '₦' + Number(n).toLocaleString('en-NG'); }
  function escapeHTML(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function formatHomeCardDate(value) {
    if (!value) return '';
    var date = new Date(value + 'T00:00:00');
    if (isNaN(date.getTime())) return '';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function fetchConfig(eventKey) {
    if (loadedConfigs[eventKey]) return Promise.resolve(loadedConfigs[eventKey]);
    return fetch(CONFIG_ENDPOINT + '?event=' + encodeURIComponent(eventKey))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var cfg = (data && data.success && data.event) ? data.event : DEFAULT_CONFIG[eventKey];
        loadedConfigs[eventKey] = cfg;
        return cfg;
      })
      .catch(function () {
        loadedConfigs[eventKey] = DEFAULT_CONFIG[eventKey];
        return DEFAULT_CONFIG[eventKey];
      });
  }

  function renderHomeBookingCards() {
    var list = document.getElementById('home-booking-list');
    if (!list) return;

    function draw(cards) {
      list.innerHTML = '';
      if (!cards || !cards.length) return;
      cards.slice(0, 1).forEach(function (cardConfig) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'gy-trigger-card';
      btn.dataset.openModal = cardConfig.modalId || cardConfig.modal_id;
      btn.dataset.eventKey = cardConfig.eventKey || cardConfig.event_key;
      var imageUrl = cardConfig.image || cardConfig.image_url || '';
      if (imageUrl) btn.style.backgroundImage = "url('" + imageUrl.replace(/'/g, '%27') + "')";
      btn.innerHTML =
        '<span class="gy-trigger-overlay"></span>' +
        '<span class="gy-trigger-content">' +
          '<span class="gy-trigger-eyebrow">' + escapeHTML([cardConfig.eyebrow || 'Event booking', formatHomeCardDate(cardConfig.event_date)].filter(Boolean).join(' · ')) + '</span>' +
          '<span class="gy-trigger-title">' + escapeHTML(cardConfig.title) + '</span>' +
          '<span class="gy-trigger-sub">' + escapeHTML(cardConfig.subtitle || '') + '</span>' +
        '</span>';
      list.appendChild(btn);

      fetchConfig(btn.dataset.eventKey).then(function (cfg) {
        var eyebrow = btn.querySelector('.gy-trigger-eyebrow');
        var sub = btn.querySelector('.gy-trigger-sub');
        if (eyebrow && !cardConfig.eyebrow) eyebrow.textContent = cfg.eyebrow || cfg.name || 'Event booking';
        if (sub && cfg.requires_access_code && !(cardConfig.subtitle || '').trim()) sub.textContent = cfg.requires_access_code
          ? 'Access-code required · reserve your ride'
          : cardConfig.subtitle;
      });
    });
    }

    fetch('/api/booking-cards.php?action=get_active')
      .then(function (r) { return r.json(); })
      .then(function (data) { draw((data && data.cards && data.cards.length) ? data.cards : FALLBACK_HOME_BOOKING_CARDS); })
      .catch(function () { draw(FALLBACK_HOME_BOOKING_CARDS); });
  }

  // ── Wire up every overlay present on the page ───────────────────────────
  renderHomeBookingCards();

  document.querySelectorAll('.gy-overlay').forEach(function (overlay) {
    var eventKey = overlay.dataset.eventKey;
    var modal = overlay.querySelector('.gy-modal');
    var closeBtn = overlay.querySelector('.gy-close');
    var accessGate = overlay.querySelector('.gy-access-gate');
    var bookingView = overlay.querySelector('.gy-booking-view');
    var detailView = overlay.querySelector('.gy-car-detail-view');
    var form = overlay.querySelector('.gy-form');
    var pickupInput = overlay.querySelector('.gy-pickup-input');
    var dropoffInput = overlay.querySelector('.gy-dropoff-input');
    var pickupZoneInput = overlay.querySelector('.gy-zone-input');
    var pickupLng = overlay.querySelector('.gy-pickup-lng');
    var pickupLat = overlay.querySelector('.gy-pickup-lat');
    var suggestionsList = overlay.querySelector('.gy-suggestions');
    var packageListEl = overlay.querySelector('.gy-package-section .gy-package-list');
    var packageSection = overlay.querySelector('.gy-package-section');
    var submitBtn = overlay.querySelector('.gy-submit-btn');
    var successMsg = overlay.querySelector('.gy-booking-success');
    var debounceTimer = null;
    var activeSuggestionIndex = -1;
    var selectedSystemCar = null;
    var systemCars = [];

    function currentZone() {
      var raw = (pickupZoneInput && pickupZoneInput.value ? pickupZoneInput.value : '').toLowerCase();
      if (raw.indexOf('8') !== -1 || raw.indexOf('mile') !== -1 || raw.indexOf('mcc') !== -1) return '8miles';
      return 'municipal';
    }
    function currentZoneLabel() {
      return pickupZoneInput && pickupZoneInput.value ? pickupZoneInput.value.trim() : 'Municipal & Calabar South';
    }
    function currentRideType() {
      return 'car';
    }

    function systemCarPrice(car) {
      var zone = currentZone();
      var base = car.car_type === 'self_drive' ? 30000 : 15000;
      if (zone === '8miles') base += 5000;
      var surge = Number(car.surge_multiplier || 1);
      return Math.round(base * (surge > 0 ? surge : 1));
    }

    function carDisplayName(car) {
      return [car.name, car.year, car.make, car.model].filter(Boolean).join(' · ');
    }

    function fetchCarsByType(type) {
      return fetch('/api/cars.php?action=get_all&type=' + type + '&city=Calabar&available=1')
        .then(function (r) {
          if (!r.ok) throw new Error('Could not load ' + type + ' cars');
          return r.json();
        })
        .then(function (data) {
          if (data.error) throw new Error(data.error);
          return data.cars || [];
        });
    }

    function loadSystemCars() {
      return Promise.all([
        fetchCarsByType('chauffeur'),
        fetchCarsByType('self_drive')
      ]).then(function (sets) {
        return { cars: sets[0].concat(sets[1]), error: '' };
      }).catch(function (err) {
        return { cars: [], error: err && err.message ? err.message : 'Could not load cars' };
      });
    }

    function renderSystemCarsEmpty(message, detail) {
      if (!packageListEl) {
        console.error("Car list element not found for event:", eventKey);
        return;
      }
      packageListEl.innerHTML =
        '<div class="gy-empty-state">' +
          '<strong>' + escapeHTML(message) + '</strong>' +
          '<span>' + escapeHTML(detail) + '</span>' +
          '<a href="/?bkp=bk_adm_2026">Open admin cars</a>' +
        '</div>';
    }

    function renderSystemCars(result) {
      if (!packageListEl) {
        console.error("Package list element not found for event:", eventKey);
        return;
      }
      var cars = (result && result.cars) || [];
      packageListEl.innerHTML = '';
      selectedSystemCar = null;
      if (!cars.length) {
        if (result && result.error) {
          renderSystemCarsEmpty('Cars could not load', result.error + '. Check that the local server and database are running.');
        } else {
          renderSystemCarsEmpty('No available cars right now', 'Add a self-drive car marked available, or make sure chauffeur cars have an active online driver.');
        }
        return;
      }
      cars.forEach(function (car, i) {
        var price = systemCarPrice(car);
        var card = document.createElement('label');
        card.className = 'gy-package-card';
        card.innerHTML =
          '<input type="radio" name="car-' + eventKey + '" value="' + car.id + '"' + (i === 0 ? ' checked' : '') + ' />' +
          '<span class="gy-package-top">' +
            '<span class="gy-package-name">' + escapeHTML(car.name || (car.make + ' ' + car.model)) + '</span>' +
            '<span class="gy-package-price" data-car-price-for="' + car.id + '">' + formatNaira(price) + '</span>' +
          '</span>' +
          '<span class="gy-package-tagline">' + escapeHTML((car.car_type === 'self_drive' ? 'Self-drive' : 'Chauffeur') + ' · ' + (car.category || 'car')) + '</span>' +
          '<span class="gy-package-cars-line">' + escapeHTML([car.year, car.make, car.model, car.color].filter(Boolean).join(' · ')) + '</span>';
        packageListEl.appendChild(card);

        var radio = card.querySelector('input[type="radio"]');
        radio.addEventListener('change', function () {
          selectedSystemCar = car;
          updateSelectedCardStyles();
        });
        if (i === 0) selectedSystemCar = car;
      });
      updateSelectedCardStyles();
      systemCars = cars;
      updateCarPrices(systemCars);
    }

    function renderBusRoutes(cfg) {
      if (!busListEl) return;
      busListEl.innerHTML = '';
      var routes = (cfg.party_bus && cfg.party_bus.routes) || [];
      routes.forEach(function (route, i) {
        var card = document.createElement('label');
        card.className = 'gy-package-card';
        card.innerHTML =
          '<input type="radio" name="bus-' + eventKey + '" value="' + route.id + '"' + (i === 0 ? ' checked' : '') + ' />' +
          '<span class="gy-package-top">' +
            '<span class="gy-package-name">' + route.name + '</span>' +
            '<span class="gy-package-price">' + formatNaira(route.price) + '</span>' +
          '</span>' +
          '<span class="gy-package-tagline">Stops: ' + route.stops.join(', ') + '</span>' +
          '<span class="gy-package-cars-line">Departs ' + route.departure_time + ' · Returns ' + route.return_time + '</span>';
        busListEl.appendChild(card);
        card.querySelector('input').addEventListener('change', updateSelectedCardStyles);
      });
    }

    function updateSelectedCardStyles() {
      overlay.querySelectorAll('.gy-package-card').forEach(function (card) {
        var input = card.querySelector('input[type="radio"]');
        card.classList.toggle('gy-selected', !!(input && input.checked));
      });
    }

    function updateCarPrices(cars) {
      (cars || []).forEach(function (car) {
        var label = packageListEl.querySelector('[data-car-price-for="' + car.id + '"]');
        if (!label) return;
        var price = systemCarPrice(car);
        label.textContent = formatNaira(price);
        label.dataset.finalPrice = price;
      });
    }

    function selectedCarPrice() {
      var checked = form.querySelector('input[name="car-' + eventKey + '"]:checked');
      if (!checked) return null;
      var label = packageListEl.querySelector('[data-car-price-for="' + checked.value + '"]');
      return label ? Number(label.dataset.finalPrice) : null;
    }

    function selectedBusPrice(cfg) {
      var checked = form.querySelector('input[name="bus-' + eventKey + '"]:checked');
      if (!checked) return null;
      var routes = (cfg.party_bus && cfg.party_bus.routes) || [];
      var route = routes.filter(function (r) { return r.id === checked.value; })[0];
      return route ? route.price : null;
    }

    // ── Car detail / picker view ──────────────────────────────────────────
    function openCarDetail(cfg, packageId) {
      var pkg = cfg.packages.filter(function (p) { return p.id === packageId; })[0];
      if (!pkg) return;

      var detailTitle = detailView.querySelector('.gy-detail-title');
      var carsWrap = detailView.querySelector('.gy-detail-cars');
      detailTitle.textContent = pkg.name + ' — choose your car';
      carsWrap.innerHTML = '';

      pkg.cars.forEach(function (car) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'gy-detail-car-card';
        var isSelected = selectedCarByPackage[packageId] && selectedCarByPackage[packageId].model === car.model && selectedCarByPackage[packageId].year === car.year;
        if (isSelected) btn.classList.add('gy-selected');
        btn.innerHTML =
          '<span class="gy-detail-car-thumb"' + (car.photo ? ' style="background-image:url(\'' + car.photo + '\')"' : '') + '>' +
            (car.photo ? '' : '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 13l1.5-4.5A2 2 0 0 1 6.4 7h11.2a2 2 0 0 1 1.9 1.5L21 13M3 13l1 4a2 2 0 0 0 2 1.5h12a2 2 0 0 0 2-1.5l1-4M7 13v3a2 2 0 1 0 4 0v-3m2 0v3a2 2 0 1 0 4 0v-3"></path></svg>') +
          '</span>' +
          '<span class="gy-detail-car-info">' +
            '<span class="gy-detail-car-name">' + car.model + '</span>' +
            '<span class="gy-detail-car-year">' + (car.year || '') + '</span>' +
          '</span>';

        btn.addEventListener('click', function () {
          selectedCarByPackage[packageId] = { model: car.model, year: car.year };
          var card = packageListEl.querySelector('input[value="' + packageId + '"]').closest('.gy-package-card');
          var selectedLine = card.querySelector('.gy-package-selected-car');
          if (selectedLine) {
            selectedLine.hidden = false;
            selectedLine.textContent = 'Selected: ' + car.model + (car.year ? ' (' + car.year + ')' : '');
          }
          packageListEl.querySelector('input[value="' + packageId + '"]').checked = true;
          updateSelectedCardStyles();
          closeCarDetail();
        });

        carsWrap.appendChild(btn);
      });

      bookingView.hidden = true;
      detailView.hidden = false;
      var backBtn = detailView.querySelector('.gy-back-btn');
      if (backBtn) backBtn.focus();
    }

    function closeCarDetail() {
      detailView.hidden = true;
      bookingView.hidden = false;
    }

    var backBtnEl = detailView.querySelector('.gy-back-btn');
    if (backBtnEl) backBtnEl.addEventListener('click', closeCarDetail);

    // ── Pickup autocomplete ──────────────────────────────────────────────
    function fetchSuggestions(query, cfg) {
      if (!query || query.length < 3) { renderSuggestions([]); return; }
      var url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(query) + '.json' +
        '?access_token=' + GY_TOKEN + '&country=ng&proximity=' + cfg.venue_lng + ',' + cfg.venue_lat + '&limit=5';
      fetch(url).then(function (r) { return r.json(); })
        .then(function (data) { renderSuggestions(data && data.features ? data.features : []); })
        .catch(function () { renderSuggestions([]); });
    }

    function renderSuggestions(features) {
      suggestionsList.innerHTML = '';
      activeSuggestionIndex = -1;
      if (!features.length) { suggestionsList.hidden = true; return; }
      features.forEach(function (feature) {
        var li = document.createElement('li');
        li.textContent = feature.place_name;
        li.setAttribute('role', 'option');
        li.addEventListener('click', function () {
          pickupInput.value = feature.place_name;
          pickupLng.value = feature.center[0];
          pickupLat.value = feature.center[1];
          suggestionsList.hidden = true;
          suggestionsList.innerHTML = '';
        });
        suggestionsList.appendChild(li);
      });
      suggestionsList.hidden = false;
    }

    pickupInput.addEventListener('input', function () {
      pickupLng.value = ''; pickupLat.value = '';
      clearTimeout(debounceTimer);
      var query = pickupInput.value.trim();
      debounceTimer = setTimeout(function () {
        fetchConfig(eventKey).then(function (cfg) { fetchSuggestions(query, cfg); });
      }, 300);
    });

    pickupInput.addEventListener('keydown', function (e) {
      var items = suggestionsList.querySelectorAll('li');
      if (!items.length || suggestionsList.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); activeSuggestionIndex = Math.min(activeSuggestionIndex + 1, items.length - 1); highlight(items); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activeSuggestionIndex = Math.max(activeSuggestionIndex - 1, 0); highlight(items); }
      else if (e.key === 'Enter' && activeSuggestionIndex >= 0) { e.preventDefault(); items[activeSuggestionIndex].click(); }
    });
    function highlight(items) {
      items.forEach(function (item, i) { item.classList.toggle('gy-active', i === activeSuggestionIndex); });
      if (items[activeSuggestionIndex]) items[activeSuggestionIndex].scrollIntoView({ block: 'nearest' });
    }
    document.addEventListener('click', function (e) {
      if (!suggestionsList.contains(e.target) && e.target !== pickupInput) suggestionsList.hidden = true;
    });

    // ── Ride-type toggle is retired for event bookings; cars come from fleet ──
    form.querySelectorAll('input[name^="ridetype-"]').forEach(function (input) {
      input.addEventListener('change', function () {
        packageSection.hidden = false;
        busSection.hidden = true;
      });
    });
    if (pickupZoneInput) {
      pickupZoneInput.addEventListener('input', function () { updateCarPrices(systemCars); });
      pickupZoneInput.value = 'Municipal & Calabar South';
    }

    // ── Map init ─────────────────────────────────────────────────────────
    function initMap(cfg) {
      if (mapInstances[overlay.id] || typeof mapboxgl === 'undefined') return;
      mapboxgl.accessToken = GY_TOKEN;
      var mapEl = overlay.querySelector('.gy-map-canvas');
      var map = new mapboxgl.Map({
        container: mapEl, style: 'mapbox://styles/mapbox/dark-v11',
        center: [cfg.venue_lng, cfg.venue_lat], zoom: 15, interactive: true, attributionControl: false
      });
      map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'bottom-right');
      var pinEl = document.createElement('div');
      pinEl.className = 'gy-pin-marker';
      pinEl.innerHTML = '<svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M12 2C7.58 2 4 5.58 4 10c0 5.42 7 12 8 12s8-6.58 8-12c0-4.42-3.58-8-8-8zm0 10.5A2.5 2.5 0 1 1 9.5 10 2.5 2.5 0 0 1 12 12.5z"></path></svg>';
      new mapboxgl.Marker({ element: pinEl, anchor: 'bottom' }).setLngLat([cfg.venue_lng, cfg.venue_lat])
        .setPopup(new mapboxgl.Popup({ offset: 25, closeButton: false }).setHTML('<b>' + cfg.name + '</b><br>Event pickup &amp; drop-off'))
        .addTo(map);
      mapInstances[overlay.id] = map;
      setTimeout(function () { map.resize(); }, 60);
    }

    // ── Render config into this modal ───────────────────────────────────
    function populateModal(cfg) {
      overlay.querySelector('.gy-dyn-eyebrow').textContent = cfg.eyebrow;
      overlay.querySelector('.gy-dyn-title').textContent = 'Event Transport – ' + cfg.name;
      overlay.querySelector('.gy-dyn-maplabel').textContent = cfg.name;
      overlay.querySelector('.gy-date-display').value = cfg.date_display;
      overlay.querySelector('.gy-date-value').value = cfg.date;

      var discountBanner = overlay.querySelector('.gy-discount-banner');
      if (discountBanner && discountByEvent[eventKey]) {
        discountBanner.hidden = false;
        discountBanner.textContent = '🎉 ' + discountByEvent[eventKey] + '% discount applied with your access code';
      }

      if (packageSection) packageSection.hidden = false;
      if (packageListEl) packageListEl.innerHTML = '<div class="gy-empty-state"><strong>Loading available cars...</strong><span>Checking the active fleet for Calabar.</span></div>';
      loadSystemCars().then(renderSystemCars);
      initMap(cfg);
    }

    // ── Access code gate ─────────────────────────────────────────────────
    var codeInput = overlay.querySelector('.gy-code-input');
    var codeSubmit = overlay.querySelector('.gy-code-submit');
    var codeError = overlay.querySelector('.gy-code-error');

    function attemptUnlock(cfg) {
      var code = codeInput.value.trim();
      if (!code) { codeError.classList.add('gy-error'); codeError.textContent = 'Enter a code to continue.'; return; }
      codeSubmit.disabled = true;
      codeSubmit.textContent = 'Checking…';
      fetch(CODE_ENDPOINT, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: eventKey, code: code })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success) {
            unlockedEvents[eventKey] = true;
            discountByEvent[eventKey] = data.discount_percent || 0;
            accessGate.hidden = true;
            bookingView.hidden = false;
            populateModal(cfg);
          } else {
            codeError.classList.add('gy-error');
            codeError.textContent = (data && data.error) || 'Invalid code.';
          }
        })
        .catch(function () {
          codeError.classList.add('gy-error');
          codeError.textContent = 'Could not verify code — try again.';
        })
        .finally(function () {
          codeSubmit.disabled = false;
          codeSubmit.textContent = 'Unlock booking';
        });
    }

    if (codeSubmit) {
      codeSubmit.addEventListener('click', function () {
        fetchConfig(eventKey).then(attemptUnlock);
      });
      codeInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); fetchConfig(eventKey).then(attemptUnlock); }
      });
    }

    // ── Open / close / focus trap ────────────────────────────────────────
    function getFocusableEls() {
      return Array.prototype.slice.call(
        modal.querySelectorAll('button, [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])')
      ).filter(function (el) { return !el.disabled && el.offsetParent !== null; });
    }

    function onKeyDown(e) {
      if (e.key === 'Escape') { closeOverlay(); return; }
      if (e.key === 'Tab') {
        var focusables = getFocusableEls();
        if (!focusables.length) return;
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }

    function openOverlay() {
      lastFocusedEl = document.activeElement;
      activeOverlay = overlay;
      overlay.classList.add('gy-open');
      document.body.style.overflow = 'hidden';
      document.addEventListener('keydown', onKeyDown);

      fetchConfig(eventKey).then(function (cfg) {
       bookingView.hidden = false;
        populateModal(cfg);

        var focusables = getFocusableEls();
        if (focusables.length) focusables[0].focus();
        if (mapInstances[overlay.id]) setTimeout(function () { mapInstances[overlay.id].resize(); }, 200);
      });
    }

    function closeOverlay() {
      overlay.classList.remove('gy-open');
      document.body.style.overflow = '';
      document.removeEventListener('keydown', onKeyDown);
      successMsg.textContent = '';
      successMsg.classList.remove('gy-error');
      if (lastFocusedEl) lastFocusedEl.focus();
    }

    closeBtn.addEventListener('click', closeOverlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(); });

    // ── Submit booking ───────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      fetchConfig(eventKey).then(function (cfg) {
        var pickup = pickupInput.value.trim();
        var dropoff = dropoffInput ? dropoffInput.value.trim() : '';
        var zone = currentZone();
        var zoneLabel = currentZoneLabel();
        var date = overlay.querySelector('.gy-date-value').value;
        var dateDisplay = overlay.querySelector('.gy-date-display').value;
        var time = overlay.querySelector('.gy-time-input').value;
        var passengers = overlay.querySelector('.gy-passengers-input').value;
        var rideType = currentRideType();

        var payload = {
          event: eventKey, event_name: cfg.name,
          pickup_address: pickup,
          pickup_lng: pickupLng.value || null, pickup_lat: pickupLat.value || null,
          dropoff_address: dropoff,
          zone: zone, zone_label: zoneLabel, date: date, date_display: dateDisplay, time: time, passengers: passengers,
          ride_type: rideType, discount_percent: discountByEvent[eventKey] || 0
        };

        var carChecked = form.querySelector('input[name="car-' + eventKey + '"]:checked');
        if (carChecked) {
          selectedSystemCar = systemCars.filter(function (car) { return String(car.id) === String(carChecked.value); })[0] || selectedSystemCar;
        }
        if (!pickup || !dropoff || !zoneLabel || !date || !time || !passengers || !selectedSystemCar) {
          alert('Please fill in all fields and select a car to complete your booking.');
          return;
        }
        payload.car_id = selectedSystemCar.id;
        payload.selected_car = carDisplayName(selectedSystemCar);
        payload.package = null;
        payload.price = selectedCarPrice();

        submitBtn.disabled = true; submitBtn.textContent = 'Booking…';
        successMsg.classList.remove('gy-error'); successMsg.textContent = '';

        fetch(BOOKING_ENDPOINT, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        })
          .then(function (r) { if (!r.ok) throw new Error('bad response'); return r.json().catch(function () { return {}; }); })
          .then(function (data) {
            var discountLine = data.discount_code ? ' · Discount code: ' + data.discount_code : '';
            successMsg.textContent = 'Booking received. Awaiting admin confirmation · ' + formatNaira(data.final_price || payload.price) +
              ' · ' + dateDisplay + ' at ' + time + discountLine;
          })
          .catch(function () {
            successMsg.classList.add('gy-error');
            successMsg.textContent = 'Something went wrong sending your booking. Please try again, or contact us directly.';
          })
          .finally(function () { submitBtn.disabled = false; submitBtn.textContent = 'Book Now'; });
      });
    });

    // expose opener for the trigger buttons below
    overlay._gyOpen = openOverlay;
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-open-modal]');
    if (btn) {
      var overlay = document.getElementById(btn.dataset.openModal);
      if (overlay && overlay._gyOpen) overlay._gyOpen();
    }
  });
})();
</script>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE: CUSTOMER DASHBOARD                                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="page-customer-dashboard" class="page" style="display:none">
  <div class="dashboard-layout">
    <aside class="sidebar" id="customer-sidebar"></aside>
    <main class="main-content">
      <div class="topbar" id="customer-topbar"></div>
      <div class="tab-body">
        <div class="tab-content" id="tab-customer-home"></div>
        <div class="tab-content" id="tab-customer-rent"     style="display:none"></div>
        <div class="tab-content" id="tab-customer-ride"     style="display:none"></div>
        <div class="tab-content" id="tab-customer-bookings" style="display:none"></div>
        <div class="tab-content" id="tab-customer-tracking" style="display:none"></div>
        <div class="tab-content" id="tab-customer-messages" style="display:none"></div>
        <div class="tab-content" id="tab-customer-wallet"   style="display:none"></div>
        <div class="tab-content" id="tab-customer-wishlist" style="display:none"></div>
        <div class="tab-content" id="tab-customer-profile"  style="display:none"></div>
      </div>
    </main>
  </div>
  <nav class="mobile-nav" id="customer-mobile-nav"></nav>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE: DRIVER DASHBOARD                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="page-driver-dashboard" class="page" style="display:none">
  <div class="dashboard-layout">
    <aside class="sidebar" id="driver-sidebar"></aside>
    <main class="main-content">
      <div class="topbar" id="driver-topbar"></div>
      <div class="tab-body">
        <div class="tab-content" id="tab-driver-home"></div>
        <div class="tab-content" id="tab-driver-navigation" style="display:none"></div>
        <div class="tab-content" id="tab-driver-earnings"   style="display:none"></div>
        <div class="tab-content" id="tab-driver-mycar"      style="display:none"></div>
        <div class="tab-content" id="tab-driver-messages"   style="display:none"></div>
        <div class="tab-content" id="tab-driver-profile"    style="display:none"></div>
      </div>
    </main>
  </div>
  <nav class="mobile-nav" id="driver-mobile-nav"></nav>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE: ADMIN DASHBOARD                                                      -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="page-admin-dashboard" class="page" style="display:none">
  <div class="dashboard-layout">
    <aside class="sidebar" id="admin-sidebar"></aside>
    <main class="main-content">
      <div class="topbar" id="admin-topbar"></div>
      <div class="tab-body">
        <div class="tab-content" id="tab-admin-dashboard"></div>
        <div class="tab-content" id="tab-admin-cars"       style="display:none"></div>
        <div class="tab-content" id="tab-admin-drivers"    style="display:none"></div>
        <div class="tab-content" id="tab-admin-bookings"   style="display:none"></div>
        <div class="tab-content" id="tab-admin-media"      style="display:none"></div>
        <div class="tab-content" id="tab-admin-chats"      style="display:none"></div>
        <div class="tab-content" id="tab-admin-customers"  style="display:none"></div>
        <div class="tab-content" id="tab-admin-revenue"    style="display:none"></div>
        <div class="tab-content" id="tab-admin-payouts"    style="display:none"></div>
        <div class="tab-content" id="tab-admin-pricing"    style="display:none"></div>
        <div class="tab-content" id="tab-admin-promos"     style="display:none"></div>
        <div class="tab-content" id="tab-admin-cities"     style="display:none"></div>
        <div class="tab-content" id="tab-admin-booking-card" style="display:none"></div>
      </div>
    </main>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE: UNDER REVIEW                                                         -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="page-under-review" class="page" style="display:none">
  <div id="under-review-content"></div>
</div>

<!-- Scripts -->
<script src="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script src="https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js" async defer></script>
<script src="/js/utils.js?v=4"></script>
<script src="/js/app.js?v=4"></script>
<script src="/js/maps.js?v=4"></script>
<script src="/js/auth.js?v=4"></script>
<script src="/js/customer.js?v=4"></script>
<script src="/js/driver.js?v=4"></script>
<script src="/js/admin.js?v=6"></script>
<script>
  // Register service worker for PWA
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  }
</script>
</body>
</html>
