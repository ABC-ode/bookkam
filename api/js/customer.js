// ── CUSTOMER.JS ───────────────────────────────────────────────────────────────

function loadCustomerDashboard() {
  renderCustomerSidebar();
  renderCustomerTopbar();
  renderCustomerMobileNav();
  showTab("tab-customer-home");
  loadCustomerHome();
  startNotifPoll();
  // Return resolved promise so boot can chain .then() for tab restore
  return Promise.resolve();
}

function renderCustomerSidebar() {
  const isGuest = !authToken || currentUser?.role === "guest";
  document.getElementById("customer-sidebar").innerHTML = `
  <div class="sidebar-brand"><img src="/icons/logo-main.png" alt="BOOKKAM" class="sidebar-logo-img"></div>
  <ul class="sidebar-nav">
    <li><a class="active" onclick="customerTab('home',this)" data-label="Home"><span class="material-icons-outlined">home</span><span class="nav-label">Home</span></a></li>
    <li><a onclick="customerTab('rent',this)" data-label="Rent a Car"><span class="material-icons-outlined">drive_eta</span><span class="nav-label">Rent a Car</span></a></li>
    <li><a onclick="customerTab('ride',this)" data-label="Book a Ride"><span class="material-icons-outlined">airport_shuttle</span><span class="nav-label">Book a Ride</span></a></li>
    <li><a onclick="customerTab('bookings',this)" data-label="Bookings"><span class="material-icons-outlined">receipt_long</span><span class="nav-label">My Bookings</span></a></li>
    <li><a onclick="customerTab('tracking',this)" data-label="Track"><span class="material-icons-outlined">map</span><span class="nav-label">Track Trip</span></a></li>
    <li><a onclick="customerTab('messages',this)" data-label="Messages"><span class="material-icons-outlined">chat_bubble_outline</span><span class="nav-label">Messages</span></a></li>
    <li><a onclick="customerTab('wallet',this)" data-label="Wallet"><span class="material-icons-outlined">account_balance_wallet</span><span class="nav-label">Wallet</span></a></li>
    <li><a onclick="customerTab('wishlist',this)" data-label="Wishlist"><span class="material-icons-outlined">favorite_border</span><span class="nav-label">Wishlist</span></a></li>
    ${isGuest
      ? `<li><a onclick="showPage('page-auth');renderAuthLogin()" data-label="Login" style="color:var(--gold)"><span class="material-icons-outlined">login</span><span class="nav-label">Login / Sign Up</span></a></li>`
      : `<li><a onclick="customerTab('profile',this)" data-label="Profile"><span class="material-icons-outlined">person_outline</span><span class="nav-label">Profile</span></a></li>`
    }
  </ul>`;
}

function renderCustomerTopbar() {
  document.getElementById("customer-topbar").innerHTML = `
  <div class="topbar-greeting">
    ${greet()}, <span>${currentUser.name ? currentUser.name.split(" ")[0] : "Guest"}</span>
  </div>
  <div class="topbar-actions">
    <div class="topbar-notif" onclick="toggleNotifications(event)">
      <span class="material-icons-outlined">notifications_none</span>
      <span id="notif-badge"></span>
    </div>
    <div class="city-badge" onclick="showCitySelector()">
      <span class="material-icons-outlined" style="font-size:14px">location_on</span>
      <span>${customerCity}</span>
    </div>
  </div>`;
}

function renderCustomerMobileNav() {
  document.getElementById("customer-mobile-nav").innerHTML = `<div class="mobile-nav-items">
    <div class="mobile-nav-item active" id="mnav-home" onclick="customerTab('home');setMobileNav('customer','home')">
      <span class="material-icons-outlined">home</span><span>Home</span>
    </div>
    <div class="mobile-nav-item" id="mnav-rent" onclick="customerTab('rent');setMobileNav('customer','rent')">
      <span class="material-icons-outlined">drive_eta</span><span>Rent</span>
    </div>
    <div class="mobile-nav-item" id="mnav-ride" onclick="customerTab('ride');setMobileNav('customer','ride')">
      <span class="material-icons-outlined">airport_shuttle</span><span>Ride</span>
    </div>
    <div class="mobile-nav-item" id="mnav-bookings" onclick="customerTab('bookings');setMobileNav('customer','bookings')">
      <span class="material-icons-outlined">receipt_long</span><span>Bookings</span>
    </div>
    ${(!authToken || currentUser?.role === "guest")
      ? `<div class="mobile-nav-item" id="mnav-profile" onclick="showPage('page-auth');renderAuthLogin()" style="color:var(--gold)">
           <span class="material-icons-outlined">login</span><span>Login</span>
         </div>`
      : `<div class="mobile-nav-item" id="mnav-profile" onclick="customerTab('profile');setMobileNav('customer','profile')">
           <span class="material-icons-outlined">person_outline</span><span>Profile</span>
         </div>`
    }
  </div>`;
}

function customerTab(name, el) {
  document.querySelectorAll(".sidebar-nav a").forEach(a => a.classList.remove("active"));
  if (el) el.classList.add("active");
  showTab("tab-customer-" + name);
  // Persist current tab so reload returns here
  if (typeof saveCurrentPage === "function") {
    saveCurrentPage("page-customer-dashboard", name);
  }
  const handlers = {
    home:     loadCustomerHome,
    rent:     loadRentACar,
    ride:     loadBookARide,
    bookings: loadMyBookings,
    tracking: loadTracking,
    messages: loadCustomerMessages,
    wallet:   loadWallet,
    wishlist: loadWishlist,
    profile:  loadCustomerProfile,
  };
  if (handlers[name]) handlers[name]();
}

// ── HOME ──────────────────────────────────────────────────────────────────────
async function loadCustomerHome() {
  const isGuest = !authToken || currentUser?.role === "guest";
  const el = document.getElementById("tab-customer-home");
  el.innerHTML = `
  <div style="padding:20px 20px 0">
    ${isGuest
      ? `<div class="hero-wallet" style="cursor:default;background:linear-gradient(135deg,rgba(201,125,58,0.15),rgba(201,125,58,0.05));border:1px solid rgba(201,125,58,0.2)" onclick="showPage('page-auth');renderAuthLogin()">
           <div style="font-size:11px;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:2px;margin-bottom:8px">Welcome to BOOKKAM</div>
           <div style="font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:300;margin-bottom:8px">Luxury rides at your fingertips</div>
           <div style="display:inline-flex;align-items:center;gap:6px;background:var(--gold);color:#000;padding:8px 16px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:0.5px">
             <span class="material-icons-outlined" style="font-size:14px">login</span> Login or Sign Up Free
           </div>
         </div>`
      : `<div class="hero-wallet" onclick="customerTab('wallet')">
           <div style="font-size:11px;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:2px;margin-bottom:4px">Wallet Balance</div>
           <div style="font-family:'Cormorant Garamond',serif;font-size:36px;font-weight:300" id="home-wallet-bal">—</div>
           <div style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:4px">Tap to top up</div>
         </div>`
    }
  </div>
  <div id="active-booking-area" style="padding:0 20px"></div>
  <div style="padding:20px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px">
      <div class="action-card" onclick="customerTab('rent');setMobileNav('customer','rent')">
        <span class="material-icons-outlined" style="font-size:32px;color:var(--gold);display:block;margin-bottom:8px">drive_eta</span>
        <div style="font-weight:700;font-size:15px">Rent a Car</div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px">Self-drive experience</div>
      </div>
      <div class="action-card" onclick="customerTab('ride');setMobileNav('customer','ride')">
        <span class="material-icons-outlined" style="font-size:32px;color:var(--cyan);display:block;margin-bottom:8px">airport_shuttle</span>
        <div style="font-weight:700;font-size:15px">Book a Ride</div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px">Driver included</div>
      </div>
    </div>
    ${!isGuest ? `
    <div class="flex-between" style="margin-bottom:12px">
      <div class="section-label">Recent Bookings</div>
      <a onclick="customerTab('bookings')" style="font-size:11px;font-family:'Space Mono',monospace;letter-spacing:1px;color:var(--muted)">View all →</a>
    </div>
    <div id="home-recent-bookings">${shimmerCards(2)}</div>` : ""}
  </div>`;

  // Skip all auth-required API calls for guests
  if (isGuest) return;

  // Load wallet balance
  const walletData = await api("/payments.php?action=get_wallet");
  const walBal = document.getElementById("home-wallet-bal");
  if (walBal) walBal.textContent = fmt(walletData.balance || 0);

  // Check active booking
  checkActiveBooking();

  // Load recent bookings
  const bData = await api("/bookings.php?action=get_my_bookings");
  const recEl = document.getElementById("home-recent-bookings");
  if (!recEl) return;
  const bookings = (bData.bookings || []).slice(0,3);
  if (!bookings.length) {
    recEl.innerHTML = `<div style="text-align:center;padding:32px;color:var(--muted)">No bookings yet. Start by renting a car or booking a ride!</div>`;
    return;
  }
  recEl.innerHTML = bookings.map(b => bookingCardHTML(b)).join("");
}

