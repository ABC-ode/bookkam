// ── DRIVER.JS ─────────────────────────────────────────────────────────────────
let driverOnline        = false;
let tripCountdown       = null;
let pendingBookingId    = null;
let locationWatchId     = null;
let locationSendInterval = null;
let lastKnownLat        = null;
let lastKnownLng        = null;
let lastKnownHeading    = null;
let activeBookingId     = null;

// ── LIVE GPS TRACKING ─────────────────────────────────────────────────────────
function startLocationTracking(bookingId) {
  activeBookingId = bookingId || null;
  if (!navigator.geolocation) { showToast("GPS not supported on this device","error"); return; }
  locationWatchId = navigator.geolocation.watchPosition(
    pos => {
      lastKnownLat     = pos.coords.latitude;
      lastKnownLng     = pos.coords.longitude;
      lastKnownHeading = pos.coords.heading || 0;
    },
    err => console.warn("GPS error:", err.message),
    { enableHighAccuracy:true, maximumAge:5000, timeout:10000 }
  );
  locationSendInterval = setInterval(sendLocationToServer, 5000);
  sendLocationToServer();
}

function stopLocationTracking() {
  if (locationWatchId !== null) { navigator.geolocation.clearWatch(locationWatchId); locationWatchId = null; }
  if (locationSendInterval) { clearInterval(locationSendInterval); locationSendInterval = null; }
}

async function sendLocationToServer() {
  if (lastKnownLat === null) return;
  await api("/drivers.php?action=update_location","POST",{
    lat:lastKnownLat, lng:lastKnownLng, heading:lastKnownHeading||0, booking_id:activeBookingId||0
  });
}

// ── DASHBOARD ─────────────────────────────────────────────────────────────────
function loadDriverDashboard() {
  renderDriverSidebar();
  renderDriverTopbar();
  renderDriverMobileNav();
  showTab("tab-driver-home");
  loadDriverHome();
  startNotifPoll();
  // Return resolved promise so boot can chain .then() for tab restore
  return Promise.resolve();
}

function renderDriverSidebar() {
  document.getElementById("driver-sidebar").innerHTML = `
  <div class="sidebar-brand"><img src="/icons/logo-main.png" alt="BOOKKAM" class="sidebar-logo-img"></div>
  <ul class="sidebar-nav">
    <li><a class="active" onclick="driverTab('home',this)"><span class="material-icons-outlined">home</span><span class="nav-label">Home</span></a></li>
    <li><a onclick="driverTab('navigation',this)"><span class="material-icons-outlined">navigation</span><span class="nav-label">Navigation</span></a></li>
    <li><a onclick="driverTab('earnings',this)"><span class="material-icons-outlined">payments</span><span class="nav-label">Earnings</span></a></li>
    <li><a onclick="driverTab('mycar',this)"><span class="material-icons-outlined">directions_car</span><span class="nav-label">My Car</span></a></li>
    <li><a onclick="driverTab('messages',this)"><span class="material-icons-outlined">chat_bubble_outline</span><span class="nav-label">Messages</span></a></li>
    <li><a onclick="driverTab('profile',this)"><span class="material-icons-outlined">person_outline</span><span class="nav-label">Profile</span></a></li>
  </ul>`;
}

function renderDriverTopbar() {
  document.getElementById("driver-topbar").innerHTML = `
  <div class="topbar-greeting">
    ${greet()}, <span>${currentUser.name ? currentUser.name.split(" ")[0] : "Driver"}</span>
  </div>
  <div class="topbar-actions">
    <div class="topbar-notif" onclick="toggleNotifications(event)">
      <span class="material-icons-outlined">notifications_none</span>
      <span id="notif-badge"></span>
    </div>
  </div>`;
}

