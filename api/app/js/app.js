// ── BOOKKAM APP.JS ────────────────────────────────────────────────────────────
const API = "/api";

let authToken   = localStorage.getItem("bookkam_token")   || sessionStorage.getItem("bookkam_token")  || null;
let currentUser = JSON.parse(localStorage.getItem("bookkam_user") || sessionStorage.getItem("bookkam_user") || "null");
let customerCity = "Calabar";

// ── API helper ────────────────────────────────────────────────────────────────
async function api(endpoint, method="GET", body=null) {
  const opts = {
    method,
    headers: { "Content-Type": "application/json", "Authorization": "Bearer " + authToken }
  };
  if (body) opts.body = JSON.stringify(body);
  try {
    const res  = await fetch(API + endpoint, opts);
    const data = await res.json();
    return data;
  } catch(e) {
    return { error: "Network error" };
  }
}

// ── Page/Tab helpers ──────────────────────────────────────────────────────────
function showPage(id) {
  document.querySelectorAll(".page").forEach(p => p.style.display = "none");
  const el = document.getElementById(id);
  if (el) { el.style.display = "block"; el.classList.add("fade-in"); }
}

function showTab(id) {
  document.querySelectorAll(".tab-content").forEach(t => t.style.display = "none");
  const el = document.getElementById(id);
  if (el) { el.style.display = "block"; el.classList.add("fade-in"); }
}