async function checkActiveBooking() {
  const data = await api("/bookings.php?action=get_my_bookings&status=active");
  const area = document.getElementById("active-booking-area");
  if (!area) return;
  if (data.bookings && data.bookings.length > 0) {
    const b = data.bookings[0];
    area.innerHTML = `
    <div class="active-booking-banner">
      <div style="display:flex;align-items:center;gap:10px">
        <span class="pulse-dot"></span>
        <div>
          <div style="font-weight:700;color:var(--cyan);font-size:14px">Active Trip</div>
          <div style="font-size:12px;color:var(--muted)">${b.car_name} · ${b.car_type==="self_drive"?"Self Drive":"With Driver"}</div>
        </div>
      </div>
      <button class="btn btn-sm" style="background:var(--cyan);color:#020B18;font-weight:700" onclick="customerTab('tracking')">
        <span class="material-icons-outlined" style="font-size:15px">map</span> Track
      </button>
    </div>`;
  }
}

function bookingCardHTML(b) {
  const isRide = b.car_type === "chauffeur";
  return `
  <div class="card card-hover" style="margin-bottom:12px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700;font-size:15px;margin-bottom:3px">${b.car_name}</div>
        <div style="font-size:12px;color:var(--muted)">${isRide?"🚗 Ride":"🔑 Rental"} · ${capitalize(b.booking_type)} · ${fmtDate(b.created_at)}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px">${b.pickup_address||"—"}</div>
      </div>
      <div style="text-align:right">
        <span class="badge ${statusColor(b.status)}">${capitalize(b.status)}</span>
        <div style="font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:300;color:var(--gold);margin-top:4px">${fmt(b.total_price)}</div>
      </div>
    </div>
  </div>`;
}

// ── RENT A CAR ────────────────────────────────────────────────────────────────
async function loadRentACar() {
  const el = document.getElementById("tab-customer-rent");
  el.innerHTML = `
  <div class="page-header">
    <h2>Rent a <span>Car</span></h2>
    <p>Self-drive experience — you're behind the wheel</p>
  </div>
  <div style="padding:0 20px 20px">
    <div class="info-banner" style="background:rgba(201,125,58,0.1);border:1px solid rgba(201,125,58,0.2);border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-start">
      <span class="material-icons-outlined" style="color:var(--gold);font-size:18px;flex-shrink:0;margin-top:2px">info</span>
      <div style="font-size:12px;color:var(--muted);line-height:1.5">A refundable security deposit is required. It will be returned after the car is inspected on return.</div>
    </div>
    <div class="car-grid" id="rent-cars-grid">${shimmerCards(4)}</div>
  </div>`;

  const data = await api(`/cars.php?action=get_all&type=self_drive&city=${customerCity}`);
  // Preload wishlist IDs so hearts render correctly
  if (authToken && currentUser?.role !== "guest") {
    const wData = await api("/cars.php?action=get_wishlist_ids");
    window._wishlistIds = (wData.ids || []).map(Number);
  } else {
    window._wishlistIds = [];
  }
  const grid = document.getElementById("rent-cars-grid");
  if (!grid) return;
  const cars = data.cars || [];
  if (!cars.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:48px 20px;color:var(--muted)">No self-drive cars available in ${customerCity} right now.</div>`;
    return;
  }
  grid.innerHTML = cars.map(c => selfDriveCardHTML(c)).join("");
}

function selfDriveCardHTML(c) {
  const available   = c.availability_status === "available";
  const wishlisted  = (window._wishlistIds || []).includes(c.id);
  return `
  <div class="car-card ${!available?"car-unavailable":""}" onclick="${available?`openSelfDriveModal(${c.id})`:""}">
    <div class="car-card-img" style="position:relative">
      ${c.main_photo ? `<img src="${c.main_photo}" alt="${c.name}" loading="lazy">` : `<div class="car-img-placeholder"><span class="material-icons-outlined">directions_car</span></div>`}
      <div class="availability-badge ${available?"badge-available":"badge-in-use"}">
        <span class="material-icons-outlined" style="font-size:10px">${available?"check_circle":"radio_button_checked"}</span>
        ${available?"Available":"In Use"}
      </div>
      ${!available && c.expected_return_at ? `<div class="return-badge">Returns ${fmtDate(c.expected_return_at)}</div>` : ""}
      <button id="wish-btn-${c.id}" class="wishlist-heart-btn" onclick="event.stopPropagation();toggleWishlist(${c.id},this)" title="${wishlisted?"Remove from wishlist":"Save to wishlist"}">
        <span class="material-icons-outlined" style="color:${wishlisted?"#e74c3c":"rgba(255,255,255,0.8)"}">${wishlisted?"favorite":"favorite_border"}</span>
      </button>
    </div>
    <div class="car-card-body">
      <div class="car-card-name">${c.name}</div>
      <div class="car-specs-mini">
        ${c.year ? `<span>${c.year}</span>` : ""}
        ${c.transmission ? `<span>${capitalize(c.transmission)}</span>` : ""}
        ${c.seats ? `<span>${c.seats} seats</span>` : ""}
      </div>
      ${c.security_deposit > 0 ? `<div style="font-size:11px;color:var(--muted);margin-top:4px">Deposit: ${fmt(c.security_deposit)}</div>` : ""}
      <div style="font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:300;color:var(--gold);margin-top:8px">
        ${fmt(c.per_day_price || 0)}<span style="font-size:12px;color:var(--muted)">/day</span>
      </div>
    </div>
  </div>`;
}