function renderDriverMobileNav() {
  document.getElementById("driver-mobile-nav").innerHTML = `<div class="mobile-nav-items">
    <div class="mobile-nav-item active" id="mnav-home" onclick="driverTab('home');setMobileNav('driver','home')">
      <span class="material-icons-outlined">home</span><span>Home</span>
    </div>
    <div class="mobile-nav-item" id="mnav-navigation" onclick="driverTab('navigation');setMobileNav('driver','navigation')">
      <span class="material-icons-outlined">navigation</span><span>Navigate</span>
    </div>
    <div class="mobile-nav-item" id="mnav-earnings" onclick="driverTab('earnings');setMobileNav('driver','earnings')">
      <span class="material-icons-outlined">payments</span><span>Earnings</span>
    </div>
    <div class="mobile-nav-item" id="mnav-mycar" onclick="driverTab('mycar');setMobileNav('driver','mycar')">
      <span class="material-icons-outlined">directions_car</span><span>My Car</span>
    </div>
    <div class="mobile-nav-item" id="mnav-profile" onclick="driverTab('profile');setMobileNav('driver','profile')">
      <span class="material-icons-outlined">person_outline</span><span>Profile</span>
    </div>
  </div>`;
}

function driverTab(name, el) {
  document.querySelectorAll(".sidebar-nav a").forEach(a => a.classList.remove("active"));
  if (el) el.classList.add("active");
  showTab("tab-driver-" + name);
  // Persist current tab so reload returns here
  if (typeof saveCurrentPage === "function") {
    saveCurrentPage("page-driver-dashboard", name);
  }
  const handlers = {
    home:       loadDriverHome,
    navigation: loadDriverNav,
    earnings:   loadDriverEarnings,
    mycar:      loadMyCar,
    messages:   loadDriverMessages,
    profile:    loadDriverProfile,
  };
  if (handlers[name]) handlers[name]();
}

// ── HOME ──────────────────────────────────────────────────────────────────────
async function loadDriverHome() {
  const el = document.getElementById("tab-driver-home");
  el.innerHTML = `<div style="padding:20px">${shimmerCards(3)}</div>`;

  const [statusData, earningsData] = await Promise.all([
    api("/drivers.php?action=get_my_status"),
    api("/drivers.php?action=get_earnings&period=today")
  ]);

  driverOnline = statusData.driver?.is_online === 1;

  el.innerHTML = `
  <div style="padding:20px">
    <div class="driver-status-hero ${driverOnline?"online":""}" id="driver-hero">
      <div class="driver-status-text">
        <h3>${driverOnline?"You are Online":"You are Offline"}</h3>
        <p>${driverOnline?"Accepting trip requests — stay alert":"Go online to start earning"}</p>
      </div>
      <label class="toggle-switch">
        <input type="checkbox" id="online-toggle" ${driverOnline?"checked":""} onchange="toggleOnline(this)">
        <span class="toggle-slider"></span>
      </label>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px">
      <div class="stat-mini">
        <div class="stat-mini-val">${earningsData.earnings?.trips||0}</div>
        <div class="stat-mini-label">Trips Today</div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-val">${fmt(earningsData.earnings?.net||0)}</div>
        <div class="stat-mini-label">Earnings Today</div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-val">${statusData.driver?.rating||"5.0"}⭐</div>
        <div class="stat-mini-label">Rating</div>
      </div>
    </div>

    <div id="trip-request-area"></div>
    <div id="active-trip-area"></div>
  </div>`;

  checkDriverActiveTrip();
  if (driverOnline) pollTripRequests();
}

async function toggleOnline(checkbox) {
  driverOnline = checkbox.checked;
  await api("/drivers.php?action=toggle_online","POST",{ is_online:driverOnline?1:0 });
  const hero = document.querySelector(".driver-status-hero");
  const txt  = document.querySelector(".driver-status-text");
  if (hero) hero.classList.toggle("online", driverOnline);
  if (txt) {
    txt.querySelector("h3").textContent = driverOnline?"You are Online":"You are Offline";
    txt.querySelector("p").textContent  = driverOnline?"Accepting trip requests — stay alert":"Go online to start earning";
  }
  showToast(driverOnline?"You are now online!":"You are now offline", driverOnline?"success":"info");
  if (driverOnline) pollTripRequests();
}