function showModal(html) {
  document.getElementById("modal-content").innerHTML = html;
  document.getElementById("modal-overlay").style.display = "flex";
}
function closeModal() {
  document.getElementById("modal-overlay").style.display = "none";
  // Destroy ride preview map if open so Leaflet doesn't leak
  if (typeof ridePreviewMap !== "undefined" && ridePreviewMap) {
    ridePreviewMap.remove();
    ridePreviewMap = null;
  }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(message, type="success") {
  const colors = { success:"#00E87A", error:"#FF5A5A", warning:"#F5A623", info:"#00D4FF" };
  const icons  = { success:"check_circle", error:"error", warning:"warning", info:"info" };
  const toast  = document.createElement("div");
  toast.className = "toast";
  toast.style.cssText = `border-left:3px solid ${colors[type]||colors.info}`;
  toast.innerHTML = `<span class="material-icons-outlined" style="color:${colors[type]};font-size:18px">${icons[type]||"info"}</span><span>${message}</span>`;
  document.getElementById("toast-container").appendChild(toast);
  setTimeout(() => toast.classList.add("show"), 10);
  setTimeout(() => { toast.classList.remove("show"); setTimeout(() => toast.remove(), 300); }, 3500);
}

// ── Inactivity logout (1 hour) ────────────────────────────────────────────────
let inactivityTimer = null;
const INACTIVITY_MS = 60 * 60 * 1000; // 1 hour

function resetInactivityTimer() {
  clearTimeout(inactivityTimer);
  inactivityTimer = setTimeout(() => {
    if (authToken) {
      showToast("You've been logged out due to inactivity", "info");
      setTimeout(logout, 1500);
    }
  }, INACTIVITY_MS);
}

function startInactivityWatcher() {
  ["click","mousemove","keypress","scroll","touchstart"].forEach(evt => {
    document.addEventListener(evt, resetInactivityTimer, { passive: true });
  });
  resetInactivityTimer();
}

// ── Logout ────────────────────────────────────────────────────────────────────
function logout() {
  clearSessionPersistence();
  authToken   = null;
  currentUser = null;
  localStorage.removeItem("bookkam_token");
  localStorage.removeItem("bookkam_user");
  sessionStorage.removeItem("bookkam_token");
  sessionStorage.removeItem("bookkam_user");
  clearTimeout(inactivityTimer);
  location.reload();
}

// ── Theme toggle ──────────────────────────────────────────────────────────────
function toggleTheme() {
  const html    = document.documentElement;
  const current = html.getAttribute('data-theme') || 'dark';
  const next    = current === 'dark' ? 'light' : 'dark';
  const orb     = document.getElementById('mode-orb');
  const icon    = document.getElementById('theme-icon');

  if (orb) orb.classList.add('switching');
  setTimeout(() => {
    html.setAttribute('data-theme', next);
    localStorage.setItem('bookkam_theme', next);
    if (icon) icon.textContent = next === 'dark' ? 'dark_mode' : 'light_mode';
    if (orb) orb.classList.remove('switching');
    if (typeof refreshMapTiles === 'function') refreshMapTiles();
  }, 200);
}

// ── Admin login toggle (triggered by URL param, no public button) ─────────────
function showAdminLogin() {
  document.getElementById('admin-login-area').style.display = 'block';
  document.getElementById('auth-content').style.display     = 'none';
}
function hideAdminLogin() {
  document.getElementById('admin-login-area').style.display = 'none';
  document.getElementById('auth-content').style.display     = 'block';
}

// ── Guest booking guard ───────────────────────────────────────────────────────
function requireAuth(callback) {
  if (!authToken || currentUser?.role === "guest") {
    showModal(`
      <div style="padding:32px;text-align:center">
        <span class="material-icons-outlined" style="font-size:48px;color:var(--gold);margin-bottom:16px;display:block">lock_outline</span>
        <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;margin-bottom:8px">Sign in to continue</h3>
        <p style="color:var(--muted);margin-bottom:24px">You need an account to book this car</p>
        <button class="btn btn-gold btn-full" onclick="closeModal();showPage('page-auth');renderAuthLogin()">
          <span class="material-icons-outlined">login</span> Login
        </button>
        <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="closeModal()">
          <span class="material-icons-outlined">close</span> Cancel
        </button>
      </div>
    `);
    return false;
  }
  if (callback) callback();
  return true;
}



function shimmerCards(n) {
  return Array(n).fill(`<div class="shimmer" style="height:200px;border-radius:14px"></div>`).join("");
}

function setMobileNav(role, name) {
  document.querySelectorAll(`#${role}-mobile-nav .mobile-nav-item`).forEach(i => i.classList.remove("active"));
  const el = document.getElementById(`mnav-${name}`);
  if (el) el.classList.add("active");
}

// ── Notification poll ─────────────────────────────────────────────────────────
let notifPollInterval = null;
function startNotifPoll() {
  updateNotifBadge();
  notifPollInterval = setInterval(updateNotifBadge, 30000);
}
async function updateNotifBadge() {
  try {
    const data = await api("/notifications.php?action=get_unread_count");
    const count = data.count || 0;
    // Update all badge elements (customer + driver topbar both use id="notif-badge")
    document.querySelectorAll("#notif-badge").forEach(badge => {
      badge.textContent = count > 0 ? (count > 99 ? "99+" : count) : "";
    });
  } catch(e) {}
}

// ── Toggle notifications dropdown ─────────────────────────────────────────────
let notifOpen = false;
function toggleNotifications(event) {
  event.stopPropagation();
  notifOpen ? closeNotifications() : openNotifications();
}
async function openNotifications() {
  notifOpen = true;
  const btn = document.querySelector(".topbar-notif");
  if (!btn) return;
  btn.style.position = "relative";
  const existing = document.getElementById("notif-dropdown");
  if (existing) existing.remove();
  const dropdown = document.createElement("div");
  dropdown.className = "notif-dropdown";
  dropdown.id = "notif-dropdown";
  dropdown.innerHTML = `
    <div class="notif-dropdown-header">
      <span>Notifications</span>
      <button onclick="markAllNotifRead()">Mark all read</button>
    </div>
    <div class="notif-list" id="notif-list">
      <div style="padding:24px;text-align:center">
        <div class="shimmer" style="height:60px;border-radius:10px;margin-bottom:8px"></div>
        <div class="shimmer" style="height:60px;border-radius:10px"></div>
      </div>
    </div>`;
  btn.appendChild(dropdown);
  const data = await api("/notifications.php?action=get_all");
  const list = document.getElementById("notif-list");
  if (!list) return;
  if (!data.notifications || !data.notifications.length) {
    list.innerHTML = `<div class="notif-empty"><span class="material-icons-outlined">notifications_none</span><p>No notifications yet</p></div>`;
    return;
  }
  list.innerHTML = data.notifications.map(n => `
    <div class="notif-item ${n.is_read?"read":"unread"}">
      <div class="notif-icon"><span class="material-icons-outlined">${n.type==="booking"?"directions_car":n.type==="flag"?"warning":"notifications"}</span></div>
      <div class="notif-body">
        <div class="notif-title">${n.title}</div>
        <div class="notif-msg">${n.body}</div>
        <div class="notif-time">${fmtDate(n.created_at)}</div>
      </div>
    </div>`).join("");
}
function closeNotifications() {
  notifOpen = false;
  const d = document.getElementById("notif-dropdown");
  if (d) d.remove();
}
async function markAllNotifRead() {
  await api("/notifications.php?action=mark_all_read", "POST");
  closeNotifications();
  updateNotifBadge();
}
document.addEventListener("click", e => {
  if (notifOpen && !e.target.closest(".topbar-notif")) closeNotifications();
});

// ── Boot ──────────────────────────────────────────────────────────────────────
window.addEventListener("DOMContentLoaded", () => {
  // Sync orb icon with theme already applied by inline <head> script
  const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
  const themeIcon = document.getElementById('theme-icon');
  if (themeIcon) themeIcon.textContent = currentTheme === 'dark' ? 'dark_mode' : 'light_mode';

  setTimeout(() => {
    const loader = document.getElementById("app-loader");
    if (loader) { loader.style.opacity = "0"; setTimeout(() => loader.remove(), 400); }
  }, 1200);

  if (authToken && currentUser) {
    startInactivityWatcher();
    customerCity = currentUser.city || "Calabar";

    if (currentUser.role === "customer" || currentUser.role === "guest") {
      showPage("page-customer-dashboard");
      loadCustomerDashboard().then(() => {
        // Restore last tab after dashboard loads
        const lastTab = getLastTab();
        if (lastTab && lastTab !== "home") {
          customerTab(lastTab);
          setMobileNav("customer", lastTab);
        }
      });

    } else if (currentUser.role === "driver") {
      api("/api/auth.php?action=get_driver_status").then(data => {
        if (data.driver_status === "active") {
          showPage("page-driver-dashboard");
          loadDriverDashboard().then(() => {
            const lastTab = getLastTab();
            if (lastTab && lastTab !== "home") {
              driverTab(lastTab);
              setMobileNav("driver", lastTab);
            }
          });
        } else {
          showPage("page-under-review");
          renderUnderReview(data.driver_status || "pending");
        }
      });

    } else if (currentUser.role === "admin") {
      showPage("page-admin-dashboard");
      loadAdminDashboard();
    }

  } else {
    showPage("page-auth");
    renderAuthLogin();
    // Auto-reveal admin form if accessed via secret URL
    if (window.ADMIN_PORTAL) {
      setTimeout(showAdminLogin, 100);
    }
  }
});
// ── PWA INSTALL PROMPT ────────────────────────────────────────────────────────
// Paste this entire block at the bottom of your app.js

let deferredInstallPrompt = null;
const PWA_DISMISSED_KEY   = 'bookkam_pwa_dismissed';
const PWA_INSTALLED_KEY   = 'bookkam_pwa_installed';

// Catch the install prompt before browser shows it
window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  deferredInstallPrompt = e;

  // Don't show if already installed or dismissed recently
  if (localStorage.getItem(PWA_INSTALLED_KEY)) return;
  const dismissed = localStorage.getItem(PWA_DISMISSED_KEY);
  if (dismissed && Date.now() - parseInt(dismissed) < 7 * 24 * 60 * 60 * 1000) return;

  // Wait 30 seconds before showing — not aggressive
  setTimeout(showInstallBanner, 30000);
});

