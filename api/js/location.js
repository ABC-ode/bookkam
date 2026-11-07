// -- LOCATION.JS -- pickup location helpers ---------------------------------------------------------------------
// * The browser is asked for the position ONLY when the customer taps "Use my current location" (never on page or modal load).
// * A position only SUGGESTS a city. It never switches the city and never changes the city a booking is made in.
// * "Choose location on map" is disabled, with an explanation, unless the server enabled Mapbox (see loadMapbox in maps.js).
// * The pickup text and coordinates live on the pickup <input> (data-lat / data-lng); the booking sends them as "lat,lng".

const BK_NEAR_CITY_KM = 30;   // a metro-area radius: a spot 45 km away is in another city, not "near" this one
let bkLocCtx    = null;     // { inputId, carCity, onChange } for the modal that is open
let bkCityCache = null;     // active city names, fetched once

function bkValidCoords(lat, lng) {
  if (lat === null || lng === null || lat === "" || lng === "" || typeof lat === "boolean" || typeof lng === "boolean") return false;
  const a = Number(lat), b = Number(lng);
  return Number.isFinite(a) && Number.isFinite(b) && Math.abs(a) <= 90 && Math.abs(b) <= 180 && !(a === 0 && b === 0);
}
// "lat,lng" in the shape the server accepts (max 10 decimals), or null.
function bkCoordString(lat, lng) { return bkValidCoords(lat, lng) ? Number(lat).toFixed(6) + "," + Number(lng).toFixed(6) : null; }

function bkHaversineKm(lat1, lng1, lat2, lng2) {
  const r = Math.PI / 180, dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) ** 2;
  return 6371 * 2 * Math.asin(Math.sqrt(h));
}
// The closest of the given city names within maxKm, or null. BOOKKAM_COORDS holds [lng, lat] per city.
function bkNearestCity(lat, lng, cityNames, maxKm) {
  if (!bkValidCoords(lat, lng)) return null;
  let best = null, bestKm = maxKm || BK_NEAR_CITY_KM;
  for (const name of (cityNames || [])) {
    const c = BOOKKAM_COORDS[String(name).toUpperCase()];
    if (!c || String(name).toUpperCase() === "DEFAULT") continue;
    const km = bkHaversineKm(Number(lat), Number(lng), c[1], c[0]);
    if (km <= bestKm) { best = name; bestKm = km; }
  }
  return best;
}

function bkSetPickup(inputEl, p) {
  if (!inputEl) return;
  if (p.address !== undefined) inputEl.value = p.address;
  if (bkValidCoords(p.lat, p.lng)) { inputEl.dataset.lat = String(Number(p.lat)); inputEl.dataset.lng = String(Number(p.lng)); inputEl.dataset.source = p.source || "typed"; }
  else { delete inputEl.dataset.lat; delete inputEl.dataset.lng; delete inputEl.dataset.source; }
}
function bkPickupCoords(inputEl) { return inputEl && inputEl.dataset ? bkCoordString(inputEl.dataset.lat, inputEl.dataset.lng) : null; }

function bkLocNote(text, kind) {
  const el = document.getElementById("bk-loc-note");
  if (!el) return;
  el.textContent = text || "";
  el.style.color = kind === "error" ? "var(--red)" : kind === "ok" ? "var(--green)" : "var(--muted)";
}

function bkLocationControlsHTML() {
  return `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
    <button type="button" class="btn btn-ghost btn-sm" id="bk-loc-gps" onclick="bkLocateClick()"><span class="material-icons-outlined" style="font-size:16px">my_location</span> Use my current location</button>
    <button type="button" class="btn btn-ghost btn-sm" id="bk-loc-map" disabled aria-disabled="true" title="Choosing on the map is not available yet" onclick="bkChooseOnMapClick()"><span class="material-icons-outlined" style="font-size:16px">map</span> Choose location on map</button>
    <button type="button" class="btn btn-ghost btn-sm" id="bk-loc-city" onclick="showCitySelector()"><span class="material-icons-outlined" style="font-size:16px">location_city</span> Choose city manually</button>
  </div>
  <div id="bk-loc-note" style="font-size:12px;margin-top:6px;color:var(--muted)">Choosing a point on the map is not available yet. Type the pickup address, or use your current location.</div>`;
}

// Call right after the controls are on the page. Enables the map button only when Mapbox is really on.
function bkInitLocationControls(ctx) {
  bkLocCtx = ctx;
  loadMapbox().then(ok => {
    const btn = document.getElementById("bk-loc-map");
    if (!btn || bkLocCtx !== ctx) return;
    if (ok) {
      btn.disabled = false; btn.removeAttribute("aria-disabled"); btn.title = "Pin the pickup point on a map";
      bkLocNote("Type an address, use your current location, or pin the point on the map.", "info");
    }   // else: stays disabled and the note above explains why
  });
}