async function checkDriverActiveTrip() {
  const data = await api("/bookings.php?action=get_driver_bookings");
  const area = document.getElementById("active-trip-area");
  if (!area) return;
  const active = (data.bookings||[]).find(b => ["confirmed","active"].includes(b.status));
  if (!active) return;
  const isInTrip = active.status === "active";
  area.innerHTML = `
  <div class="card" style="border-left:3px solid ${isInTrip ? "var(--gold)" : "var(--cyan)"}">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
      <div style="font-weight:700">${isInTrip ? "Trip In Progress" : "Confirmed Booking"}</div>
      <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:${isInTrip ? "var(--gold)" : "var(--cyan)"};color:#020B18;font-weight:600">${isInTrip ? "IN TRIP" : "READY"}</span>
    </div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:4px">${active.customer_name}</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
      <span class="material-icons-outlined" style="font-size:12px;vertical-align:middle">my_location</span> ${active.pickup_address||"—"}
      ${active.dropoff_address ? `<br><span class="material-icons-outlined" style="font-size:12px;vertical-align:middle">location_on</span> ${active.dropoff_address}` : ""}
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm btn-ghost" onclick="driverTab('navigation')">
        <span class="material-icons-outlined">navigation</span> Navigate
      </button>
      ${!isInTrip ? `
      <button class="btn btn-sm" style="background:var(--cyan);color:#020B18;font-weight:600" onclick="updateTripStatus(${active.id},'active')">
        <span class="material-icons-outlined">play_circle</span> Start Trip
      </button>` : `
      <button class="btn btn-sm btn-gold" onclick="updateTripStatus(${active.id},'completed')">
        <span class="material-icons-outlined">check_circle</span> Complete Trip
      </button>`}
      <button class="btn btn-sm btn-ghost" onclick="openDriverChatModal(${active.id},${active.customer_id})">
        <span class="material-icons-outlined">chat</span>
      </button>
    </div>
  </div>`;
}

let tripPollInterval = null;
function pollTripRequests() {
  if (tripPollInterval) clearInterval(tripPollInterval);
  fetchPendingTrips();
  tripPollInterval = setInterval(fetchPendingTrips, 8000);
}

async function fetchPendingTrips() {
  if (!driverOnline) { clearInterval(tripPollInterval); return; }
  const data = await api("/bookings.php?action=get_driver_bookings");
  const area = document.getElementById("trip-request-area");
  if (!area) return;
  const pending = (data.bookings||[]).find(b => b.status==="pending" && b.id !== pendingBookingId);
  if (!pending) return;
  pendingBookingId = pending.id;
  let secs = 30;
  area.innerHTML = `
  <div class="trip-request-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-weight:700;color:var(--gold)">New Trip Request!</div>
      <div id="trip-countdown" style="font-family:'Space Mono',monospace;font-size:18px;color:var(--cyan)">${secs}s</div>
    </div>
    <div style="font-size:13px;margin-bottom:4px"><b>Customer:</b> ${pending.customer_name}</div>
    <div style="font-size:13px;margin-bottom:4px"><b>Pickup:</b> ${pending.pickup_address||"—"}</div>
    <div style="font-size:13px;margin-bottom:12px"><b>Total:</b> ${fmt(pending.total_price)}</div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-green" style="flex:1" onclick="acceptTrip(${pending.id})"><span class="material-icons-outlined">check</span> Accept</button>
      <button class="btn btn-ghost-red" style="flex:1" onclick="declineTrip(${pending.id})"><span class="material-icons-outlined">close</span> Decline</button>
    </div>
  </div>`;
  if (tripCountdown) clearInterval(tripCountdown);
  tripCountdown = setInterval(() => {
    secs--;
    const cd = document.getElementById("trip-countdown");
    if (cd) cd.textContent = secs+"s";
    if (secs <= 0) { clearInterval(tripCountdown); if (area) area.innerHTML = ""; pendingBookingId = null; }
  }, 1000);
}

async function acceptTrip(bookingId) {
  clearInterval(tripCountdown);
  pendingBookingId = null;
  await api("/bookings.php?action=update_status","POST",{ booking_id:bookingId, status:"confirmed" });
  const area = document.getElementById("trip-request-area");
  if (area) area.innerHTML = "";
  showToast("Trip accepted! Head to pickup","success");
  startLocationTracking(bookingId);
  checkDriverActiveTrip();
}

async function declineTrip(bookingId) {
  clearInterval(tripCountdown);
  pendingBookingId = null;
  const area = document.getElementById("trip-request-area");
  if (area) area.innerHTML = "";
}