async function openSelfDriveModal(carId) {
  if (!requireAuth()) return;
  showModal(`<div style="padding:20px;text-align:center">${shimmerCards(1)}</div>`);
  const data = await api(`/cars.php?action=get_one&id=${carId}`);
  const c = data.car;
  if (!c) { showToast("Car not found","error"); closeModal(); return; }

  showModal(`
  <div class="modal-header">
    <h3>${c.name}</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="modal-body">
    ${c.media && c.media.length ? `<div class="modal-car-photos">${c.media.map((m,i)=>`<img src="${m.url}" class="modal-car-photo" onclick="openLightbox(${JSON.stringify(c.media.map(x=>x.url))},${i})">`).join("")}</div>` : ""}
    
    <div class="specs-grid">
      <div class="spec-item"><span class="material-icons-outlined">calendar_today</span><div><div class="spec-label">Year</div><div class="spec-val">${c.year||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">palette</span><div><div class="spec-label">Color</div><div class="spec-val">${c.color||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">settings</span><div><div class="spec-label">Transmission</div><div class="spec-val">${capitalize(c.transmission)||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">local_gas_station</span><div><div class="spec-label">Fuel</div><div class="spec-val">${capitalize(c.fuel_type)||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">event_seat</span><div><div class="spec-label">Seats</div><div class="spec-val">${c.seats||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">speed</span><div><div class="spec-label">Daily Limit</div><div class="spec-val">${c.mileage_limit_per_day?c.mileage_limit_per_day+"km":"Unlimited"}</div></div></div>
    </div>

    ${c.description ? `<div style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.6">${c.description}</div>` : ""}

    ${c.security_deposit > 0 ? `
    <div style="background:rgba(201,125,58,0.1);border:1px solid rgba(201,125,58,0.2);border-radius:10px;padding:12px 16px;margin-bottom:16px">
      <div style="font-size:12px;color:var(--muted)">Refundable Security Deposit</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:24px;color:var(--gold)">${fmt(c.security_deposit)}</div>
    </div>` : ""}

    <div class="input-group">
      <label>Booking Type</label>
      <select class="input-field" id="modal-booking-type" onchange="updateRentPrice(${carId})">
        <option value="hourly">Hourly</option>
        <option value="daily" selected>Daily</option>
      </select>
    </div>
    <div class="input-group" id="duration-hours-group">
      <label>Duration (hours)</label>
      <input type="number" class="input-field" id="modal-duration" value="4" min="1" max="23" onchange="updateRentPrice(${carId})">
    </div>
    <div class="input-group" id="duration-days-group" style="display:none">
      <label>Duration (days)</label>
      <input type="number" class="input-field" id="modal-days" value="1" min="1" max="30" onchange="updateRentPrice(${carId})">
    </div>
    <div class="input-group">
      <label>Pickup Address</label>
      <input class="input-field" id="modal-pickup" placeholder="Where should we deliver the car?">
    </div>

    <div id="rent-price-preview" style="text-align:center;margin:16px 0"></div>

    <button class="btn btn-gold btn-full" onclick="createRentBooking(${carId},'${c.city}')">
      <span class="material-icons-outlined">drive_eta</span> Rent This Car
    </button>
  </div>`);

  updateRentPrice(carId);
  // Hook type selector
  document.getElementById("modal-booking-type").addEventListener("change", function() {
    document.getElementById("duration-hours-group").style.display = this.value === "hourly" ? "block" : "none";
    document.getElementById("duration-days-group").style.display  = this.value === "daily"  ? "block" : "none";
  });
}

async function updateRentPrice(carId) {
  const type     = document.getElementById("modal-booking-type")?.value || "daily";
  const duration = parseFloat(document.getElementById("modal-duration")?.value || 4);
  const days     = parseInt(document.getElementById("modal-days")?.value || 1);
  const preview  = document.getElementById("rent-price-preview");
  if (!preview) return;
  const data = await api(`/cars.php?action=get_pricing&city=${customerCity}&type=${type}`);
  const pricing = (data.pricing || [])[0];
  if (!pricing) return;
  let price = type === "hourly" ? pricing.per_hour_price * duration : pricing.per_day_price * days;
  preview.innerHTML = `<div style="font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;color:var(--gold)">${fmt(price)}</div><div style="font-size:11px;color:var(--muted)">${type==="hourly"?`${duration} hrs`:`${days} day${days>1?"s":""}`} · excluding deposit</div>`;
}

async function createRentBooking(carId, city) {
  const type    = document.getElementById("modal-booking-type").value;
  const pickup  = document.getElementById("modal-pickup").value.trim();
  const duration = parseFloat(document.getElementById("modal-duration")?.value || 4);
  const days    = parseInt(document.getElementById("modal-days")?.value || 1);
  if (!pickup) { showToast("Enter pickup address","error"); return; }
  const data = await api("/bookings.php?action=create","POST",{
    car_id:carId, booking_type:type, pickup_address:pickup,
    duration_hours:duration, duration_days:days, city, car_type:"self_drive"
  });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast(`Booking confirmed! Total: ${fmt(data.total)}${data.deposit>0?" + "+fmt(data.deposit)+" deposit":""}`, "success");
  openPaymentModal(data.booking_id, data.total_with_deposit);
}

// ── BOOK A RIDE ───────────────────────────────────────────────────────────────
async function loadBookARide() {
  const el = document.getElementById("tab-customer-ride");
  el.innerHTML = `
  <div class="page-header">
    <h2>Book a <span>Ride</span></h2>
    <p>Professional driver included — sit back and relax</p>
  </div>
  <div style="padding:0 20px 20px">
    <div class="car-grid" id="ride-cars-grid">${shimmerCards(4)}</div>
  </div>`;

  const data = await api(`/cars.php?action=get_all&type=chauffeur&city=${customerCity}&available=1`);
  // Preload wishlist IDs so hearts render correctly
  if (authToken && currentUser?.role !== "guest") {
    const wData = await api("/cars.php?action=get_wishlist_ids");
    window._wishlistIds = (wData.ids || []).map(Number);
  } else {
    window._wishlistIds = [];
  }
  const grid = document.getElementById("ride-cars-grid");
  if (!grid) return;
  const cars = data.cars || [];
  if (!cars.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:48px 20px;color:var(--muted)">No drivers online in ${customerCity} right now.</div>`;
    return;
  }
  grid.innerHTML = cars.map(c => rideCardHTML(c)).join("");
}

function rideCardHTML(c) {
  const wishlisted = (window._wishlistIds || []).includes(c.id);
  return `
  <div class="car-card" onclick="openRideModal(${c.id})">
    <div class="car-card-img" style="position:relative">
      ${c.main_photo ? `<img src="${c.main_photo}" alt="${c.name}" loading="lazy">` : `<div class="car-img-placeholder"><span class="material-icons-outlined">directions_car</span></div>`}
      <div class="availability-badge badge-available">
        <span class="material-icons-outlined" style="font-size:10px">radio_button_checked</span> Online
      </div>
      <button id="wish-btn-${c.id}" class="wishlist-heart-btn" onclick="event.stopPropagation();toggleWishlist(${c.id},this)" title="${wishlisted?"Remove from wishlist":"Save to wishlist"}">
        <span class="material-icons-outlined" style="color:${wishlisted?"#e74c3c":"rgba(255,255,255,0.8)"}">${wishlisted?"favorite":"favorite_border"}</span>
      </button>
    </div>
    <div class="car-card-body">
      <div class="car-card-name">${c.name}</div>
      <div class="car-specs-mini">
        ${c.make || c.model ? `<span>${c.make||""} ${c.model||""}</span>` : ""}
        ${c.year ? `<span>${c.year}</span>` : ""}
      </div>
      <div style="display:flex;align-items:center;gap:4px;margin-top:6px">
        <span class="material-icons-outlined" style="font-size:14px;color:var(--gold)">star</span>
        <span style="font-size:12px;font-weight:600">${c.driver_rating||"5.0"}</span>
        <span style="font-size:11px;color:var(--muted)">(${c.total_trips||0} trips)</span>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">by ${c.driver_name||"Driver"}</div>
    </div>
  </div>`;
}

