<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <script>
    (function () {
      try { var t = localStorage.getItem('bookkam_theme') || 'dark'; } catch (e) { var t = 'dark'; }
      document.documentElement.classList.add(t === 'light' ? 'light-theme' : 'dark-theme');
    })();
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BOOKKAM — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/material-icons-outlined.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy: #0A0F1E;
      --navy-card: #111827;
      --navy-mid: #1A2338;
      --cognac: #E8420A;
      --cognac-glow: #F4622A;
      --white: #F0EDE8;
      --muted: #8A96A8;
      --border: rgba(200, 114, 42, 0.18);
    }
    .light-theme {
      --navy: #F8F9FA;
      --navy-card: #FFFFFF;
      --navy-mid: #F1F3F5;
      --white: #0A0F1E;
      --muted: #5C677D;
      --border: rgba(200, 114, 42, 0.12);
    }

    html, body {
      background: var(--navy);
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
    }

    .wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .card {
      width: 100%;
      max-width: 420px;
      background: var(--navy-card);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 32px 28px;
      text-align: center;
    }

    .avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: rgba(200, 114, 42, 0.12);
      color: var(--cognac);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 18px;
      font-size: 28px;
    }

    h1 {
      font-family: 'Outfit', sans-serif;
      font-weight: 800;
      font-size: 22px;
      margin-bottom: 6px;
    }

    .role-pill {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: var(--cognac);
      background: rgba(200, 114, 42, 0.1);
      border: 1px solid rgba(200, 114, 42, 0.25);
      border-radius: 100px;
      padding: 4px 12px;
      margin-bottom: 18px;
    }

    .field {
      text-align: left;
      background: var(--navy-mid);
      border-radius: 12px;
      padding: 10px 14px;
      margin-bottom: 10px;
    }

    .field-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--muted);
      margin-bottom: 2px;
    }

    .field-value {
      font-size: 13px;
      font-weight: 600;
      word-break: break-all;
    }

    .btn {
      width: 100%;
      margin-top: 18px;
      padding: 13px;
      border-radius: 12px;
      border: 1.5px solid var(--border);
      background: transparent;
      color: var(--muted);
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .btn:hover { color: var(--cognac); border-color: var(--cognac); }

    .loading, .error {
      text-align: center;
      color: var(--muted);
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="wrap" id="wrap">
    <div class="loading">Checking your session…</div>
  </div>

  <script>
    // ── Confirms the redirect-with-JWT-session flow ─────────────────────────
    // auth.js stores the token + user object in localStorage (or
    // sessionStorage, if "remember me" wasn't checked) right before it
    // redirects here. This page's only job is to read that session back —
    // if it's missing or unreadable, there's no valid login and we bounce
    // back to the landing page instead of showing a dashboard to nobody.
    (function () {
      const token = localStorage.getItem('bookkam_token') || sessionStorage.getItem('bookkam_token');
      const userRaw = localStorage.getItem('bookkam_user') || sessionStorage.getItem('bookkam_user');
      const wrap = document.getElementById('wrap');

      if (!token || !userRaw) {
        window.location.href = 'landing.html';
        return;
      }

      let user;
      try {
        user = JSON.parse(userRaw);
      } catch (e) {
        // Corrupt session data — don't trust it, clear it, send them back.
        localStorage.removeItem('bookkam_token');
        localStorage.removeItem('bookkam_user');
        sessionStorage.removeItem('bookkam_token');
        sessionStorage.removeItem('bookkam_user');
        window.location.href = 'landing.html';
        return;
      }

      const params = new URLSearchParams(window.location.search);
      const role   = user.role || params.get('role') || 'customer';

      const firstName = (user.name || 'there').split(' ')[0];
      const tokenPreview = token.length > 24 ? token.slice(0, 16) + '…' + token.slice(-6) : token;

      const card = document.createElement('div');
      card.className = 'card';
      card.innerHTML = `
        <div class="avatar"><span class="material-icons-outlined">${role === 'driver' ? 'directions_car' : 'person'}</span></div>
        <div class="role-pill">${role === 'driver' ? 'Driver' : 'Customer'} Dashboard</div>
        <h1>Welcome back, ${escapeHtml(firstName)}!</h1>
        <p style="color:var(--muted);font-size:13px;margin-bottom:18px">You're signed in with an active session.</p>
        <div class="field">
          <div class="field-label">Name</div>
          <div class="field-value">${escapeHtml(user.name || '—')}</div>
        </div>
        <div class="field">
          <div class="field-label">${user.email ? 'Email' : 'Phone'}</div>
          <div class="field-value">${escapeHtml(user.email || user.phone || '—')}</div>
        </div>
        <div class="field">
          <div class="field-label">Session Token</div>
          <div class="field-value" style="font-family:'Space Mono',monospace;color:var(--cognac)">${escapeHtml(tokenPreview)}</div>
        </div>
        <button class="btn" id="logout-btn">
          <span class="material-icons-outlined" style="font-size:16px">logout</span> Logout
        </button>
      `;
      wrap.innerHTML = '';
      wrap.appendChild(card);

      document.getElementById('logout-btn').addEventListener('click', () => {
        localStorage.removeItem('bookkam_token');
        localStorage.removeItem('bookkam_user');
        sessionStorage.removeItem('bookkam_token');
        sessionStorage.removeItem('bookkam_user');
        window.location.href = 'landing.html';
      });

      function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
      }
    })();
  </script>
</body>
</html>