async function updateTripStatus(bookingId, status) {
  await api("/bookings.php?action=update_status","POST",{ booking_id:bookingId, status });
  if (status === "completed") stopLocationTracking();
  showToast(status==="completed"?"Trip completed! Great work":"Status updated","success");
  loadDriverHome();
}

// ── NAVIGATION MAP ────────────────────────────────────────────────────────────
async function loadDriverNav() {
  const el = document.getElementById("tab-driver-navigation");
  const data = await api("/bookings.php?action=get_driver_bookings");
  const active = (data.bookings||[]).find(b => ["confirmed","active"].includes(b.status));
  if (!active) {
    el.innerHTML = `
    <div class="page-header"><h2>Navigation</h2></div>
    <div style="text-align:center;padding:60px 20px">
      <span class="material-icons-outlined" style="font-size:64px;color:var(--dim);display:block;margin-bottom:16px">navigation</span>
      <p style="color:var(--muted)">Go online and accept a trip to navigate</p>
    </div>`;
    return;
  }
  el.innerHTML = `
  <div class="page-header"><h2>Navigation</h2><p>To: ${active.pickup_address||"Pickup"}</p></div>
  <div id="driver-nav-map" style="height:calc(100vh - 200px);border-radius:0"></div>`;
  setTimeout(() => initDriverNavMap("driver-nav-map", active), 100);
}

// ── EARNINGS ──────────────────────────────────────────────────────────────────
async function loadDriverEarnings() {
  const el = document.getElementById("tab-driver-earnings");
  el.innerHTML = `<div class="page-header"><h2>My <span>Earnings</span></h2></div><div style="padding:0 20px">${shimmerCards(2)}</div>`;
  const data = await api("/drivers.php?action=get_earnings&period=month");
  const e    = data.earnings || {};
  document.querySelector("#tab-driver-earnings > div:last-child").innerHTML = `
  <div class="card" style="text-align:center;margin-bottom:20px">
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:8px">This Month</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:48px;font-weight:300;color:var(--gold)">${fmt(e.net||0)}</div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">${e.trips||0} trips · ${fmt(e.commission||0)} commission deducted</div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
    <div class="stat-mini"><div class="stat-mini-val">${fmt(e.total||0)}</div><div class="stat-mini-label">Gross Earnings</div></div>
    <div class="stat-mini"><div class="stat-mini-val">${fmt(e.net||0)}</div><div class="stat-mini-label">Net (After 20%)</div></div>
  </div>
  <div class="input-group"><label>Request Payout Amount (₦)</label><input type="number" class="input-field" id="payout-amount" placeholder="Enter amount" min="1000"></div>
  <button class="btn btn-gold btn-full" onclick="requestPayout()"><span class="material-icons-outlined">send</span> Request Payout</button>`;
}

async function requestPayout() {
  const amount = parseFloat(document.getElementById("payout-amount").value);
  if (!amount) { showToast("Enter amount","error"); return; }
  const data = await api("/drivers.php?action=request_payout","POST",{ amount });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Payout request submitted!","success");
}