// Hide banner after actual install
window.addEventListener('appinstalled', () => {
  localStorage.setItem(PWA_INSTALLED_KEY, '1');
  hideInstallBanner();
});

function showInstallBanner() {
  if (document.getElementById('pwa-banner')) return;
  if (localStorage.getItem(PWA_INSTALLED_KEY)) return;

  // Detect iOS
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isInStandaloneMode = window.matchMedia('(display-mode: standalone)').matches;
  if (isInStandaloneMode) return; // Already installed

  const banner = document.createElement('div');
  banner.id = 'pwa-banner';

  if (isIOS) {
    // iOS Safari needs manual instructions
    banner.innerHTML = `
      <div class="pwa-banner-inner">
        <div class="pwa-banner-icon">
          <img src="/icons/icon-192.png" alt="BOOKKAM">
        </div>
        <div class="pwa-banner-text">
          <strong>Add BOOKKAM to Home Screen</strong>
          <span>Tap <b>Share</b> ↑ then <b>"Add to Home Screen"</b></span>
        </div>
        <button class="pwa-banner-close" onclick="dismissInstallBanner()">✕</button>
      </div>
    `;
  } else {
    // Android / Chrome
    banner.innerHTML = `
      <div class="pwa-banner-inner">
        <div class="pwa-banner-icon">
          <img src="/icons/icon-192.png" alt="BOOKKAM">
        </div>
        <div class="pwa-banner-text">
          <strong>Install BOOKKAM</strong>
          <span>Get the app — works offline too</span>
        </div>
        <button class="pwa-banner-install" onclick="triggerInstall()">Install</button>
        <button class="pwa-banner-close" onclick="dismissInstallBanner()">✕</button>
      </div>
    `;
  }

  document.body.appendChild(banner);

  // Animate in
  requestAnimationFrame(() => {
    requestAnimationFrame(() => banner.classList.add('pwa-banner-visible'));
  });
}