async function bkActiveCities() {
  if (bkCityCache) return bkCityCache;
  const data = await api("/admin.php?action=get_cities");
  bkCityCache = (data && data.cities || []).filter(c => Number(c.is_active) === 1).map(c => String(c.name));
  return bkCityCache;
}

// Explicit tap only. Fills the pickup box and saves the coordinates; only ever SUGGESTS a city (text, no switch).
function bkLocateClick() {
  const ctx = bkLocCtx, input = ctx && document.getElementById(ctx.inputId);
  if (!input) return;
  if (!navigator.geolocation) { bkLocNote("This phone or browser cannot share its location. Type your pickup address instead.", "error"); return; }
  const btn = document.getElementById("bk-loc-gps"); if (btn) btn.disabled = true;
  bkLocNote("Getting your location...", "info");
  navigator.geolocation.getCurrentPosition(async pos => {
    if (btn) btn.disabled = false;
    const lat = pos.coords.latitude, lng = pos.coords.longitude;
    if (!bkValidCoords(lat, lng)) { bkLocNote("Could not read a valid location. Type your pickup address instead.", "error"); return; }
    let label = null;
    if (await loadMapbox()) label = await reverseGeocode(lng, lat);
    bkSetPickup(input, { address: label || "My current location", lat, lng, source: "gps" });
    let msg = label ? "Pickup set from your current location." : "Pickup point saved from your current location. Add a street or landmark so the driver can find you.";
    try {
      const near = bkNearestCity(lat, lng, await bkActiveCities());
      if (near && ctx.carCity && near.toUpperCase() !== String(ctx.carCity).toUpperCase()) msg += " You seem to be near " + near + ", but this ride is in " + ctx.carCity + " and will be booked there. Use 'Choose city manually' to see " + near + " cars.";
      else if (!near) msg += " Your location is outside our service cities.";
    } catch (e) { /* the suggestion is optional */ }
    bkLocNote(msg, "ok");
    if (typeof ctx.onChange === "function") ctx.onChange();
  }, err => {
    if (btn) btn.disabled = false;
    const m = err && err.code === 1 ? "Location permission was denied. You can type your pickup address instead."
            : err && err.code === 3 ? "Getting your location took too long. Try again, or type your pickup address."
            : "Your location is not available right now. Type your pickup address instead.";
    bkLocNote(m, "error");
  }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
}

// Map picker (Mapbox only): drag the map under a fixed pin, then confirm.
async function bkChooseOnMapClick() {
  const ctx = bkLocCtx, input = ctx && document.getElementById(ctx.inputId);
  if (!input) return;
  if (!(await loadMapbox())) { bkLocNote("Choosing on the map is not available yet. Type the pickup address instead.", "error"); return; }
  const city  = String(ctx.carCity || "Calabar").toUpperCase();
  const start = bkValidCoords(input.dataset.lat, input.dataset.lng) ? [Number(input.dataset.lng), Number(input.dataset.lat)] : (BOOKKAM_COORDS[city] || BOOKKAM_COORDS.DEFAULT);
  const ov = document.createElement("div");
  ov.id = "bk-map-picker";
  ov.style.cssText = "position:fixed;inset:0;z-index:5000;background:var(--bg-deep,#020b18);display:flex;flex-direction:column";
  ov.innerHTML = `<div style="padding:12px 16px;font-weight:700;color:var(--text)">Choose pickup location</div>
    <div style="position:relative;flex:1"><div id="bk-map-picker-map" style="position:absolute;inset:0"></div>
      <span class="material-icons-outlined" style="position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);font-size:40px;color:var(--gold);pointer-events:none">location_on</span></div>
    <div style="display:flex;gap:8px;padding:12px 16px"><button type="button" class="btn btn-ghost" id="bk-map-cancel" style="flex:1">Cancel</button><button type="button" class="btn btn-gold" id="bk-map-confirm" style="flex:2">Confirm pickup location</button></div>`;
  document.body.appendChild(ov);
  const map = new mapboxgl.Map({ container: "bk-map-picker-map", style: getMapStyle(), center: start, zoom: 15 });
  map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), "bottom-right");
  const close = () => { try { map.remove(); } catch (e) {} ov.remove(); };
  document.getElementById("bk-map-cancel").onclick = close;
  document.getElementById("bk-map-confirm").onclick = async () => {
    const c = map.getCenter();
    if (!bkValidCoords(c.lat, c.lng)) { showToast("Could not read that point. Move the map and try again.", "error"); return; }
    const label = await reverseGeocode(c.lng, c.lat);
    bkSetPickup(input, { address: label || "Pinned location", lat: c.lat, lng: c.lng, source: "map" });
    close();
    bkLocNote("Pickup location confirmed on the map.", "ok");
    if (typeof ctx.onChange === "function") ctx.onChange();
  };
}