// ── MY CAR ────────────────────────────────────────────────────────────────────
async function loadMyCar() {
  const el = document.getElementById("tab-driver-mycar");
  el.innerHTML = `<div class="page-header"><h2>My <span>Car</span></h2><p>Manage your vehicle</p></div><div style="padding:0 20px">${shimmerCards(2)}</div>`;

  const exteriorSlots = ["Front","Rear","Driver Side","Passenger Side","Front Quarter","Rear Quarter"];
  const interiorSlots = ["Dashboard","Front Seats","Rear Seats","Boot"];

  // Load existing car specs and photos
  const [statusData, mediaData] = await Promise.all([
    api("/drivers.php?action=get_my_status"),
    currentUser.car_id ? api(`/media.php?action=get_car_media&car_id=${currentUser.car_id}`) : Promise.resolve({ media:[] })
  ]);

  const car    = statusData.driver_car || {};
  const mediaMap = {};
  (mediaData.media||[]).forEach(m => { mediaMap[m.label] = m; });

  function renderSlot(label) {
    const m  = mediaMap[label];
    const id = "slot-"+label.replace(/ /g,"-");
    if (m && m.status==="approved") {
      return `<div class="photo-slot approved" id="${id}" data-label="${label}" data-status="approved" style="position:relative">
        <img src="${m.url}" style="width:100%;height:100%;object-fit:cover;border-radius:8px">
        <div style="position:absolute;bottom:4px;left:4px;background:#00E87A;color:#000;font-size:10px;padding:2px 6px;border-radius:6px;font-weight:700">✓ Approved</div>
        <div style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,0.7);color:#fff;font-size:10px;padding:2px 6px;border-radius:6px;cursor:pointer" onclick="replacePhoto('${id}','${label}')">Replace</div>
      </div>`;
    } else if (m && m.status==="pending") {
      return `<div class="photo-slot" id="${id}" data-label="${label}" data-status="pending" style="position:relative">
        <img src="${m.url}" style="width:100%;height:100%;object-fit:cover;border-radius:8px;opacity:0.6">
        <div style="position:absolute;bottom:4px;left:4px;background:#F5A623;color:#000;font-size:10px;padding:2px 6px;border-radius:6px">⏳ Pending</div>
      </div>`;
    } else {
      return `<div class="photo-slot" id="${id}" data-label="${label}" data-status="empty">
        <div class="slot-icon"><span class="material-icons-outlined">photo_camera</span></div>
        <div class="slot-label">${label}</div>
      </div>`;
    }
  }

  document.querySelector("#tab-driver-mycar > div:last-child").innerHTML = `
  <div class="card" style="margin-bottom:16px">
    <div class="section-label" style="margin-bottom:16px">Car Details</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="input-group"><label>Car Name</label><input class="input-field" id="car-name" value="${car.name||""}" placeholder="e.g. Toyota Camry 2021"></div>
      <div class="input-group"><label>Make</label><input class="input-field" id="car-make" value="${car.make||""}" placeholder="e.g. Toyota"></div>
      <div class="input-group"><label>Model</label><input class="input-field" id="car-model" value="${car.model||""}" placeholder="e.g. Camry"></div>
      <div class="input-group"><label>Year</label><input type="number" class="input-field" id="car-year" value="${car.year||new Date().getFullYear()}" min="2000" max="${new Date().getFullYear()+1}"></div>
      <div class="input-group"><label>Color</label><input class="input-field" id="car-color" value="${car.color||""}" placeholder="e.g. Black"></div>
      <div class="input-group"><label>Plate Number</label><input class="input-field" id="car-plate" value="${car.plate_number||""}" placeholder="e.g. ABC-123-XY"></div>
      <div class="input-group"><label>Transmission</label>
        <select class="input-field" id="car-transmission">
          <option value="automatic" ${car.transmission==="automatic"?"selected":""}>Automatic</option>
          <option value="manual" ${car.transmission==="manual"?"selected":""}>Manual</option>
        </select>
      </div>
      <div class="input-group"><label>Fuel Type</label>
        <select class="input-field" id="car-fuel">
          <option value="petrol" ${car.fuel_type==="petrol"?"selected":""}>Petrol</option>
          <option value="diesel" ${car.fuel_type==="diesel"?"selected":""}>Diesel</option>
          <option value="electric" ${car.fuel_type==="electric"?"selected":""}>Electric</option>
        </select>
      </div>
      <div class="input-group"><label>Seats</label>
        <select class="input-field" id="car-seats">
          ${[4,5,6,7,8].map(n=>`<option value="${n}" ${car.seats==n?"selected":""}>${n} seats</option>`).join("")}
        </select>
      </div>
      <div class="input-group"><label>Category</label>
        <select class="input-field" id="car-category">
          <option value="sedan" ${car.category==="sedan"?"selected":""}>Sedan</option>
          <option value="suv" ${car.category==="suv"?"selected":""}>SUV</option>
          <option value="luxury" ${car.category==="luxury"?"selected":""}>Luxury</option>
          <option value="van" ${car.category==="van"?"selected":""}>Van/Bus</option>
        </select>
      </div>
    </div>
    <button class="btn btn-gold btn-full" style="margin-top:8px" onclick="saveCarSpecs()">
      <span class="material-icons-outlined">save</span> Save Car Details
    </button>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="section-label" style="margin-bottom:12px">Exterior Photos</div>
    <div class="grid-3">${exteriorSlots.map(renderSlot).join("")}</div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="section-label" style="margin-bottom:12px">Interior Photos</div>
    <div class="grid-3">${interiorSlots.map(renderSlot).join("")}</div>
  </div>

  <div class="card">
    <div class="section-label" style="margin-bottom:12px">Availability Calendar</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Mark dates when your car is unavailable (personal use, maintenance, etc.)</p>
    <div class="input-group"><label>Unavailable Date</label><input type="date" class="input-field" id="unavail-date"></div>
    <div class="input-group"><label>Reason (optional)</label><input class="input-field" id="unavail-reason" placeholder="e.g. Personal use"></div>
    <button class="btn btn-ghost btn-full" onclick="addUnavailableDate()"><span class="material-icons-outlined">event_busy</span> Mark Date Unavailable</button>
  </div>

  <input type="file" id="master-file-input" accept="image/*" style="display:none">`;

  // Wire up photo slots
  let activeSlot = null, activeLabel = null, activeCategory = null;

  document.querySelectorAll(".photo-slot[data-status='empty']").forEach(slot => {
    slot.addEventListener("click", () => {
      activeSlot = slot;
      activeLabel = slot.dataset.label;
      activeCategory = exteriorSlots.includes(activeLabel) ? "exterior" : "interior";
      document.getElementById("master-file-input").click();
    });
  });

  window.replacePhoto = function(slotId, label) {
    activeSlot = document.getElementById(slotId);
    activeLabel = label;
    activeCategory = exteriorSlots.includes(label) ? "exterior" : "interior";
    document.getElementById("master-file-input").click();
  };

  document.getElementById("master-file-input").addEventListener("change", async function() {
    const file = this.files[0];
    if (!file || !activeSlot) return;
    if (!currentUser.car_id) { showToast("Save car details first","error"); return; }
    showToast("Uploading...","info");
    const formData = new FormData();
    formData.append("file", file);
    formData.append("car_id", currentUser.car_id);
    formData.append("media_type", "photo");
    formData.append("category", activeCategory);
    formData.append("label", activeLabel);
    const res = await fetch(API + "/media.php?action=upload", {
      method:"POST", headers:{ "Authorization":"Bearer "+authToken }, body:formData
    });
    const data = await res.json();
    if (data.error) { showToast(data.error,"error"); return; }
    activeSlot.outerHTML = `<div class="photo-slot" data-label="${activeLabel}" data-status="pending" style="position:relative">
      <img src="${data.url}" style="width:100%;height:100%;object-fit:cover;border-radius:8px;opacity:0.6">
      <div style="position:absolute;bottom:4px;left:4px;background:#F5A623;color:#000;font-size:10px;padding:2px 6px;border-radius:6px">⏳ Pending</div>
    </div>`;
    showToast("Photo uploaded! Pending admin approval","success");
    this.value = "";
  });
}