async function openRideModal(carId) {
  if (!requireAuth()) return;
  showModal(`<div style="padding:20px;text-align:center">${shimmerCards(1)}</div>`);
  const data = await api(`/cars.php?action=get_one&id=${carId}`);
  const c = data.car;
  if (!c) { showToast("Car not found","error"); closeModal(); return; }

  showModal(`
  <div class="modal-header">
    <h3>${c.name}</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="modal-body">
    ${c.media && c.media.length ? `<div class="modal-car-photos">${c.media.map((m,i)=>`<img src="${m.url}" class="modal-car-photo" onclick="openLightbox([${c.media.map(x=>`'${x.url}'`).join(",")}],${i})">`).join("")}</div>` : ""}

    <div class="specs-grid">
      <div class="spec-item"><span class="material-icons-outlined">directions_car</span><div><div class="spec-label">Make/Model</div><div class="spec-val">${c.make||""} ${c.model||""}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">calendar_today</span><div><div class="spec-label">Year</div><div class="spec-val">${c.year||"—"}</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">star</span><div><div class="spec-label">Rating</div><div class="spec-val">${c.driver_rating||"5.0"} ⭐</div></div></div>
      <div class="spec-item"><span class="material-icons-outlined">event_seat</span><div><div class="spec-label">Trips Done</div><div class="spec-val">${c.total_trips||0}</div></div></div>
    </div>

    <div class="driver-card-mini">
      <div style="width:44px;height:44px;border-radius:50%;background:var(--card-dark);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <span class="material-icons-outlined" style="color:var(--gold)">person</span>
      </div>
      <div>
        <div style="font-weight:600">${c.driver_name||"Your Driver"}</div>
        <div style="font-size:12px;color:var(--muted)">Professional Chauffeur</div>
      </div>
    </div>

    <div class="input-group">
      <label>Ride Type</label>
      <div class="ride-type-radios">
        <label class="ride-radio-option">
          <input type="radio" name="modal-booking-type" value="trip" checked>
          <span class="ride-radio-label">One-way Trip</span>
        </label>
        <label class="ride-radio-option">
          <input type="radio" name="modal-booking-type" value="hourly">
          <span class="ride-radio-label">Hourly</span>
        </label>
        <label class="ride-radio-option">
          <input type="radio" name="modal-booking-type" value="daily">
          <span class="ride-radio-label">Full Day</span>
        </label>
      </div>
    </div>
    <div class="input-group" id="ride-duration-group" style="display:none">
      <label>Duration (hours)</label>
      <input type="number" class="input-field" id="modal-duration" value="2" min="1" max="24">
    </div>
    <div class="input-group" style="position:relative">
      <label style="display:flex;align-items:center;justify-content:space-between">
        <span>Pickup Address</span>
        <button id="locate-me-btn" onclick="autoFillPickup()" style="background:none;border:none;cursor:pointer;color:var(--gold);font-size:11px;display:flex;align-items:center;gap:3px;padding:0;font-family:inherit">
          <span class="material-icons-outlined" style="font-size:13px">my_location</span> Use my location
        </button>
      </label>
      <div class="addr-input-wrap">
        <span class="material-icons-outlined addr-search-icon">search</span>
        <input class="input-field addr-input-field" id="modal-pickup" placeholder="e.g. SPAR Marian Road, Calabar" autocomplete="off">
      </div>
      <div id="pickup-suggestions" class="address-suggestions"></div>
    </div>
    <div class="input-group" style="position:relative">
      <label>Drop-off Address</label>
      <div class="addr-input-wrap">
        <span class="material-icons-outlined addr-search-icon">search</span>
        <input class="input-field addr-input-field" id="modal-dropoff" placeholder="e.g. Tinapa Resort" autocomplete="off">
      </div>
      <div id="dropoff-suggestions" class="address-suggestions"></div>
    </div>

    <!-- Live map preview -->
    <div id="ride-map-wrap" style="margin:12px 0;border-radius:12px;overflow:hidden;border:1.5px solid var(--border);position:relative">
      <div id="ride-booking-map" style="height:220px;width:100%;background:var(--bg-deep)"></div>
      <div id="ride-map-status" style="position:absolute;bottom:0;left:0;right:0;background:rgba(2,11,24,0.82);padding:6px 12px;font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px;backdrop-filter:blur(4px)">
        <span class="material-icons-outlined" style="font-size:13px">info</span>
        <span id="ride-map-status-text">Enter addresses to preview the route</span>
      </div>
    </div>

    <button class="btn btn-gold btn-full" id="ride-book-btn" onclick="createRideBooking(${carId},'${c.city}')">
      <span class="material-icons-outlined">airport_shuttle</span> Book This Ride
    </button>
  </div>`);

  // Show duration for hourly
  document.querySelectorAll("input[name='modal-booking-type']").forEach(r => {
    r.addEventListener("change", function() {
      document.getElementById("ride-duration-group").style.display = this.value==="hourly"?"block":"none";
    });
  });

  // Auto-fill pickup with geolocation
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(async pos => {
      try {
        const { latitude: lat, longitude: lng } = pos.coords;
        const label = await reverseGeocode(lng, lat);
        const pickupInput = document.getElementById("modal-pickup");
        if (pickupInput && label) {
          pickupInput.value = label;
          pickupInput.dataset.lat = lat;
          pickupInput.dataset.lng = lng;
          pickupInput.style.borderColor = "var(--gold)";
          setTimeout(() => { if (pickupInput) pickupInput.style.borderColor = ""; }, 2000);
          updateRidePreviewMap();
        }
      } catch(e) {}
    }, () => {});
  }

  // Init live preview map
  initRidePreviewMap('${c.city}');

  // Address autocomplete — on select, update the map immediately
  initAddressAutocomplete("modal-pickup", "pickup-suggestions", () => updateRidePreviewMap());
  initAddressAutocomplete("modal-dropoff", "dropoff-suggestions", () => updateRidePreviewMap());

  // Also update map as user types (debounced geocode for manual entry)
  let rideMapDebounce = null;
  ["modal-pickup","modal-dropoff"].forEach(id => {
    document.getElementById(id)?.addEventListener("input", () => {
      clearTimeout(rideMapDebounce);
      rideMapDebounce = setTimeout(() => updateRidePreviewMap(), 600);
    });
  });
}

function autoFillPickup() {
  const btn = document.getElementById("locate-me-btn");
  if (btn) btn.innerHTML = `<span class="material-icons-outlined" style="font-size:13px;animation:spin 1s linear infinite">sync</span> Locating…`;
  if (!navigator.geolocation) { showToast("Geolocation not supported","error"); return; }
  navigator.geolocation.getCurrentPosition(async pos => {
    try {
      const { latitude: lat, longitude: lng } = pos.coords;
      // Use Mapbox reverse geocoding (better Nigerian address labels than Nominatim)
      const label = await reverseGeocode(lng, lat);
      const pickupInput = document.getElementById("modal-pickup");
      if (pickupInput && label) {
        pickupInput.value = label;
        pickupInput.dataset.lat = lat;
        pickupInput.dataset.lng = lng;
        pickupInput.style.borderColor = "var(--gold)";
        setTimeout(() => { if (pickupInput) pickupInput.style.borderColor = ""; }, 2000);
        updateRidePreviewMap();
      }
      if (btn) btn.innerHTML = `<span class="material-icons-outlined" style="font-size:13px">my_location</span> Use my location`;
    } catch(e) {
      showToast("Could not fetch location","error");
      if (btn) btn.innerHTML = `<span class="material-icons-outlined" style="font-size:13px">my_location</span> Use my location`;
    }
  }, () => {
    showToast("Location access denied","error");
    if (btn) btn.innerHTML = `<span class="material-icons-outlined" style="font-size:13px">my_location</span> Use my location`;
  });
}

async function createRideBooking(carId, city) {
  const type       = (document.querySelector("input[name='modal-booking-type']:checked") || {}).value || "trip";
  const pickupEl   = document.getElementById("modal-pickup");
  const dropoffEl  = document.getElementById("modal-dropoff");
  const pickup     = pickupEl.value.trim();
  const dropoff    = dropoffEl.value.trim();
  const duration   = parseFloat(document.getElementById("modal-duration")?.value || 2);
  if (!pickup) { showToast("Enter pickup address","error"); return; }

  // Coords stored by autocomplete on the input element as data-lat / data-lng
  const pickupCoords  = (pickupEl.dataset.lat  && pickupEl.dataset.lng)  ? `${pickupEl.dataset.lat},${pickupEl.dataset.lng}`   : null;
  const dropoffCoords = (dropoffEl.dataset.lat && dropoffEl.dataset.lng) ? `${dropoffEl.dataset.lat},${dropoffEl.dataset.lng}` : null;

  const data = await api("/bookings.php?action=create","POST",{
    car_id:carId, booking_type:type, pickup_address:pickup,
    dropoff_address:dropoff, duration_hours:duration, city, car_type:"chauffeur",
    pickup_coords:pickupCoords, dropoff_coords:dropoffCoords
  });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast(`Ride booked! Total: ${fmt(data.total)}`, "success");
  openPaymentModal(data.booking_id, data.total);
}

// ── PAYMENT MODAL ─────────────────────────────────────────────────────────────
function openPaymentModal(bookingId, total) {
  showModal(`
  <div class="modal-header">
    <h3>Complete Payment</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="modal-body">
    <div style="text-align:center;margin-bottom:28px;padding:20px;background:var(--card-dark);border-radius:12px">
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-family:'Space Mono',monospace;letter-spacing:2px;text-transform:uppercase">Total Amount</div>
      <div style="font-family:'Cormorant Garamond',serif;font-size:48px;font-weight:300;color:var(--gold)">${fmt(total)}</div>
    </div>
    <div style="display:grid;gap:10px">
      <button class="btn btn-ghost btn-full" onclick="payBooking(${bookingId},'cash')">
        <span class="material-icons-outlined">payments</span> Pay Cash to Driver
      </button>
      <button class="btn btn-ghost btn-full" onclick="payBooking(${bookingId},'wallet')">
        <span class="material-icons-outlined">account_balance_wallet</span> Pay from Wallet
      </button>
      <button class="btn btn-ghost btn-full" onclick="payBooking(${bookingId},'card')">
        <span class="material-icons-outlined">credit_card</span> Pay with Card (Paystack)
      </button>
      <button class="btn btn-test btn-full" onclick="simulatePayment(${bookingId})">
        <span class="material-icons-outlined">science</span> Simulate Payment (Test Mode)
      </button>
    </div>
  </div>`);
}

async function payBooking(bookingId, method) {
  const data = await api("/payments.php?action=initiate","POST",{ booking_id:bookingId, method });
  if (data.error) { showToast(data.error,"error"); return; }
  if (data.authorization_url) { window.location.href = data.authorization_url; return; }
  closeModal();
  showToast(data.message||"Payment recorded!","success");
  loadMyBookings();
}
async function simulatePayment(bookingId) { await payBooking(bookingId,"test"); }