function hideInstallBanner() {
  const banner = document.getElementById('pwa-banner');
  if (!banner) return;
  banner.classList.remove('pwa-banner-visible');
  setTimeout(() => banner.remove(), 400);
}

function dismissInstallBanner() {
  localStorage.setItem(PWA_DISMISSED_KEY, Date.now().toString());
  hideInstallBanner();
}

async function triggerInstall() {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  const { outcome } = await deferredInstallPrompt.userChoice;
  if (outcome === 'accepted') {
    localStorage.setItem(PWA_INSTALLED_KEY, '1');
  } else {
    localStorage.setItem(PWA_DISMISSED_KEY, Date.now().toString());
  }
  deferredInstallPrompt = null;
  hideInstallBanner();
}

// ── Manual install button (put anywhere in your UI) ───────────────────────────
// Call showInstallBanner() from a button if you want a manual trigger
// e.g. in your settings page: <button onclick="showInstallBanner()">Install App</button>

// ── SESSION PERSISTENCE ───────────────────────────────────────────────────────
const BK_PAGE_KEY = "bookkam_last_page";
const BK_TAB_KEY  = "bookkam_last_tab";

function saveCurrentPage(page, tab) {
  localStorage.setItem(BK_PAGE_KEY, page);
  if (tab) localStorage.setItem(BK_TAB_KEY, tab);
}

function getLastPage() {
  return localStorage.getItem(BK_PAGE_KEY) || null;
}

function getLastTab() {
  return localStorage.getItem(BK_TAB_KEY) || "home";
}

function clearSessionPersistence() {
  localStorage.removeItem(BK_PAGE_KEY);
  localStorage.removeItem(BK_TAB_KEY);
}