async function saveCarSpecs() {
  const data = await api("/cars.php?action=driver_save","POST",{
    name:         document.getElementById("car-name").value.trim(),
    make:         document.getElementById("car-make").value.trim(),
    model:        document.getElementById("car-model").value.trim(),
    year:         parseInt(document.getElementById("car-year").value),
    color:        document.getElementById("car-color").value.trim(),
    plate_number: document.getElementById("car-plate").value.trim(),
    transmission: document.getElementById("car-transmission").value,
    fuel_type:    document.getElementById("car-fuel").value,
    seats:        parseInt(document.getElementById("car-seats").value),
    category:     document.getElementById("car-category").value,
  });
  if (data.error) { showToast(data.error,"error"); return; }
  // Update car_id in currentUser
  currentUser.car_id = data.car_id;
  localStorage.setItem("bookkam_user", JSON.stringify(currentUser));
  showToast("Car details saved!","success");
}

async function addUnavailableDate() {
  const date   = document.getElementById("unavail-date").value;
  const reason = document.getElementById("unavail-reason").value.trim();
  if (!date) { showToast("Select a date","error"); return; }
  const data = await api("/drivers.php?action=add_unavailable_date","POST",{ date, reason });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Date marked as unavailable","success");
  document.getElementById("unavail-date").value = "";
  document.getElementById("unavail-reason").value = "";
}