// ── MY BOOKINGS ───────────────────────────────────────────────────────────────
// ── WISHLIST TOGGLE ───────────────────────────────────────────────────────────
async function toggleWishlist(carId, btn) {
  if (!requireAuth()) return;
  const icon = btn.querySelector(".material-icons-outlined");
  const isNow = icon.textContent.trim() === "favorite";
  // Optimistic UI
  icon.textContent  = isNow ? "favorite_border" : "favorite";
  icon.style.color  = isNow ? "rgba(255,255,255,0.8)" : "#e74c3c";
  btn.title         = isNow ? "Save to wishlist" : "Remove from wishlist";
  if (!window._wishlistIds) window._wishlistIds = [];
  if (isNow) {
    window._wishlistIds = window._wishlistIds.filter(id => id !== carId);
  } else {
    window._wishlistIds.push(carId);
  }
  const data = await api(`/cars.php?action=toggle_wishlist&id=${carId}`, "POST");
  if (data.error) {
    // Revert on failure
    icon.textContent = isNow ? "favorite" : "favorite_border";
    icon.style.color = isNow ? "#e74c3c" : "rgba(255,255,255,0.8)";
    showToast(data.error, "error");
  } else {
    showToast(data.wishlisted ? "Saved to wishlist ♥" : "Removed from wishlist", data.wishlisted ? "success" : "info");
  }
}

// ── GUEST GATE HELPER ─────────────────────────────────────────────────────────
function guestGateHTML(icon, title, subtitle) {
  return `
  <div class="page-header"><h2>${title.includes(" ") ? title.replace(/(\w+)(\s.+)/, '<span>$1</span>$2') : `<span>${title}</span>`}</h2></div>
  <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 32px;text-align:center">
    <div style="width:80px;height:80px;border-radius:50%;background:rgba(201,125,58,0.1);border:1px solid rgba(201,125,58,0.2);display:flex;align-items:center;justify-content:center;margin-bottom:24px">
      <span class="material-icons-outlined" style="font-size:36px;color:var(--gold)">${icon}</span>
    </div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300;color:var(--text);margin-bottom:8px">${title}</h3>
    <p style="color:var(--muted);font-size:14px;max-width:260px;line-height:1.6;margin-bottom:28px">${subtitle}</p>
    <button class="btn btn-gold" style="min-width:180px" onclick="showPage('page-auth');renderAuthLogin()">
      <span class="material-icons-outlined">login</span> Login to Access
    </button>
    <button class="btn btn-ghost" style="min-width:180px;margin-top:10px" onclick="showPage('page-auth');renderAuthLogin()">
      <span class="material-icons-outlined">person_add</span> Create Free Account
    </button>
  </div>`;
}

async function loadMyBookings() {
  const el = document.getElementById("tab-customer-bookings");
  if (!authToken || currentUser?.role === "guest") {
    el.innerHTML = guestGateHTML("receipt_long", "My Bookings", "Track your rentals and rides in one place");
    return;
  }
  el.innerHTML = `
  <div class="page-header"><h2>My <span>Bookings</span></h2><p>Your trip history</p></div>
  <div style="padding:0 20px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      ${["","pending","confirmed","active","completed","cancelled"].map(s =>
        `<button class="btn btn-sm ${s?"btn-ghost":"btn-gold"}" onclick="loadBookingsByStatus('${s}')">${s?capitalize(s):"All"}</button>`).join("")}
    </div>
    <div id="bookings-list">${shimmerCards(3)}</div>
  </div>`;
  loadBookingsByStatus("");
}

async function loadBookingsByStatus(status) {
  const el = document.getElementById("bookings-list");
  if (!el) return;
  el.innerHTML = shimmerCards(3);
  const data = await api(`/bookings.php?action=get_my_bookings${status?"&status="+status:""}`);
  if (!data.bookings || !data.bookings.length) {
    el.innerHTML = `<div style="text-align:center;padding:48px 20px;color:var(--muted)"><span class="material-icons-outlined" style="font-size:48px;display:block;margin-bottom:12px">receipt_long</span>No bookings found</div>`;
    return;
  }
  el.innerHTML = data.bookings.map(b => `
  <div class="card card-hover" style="margin-bottom:12px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700;font-size:15px;margin-bottom:3px">${b.car_name}</div>
        <div style="font-size:12px;color:var(--muted)">${b.car_type==="self_drive"?"🔑 Rental":"🚗 Ride"} · ${capitalize(b.booking_type)} · ${fmtDate(b.created_at)}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px">${b.pickup_address||"—"}</div>
      </div>
      <div style="text-align:right">
        <span class="badge ${statusColor(b.status)}">${capitalize(b.status)}</span>
        <div style="font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:300;color:var(--gold);margin-top:4px">${fmt(b.total_price)}</div>
      </div>
    </div>
    ${["pending","confirmed","active","completed"].includes(b.status)?`
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-soft);display:flex;gap:8px">
      ${b.car_type==="chauffeur"?`<button class="btn btn-sm btn-ghost" onclick="openChatModal(${b.id},${b.driver_user_id||b.driver_id})"><span class="material-icons-outlined">chat</span> Chat</button>`:""}
      ${["pending","confirmed"].includes(b.status)?`<button class="btn btn-sm btn-ghost-red" onclick="cancelBooking(${b.id})">Cancel</button>`:""}
    </div>`:""}
  </div>`).join("");
}

async function cancelBooking(bookingId) {
  if (!confirm("Cancel this booking?")) return;
  const data = await api("/bookings.php?action=cancel","POST",{ booking_id:bookingId });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Booking cancelled","warning");
  loadMyBookings();
}

// ── TRACKING ──────────────────────────────────────────────────────────────────
async function loadTracking() {
  const el = document.getElementById("tab-customer-tracking");
  if (!authToken || currentUser?.role === "guest") {
    el.innerHTML = guestGateHTML("map", "Track Trip", "See your driver's live location and route");
    return;
  }
  const data = await api("/bookings.php?action=get_my_bookings&status=active");
  if (!data.bookings || !data.bookings.length) {
    el.innerHTML = `
    <div class="page-header"><h2>Track <span>Trip</span></h2></div>
    <div style="text-align:center;padding:60px 20px">
      <span class="material-icons-outlined" style="font-size:64px;color:var(--dim);display:block;margin-bottom:16px">map</span>
      <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300;color:var(--text);margin-bottom:8px">No Active Trip</h3>
      <p style="color:var(--muted)">You don't have an active booking to track</p>
    </div>`;
    return;
  }
  const b = data.bookings[0];
  if (b.car_type === "self_drive") {
    el.innerHTML = `
    <div class="page-header"><h2>Track <span>Trip</span></h2></div>
    <div style="padding:0 20px">
      <div class="card">
        <div style="font-weight:700;margin-bottom:8px">${b.car_name}</div>
        <div style="font-size:13px;color:var(--muted)">Self-drive rental — pickup location shown below</div>
      </div>
      <div id="tracking-map" style="height:400px;border-radius:14px;margin-top:16px"></div>
    </div>`;
    setTimeout(() => initSelfDriveMap("tracking-map", b), 100);
  } else {
    el.innerHTML = `
    <div class="page-header" style="display:flex;align-items:center;gap:10px">
      <button onclick="customerTab('bookings')" style="background:none;border:none;cursor:pointer;color:var(--muted);padding:0;display:flex;align-items:center">
        <span class="material-icons-outlined" style="font-size:22px">arrow_back</span>
      </button>
      <h2>Track <span>Trip</span></h2>
    </div>
    <div style="padding:0 20px">
      <div class="card" style="margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div>
            <div style="font-weight:700;margin-bottom:2px">${b.car_name}</div>
            <div style="font-size:12px;color:var(--muted)">${b.driver_name||"Your Driver"}</div>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            ${b.driver_phone ? `<a href="tel:${b.driver_phone}" style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:rgba(0,232,122,0.1);color:var(--green);text-decoration:none">
              <span class="material-icons-outlined" style="font-size:20px">call</span>
            </a>` : ""}
            <button onclick="openChatModal(${b.id},${b.driver_user_id||b.driver_id})" style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:rgba(201,125,58,0.1);color:var(--gold);border:none;cursor:pointer">
              <span class="material-icons-outlined" style="font-size:20px">chat</span>
            </button>
          </div>
        </div>
      </div>
      <div id="tracking-map" style="height:calc(100vh - 280px);border-radius:14px"></div>
    </div>`;
    setTimeout(() => {
      initCustomerTrackingMap("tracking-map", b);
      startPollingDriverLocation(b.id);
    }, 100);
  }
}

// ── IN-APP CHAT ───────────────────────────────────────────────────────────────
let chatPollInterval = null;
let currentChatBookingId = null;
let currentChatReceiverId = null;

