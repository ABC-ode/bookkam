// ── ADMIN.JS ───────────────────────────────────────────────────────────────

function adminEscapeHTML(value) {
  return String(value == null ? "" : value).replace(/[&<>"']/g, ch => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  }[ch]));
}

function loadAdminDashboard() {
  renderAdminSidebar();
  renderAdminTopbar();
  showTab("tab-admin-dashboard");
  loadAdminHome();
}

function renderAdminSidebar() {
  document.getElementById("admin-sidebar").innerHTML = `
  <div class="sidebar-brand"><img src="/icons/logo-main.png" alt="BOOKKAM" class="sidebar-logo-img"> <span style="font-size:10px;color:var(--gold);font-family:'Space Mono',monospace">ADMIN</span></div>
  <ul class="sidebar-nav">
    <li><a class="active" onclick="adminTab('dashboard',this)"><span class="material-icons-outlined">dashboard</span><span class="nav-label">Dashboard</span></a></li>
    <li><a onclick="adminTab('cars',this)"><span class="material-icons-outlined">directions_car</span><span class="nav-label">Cars</span></a></li>
    <li><a onclick="adminTab('drivers',this)"><span class="material-icons-outlined">person_pin</span><span class="nav-label">Drivers</span></a></li>
    <li><a onclick="adminTab('bookings',this)"><span class="material-icons-outlined">receipt_long</span><span class="nav-label">Bookings</span></a></li>
    <li><a onclick="adminTab('media',this)"><span class="material-icons-outlined">photo_library</span><span class="nav-label">Media</span></a></li>
    <li><a onclick="adminTab('chats',this)"><span class="material-icons-outlined">forum</span><span class="nav-label">Chat Monitor</span></a></li>
    <li><a onclick="adminTab('customers',this)"><span class="material-icons-outlined">group</span><span class="nav-label">Customers</span></a></li>
    <li><a onclick="adminTab('revenue',this)"><span class="material-icons-outlined">bar_chart</span><span class="nav-label">Revenue</span></a></li>
    <li><a onclick="adminTab('payouts',this)"><span class="material-icons-outlined">payments</span><span class="nav-label">Payouts</span></a></li>
    <li><a onclick="adminTab('pricing',this)"><span class="material-icons-outlined">price_change</span><span class="nav-label">Pricing</span></a></li>
    <li><a onclick="adminTab('promos',this)"><span class="material-icons-outlined">local_offer</span><span class="nav-label">Promos</span></a></li>
    <li><a onclick="adminTab('cities',this)"><span class="material-icons-outlined">location_city</span><span class="nav-label">Cities</span></a></li>
    <li><a onclick="adminTab('booking-card',this)"><span class="material-icons-outlined">view_carousel</span><span class="nav-label">Booking Card</span></a></li>
    <li style="margin-top:auto"><a onclick="logout()"><span class="material-icons-outlined">logout</span><span class="nav-label">Logout</span></a></li>
  </ul>`;
}

function renderAdminTopbar() {
  document.getElementById("admin-topbar").innerHTML = `
  <div class="topbar-greeting">Admin Panel — <span>${greet()}</span></div>
  <div class="topbar-actions">
    <div class="topbar-notif" onclick="toggleNotifications(event)">
      <span class="material-icons-outlined">notifications_none</span>
      <span id="notif-badge"></span>
    </div>
    <span style="font-size:12px;color:var(--muted);font-family:'Space Mono',monospace">${currentUser.email||currentUser.name}</span>
  </div>`;
}

function adminTab(name, el) {
  document.querySelectorAll(".sidebar-nav a").forEach(a => a.classList.remove("active"));
  if (el) el.classList.add("active");
  showTab("tab-admin-" + name);
  const handlers = {
    dashboard: loadAdminHome,
    cars:      loadAdminCars,
    drivers:   loadAdminDrivers,
    bookings:  loadAdminBookings,
    media:     loadAdminMedia,
    chats:     loadAdminChats,
    customers: loadAdminCustomers,
    revenue:   loadAdminRevenue,
    payouts:   loadAdminPayouts,
    pricing:   loadAdminPricing,
    promos:    loadAdminPromos,
    cities:    loadAdminCities,
    "booking-card": loadAdminBookingCard,
  };
  if (handlers[name]) handlers[name]();
}

// ── DASHBOARD ──────────────────────────────────────────────────────────────
async function loadAdminHome() {
  const el = document.getElementById("tab-admin-dashboard");
  el.innerHTML = `<div class="page-header"><h2>Dashboard</h2></div><div style="padding:0 20px">${shimmerCards(4)}</div>`;
  const data = await api("/admin.php?action=get_stats");
  const s    = data.stats || {};
  document.querySelector("#tab-admin-dashboard > div:last-child").innerHTML = `
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
    ${[
      ["Today's Bookings",s.today_bookings||0,"receipt_long","var(--gold)"],
      ["Active Trips",s.active_trips||0,"directions_car","var(--cyan)"],
      ["Today's Revenue","₦"+(parseFloat(s.today_revenue||0)).toLocaleString(),"payments","var(--green)"],
      ["Total Customers",s.total_customers||0,"group","var(--amber)"],
      ["Active Drivers",s.total_drivers||0,"person_pin","var(--gold)"],
      ["Total Cars",s.total_cars||0,"garage","var(--cyan)"],
      ["Pending Media",s.pending_media||0,"photo_library","var(--orange)"],
      ["Flagged Chats",s.flagged_messages||0,"warning","var(--fire)"],
    ].map(([label,val,icon,color]) => `
    <div class="stat-card">
      <span class="material-icons-outlined" style="font-size:28px;color:${color};display:block;margin-bottom:8px">${icon}</span>
      <div style="font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:300;color:var(--text)">${val}</div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:0.5px">${label}</div>
    </div>`).join("")}
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card">
      <div class="section-label" style="margin-bottom:12px">Pending Actions</div>
      ${s.pending_drivers>0?`<div class="alert-item" onclick="adminTab('drivers')"><span class="material-icons-outlined" style="color:var(--orange)">person_pin</span>${s.pending_drivers} driver${s.pending_drivers!==1?"s":""} awaiting approval</div>`:""}
      ${s.pending_media>0?`<div class="alert-item" onclick="adminTab('media')"><span class="material-icons-outlined" style="color:var(--orange)">photo_library</span>${s.pending_media} photo${s.pending_media!==1?"s":""} to review</div>`:""}
      ${s.flagged_messages>0?`<div class="alert-item" onclick="adminTab('chats')"><span class="material-icons-outlined" style="color:var(--fire)">warning</span>${s.flagged_messages} flagged message${s.flagged_messages!==1?"s":""}</div>`:""}
      ${!s.pending_drivers&&!s.pending_media&&!s.flagged_messages?`<div style="color:var(--muted);font-size:13px">All clear! No pending actions.</div>`:""}
    </div>
    <div class="card">
      <div class="section-label" style="margin-bottom:12px">Fleet Overview</div>
      <div class="flex-between" style="margin-bottom:8px"><span style="font-size:13px;color:var(--muted)">Chauffeur Cars</span><span style="font-weight:700">${s.chauffeur_cars||0}</span></div>
      <div class="flex-between" style="margin-bottom:8px"><span style="font-size:13px;color:var(--muted)">Self-Drive Cars</span><span style="font-weight:700">${s.self_drive_cars||0}</span></div>
      <div class="flex-between"><span style="font-size:13px;color:var(--muted)">Total Bookings</span><span style="font-weight:700">${s.total_bookings||0}</span></div>
    </div>
  </div>`;
}

// ── CARS MANAGEMENT ────────────────────────────────────────────────────────
async function loadAdminCars() {
  const el = document.getElementById("tab-admin-cars");
  el.innerHTML = `
  <div class="page-header"><h2>Manage <span>Cars</span></h2></div>
  <div style="padding:0 20px">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      <button class="btn btn-gold" onclick="showAddCarModal('self_drive')"><span class="material-icons-outlined">add</span> Add Self-Drive Car</button>
      <button class="btn btn-ghost" onclick="showAddCarModal('chauffeur')"><span class="material-icons-outlined">add</span> Add Chauffeur Car</button>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button class="btn btn-sm btn-gold" onclick="loadCarsList('all')">All</button>
      <button class="btn btn-sm btn-ghost" onclick="loadCarsList('self_drive')">Self-Drive</button>
      <button class="btn btn-sm btn-ghost" onclick="loadCarsList('chauffeur')">Chauffeur</button>
    </div>
    <div id="admin-cars-list">${shimmerCards(4)}</div>
  </div>`;
  loadCarsList("all");
}

async function loadCarsList(type) {
  const el = document.getElementById("admin-cars-list");
  if (!el) return;
  el.innerHTML = shimmerCards(4);
  const data = await api(`/cars.php?action=admin_get_all${type&&type!=="all"?"&type="+type:""}`);
  if (data.error) {
    el.innerHTML = adminCarsEmptyState("Cars could not load", data.error, type);
    return;
  }
  const cars = data.cars || [];
  if (!cars.length) {
    const filterLabel = type && type !== "all" ? type.replace("_", "-") : "fleet";
    el.innerHTML = adminCarsEmptyState(
      `No ${filterLabel} cars yet`,
      "Add a car here first. Booking cards only show cars that exist in this system.",
      type
    );
    return;
  }
  el.innerHTML = cars.map(c => `
  <div class="card card-hover" style="margin-bottom:12px">
    <div class="flex-between">
      <div style="display:flex;gap:12px;align-items:center">
        ${c.main_photo?`<img src="${c.main_photo}" style="width:60px;height:60px;border-radius:8px;object-fit:cover;flex-shrink:0">`:
          `<div style="width:60px;height:60px;border-radius:8px;background:var(--card-dark);display:flex;align-items:center;justify-content:center;flex-shrink:0"><span class="material-icons-outlined">directions_car</span></div>`}
        <div>
          <div style="font-weight:700">${c.name}</div>
          <div style="font-size:12px;color:var(--muted)">${c.make||""} ${c.model||""} ${c.year||""} · ${c.plate_number||""}</div>
          <div style="display:flex;gap:6px;margin-top:4px">
            <span class="badge ${c.car_type==="self_drive"?"badge-blue":"badge-orange"}">${c.car_type==="self_drive"?"Self-Drive":"Chauffeur"}</span>
            <span class="badge ${c.availability_status==="available"?"badge-green":"badge-red"}">${capitalize(c.availability_status||"available")}</span>
          </div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${c.car_type==="self_drive"?`
        <button class="btn btn-sm ${c.availability_status==="available"?"btn-ghost-red":"btn-green"}" onclick="setCarAvailability(${c.id},'${c.availability_status==="available"?"in_use":"available"}')">
          ${c.availability_status==="available"?"Mark In Use":"Mark Available"}
        </button>`:
        `<button class="btn btn-sm btn-ghost" onclick="showDriverAssignModal(${c.id})">Assign Driver</button>`}
        <button class="btn btn-sm btn-ghost" onclick="showEditCarModal(${c.id})"><span class="material-icons-outlined" style="font-size:14px">edit</span> Edit</button>
        <button class="btn btn-sm btn-ghost" onclick="showSurgePricingModal(${c.id},${c.surge_multiplier||1})">Surge: ${c.surge_multiplier||1}x</button>
      </div>
    </div>
    ${c.car_type==="self_drive"&&c.availability_status==="in_use"?`
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-soft)">
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Expected return: ${c.expected_return_at?fmtDate(c.expected_return_at):"Not set"}</div>
      <button class="btn btn-sm btn-ghost" onclick="showReturnDateModal(${c.id})">Update Return Date</button>
    </div>`:""}
  </div>`).join("");
}

function adminCarsEmptyState(title, message, type) {
  const defaultType = type === "chauffeur" ? "chauffeur" : "self_drive";
  return `
  <div class="admin-empty-state">
    <span class="material-icons-outlined">directions_car</span>
    <h3>${adminEscapeHTML(title)}</h3>
    <p>${adminEscapeHTML(message)}</p>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
      <button class="btn btn-gold" onclick="showAddCarModal('${defaultType}')">
        <span class="material-icons-outlined">add</span> Add ${defaultType === "self_drive" ? "Self-Drive" : "Chauffeur"} Car
      </button>
      ${defaultType === "chauffeur" ? `<button class="btn btn-ghost" onclick="adminTab('drivers')"><span class="material-icons-outlined">badge</span> Check Drivers</button>` : ""}
    </div>
  </div>`;
}

async function setCarAvailability(carId, status) {
  const data = await api("/admin.php?action=set_car_availability","POST",{ car_id:carId, status });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast(`Car marked as ${status==="available"?"available":"in use"}`,"success");
  loadCarsList("all");
}

function showReturnDateModal(carId) {
  showModal(`
  <div class="modal-header"><h3>Set Return Date</h3><button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button></div>
  <div class="modal-body">
    <div class="input-group"><label>Expected Return Date & Time</label><input type="datetime-local" class="input-field" id="return-datetime"></div>
    <button class="btn btn-gold btn-full" onclick="updateReturnDate(${carId})">Update</button>
  </div>`);
}

async function updateReturnDate(carId) {
  const dt   = document.getElementById("return-datetime").value;
  const data = await api("/admin.php?action=set_car_availability","POST",{ car_id:carId, status:"in_use", expected_return:dt });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast("Return date updated","success");
  loadCarsList("all");
}

function showSurgePricingModal(carId, current) {
  showModal(`
  <div class="modal-header"><h3>Surge Pricing</h3><button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button></div>
  <div class="modal-body">
    <p style="color:var(--muted);font-size:13px;margin-bottom:16px">Multiply the base price during peak demand.</p>
    <div class="input-group"><label>Multiplier (1.0 = normal, 2.0 = double price)</label>
      <input type="number" class="input-field" id="surge-val" value="${current}" min="1" max="5" step="0.1">
    </div>
    <button class="btn btn-gold btn-full" onclick="setSurge(${carId})">Apply Surge</button>
    <button class="btn btn-ghost btn-full" style="margin-top:8px" onclick="setSurgeVal(${carId},1)">Remove Surge (Reset to 1x)</button>
  </div>`);
}

async function setSurge(carId) {
  const val  = parseFloat(document.getElementById("surge-val").value) || 1;
  const data = await api("/admin.php?action=toggle_surge","POST",{ car_id:carId, multiplier:val });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast(`Surge set to ${val}x`,"success");
  loadCarsList("all");
}

async function setSurgeVal(carId, val) {
  await api("/admin.php?action=toggle_surge","POST",{ car_id:carId, multiplier:val });
  closeModal();
  showToast("Surge removed","success");
  loadCarsList("all");
}

function showAddCarModal(carType) {
  showModal(`
  <div class="modal-header">
    <h3>Add ${carType==="self_drive"?"Self-Drive":"Chauffeur"} Car</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="modal-body" style="max-height:80vh;overflow-y:auto">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="input-group"><label>Car Name *</label><input class="input-field" id="ac-name" placeholder="e.g. Toyota Camry 2021"></div>
      <div class="input-group"><label>Make</label><input class="input-field" id="ac-make" placeholder="Toyota"></div>
      <div class="input-group"><label>Model</label><input class="input-field" id="ac-model" placeholder="Camry"></div>
      <div class="input-group"><label>Year</label><input type="number" class="input-field" id="ac-year" value="${new Date().getFullYear()}" min="2000"></div>
      <div class="input-group"><label>Color</label><input class="input-field" id="ac-color" placeholder="Black"></div>
      <div class="input-group"><label>Plate Number *</label><input class="input-field" id="ac-plate" placeholder="ABC-123-XY"></div>
      <div class="input-group"><label>Transmission</label>
        <select class="input-field" id="ac-transmission">
          <option value="automatic">Automatic</option>
          <option value="manual">Manual</option>
        </select>
      </div>
      <div class="input-group"><label>Fuel Type</label>
        <select class="input-field" id="ac-fuel">
          <option value="petrol">Petrol</option>
          <option value="diesel">Diesel</option>
          <option value="electric">Electric</option>
          <option value="hybrid">Hybrid</option>
        </select>
      </div>
      <div class="input-group"><label>Seats</label>
        <select class="input-field" id="ac-seats">
          ${[4,5,6,7,8].map(n=>`<option value="${n}">${n} seats</option>`).join("")}
        </select>
      </div>
      <div class="input-group"><label>Category</label>
        <select class="input-field" id="ac-category">
          <option value="sedan">Sedan</option>
          <option value="suv">SUV</option>
          <option value="luxury">Luxury</option>
          <option value="van">Van/Bus</option>
        </select>
      </div>
      <div class="input-group"><label>City</label>
        <select class="input-field" id="ac-city">
          <option>Calabar</option><option>Ikom</option><option>Lagos</option><option>Abuja</option><option>Port Harcourt</option>
        </select>
      </div>
      ${carType==="self_drive"?`
      <div class="input-group"><label>Security Deposit (₦)</label><input type="number" class="input-field" id="ac-deposit" placeholder="50000"></div>
      <div class="input-group"><label>Daily Mileage Limit (km)</label><input type="number" class="input-field" id="ac-mileage" placeholder="200 (0 = unlimited)"></div>
      `:`<div class="input-group"><label>Driver ID (optional)</label><input type="number" class="input-field" id="ac-driver" placeholder="Leave blank to assign later"></div>`}
    </div>
    
    <!-- IMAGE UPLOAD -->
    <div class="input-group">
      <label>Car Image</label>
      <input type="file" class="input-field" id="ac-image-file" accept="image/jpeg,image/png,image/webp" onchange="adminUploadCarImage()">
      <input type="hidden" id="ac-image-url">
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
        <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('ac-image-file')?.click()">
          <span class="material-icons-outlined">cloud_upload</span> Upload Image
        </button>
        <span id="ac-image-status" style="font-size:12px;color:var(--muted)">No image uploaded</span>
      </div>
      <div id="ac-image-preview" style="margin-top:8px;height:80px;border-radius:8px;background:var(--card-dark);display:flex;align-items:center;justify-content:center;overflow:hidden"></div>
    </div>
    
    <!-- LOCATION PRICING -->
    <div class="input-group">
      <label>Per-Location Pricing (optional)</label>
      <div style="background:var(--card-dark);border-radius:8px;padding:12px;margin-bottom:12px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Add pricing for each location. Leave empty to use base pricing.</div>
        <div id="ac-location-pricing-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px"></div>
        <button type="button" class="btn btn-sm btn-ghost" onclick="addLocationPricingRow()">
          <span class="material-icons-outlined" style="font-size:16px">add</span> Add Location Price
        </button>
      </div>
    </div>
    
    <div class="input-group"><label>Description</label><textarea class="input-field" id="ac-desc" rows="3" placeholder="Brief description of the car..."></textarea></div>
    <button class="btn btn-gold btn-full" onclick="adminAddCar('${carType}')">
      <span class="material-icons-outlined">add</span> Add Car
    </button>
  </div>`);
  
  // Initialize location pricing rows
  document.getElementById("ac-location-pricing-list").innerHTML = "";
  addLocationPricingRow();
}

function addLocationPricingRow() {
  const container = document.getElementById("ac-location-pricing-list");
  if (!container) return;
  const rowId = Date.now();
  const row = document.createElement("div");
  row.id = `price-row-${rowId}`;
  row.style.display = "flex";
  row.style.gap = "6px";
  row.innerHTML = `
    <input type="text" class="input-field" placeholder="e.g. Municipal" style="flex:1;font-size:13px;padding:8px" data-location>
    <input type="number" class="input-field" placeholder="₦ price" style="flex:1;font-size:13px;padding:8px" data-price min="0">
    <button type="button" class="btn btn-sm btn-ghost-red" onclick="document.getElementById('price-row-${rowId}').remove()">
      <span class="material-icons-outlined" style="font-size:16px">delete</span>
    </button>
  `;
  container.appendChild(row);
}

async function adminUploadCarImage() {
  const input = document.getElementById("ac-image-file");
  const file = input?.files?.[0];
  if (!file) return;
  if (!file.type.startsWith("image/")) { showToast("Choose an image file","error"); input.value = ""; return; }
  if (file.size > 5*1024*1024) { showToast("Max 5MB","error"); input.value = ""; return; }
  
  const status = document.getElementById("ac-image-status");
  const preview = document.getElementById("ac-image-preview");
  const urlInput = document.getElementById("ac-image-url");
  
  if (status) status.textContent = "Uploading...";
  try {
    const cfg = await api("/admin.php?action=get_cloudinary_upload_config");
    if (cfg.error) throw new Error(cfg.error);
    
    const form = new FormData();
    form.append("file", file);
    form.append("upload_preset", cfg.upload_preset);
    
    const res = await fetch(`https://api.cloudinary.com/v1_1/${encodeURIComponent(cfg.cloud_name)}/image/upload`, {
      method: "POST", body: form
    });
    const data = await res.json();
    if (!res.ok || !data.secure_url) throw new Error(data.error?.message || "Upload failed");
    
    if (urlInput) urlInput.value = data.secure_url;
    if (preview) preview.innerHTML = `<img src="${data.secure_url}" style="width:100%;height:100%;object-fit:cover">`;
    if (status) status.textContent = "✓ Uploaded";
    showToast("Image uploaded","success");
  } catch(err) {
    if (status) status.textContent = "Upload failed";
    showToast(err.message || "Upload failed","error");
  } finally {
    input.value = "";
  }
}

async function adminAddCar(carType) {
  // Collect location pricing
  const locationPricing = [];
  document.querySelectorAll("#ac-location-pricing-list [data-location]").forEach(el => {
    const location = el.value.trim();
    const price = parseFloat(el.parentElement.querySelector("[data-price]")?.value || 0);
    if (location && price > 0) {
      locationPricing.push({ location, price });
    }
  });
  
  const data = await api("/cars.php?action=admin_add","POST",{
    name:         document.getElementById("ac-name").value.trim(),
    car_type:     carType,
    make:         document.getElementById("ac-make").value.trim(),
    model:        document.getElementById("ac-model").value.trim(),
    year:         parseInt(document.getElementById("ac-year").value),
    color:        document.getElementById("ac-color").value.trim(),
    plate_number: document.getElementById("ac-plate").value.trim(),
    transmission: document.getElementById("ac-transmission").value,
    fuel_type:    document.getElementById("ac-fuel").value,
    seats:        parseInt(document.getElementById("ac-seats").value),
    category:     document.getElementById("ac-category").value,
    city:         document.getElementById("ac-city").value,
    description:  document.getElementById("ac-desc").value.trim(),
    security_deposit:       parseFloat(document.getElementById("ac-deposit")?.value||0),
    mileage_limit_per_day:  parseInt(document.getElementById("ac-mileage")?.value||0),
    driver_id:              parseInt(document.getElementById("ac-driver")?.value||0)||null,
    main_photo:             document.getElementById("ac-image-url")?.value || "",
    location_pricing:       locationPricing,
  });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast("Car added successfully!","success");
  loadCarsList("all");
}

function showEditCarModal(carId) {
  showModal(`
  <div class="modal-header">
    <h3>Edit Car</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="modal-body" id="edit-car-body" style="max-height:80vh;overflow-y:auto">Loading...</div>`);
  
  api(`/cars.php?action=admin_get_one&id=${carId}`).then(data => {
    if (data.error) { showToast(data.error,"error"); closeModal(); return; }
    const c = data.car;
    const pricing = (c.location_pricing && typeof c.location_pricing === 'string') ? JSON.parse(c.location_pricing) : (c.location_pricing || []);
    
    document.getElementById("edit-car-body").innerHTML = `
    <div class="input-group"><label>Car Name</label><input class="input-field" id="ec-name" value="${adminEscapeHTML(c.name||"")}"></div>
    
    <div class="input-group">
      <label>Per-Location Pricing</label>
      <div style="background:var(--card-dark);border-radius:8px;padding:12px;margin-bottom:12px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Add or edit pricing for each location.</div>
        <div id="ec-pricing-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px"></div>
        <button type="button" class="btn btn-sm btn-ghost" onclick="addEditLocationPricingRow()">
          <span class="material-icons-outlined">add</span> Add Location Price
        </button>
      </div>
    </div>
    
    <button class="btn btn-gold btn-full" onclick="adminSaveCarEdit(${carId})">Save Changes</button>
    `;
    
    const pricingList = document.getElementById("ec-pricing-list");
    pricing.forEach(p => {
      const row = document.createElement("div");
      row.style.display = "flex";
      row.style.gap = "6px";
      row.innerHTML = `
        <input type="text" class="input-field" value="${adminEscapeHTML(p.location || "")}" style="flex:1;font-size:13px;padding:8px" data-location>
        <input type="number" class="input-field" value="${p.price || 0}" style="flex:1;font-size:13px;padding:8px" data-price min="0">
        <button type="button" class="btn btn-sm btn-ghost-red" onclick="this.parentElement.remove()">
          <span class="material-icons-outlined" style="font-size:16px">delete</span>
        </button>
      `;
      pricingList.appendChild(row);
    });
  });
}

function addEditLocationPricingRow() {
  const rowId = Date.now();
  const row = document.createElement("div");
  row.id = `ec-price-row-${rowId}`;
  row.style.display = "flex";
  row.style.gap = "6px";
  row.innerHTML = `
    <input type="text" class="input-field" placeholder="e.g. 8 Miles" style="flex:1;font-size:13px;padding:8px" data-location>
    <input type="number" class="input-field" placeholder="₦ price" style="flex:1;font-size:13px;padding:8px" data-price min="0">
    <button type="button" class="btn btn-sm btn-ghost-red" onclick="document.getElementById('ec-price-row-${rowId}').remove()">
      <span class="material-icons-outlined" style="font-size:16px">delete</span>
    </button>
  `;
  document.getElementById("ec-pricing-list").appendChild(row);
}

async function adminSaveCarEdit(carId) {
  const locationPricing = [];
  document.querySelectorAll("#ec-pricing-list > div").forEach(row => {
    const location = row.querySelector("[data-location]").value.trim();
    const price = parseFloat(row.querySelector("[data-price]").value || 0);
    if (location && price > 0) {
      locationPricing.push({ location, price });
    }
  });
  
  const data = await api("/cars.php?action=admin_update","POST",{
    car_id: carId,
    name: document.getElementById("ec-name")?.value.trim() || "",
    location_pricing: locationPricing
  });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast("Car updated","success");
  loadCarsList("all");
}

// ── DRIVERS ────────────────────────────────────────────────────────────────
async function loadAdminDrivers() {
  const el = document.getElementById("tab-admin-drivers");
  el.innerHTML = `
  <div class="page-header"><h2>Manage <span>Drivers</span></h2></div>
  <div style="padding:0 20px">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      ${["","pending","active","suspended"].map(s=>`<button class="btn btn-sm ${s?"btn-ghost":"btn-gold"}" onclick="loadDriversList('${s}')">${s?capitalize(s):"All"}</button>`).join("")}
    </div>
    <div id="admin-drivers-list">${shimmerCards(3)}</div>
  </div>`;
  loadDriversList("");
}

async function loadDriversList(status) {
  const el = document.getElementById("admin-drivers-list");
  if (!el) return;
  el.innerHTML = shimmerCards(3);
  const data = await api(`/admin.php?action=get_drivers${status?"&status="+status:""}`);
  const drivers = data.drivers || [];
  if (!drivers.length) { el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No drivers found.</div>`; return; }
  el.innerHTML = drivers.map(d => `
  <div class="card" style="margin-bottom:12px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${d.name}</div>
        <div style="font-size:12px;color:var(--muted)">${d.phone} · ${d.city}</div>
        <div style="font-size:12px;color:var(--muted)">Joined: ${fmtDate(d.joined)} · ${d.car_count||0} car(s)</div>
      </div>
      <span class="badge ${d.status==="active"?"badge-green":d.status==="pending"?"badge-orange":"badge-red"}">${capitalize(d.status)}</span>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      ${d.status!=="active"?`<button class="btn btn-sm btn-green" onclick="updateDriverStatus(${d.id},'active')">Approve</button>`:""}
      ${d.status!=="suspended"?`<button class="btn btn-sm btn-ghost-red" onclick="updateDriverStatus(${d.id},'suspended')">Suspend</button>`:""}
      ${d.status!=="pending"?`<button class="btn btn-sm btn-ghost" onclick="updateDriverStatus(${d.id},'pending')">Reset to Pending</button>`:""}
    </div>
  </div>`).join("");
}

async function updateDriverStatus(driverId, status) {
  const data = await api("/admin.php?action=update_driver_status","POST",{ driver_id:driverId, status });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast(`Driver ${status}`,"success");
  loadDriversList("");
}

// ── BOOKINGS ──────────────────────────────────────────────────────────────
async function loadAdminBookings() {
  const el = document.getElementById("tab-admin-bookings");
  el.innerHTML = `
  <div class="page-header"><h2>All <span>Bookings</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="margin-bottom:18px">
      <div class="flex-between" style="margin-bottom:12px">
        <div class="section-label">Event Bookings Awaiting Confirmation</div>
        <button class="btn btn-sm btn-ghost" onclick="loadAdminEventBookings('')">View All Event Bookings</button>
      </div>
      <div id="admin-event-bookings-list">${shimmerCards(2)}</div>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      ${["","pending","confirmed","active","completed","cancelled"].map(s=>`<button class="btn btn-sm ${s?"btn-ghost":"btn-gold"}" onclick="loadAdminBookingsList('${s}')">${s?capitalize(s):"All"}</button>`).join("")}
    </div>
    <div id="admin-bookings-list">${shimmerCards(3)}</div>
  </div>`;
  loadAdminEventBookings("awaiting_confirmation");
  loadAdminBookingsList("");
}

async function loadAdminEventBookings(status = "awaiting_confirmation") {
  const el = document.getElementById("admin-event-bookings-list");
  if (!el) return;
  el.innerHTML = shimmerCards(2);
  const data = await api(`/admin.php?action=get_event_bookings${status ? "&status=" + status : ""}`);
  const bookings = data.bookings || [];
  if (!bookings.length) {
    el.innerHTML = `<div style="text-align:center;padding:28px;color:var(--muted)">No event bookings ${status ? "awaiting confirmation" : "found"}.</div>`;
    return;
  }
  el.innerHTML = bookings.map(b => `
  <div class="card" style="margin-bottom:12px;background:var(--card-dark)">
    <div class="flex-between" style="align-items:flex-start;gap:12px">
      <div>
        <div style="font-weight:700">#EV${b.id} · ${adminEscapeHTML(b.event_name)}</div>
        <div style="font-size:12px;color:var(--muted)">${adminEscapeHTML(b.date_display || b.event_date)} · ${adminEscapeHTML(String(b.pickup_time || "").slice(0,5))} · ${Number(b.passengers || 0)} passenger(s)</div>
        <div style="font-size:12px;color:var(--muted)">Pickup: ${adminEscapeHTML(b.pickup_address)}</div>
        <div style="font-size:12px;color:var(--muted)">Drop-off: ${adminEscapeHTML(b.dropoff_address)}</div>
        <div style="font-size:12px;color:var(--muted)">Zone: ${adminEscapeHTML(b.pickup_zone)} · ${adminEscapeHTML(b.ride_type)} ${b.package_id ? "· " + adminEscapeHTML(b.package_id) : ""}${b.booking_type ? " · " + adminEscapeHTML(b.booking_type) : ""}</div>
        ${b.selected_car ? `<div style="font-size:12px;color:var(--muted)">Car: ${adminEscapeHTML(b.selected_car)}</div>` : ""}
        ${b.discount_code ? `<div style="font-size:12px;color:var(--gold)">Discount code assigned: ${adminEscapeHTML(b.discount_code)} (${Number(b.discount_percent || 0)}%)</div>` : ""}
      </div>
      <div style="text-align:right;min-width:120px">
        <span class="badge ${b.status === "confirmed" ? "badge-green" : b.status === "cancelled" ? "badge-red" : "badge-orange"}">${adminEscapeHTML(String(b.status).replace("_"," "))}</span>
        <div style="font-family:'Cormorant Garamond',serif;font-size:22px;color:var(--gold);margin-top:6px">${fmt(b.final_price || b.price || 0)}</div>
        ${Number(b.discount_percent || 0) > 0 ? `<div style="font-size:11px;color:var(--muted)">was ${fmt(b.price)}</div>` : ""}
      </div>
    </div>
    ${b.status === "awaiting_confirmation" ? `
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      <button class="btn btn-sm btn-green" onclick="adminUpdateEventBookingStatus(${b.id},'confirmed')">Confirm Booking</button>
      <button class="btn btn-sm btn-ghost-red" onclick="adminUpdateEventBookingStatus(${b.id},'cancelled')">Cancel</button>
    </div>` : ""}
  </div>`).join("");
}

async function adminUpdateEventBookingStatus(bookingId, status) {
  const data = await api("/admin.php?action=confirm_event_booking", "POST", { booking_id: bookingId, status });
  if (data.error) { showToast(data.error, "error"); return; }
  showToast(status === "confirmed" ? "Event booking confirmed" : "Event booking cancelled", "success");
  loadAdminEventBookings("awaiting_confirmation");
}

async function loadAdminBookingsList(status) {
  const el = document.getElementById("admin-bookings-list");
  if (!el) return;
  el.innerHTML = shimmerCards(3);
  const data = await api(`/admin.php?action=get_bookings${status?"&status="+status:""}`);
  const bookings = data.bookings || [];
  if (!bookings.length) { el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No bookings found.</div>`; return; }
  el.innerHTML = bookings.map(b => `
  <div class="card" style="margin-bottom:12px">
    <div class="flex-between" style="margin-bottom:8px">
      <div>
        <div style="font-weight:700">#${b.id} · ${b.car_name}</div>
        <div style="font-size:12px;color:var(--muted)">${b.car_type==="self_drive"?"🔑 Self-Drive":"🚗 Chauffeur"} · ${capitalize(b.booking_type)}</div>
        <div style="font-size:12px;color:var(--muted)">Customer: ${b.customer_name} (${b.customer_phone})</div>
        <div style="font-size:12px;color:var(--muted)">Driver: ${b.driver_name||"N/A"}</div>
      </div>
      <div style="text-align:right">
        <span class="badge ${statusColor(b.status)}">${capitalize(b.status)}</span>
        <div style="font-family:'Cormorant Garamond',serif;font-size:20px;color:var(--gold);margin-top:4px">${fmt(b.total_price)}</div>
        ${b.security_deposit>0?`<div style="font-size:11px;color:var(--muted)">+${fmt(b.security_deposit)} deposit</div>`:""}
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      ${b.status==="confirmed"?`<button class="btn btn-sm btn-green" onclick="adminUpdateBookingStatus(${b.id},'active')">Start Trip</button>`:""}
      ${b.status==="active"?`<button class="btn btn-sm btn-gold" onclick="adminUpdateBookingStatus(${b.id},'completed')">Complete</button>`:""}
      ${b.status==="completed"&&b.security_deposit>0&&!b.deposit_released?`<button class="btn btn-sm btn-ghost" onclick="releaseDeposit(${b.id})">Release Deposit</button>`:""}
      ${b.status==="completed"?`<button class="btn btn-sm btn-ghost-red" onclick="showDamageReportModal(${b.id})">Damage Report</button>`:""}
    </div>
  </div>`).join("");
}

async function adminUpdateBookingStatus(bookingId, status) {
  const data = await api("/bookings.php?action=update_status","POST",{ booking_id:bookingId, status });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Status updated","success");
  loadAdminBookingsList("");
}

async function releaseDeposit(bookingId) {
  if (!confirm("Release security deposit to customer wallet?")) return;
  const data = await api("/bookings.php?action=release_deposit","POST",{ booking_id:bookingId });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Deposit released to customer wallet","success");
  loadAdminBookingsList("");
}

function showDamageReportModal(bookingId) {
  showModal(`
  <div class="modal-header"><h3>Damage Report</h3><button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button></div>
  <div class="modal-body">
    <div class="input-group"><label>Damage Description</label><textarea class="input-field" id="dmg-desc" rows="4" placeholder="Describe the damage..."></textarea></div>
    <div class="input-group"><label>Estimated Repair Cost (₦)</label><input type="number" class="input-field" id="dmg-cost" placeholder="0 if no damage"></div>
    <button class="btn btn-gold btn-full" onclick="submitDamageReport(${bookingId})">Submit Report</button>
  </div>`);
}

async function submitDamageReport(bookingId) {
  const desc = document.getElementById("dmg-desc").value.trim();
  const cost = parseFloat(document.getElementById("dmg-cost").value||0);
  if (!desc) { showToast("Enter damage description","error"); return; }
  const data = await api("/admin.php?action=add_damage_report","POST",{ booking_id:bookingId, description:desc, repair_cost:cost });
  if (data.error) { showToast(data.error,"error"); return; }
  closeModal();
  showToast("Damage report submitted","success");
}

// ── MEDIA ──────────────────────────────────────────────────────────────────
async function loadAdminMedia() {
  const el = document.getElementById("tab-admin-media");
  el.innerHTML = `<div class="page-header"><h2>Media <span>Review</span></h2></div><div style="padding:0 20px" id="media-list">${shimmerCards(4)}</div>`;
  const data = await api("/admin.php?action=get_media_pending");
  const msgEl = document.getElementById("media-list");
  if (!msgEl) return;
  const media = data.media || [];
  if (!media.length) { msgEl.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No pending media. All caught up!</div>`; return; }
  msgEl.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
  ${media.map(m => `
  <div class="card" style="padding:0;overflow:hidden">
    <img src="${m.url}" style="width:100%;height:160px;object-fit:cover" loading="lazy">
    <div style="padding:12px">
      <div style="font-weight:600;font-size:13px;margin-bottom:2px">${m.car_name}</div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:2px">${m.label||""} · ${m.category||""}</div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:10px">${m.driver_name||"Admin"}</div>
      <div style="display:flex;gap:6px">
        <button class="btn btn-sm btn-green" style="flex:1" onclick="reviewMedia(${m.id},'approved')">✓ Approve</button>
        <button class="btn btn-sm btn-ghost-red" style="flex:1" onclick="reviewMedia(${m.id},'rejected')">✗ Reject</button>
      </div>
    </div>
  </div>`).join("")}
  </div>`;
}

async function reviewMedia(id, status) {
  const data = await api("/admin.php?action=review_media","POST",{ media_id:id, status });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast(status==="approved"?"Photo approved!":"Photo rejected","success");
  loadAdminMedia();
}

// ── CHAT MONITOR ──────────────────────────────────────────────────────────
async function loadAdminChats() {
  const el = document.getElementById("tab-admin-chats");
  el.innerHTML = `
  <div class="page-header"><h2>Chat <span>Monitor</span></h2><p>Monitor all driver-customer conversations</p></div>
  <div style="padding:0 20px">
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button class="btn btn-sm btn-gold" onclick="loadChatsList(false)">All Chats</button>
      <button class="btn btn-sm btn-ghost-red" onclick="loadChatsList(true)">⚠️ Flagged Only</button>
    </div>
    <div id="admin-chats-list">${shimmerCards(3)}</div>
  </div>`;
  loadChatsList(false);
}

async function loadChatsList(flaggedOnly) {
  const el = document.getElementById("admin-chats-list");
  if (!el) return;
  el.innerHTML = shimmerCards(3);
  const data = await api(`/admin.php?action=get_chat_monitor${flaggedOnly?"&flagged=1":""}`);
  const convos = data.conversations || [];
  if (!convos.length) { el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No conversations found.</div>`; return; }
  el.innerHTML = convos.map(c => `
  <div class="card card-hover ${c.flag_count>0?"card-flagged":""}" style="margin-bottom:12px" onclick="openAdminChatView(${c.booking_id})">
    <div class="flex-between">
      <div>
        <div style="font-weight:700;display:flex;align-items:center;gap:8px">
          ${c.flag_count>0?`<span style="color:var(--fire)">⚠️</span>`:""}
          ${c.car_name}
        </div>
        <div style="font-size:12px;color:var(--muted)">Customer: ${c.customer_name} · Driver: ${c.driver_name||"N/A"}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">${c.last_message||""}</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:11px;color:var(--muted)">${fmtDate(c.last_at)}</div>
        <div style="font-size:11px;margin-top:4px">${c.msg_count} messages</div>
        ${c.flag_count>0?`<div style="font-size:11px;color:var(--fire);font-weight:700">${c.flag_count} flagged</div>`:""}
      </div>
    </div>
  </div>`).join("");
}

async function openAdminChatView(bookingId) {
  showModal(`
  <div class="modal-header">
    <h3>Chat Monitor — Booking #${bookingId}</h3>
    <button class="modal-close" onclick="closeModal()"><span class="material-icons-outlined">close</span></button>
  </div>
  <div class="chat-container" style="max-height:500px">
    <div class="chat-messages" id="admin-chat-view">${shimmerCards(2)}</div>
  </div>`);
  const data = await api(`/messages.php?action=get&booking_id=${bookingId}`);
  const el = document.getElementById("admin-chat-view");
  if (!el) return;
  const msgs = data.messages || [];
  if (!msgs.length) { el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No messages in this conversation.</div>`; return; }
  el.innerHTML = msgs.map(m => `
  <div class="chat-msg-admin ${m.is_flagged?"chat-flagged":""}">
    <div style="font-size:11px;color:var(--muted);margin-bottom:2px">${m.sender_name} · ${fmtTime(m.created_at)}</div>
    <div class="chat-bubble">${m.message_text} ${m.is_flagged?`<span style="color:var(--fire);font-size:11px">⚠️ Flagged</span>`:""}</div>
  </div>`).join("");
  el.scrollTop = el.scrollHeight;
}

// ── CUSTOMERS ──────────────────────────────────────────────────────────────
async function loadAdminCustomers() {
  const el = document.getElementById("tab-admin-customers");
  el.innerHTML = `<div class="page-header"><h2>Customers</h2></div><div style="padding:0 20px" id="customers-list">${shimmerCards(3)}</div>`;
  const data = await api("/admin.php?action=get_customers");
  const cEl  = document.getElementById("customers-list");
  if (!cEl) return;
  const customers = data.customers || [];
  if (!customers.length) { cEl.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No customers yet.</div>`; return; }
  cEl.innerHTML = customers.map(c => `
  <div class="card" style="margin-bottom:10px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${c.name||"—"}</div>
        <div style="font-size:12px;color:var(--muted)">+234${c.phone} · ${c.city||"—"}</div>
        <div style="font-size:12px;color:var(--muted)">${c.booking_count||0} bookings · Joined ${fmtDate(c.created_at)}</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        ${c.is_blacklisted?`<span class="badge badge-red">Blacklisted</span>`:""}
        ${!c.is_blacklisted?`<button class="btn btn-sm btn-ghost-red" onclick="blacklistCustomer(${c.id})">Blacklist</button>`:""}
      </div>
    </div>
  </div>`).join("");
}

async function blacklistCustomer(customerId) {
  const reason = prompt("Reason for blacklisting:");
  if (!reason) return;
  const data = await api("/admin.php?action=blacklist_customer","POST",{ customer_id:customerId, reason });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Customer blacklisted","success");
  loadAdminCustomers();
}

// ── REVENUE ───────────────────────────────────────────────────────────────
async function loadAdminRevenue() {
  const el = document.getElementById("tab-admin-revenue");
  el.innerHTML = `<div class="page-header"><h2>Revenue</h2></div><div style="padding:0 20px" id="revenue-body">${shimmerCards(2)}</div>`;
  const data = await api("/admin.php?action=get_revenue&period=month");
  const body = document.getElementById("revenue-body");
  if (!body) return;
  const total = (data.by_method||[]).reduce((s,m)=>s+parseFloat(m.total||0),0);
  body.innerHTML = `
  <div class="card" style="text-align:center;margin-bottom:20px">
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:8px">This Month's Revenue</div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:48px;font-weight:300;color:var(--gold)">${fmt(total)}</div>
  </div>
  <div class="card">
    <div class="section-label" style="margin-bottom:12px">By Payment Method</div>
    ${(data.by_method||[]).map(m=>`
    <div class="flex-between" style="margin-bottom:8px">
      <span style="font-size:13px;color:var(--muted)">${capitalize(m.method)} (${m.count} payments)</span>
      <span style="font-weight:700">${fmt(m.total)}</span>
    </div>`).join("")}
  </div>`;
}

// ── PAYOUTS ───────────────────────────────────────────────────────────────
async function loadAdminPayouts() {
  const el = document.getElementById("tab-admin-payouts");
  el.innerHTML = `<div class="page-header"><h2>Driver <span>Payouts</span></h2></div><div style="padding:0 20px" id="payouts-list">${shimmerCards(3)}</div>`;
  const data = await api("/admin.php?action=get_payouts");
  const pEl  = document.getElementById("payouts-list");
  if (!pEl) return;
  const payouts = data.payouts || [];
  if (!payouts.length) { pEl.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No payout requests.</div>`; return; }
  pEl.innerHTML = payouts.map(p => `
  <div class="card" style="margin-bottom:10px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${p.name}</div>
        <div style="font-size:12px;color:var(--muted)">${p.bank_name||"—"} · ${p.account_number||"—"}</div>
        <div style="font-size:12px;color:var(--muted)">${fmtDate(p.created_at)}</div>
      </div>
      <div style="text-align:right">
        <div style="font-family:'Cormorant Garamond',serif;font-size:22px;color:var(--gold)">${fmt(p.amount)}</div>
        <span class="badge ${p.status==="paid"?"badge-green":"badge-orange"}">${capitalize(p.status)}</span>
        ${p.status==="pending"?`<div style="margin-top:6px"><button class="btn btn-sm btn-green" onclick="markPayoutPaid(${p.id})">Mark Paid</button></div>`:""}
      </div>
    </div>
  </div>`).join("");
}

async function markPayoutPaid(id) {
  const data = await api("/admin.php?action=pay_payout","POST",{ payout_id:id });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Payout marked as paid","success");
  loadAdminPayouts();
}

// ── PRICING ───────────────────────────────────────────────────────────────
async function loadAdminPricing() {
  const el = document.getElementById("tab-admin-pricing");
  el.innerHTML = `<div class="page-header"><h2>Pricing</h2></div><div style="padding:0 20px" id="pricing-list">${shimmerCards(3)}</div>`;
  const data = await api("/cars.php?action=get_pricing&city=Calabar");
  const pEl  = document.getElementById("pricing-list");
  if (!pEl) return;
  pEl.innerHTML = (data.pricing||[]).map(p => `
  <div class="card" style="margin-bottom:10px">
    <div style="font-weight:700;margin-bottom:8px">${capitalize(p.category)} · ${capitalize(p.booking_type)} · ${p.city}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <div class="input-group"><label>Base Price</label><input type="number" class="input-field" id="p-base-${p.id}" value="${p.base_price}"></div>
      <div class="input-group"><label>Per Hour</label><input type="number" class="input-field" id="p-hour-${p.id}" value="${p.per_hour_price}"></div>
      <div class="input-group"><label>Per Day</label><input type="number" class="input-field" id="p-day-${p.id}" value="${p.per_day_price}"></div>
      <div class="input-group"><label>Per KM</label><input type="number" class="input-field" id="p-km-${p.id}" value="${p.per_km_price}"></div>
    </div>
    <button class="btn btn-gold btn-full" onclick="savePricing(${p.id})"><span class="material-icons-outlined">save</span> Save</button>
  </div>`).join("");
}

async function savePricing(id) {
  const data = await api("/admin.php?action=update_pricing","POST",{
    id,
    base_price:     parseFloat(document.getElementById(`p-base-${id}`).value),
    per_hour_price: parseFloat(document.getElementById(`p-hour-${id}`).value),
    per_day_price:  parseFloat(document.getElementById(`p-day-${id}`).value),
    per_km_price:   parseFloat(document.getElementById(`p-km-${id}`).value),
  });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Pricing updated","success");
}

// ── PROMOS ────────────────────────────────────────────────────────────────
async function loadAdminPromos() {
  const el = document.getElementById("tab-admin-promos");
  el.innerHTML = `
  <div class="page-header"><h2>Promo <span>Codes</span></h2></div>
  <div style="padding:0 20px">
    <div class="card" style="margin-bottom:20px">
      <div class="section-label" style="margin-bottom:12px">Create Promo Code</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="input-group"><label>Code</label><input class="input-field" id="promo-code" placeholder="e.g. LAUNCH20"></div>
        <div class="input-group"><label>Discount %</label><input type="number" class="input-field" id="promo-discount" placeholder="20" min="1" max="100"></div>
        <div class="input-group"><label>Max Uses</label><input type="number" class="input-field" id="promo-uses" placeholder="100"></div>
        <div class="input-group"><label>Expiry Date</label><input type="date" class="input-field" id="promo-expiry"></div>
      </div>
      <button class="btn btn-gold btn-full" onclick="createPromo()"><span class="material-icons-outlined">add</span> Create Promo</button>
    </div>
    <div id="promos-list">${shimmerCards(2)}</div>
  </div>`;
  loadPromosList();
}

async function loadPromosList() {
  const data = await api("/admin.php?action=get_promos");
  const el   = document.getElementById("promos-list");
  if (!el) return;
  const promos = data.promos || [];
  if (!promos.length) { el.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">No promo codes yet.</div>`; return; }
  el.innerHTML = promos.map(p => `
  <div class="card" style="margin-bottom:10px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700;font-family:'Space Mono',monospace">${p.code}</div>
        <div style="font-size:12px;color:var(--muted)">${p.discount_percent}% off · ${p.uses_count||0}/${p.max_uses} uses · Expires ${fmtDate(p.expiry_date)}</div>
      </div>
      <span class="badge ${p.is_active?"badge-green":"badge-red"}">${p.is_active?"Active":"Inactive"}</span>
    </div>
  </div>`).join("");
}

async function createPromo() {
  const data = await api("/admin.php?action=manage_promo","POST",{
    code:             document.getElementById("promo-code").value.trim().toUpperCase(),
    discount_percent: parseInt(document.getElementById("promo-discount").value),
    max_uses:         parseInt(document.getElementById("promo-uses").value),
    expiry_date:      document.getElementById("promo-expiry").value,
  });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("Promo code created!","success");
  loadAdminPromos();
}

// ── HOME BOOKING CARD ──────────────────────────────────────────────────────
async function loadAdminBookingCard() {
  const el = document.getElementById("tab-admin-booking-card");
  el.innerHTML = `
  <div class="page-header"><h2>Home <span>Booking Card</span></h2></div>
  <div style="padding:0 20px" id="booking-card-admin-body">${shimmerCards(2)}</div>`;

  const data = await api("/admin.php?action=get_home_booking_card");
  const card = data.card || {
    id: 1,
    event_key: "grovveyard",
    modal_id: "gyOverlay-grovveyard",
    title: "Tap Me To Book",
    subtitle: "Reserve your ride to & from the venue",
    eyebrow: "Grovve Yard · Event shuttle",
    event_date: "2026-07-13",
    image_url: "",
    is_active: 1,
  };
  const body = document.getElementById("booking-card-admin-body");
  if (!body) return;

  body.innerHTML = `
  <div class="admin-booking-card-editor">
    <div class="card">
      <input type="hidden" id="home-card-id" value="${adminEscapeHTML(card.id || 1)}">
      <label class="auth-remember-label" style="margin-bottom:14px">
        <input type="checkbox" id="home-card-active" ${Number(card.is_active) ? "checked" : ""}> Show this card on home screen
      </label>
      <div class="input-group">
        <label>Eyebrow</label>
        <input class="input-field" id="home-card-eyebrow" value="${adminEscapeHTML(card.eyebrow || "")}" placeholder="Grovve Yard · Event shuttle">
      </div>
      <div class="input-group">
        <label>Event Date</label>
        <input type="date" class="input-field" id="home-card-date" value="${adminEscapeHTML(card.event_date || "")}">
      </div>
      <div class="input-group">
        <label>Title</label>
        <input class="input-field" id="home-card-title" value="${adminEscapeHTML(card.title || "")}" placeholder="Tap Me To Book">
      </div>
      <div class="input-group">
        <label>Subtitle</label>
        <input class="input-field" id="home-card-subtitle" value="${adminEscapeHTML(card.subtitle || "")}" placeholder="Reserve your ride to & from the venue">
      </div>
      <div class="input-group">
        <label>Card Image</label>
        <input type="file" class="input-field" id="home-card-image-file" accept="image/jpeg,image/png,image/webp,image/gif" onchange="uploadHomeBookingCardImage()">
        <input type="hidden" id="home-card-image" value="${adminEscapeHTML(card.image_url || "")}">
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
          <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('home-card-image-file')?.click()">
            <span class="material-icons-outlined">cloud_upload</span> Upload to Cloudinary
          </button>
          <span id="home-card-upload-status" style="font-size:12px;color:var(--muted)">${card.image_url ? "Image uploaded" : "No image uploaded"}</span>
        </div>
        <input class="input-field" id="home-card-image-display" value="${adminEscapeHTML(card.image_url || "")}" readonly placeholder="Cloudinary URL appears here" style="margin-top:8px;font-size:11px">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="input-group">
          <label>Event Key</label>
          <input class="input-field" id="home-card-event" value="${adminEscapeHTML(card.event_key || "grovveyard")}">
        </div>
        <div class="input-group">
          <label>Modal ID</label>
          <input class="input-field" id="home-card-modal" value="${adminEscapeHTML(card.modal_id || "gyOverlay-grovveyard")}">
        </div>
      </div>
      <button class="btn btn-gold btn-full" onclick="saveHomeBookingCard()">
        <span class="material-icons-outlined">save</span> Save Booking Card
      </button>
    </div>
    <div class="card">
      <div class="section-label" style="margin-bottom:12px">Preview</div>
      <button type="button" class="gy-trigger-card" id="home-card-preview" style="max-width:none;margin-bottom:0">
        <span class="gy-trigger-overlay"></span>
        <span class="gy-trigger-content">
          <span class="gy-trigger-eyebrow"></span>
          <span class="gy-trigger-title"></span>
          <span class="gy-trigger-sub"></span>
        </span>
      </button>
    </div>
  </div>`;

  ["home-card-eyebrow","home-card-date","home-card-title","home-card-subtitle","home-card-image-display"].forEach(id => {
    document.getElementById(id)?.addEventListener("input", updateHomeBookingCardPreview);
  });
  updateHomeBookingCardPreview();
}

function updateHomeBookingCardPreview() {
  const preview = document.getElementById("home-card-preview");
  if (!preview) return;
  const eyebrow = document.getElementById("home-card-eyebrow")?.value || "Event booking";
  const eventDate = document.getElementById("home-card-date")?.value || "";
  const title = document.getElementById("home-card-title")?.value || "Tap Me To Book";
  const subtitle = document.getElementById("home-card-subtitle")?.value || "Reserve your ride";
  const imageUrl = document.getElementById("home-card-image")?.value || "";
  preview.querySelector(".gy-trigger-eyebrow").textContent = [eyebrow, formatHomeCardDate(eventDate)].filter(Boolean).join(" · ");
  preview.querySelector(".gy-trigger-title").textContent = title;
  preview.querySelector(".gy-trigger-sub").textContent = subtitle;
  preview.style.backgroundImage = imageUrl ? `url('${imageUrl.replace(/'/g, "%27")}')` : "";
}

function formatHomeCardDate(value) {
  if (!value) return "";
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

async function saveHomeBookingCard() {
  const payload = {
    id: Number(document.getElementById("home-card-id")?.value || 1),
    is_active: document.getElementById("home-card-active")?.checked ? 1 : 0,
    eyebrow: document.getElementById("home-card-eyebrow")?.value.trim() || "",
    event_date: document.getElementById("home-card-date")?.value || "",
    title: document.getElementById("home-card-title")?.value.trim() || "",
    subtitle: document.getElementById("home-card-subtitle")?.value.trim() || "",
    image_url: document.getElementById("home-card-image")?.value.trim() || "",
    event_key: document.getElementById("home-card-event")?.value.trim() || "grovveyard",
    modal_id: document.getElementById("home-card-modal")?.value.trim() || "gyOverlay-grovveyard",
  };
  const data = await api("/admin.php?action=update_home_booking_card", "POST", payload);
  if (data.error) { showToast(data.error, "error"); return; }
  showToast("Booking card updated", "success");
  loadAdminBookingCard();
}

async function uploadHomeBookingCardImage() {
  const input = document.getElementById("home-card-image-file");
  const status = document.getElementById("home-card-upload-status");
  const file = input?.files?.[0];
  if (!file) return;
  if (!file.type.startsWith("image/")) {
    showToast("Choose an image file", "error");
    input.value = "";
    return;
  }
  if (file.size > 10 * 1024 * 1024) {
    showToast("Image is too large. Max 10MB.", "error");
    input.value = "";
    return;
  }

  if (status) status.textContent = "Uploading...";
  try {
    const cfg = await api("/admin.php?action=get_cloudinary_upload_config");
    if (cfg.error) throw new Error(cfg.error);
    const form = new FormData();
    form.append("file", file);
    form.append("upload_preset", cfg.upload_preset);

    const res = await fetch(`https://api.cloudinary.com/v1_1/${encodeURIComponent(cfg.cloud_name)}/image/upload`, {
      method: "POST",
      body: form,
    });
    const data = await res.json();
    if (!res.ok || !data.secure_url) {
      throw new Error(data.error?.message || "Cloudinary upload failed");
    }

    const hidden = document.getElementById("home-card-image");
    const display = document.getElementById("home-card-image-display");
    if (hidden) hidden.value = data.secure_url;
    if (display) display.value = data.secure_url;
    if (status) status.textContent = "Uploaded to Cloudinary";
    updateHomeBookingCardPreview();
    showToast("Image uploaded to Cloudinary", "success");
  } catch (err) {
    if (status) status.textContent = "Upload failed";
    showToast(err.message || "Cloudinary upload failed", "error");
  } finally {
    input.value = "";
  }
}

// ── CITIES ────────────────────────────────────────────────────────────────
async function loadAdminCities() {
  const el = document.getElementById("tab-admin-cities");
  el.innerHTML = `
  <div class="page-header"><h2>Cities</h2></div>
  <div style="padding:0 20px">
    <div class="card" style="margin-bottom:20px">
      <div class="section-label" style="margin-bottom:12px">Add City</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="input-group"><label>City Name</label><input class="input-field" id="city-name" placeholder="e.g. Uyo"></div>
        <div class="input-group"><label>State</label><input class="input-field" id="city-state" placeholder="e.g. Akwa Ibom"></div>
      </div>
      <button class="btn btn-gold btn-full" onclick="addCity()"><span class="material-icons-outlined">add_location</span> Add City</button>
    </div>
    <div id="cities-list">${shimmerCards(2)}</div>
  </div>`;
  loadCitiesList();
}

async function loadCitiesList() {
  const data = await api("/admin.php?action=get_cities");
  const el   = document.getElementById("cities-list");
  if (!el) return;
  el.innerHTML = (data.cities||[]).map(c => `
  <div class="card" style="margin-bottom:8px">
    <div class="flex-between">
      <div>
        <div style="font-weight:700">${c.name}, ${c.state}</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="badge ${c.is_active?"badge-green":"badge-red"}">${c.is_active?"Active":"Inactive"}</span>
        <button class="btn btn-sm btn-ghost" onclick="toggleCity(${c.id})">${c.is_active?"Disable":"Enable"}</button>
      </div>
    </div>
  </div>`).join("");
}

async function addCity() {
  const name  = document.getElementById("city-name").value.trim();
  const state = document.getElementById("city-state").value.trim();
  if (!name||!state) { showToast("Enter city name and state","error"); return; }
  const data = await api("/admin.php?action=add_city","POST",{ name, state });
  if (data.error) { showToast(data.error,"error"); return; }
  showToast("City added!","success");
  loadAdminCities();
}

async function toggleCity(id) {
  const data = await api("/admin.php?action=toggle_city","POST",{ city_id:id });
  if (data.error) { showToast(data.error,"error"); return; }
  loadCitiesList();
}