// ── MESSAGES ──────────────────────────────────────────────────────────────────
async function loadDriverMessages() {
  const el = document.getElementById("tab-driver-messages");
  el.innerHTML = `
  <div class="page-header"><h2>My <span>Messages</span></h2></div>
  <div style="padding:0 20px" id="driver-msgs-list">${shimmerCards(2)}</div>`;

  const data = await api("/bookings.php?action=get_driver_bookings");
  const msgEl = document.getElementById("driver-msgs-list");
  if (!msgEl) return;
  const bookings = (data.bookings||[]).filter(b => ["confirmed","active","completed"].includes(b.status));
  if (!bookings.length) {
    msgEl.innerHTML = `<div style="text-align:center;padding:48px 20px">
      <span class="material-icons-outlined" style="font-size:48px;color:var(--dim);display:block;margin-bottom:12px">chat_bubble_outline</span>
      <p style="color:var(--muted)">No conversations yet. Messages with customers appear here during trips.</p>
    </div>`;
    return;
  }
  msgEl.innerHTML = bookings.map(b => `
  <div class="card card-hover" style="margin-bottom:12px" onclick="openDriverChatModal(${b.id},${b.customer_id})">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${b.customer_name}</div>
        <div style="font-size:12px;color:var(--muted)">${b.car_name} · ${fmtDate(b.created_at)}</div>
      </div>
      <span class="material-icons-outlined" style="color:var(--muted)">chevron_right</span>
    </div>
  </div>`).join("");
}

// ── DRIVER CHAT ───────────────────────────────────────────────────────────────
let driverChatPoll = null;
let driverChatBookingId = null;
let driverChatReceiverId = null;

async function openDriverChatModal(bookingId, customerId) {
  driverChatBookingId  = bookingId;
  driverChatReceiverId = customerId;
  showModal(`
  <div class="modal-header">
    <h3>Chat with Customer</h3>
    <button class="modal-close" onclick="closeDriverChatModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="chat-container">
    <div class="chat-messages" id="driver-chat-messages">${shimmerCards(1)}</div>
    <div class="chat-input-bar">
      <input class="input-field" id="driver-chat-input" placeholder="Type a message..." onkeypress="if(event.key==='Enter') sendDriverChatMessage()">
      <button class="btn btn-gold" onclick="sendDriverChatMessage()"><span class="material-icons-outlined">send</span></button>
    </div>
  </div>`);
  loadDriverChatMessages();
  driverChatPoll = setInterval(loadDriverChatMessages, 3000);
}

function closeDriverChatModal() {
  closeModal();
  if (driverChatPoll) { clearInterval(driverChatPoll); driverChatPoll = null; }
}