async function openChatModal(bookingId, driverId) {
  currentChatBookingId  = bookingId;
  currentChatReceiverId = driverId;
  showModal(`
  <div class="modal-header">
    <h3>Chat with Driver</h3>
    <button class="modal-close" onclick="closeChatModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="chat-container">
    <div class="chat-messages" id="chat-messages">${shimmerCards(2)}</div>
    <div class="chat-input-bar">
      <input class="input-field" id="chat-input" placeholder="Type a message..." onkeypress="if(event.key==='Enter') sendChatMessage()">
      <button class="btn btn-gold" onclick="sendChatMessage()"><span class="material-icons-outlined">send</span></button>
    </div>
  </div>`);
  loadChatMessages();
  chatPollInterval = setInterval(loadChatMessages, 3000);
}

function closeChatModal() {
  closeModal();
  if (chatPollInterval) { clearInterval(chatPollInterval); chatPollInterval = null; }
}

async function loadChatMessages() {
  const data = await api(`/messages.php?action=get&booking_id=${currentChatBookingId}`);
  const el = document.getElementById("chat-messages");
  if (!el) return;
  const msgs = data.messages || [];
  if (!msgs.length) {
    el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No messages yet. Say hello!</div>`;
    return;
  }
  el.innerHTML = msgs.map(m => {
    const isMe = m.sender_id === currentUser.id;
    return `<div class="chat-msg ${isMe?"chat-msg-me":"chat-msg-them"}">
      <div class="chat-bubble ${m.is_flagged?"chat-flagged":""}">${m.message_text}</div>
      <div class="chat-time">${fmtTime(m.created_at)}</div>
    </div>`;
  }).join("");
  el.scrollTop = el.scrollHeight;
}

async function sendChatMessage() {
  const input = document.getElementById("chat-input");
  if (!input) { showToast("Chat input not found","error"); return; }
  const text = input.value.trim();
  if (!text) return;
  if (!currentChatBookingId) { showToast("No active booking","error"); return; }
  input.value = "";
  input.disabled = true;
  const data = await api("/messages.php?action=send","POST",{
    booking_id: currentChatBookingId,
    message_text: text
  });
  input.disabled = false;
  input.focus();
  if (data.error) { showToast(data.error,"error"); input.value = text; return; }
  if (data.flagged) showToast("⚠️ Message flagged — avoid sharing personal contact details","warning");
  loadChatMessages();
}

// ── MESSAGES TAB ──────────────────────────────────────────────────────────────
async function loadCustomerMessages() {
  const el = document.getElementById("tab-customer-messages");
  if (!authToken || currentUser?.role === "guest") {
    el.innerHTML = guestGateHTML("chat_bubble_outline", "Messages", "Chat with your driver during active rides");
    return;
  }
  el.innerHTML = `
  <div class="page-header"><h2>My <span>Messages</span></h2><p>Conversations with drivers</p></div>
  <div style="padding:0 20px" id="messages-list">${shimmerCards(3)}</div>`;

  const data = await api("/bookings.php?action=get_my_bookings");
  const msgEl = document.getElementById("messages-list");
  if (!msgEl) return;
  const bookings = (data.bookings||[]).filter(b => b.car_type==="chauffeur" && ["confirmed","active","completed"].includes(b.status));
  if (!bookings.length) {
    msgEl.innerHTML = `<div style="text-align:center;padding:48px 20px">
      <span class="material-icons-outlined" style="font-size:48px;color:var(--dim);display:block;margin-bottom:12px">chat_bubble_outline</span>
      <p style="color:var(--muted)">No conversations yet. Messages appear here during active rides.</p>
      <a href="https://wa.me/${typeof BOOKKAM_SUPPORT!=="undefined"?BOOKKAM_SUPPORT:"2340000000000"}" target="_blank" class="btn btn-ghost" style="margin-top:16px">
        <span class="material-icons-outlined">support_agent</span> Contact Support on WhatsApp
      </a>
    </div>`;
    return;
  }
  msgEl.innerHTML = bookings.map(b => `
  <div class="card card-hover" style="margin-bottom:12px" onclick="openChatModal(${b.id},${b.driver_user_id||b.driver_id})">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${b.car_name}</div>
        <div style="font-size:12px;color:var(--muted)">${b.driver_name||"Driver"} · ${fmtDate(b.created_at)}</div>
      </div>
      <span class="material-icons-outlined" style="color:var(--muted)">chevron_right</span>
    </div>
  </div>`).join("");
}

// ── WALLET ────────────────────────────────────────────────────────────────────
async function loadWallet() {
  const el = document.getElementById("tab-customer-wallet");
  if (!authToken || currentUser?.role === "guest") {
    el.innerHTML = guestGateHTML("account_balance_wallet", "Wallet", "Fund your wallet and pay for bookings instantly");
    return;
  }
  el.innerHTML = `<div class="page-header"><h2>My <span>Wallet</span></h2></div><div style="padding:0 20px" id="wallet-body">${shimmerCards(1)}</div>`;
  const data = await api("/payments.php?action=get_wallet");
  const bal  = data.balance || 0;
  document.getElementById("wallet-body").innerHTML = `
  <div class="card" style="text-align:center;margin-bottom:20px">
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:8px">Available Balance</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:52px;font-weight:300;color:var(--gold)">${fmt(bal)}</div>
  </div>
  <div class="input-group"><label>Top Up Amount (₦)</label><input type="number" class="input-field" id="topup-amount" placeholder="e.g. 10000" min="500"></div>
  <button class="btn btn-gold btn-full" onclick="topUpWallet()"><span class="material-icons-outlined">add_circle</span> Top Up Wallet</button>`;
}

async function topUpWallet() {
  const amount = parseFloat(document.getElementById("topup-amount").value);
  if (!amount || amount < 500) { showToast("Minimum top-up is ₦500","error"); return; }
  const data = await api("/payments.php?action=top_up_wallet","POST",{ amount, method:"test" });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast(`Wallet topped up with ${fmt(amount)}!`,"success");
  loadWallet();
}

// ── WISHLIST ──────────────────────────────────────────────────────────────────
async function loadWishlist() {
  const el = document.getElementById("tab-customer-wishlist");
  if (!authToken || currentUser?.role === "guest") {
    el.innerHTML = guestGateHTML("favorite_border", "Wishlist", "Save your favourite cars and book them anytime");
    return;
  }
  el.innerHTML = `<div class="page-header"><h2>My <span>Wishlist</span></h2><p>Cars you've saved</p></div><div style="padding:0 20px" id="wishlist-body">${shimmerCards(2)}</div>`;
  const data = await api("/cars.php?action=get_wishlist");
  const body = document.getElementById("wishlist-body");
  if (!body) return;
  const cars = data.cars || [];
  if (!cars.length) {
    body.innerHTML = `<div style="text-align:center;padding:48px 20px">
      <span class="material-icons-outlined" style="font-size:48px;color:var(--dim);display:block;margin-bottom:12px">favorite_border</span>
      <p style="color:var(--muted)">No saved cars yet. Tap the heart icon on any car to save it.</p>
    </div>`;
    return;
  }
  body.innerHTML = `<div class="car-grid">${cars.map(c => c.car_type==="self_drive"?selfDriveCardHTML(c):rideCardHTML(c)).join("")}</div>`;
}

// ── CITY SELECTOR ─────────────────────────────────────────────────────────────
async function showCitySelector() {
  const data = await api("/admin.php?action=get_cities");
  const cities = (data.cities||[]).filter(c => c.is_active);
  showModal(`
  <div class="modal-header"><h3>Select City</h3><button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button></div>
  <div class="modal-body">
    ${cities.map(c => `
    <div class="city-option ${c.name===customerCity?"city-option-active":""}" onclick="selectCity('${c.name}')">
      <span class="material-icons-outlined">location_on</span>${c.name}, ${c.state}
    </div>`).join("")}
  </div>`);
}

function selectCity(name) {
  customerCity = name;
  closeModal();
  renderCustomerTopbar();
  showToast(`City changed to ${name}`,"success");
}

// ── PROFILE ───────────────────────────────────────────────────────────────────
function loadCustomerProfile() { renderCustomerProfileView(); }

function renderCustomerProfileView() {
  const u = currentUser;
  const isGoogle = !!u.google_id || (!u.phone && u.email && !u.password_hash);
  const displayPhone = formatPhoneDisplay(u.phone);

  document.getElementById("tab-customer-profile").innerHTML = `
  <div class="page-header"><h2>My <span>Profile</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="max-width:480px">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border-soft)">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--card-dark);display:flex;align-items:center;justify-content:center;border:2px solid var(--border);flex-shrink:0;overflow:hidden">
          ${u.photo_url
            ? `<img src="${u.photo_url}" style="width:100%;height:100%;object-fit:cover">`
            : `<span class="material-icons-outlined" style="font-size:32px;color:var(--gold)">person_outline</span>`}
        </div>
        <div>
          <div style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300;color:var(--text)">${u.name||"No name set"}</div>
          ${isGoogle
            ? `<div style="display:inline-flex;align-items:center;gap:4px;background:rgba(66,133,244,0.12);border:1px solid rgba(66,133,244,0.25);border-radius:20px;padding:3px 10px;margin-top:4px">
                 <svg width="12" height="12" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                 <span style="font-size:11px;color:#4285F4;font-weight:600">Signed in with Google</span>
               </div>`
            : displayPhone ? `<div style="color:var(--muted);font-size:13px;margin-top:4px">${displayPhone}</div>` : ""
          }
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px">
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Full Name</div>
          <div style="font-size:14px;font-weight:600">${u.name||"Not set"}</div>
        </div>
        ${displayPhone ? `
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Phone</div>
          <div style="font-size:14px;font-weight:600">${displayPhone}</div>
        </div>` : ""}
        ${u.email ? `
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Email</div>
          <div style="font-size:14px;font-weight:600">${u.email}</div>
        </div>` : ""}
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">City</div>
          <div style="font-size:14px;font-weight:600">${u.city||"Not set"}</div>
        </div>
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Referral Code</div>
          <div style="font-size:14px;font-weight:600;font-family:'Space Mono',monospace">${u.referral_code||"—"}</div>
        </div>
      </div>
      <button class="btn btn-gold btn-full" onclick="renderCustomerProfileEdit()">
        <span class="material-icons-outlined">edit</span> Edit Profile
      </button>
      <div class="divider"></div>
      <a href="https://wa.me/${typeof BOOKKAM_SUPPORT!=="undefined"?BOOKKAM_SUPPORT:"2340000000000"}" target="_blank" class="btn btn-ghost btn-full">
        <span class="material-icons-outlined">support_agent</span> Contact Support
      </a>
      <div class="divider"></div>
      <button class="btn btn-ghost-red btn-full" onclick="logout()">
        <span class="material-icons-outlined">logout</span> Logout
      </button>
    </div>
  </div>`;
}

function renderCustomerProfileEdit() {
  const u = currentUser;
  const isGoogle = !!u.google_id || (!u.phone && u.email && !u.password_hash);
  document.getElementById("tab-customer-profile").innerHTML = `
  <div class="page-header"><h2>Edit <span>Profile</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="max-width:480px">
      <div class="input-group"><label>Full Name</label><input class="input-field" id="profile-name" value="${u.name||""}" placeholder="Your full name"></div>
      <div class="input-group">
        <label>Email ${isGoogle?'<span style="font-size:10px;color:var(--muted);font-weight:400">(managed by Google)</span>':""}</label>
        <input type="email" class="input-field" id="profile-email" value="${u.email||""}" placeholder="email@example.com" ${isGoogle?"readonly style='opacity:0.6;cursor:not-allowed'":""}>
      </div>
      <div class="input-group"><label>City</label>
        <select class="input-field" id="profile-city">
          <option ${u.city==="Calabar"?"selected":""}>Calabar</option>
          <option ${u.city==="Ikom"?"selected":""}>Ikom</option>
          <option ${u.city==="Lagos"?"selected":""}>Lagos</option>
          <option ${u.city==="Abuja"?"selected":""}>Abuja</option>
          <option ${u.city==="Port Harcourt"?"selected":""}>Port Harcourt</option>
        </select>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-gold" style="flex:1" onclick="saveProfile()"><span class="material-icons-outlined">save</span> Save</button>
        <button class="btn btn-ghost" style="flex:0.5" onclick="renderCustomerProfileView()"><span class="material-icons-outlined">close</span> Cancel</button>
      </div>
    </div>
  </div>`;
  setTimeout(() => document.getElementById("profile-name")?.focus(), 100);
}

async function saveProfile() {
  const name  = document.getElementById("profile-name").value.trim();
  const email = document.getElementById("profile-email").value.trim();
  const city  = document.getElementById("profile-city").value;
  if (!name) { showToast("Enter your name","error"); return; }
  const data = await api("/auth.php?action=update_profile","POST",{ name, email, city });
  if (data.error) { showToast(data.error,"error"); return; }
  currentUser.name  = name;
  currentUser.email = email;
  currentUser.city  = city;
  customerCity      = city;
  localStorage.setItem("bookkam_user", JSON.stringify(currentUser));
  renderCustomerTopbar();
  showToast("Profile updated!","success");
  renderCustomerProfileView();
}

// ── RIDE BOOKING PREVIEW MAP ─────────────────────────────────────────────────
let ridePreviewMap   = null;
let ridePreviewPinP  = null;
let ridePreviewPinD  = null;
let ridePreviewRoute = null;

function initRidePreviewMap(city) {
  const container = document.getElementById("ride-booking-map");
  if (!container) return;
  if (ridePreviewMap) { ridePreviewMap.remove(); ridePreviewMap = null; }

  const cityKey = (city || "Calabar").toUpperCase();
  const center  = (typeof BOOKKAM_COORDS !== "undefined" && BOOKKAM_COORDS[cityKey])
    ? BOOKKAM_COORDS[cityKey] : [4.9517, 8.3220];

  ridePreviewMap = L.map("ride-booking-map", {
    center, zoom: 13,
    zoomControl: false,
    attributionControl: false,
    dragging: true,
    scrollWheelZoom: false,
  });

  const theme = document.documentElement.getAttribute("data-theme") || "dark";
  const tile  = theme === "dark"
    ? "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png"
    : "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png";
  L.tileLayer(tile, { maxZoom: 19 }).addTo(ridePreviewMap);
  L.control.zoom({ position: "bottomright" }).addTo(ridePreviewMap);
}

function setRideMapStatus(text, type) {
  const el   = document.getElementById("ride-map-status-text");
  const wrap = document.getElementById("ride-map-status");
  if (!el || !wrap) return;
  el.textContent = text;
  const colors = { info: "var(--muted)", ok: "var(--green)", error: "var(--red)", loading: "var(--gold)" };
  wrap.style.color = colors[type] || "var(--muted)";
  // Swap icon
  const icon = wrap.querySelector(".material-icons-outlined");
  if (icon) {
    const icons = { info: "info", ok: "check_circle", error: "location_off", loading: "sync" };
    icon.textContent = icons[type] || "info";
    icon.style.animation = type === "loading" ? "spin 1s linear infinite" : "none";
  }
  // Enable Book button only when pickup is confirmed on the map
  const btn = document.getElementById("ride-book-btn");
  if (btn) {
    const ready = (type === "ok");
    btn.disabled = !ready;
    btn.style.opacity  = ready ? "1"            : "0.5";
    btn.style.cursor   = ready ? "pointer"       : "not-allowed";
  }
}

// Resolve an input to { lat, lng } — uses stored dataset first, then Mapbox geocoding
async function resolveCoords(inputEl) {
  if (inputEl.dataset.lat && inputEl.dataset.lng) {
    return { lat: parseFloat(inputEl.dataset.lat), lng: parseFloat(inputEl.dataset.lng) };
  }
  const val = inputEl.value.trim();
  if (!val || val.length < 3) return null;
  return new Promise(resolve => {
    geocodeAddress(val, results => {
      if (!results.length) { resolve(null); return; }
      // Cache resolved coords back on the element
      inputEl.dataset.lat = results[0].lat;
      inputEl.dataset.lng = results[0].lng;
      resolve({ lat: results[0].lat, lng: results[0].lng });
    });
  });
}

async function updateRidePreviewMap() {
  if (!ridePreviewMap) return;

  const pickupEl  = document.getElementById("modal-pickup");
  const dropoffEl = document.getElementById("modal-dropoff");
  if (!pickupEl) return;

  setRideMapStatus("Locating addresses…", "loading");

  const pickup  = await resolveCoords(pickupEl);
  const dropoff = dropoffEl && dropoffEl.value.trim().length >= 3
    ? await resolveCoords(dropoffEl) : null;

  // Clear old layers
  if (ridePreviewPinP)  { ridePreviewMap.removeLayer(ridePreviewPinP);  ridePreviewPinP  = null; }
  if (ridePreviewPinD)  { ridePreviewMap.removeLayer(ridePreviewPinD);  ridePreviewPinD  = null; }
  if (ridePreviewRoute) { ridePreviewMap.removeLayer(ridePreviewRoute); ridePreviewRoute = null; }

  if (!pickup) {
    const hasText = pickupEl.value.trim().length >= 3;
    pickupEl.style.borderColor = hasText ? "var(--red)" : "";
    setRideMapStatus(hasText ? "Pickup address not found — try a different name" : "Enter pickup address", hasText ? "error" : "info");
    return;
  }

  pickupEl.style.borderColor = "var(--gold)";

  const pinIcon = (iconName, color) => L.divIcon({
    className: "bk-marker",
    html: `<span class="material-icons-outlined" style="font-size:26px;color:${color};filter:drop-shadow(0 2px 8px rgba(0,0,0,0.7))">${iconName}</span>`,
    iconSize: [26, 26], iconAnchor: [13, 26], popupAnchor: [0, -26]
  });

  ridePreviewPinP = L.marker([pickup.lat, pickup.lng], { icon: pinIcon("my_location", "var(--cyan)") })
    .addTo(ridePreviewMap)
    .bindPopup(`<b>Pickup</b><br>${pickupEl.value.trim()}`);

  if (dropoff) {
    if (dropoffEl) dropoffEl.style.borderColor = "var(--gold)";

    ridePreviewPinD = L.marker([dropoff.lat, dropoff.lng], { icon: pinIcon("location_on", "var(--gold)") })
      .addTo(ridePreviewMap)
      .bindPopup(`<b>Drop-off</b><br>${dropoffEl.value.trim()}`);

    // Fit both pins in view
    ridePreviewMap.fitBounds(
      L.latLngBounds([[pickup.lat, pickup.lng], [dropoff.lat, dropoff.lng]]),
      { padding: [40, 40] }
    );

    // Draw route via OSRM
    try {
      const url  = `https://router.project-osrm.org/route/v1/driving/${pickup.lng},${pickup.lat};${dropoff.lng},${dropoff.lat}?overview=full&geometries=geojson`;
      const res  = await fetch(url);
      const data = await res.json();
      if (data.routes && data.routes.length) {
        ridePreviewRoute = L.geoJSON(data.routes[0].geometry, {
          style: { color: "var(--gold)", weight: 3, opacity: 0.85, dashArray: "6,4" }
        }).addTo(ridePreviewMap);
        ridePreviewMap.fitBounds(ridePreviewRoute.getBounds(), { padding: [40, 40] });
        const dist = (data.routes[0].distance / 1000).toFixed(1);
        const mins = Math.round(data.routes[0].duration / 60);
        setRideMapStatus(`Route found · ${dist} km · ~${mins} min`, "ok");
      } else {
        setRideMapStatus("Route could not be calculated", "error");
      }
    } catch(e) {
      setRideMapStatus("Pickup and drop-off pinned", "ok");
    }
  } else {
    // Only pickup — centre on it
    ridePreviewMap.setView([pickup.lat, pickup.lng], 15);
    if (dropoffEl && dropoffEl.value.trim().length >= 3) {
      dropoffEl.style.borderColor = "var(--red)";
      setRideMapStatus("Drop-off address not found — try a different name", "error");
    } else {
      setRideMapStatus("Pickup pinned · enter drop-off to see route", "ok");
    }
  }
}

// ── ADDRESS AUTOCOMPLETE ──────────────────────────────────────────────────────
// Mapbox Geocoding — better Nigerian coverage, already in free tier. Stores lat/lng on input.
let acDebounce = {};

function initAddressAutocomplete(inputId, suggestionsId, onSelect) {
  const input = document.getElementById(inputId);
  const sugg  = document.getElementById(suggestionsId);
  if (!input || !sugg) return;

  input.addEventListener("input", () => {
    // Clear any previously stored coords — user is typing something new
    delete input.dataset.lat;
    delete input.dataset.lng;
    clearTimeout(acDebounce[inputId]);
    const val = input.value.trim();
    if (val.length < 3) { sugg.style.display = "none"; return; }
    acDebounce[inputId] = setTimeout(() => {
      // Use Mapbox Geocoding (better Nigerian coverage, already in our free tier)
      geocodeAddress(val, results => {
        if (!results.length) { sugg.style.display = "none"; return; }
        sugg.innerHTML = results.map((r, i) =>
          `<div class="ac-item" data-idx="${i}" data-lat="${r.lat}" data-lng="${r.lng}" data-display="${r.place_name.replace(/"/g,"&quot;")}">
            <span class="material-icons-outlined" style="font-size:15px;color:var(--gold);flex-shrink:0">location_on</span>
            <span style="display:flex;flex-direction:column;gap:1px;min-width:0">
              <span style="color:var(--text);font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r.short_name}</span>
              ${r.context ? `<span style="color:var(--muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r.context}</span>` : ""}
            </span>
          </div>`
        ).join("");
        sugg.querySelectorAll(".ac-item").forEach(item => {
          item.addEventListener("click", () => {
            input.value       = item.dataset.display;
            input.dataset.lat = item.dataset.lat;
            input.dataset.lng = item.dataset.lng;
            sugg.style.display = "none";
            if (typeof onSelect === "function") {
              onSelect({ lat: parseFloat(item.dataset.lat), lng: parseFloat(item.dataset.lng), display: item.dataset.display });
            }
          });
        });
        sugg.style.display = "block";
      });
    }, 400);
  });

  input.addEventListener("keydown", e => {
    if (sugg.style.display === "none") return;
    const items = sugg.querySelectorAll(".ac-item");
    const active = sugg.querySelector(".ac-item.ac-active");
    let idx = active ? parseInt(active.dataset.idx) : -1;
    if (e.key === "ArrowDown")       { e.preventDefault(); idx = Math.min(idx+1, items.length-1); }
    else if (e.key === "ArrowUp")    { e.preventDefault(); idx = Math.max(idx-1, 0); }
    else if (e.key === "Enter" && active) { e.preventDefault(); active.click(); return; }
    else if (e.key === "Escape")     { sugg.style.display = "none"; return; }
    else return;
    items.forEach(i => i.classList.remove("ac-active"));
    if (items[idx]) { items[idx].classList.add("ac-active"); items[idx].scrollIntoView({ block:"nearest" }); }
  });

  document.addEventListener("click", e => {
    if (!e.target.closest(`#${inputId}`) && !e.target.closest(`#${suggestionsId}`)) {
      sugg.style.display = "none";
    }
  });
}

// ── PHOTO LIGHTBOX ────────────────────────────────────────────────────────────
let lightboxPhotos = [];
let lightboxIndex  = 0;

function openLightbox(photos, index) {
  lightboxPhotos = photos;
  lightboxIndex  = index;
  renderLightbox();
}

function renderLightbox() {
  const existing = document.getElementById("lightbox-overlay");
  if (existing) existing.remove();

  const overlay = document.createElement("div");
  overlay.id = "lightbox-overlay";
  overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:99999;display:flex;align-items:center;justify-content:center;flex-direction:column";
  overlay.innerHTML = `
    <button onclick="document.getElementById('lightbox-overlay').remove()" style="position:absolute;top:16px;right:16px;background:none;border:none;color:#fff;cursor:pointer">
      <span class="material-icons-outlined" style="font-size:32px">close</span>
    </button>
    <div style="position:absolute;top:16px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.5);font-size:13px;font-family:'Space Mono',monospace">
      ${lightboxIndex+1} / ${lightboxPhotos.length}
    </div>
    <img src="${lightboxPhotos[lightboxIndex]}" style="max-width:95vw;max-height:80vh;object-fit:contain;border-radius:8px">
    <div style="display:flex;gap:12px;margin-top:16px;overflow-x:auto;padding:8px;max-width:95vw;scrollbar-width:none;-ms-overflow-style:none">
      ${lightboxPhotos.map((p,i) => `
        <img src="${p}" onclick="lightboxIndex=${i};renderLightbox()"
          style="width:60px;height:60px;object-fit:cover;border-radius:6px;cursor:pointer;opacity:${i===lightboxIndex?1:0.4};border:${i===lightboxIndex?"2px solid var(--gold)":"2px solid transparent"};flex-shrink:0;transition:all 0.2s">
      `).join("")}
    </div>
    ${lightboxPhotos.length > 1 ? `
    <button onclick="lightboxIndex=Math.max(0,lightboxIndex-1);renderLightbox()" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.1);border:none;color:#fff;cursor:pointer;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center">
      <span class="material-icons-outlined">chevron_left</span>
    </button>
    <button onclick="lightboxIndex=Math.min(lightboxPhotos.length-1,lightboxIndex+1);renderLightbox()" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.1);border:none;color:#fff;cursor:pointer;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center">
      <span class="material-icons-outlined">chevron_right</span>
    </button>` : ""}`;
  document.body.appendChild(overlay);
}