async function loadDriverChatMessages() {
  const data = await api(`/messages.php?action=get&booking_id=${driverChatBookingId}`);
  const el = document.getElementById("driver-chat-messages");
  if (!el) return;
  const msgs = data.messages || [];
  if (!msgs.length) {
    el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No messages yet.</div>`;
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

async function sendDriverChatMessage() {
  const input = document.getElementById("driver-chat-input");
  if (!input) { showToast("Chat input not found","error"); return; }
  const text = input.value.trim();
  if (!text) return;
  if (!driverChatBookingId) { showToast("No active booking","error"); return; }
  input.value = "";
  input.disabled = true;
  const data = await api("/messages.php?action=send","POST",{
    booking_id:  driverChatBookingId,
    message_text: text
  });
  input.disabled = false;
  input.focus();
  if (data.error) { showToast(data.error,"error"); input.value = text; return; }
  if (data.flagged) showToast("⚠️ Avoid sharing personal contact details","warning");
  loadDriverChatMessages();
}

// ── PROFILE ───────────────────────────────────────────────────────────────────
function loadDriverProfile() { renderDriverProfileView(); }

function renderDriverProfileView() {
  const u = currentUser;
  document.getElementById("tab-driver-profile").innerHTML = `
  <div class="page-header"><h2>Driver <span>Profile</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="max-width:480px">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border-soft)">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--card-dark);display:flex;align-items:center;justify-content:center;border:2px solid var(--border);flex-shrink:0">
          <span class="material-icons-outlined" style="font-size:32px;color:var(--gold)">person_outline</span>
        </div>
        <div>
          <div style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300">${u.name||"No name set"}</div>
          <div style="color:var(--muted);font-size:13px;margin-top:4px">+234${u.phone||""}</div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px">
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Full Name</div>
          <div style="font-size:14px;font-weight:600">${u.name||"Not set"}</div>
        </div>
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">Phone</div>
          <div style="font-size:14px;font-weight:600">+234${u.phone||""}</div>
        </div>
        <div style="padding:12px 16px;background:var(--card-dark);border-radius:10px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">City</div>
          <div style="font-size:14px;font-weight:600">${u.city||"Not set"}</div>
        </div>
      </div>
      <button class="btn btn-gold btn-full" onclick="renderDriverProfileEdit()">
        <span class="material-icons-outlined">edit</span> Edit Profile
      </button>
      <div class="divider"></div>
      <button class="btn btn-ghost-red btn-full" onclick="logout()">
        <span class="material-icons-outlined">logout</span> Logout
      </button>
    </div>
  </div>`;
}

function renderDriverProfileEdit() {
  const u = currentUser;
  document.getElementById("tab-driver-profile").innerHTML = `
  <div class="page-header"><h2>Edit <span>Profile</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="max-width:480px">
      <div class="input-group"><label>Full Name</label><input class="input-field" id="driver-name" value="${u.name||""}" placeholder="Your full name"></div>
      <div class="input-group"><label>Email</label><input type="email" class="input-field" id="driver-email" value="${u.email||""}" placeholder="email@example.com"></div>
      <div class="input-group"><label>City</label>
        <select class="input-field" id="driver-city">
          <option ${u.city==="Calabar"?"selected":""}>Calabar</option>
          <option ${u.city==="Ikom"?"selected":""}>Ikom</option>
          <option ${u.city==="Lagos"?"selected":""}>Lagos</option>
        </select>
      </div>
      <div class="section-label" style="margin:16px 0 12px">Bank Details</div>
      <div class="input-group"><label>Bank Name</label>
        <select class="input-field" id="bank-name">
          ${["GTBank","Access Bank","First Bank","UBA","Zenith Bank","Kuda Bank","Opay","PalmPay"].map(b=>`<option>${b}</option>`).join("")}
        </select>
      </div>
      <div class="input-group"><label>Account Number</label><input class="input-field" id="bank-account" placeholder="10-digit account number" maxlength="10" inputmode="numeric"></div>
      <div class="input-group"><label>Account Name</label><input class="input-field" id="bank-acct-name" placeholder="As on your bank account"></div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-gold" style="flex:1" onclick="saveDriverProfile()"><span class="material-icons-outlined">save</span> Save</button>
        <button class="btn btn-ghost" style="flex:0.5" onclick="renderDriverProfileView()"><span class="material-icons-outlined">close</span> Cancel</button>
      </div>
    </div>
  </div>`;
  setTimeout(() => document.getElementById("driver-name")?.focus(), 100);
}

async function saveDriverProfile() {
  const name     = document.getElementById("driver-name").value.trim();
  const email    = document.getElementById("driver-email").value.trim();
  const city     = document.getElementById("driver-city").value;
  const bankName = document.getElementById("bank-name").value;
  const bankAcct = document.getElementById("bank-account").value.trim();
  const bankName2= document.getElementById("bank-acct-name").value.trim();
  if (!name) { showToast("Enter your name","error"); return; }
  const [profileData, bankData] = await Promise.all([
    api("/auth.php?action=update_profile","POST",{ name, email, city }),
    bankAcct ? api("/drivers.php?action=update_bank","POST",{ bank_name:bankName, account_number:bankAcct, account_name:bankName2 }) : Promise.resolve({})
  ]);
  if (profileData.error) { showToast(profileData.error,"error"); return; }
  currentUser.name  = name;
  currentUser.email = email;
  currentUser.city  = city;
  localStorage.setItem("bookkam_user", JSON.stringify(currentUser));
  renderDriverTopbar();
  showToast("Profile updated!","success");
  renderDriverProfileView();
}
