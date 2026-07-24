// ── MAPS.JS — Mapbox GL JS ────────────────────────────────────────────────────
const MAPBOX_TOKEN = "pk.eyJ1IjoiYm9va2thbSIsImEiOiJjbW5uYXRyaXYxZm9lMnByNjc1OHNycG5vIn0.zUAwUDojhM0ROm2l58J4kg";
mapboxgl.accessToken = MAPBOX_TOKEN;

const BOOKKAM_COORDS = {
  CALABAR:         [8.3220, 4.9517],
  IKOM:            [8.7086, 5.9646],
  LAGOS:           [3.3792, 6.5244],
  ABUJA:           [7.3986, 9.0765],
  "PORT HARCOURT": [7.0498, 4.8156],
  DEFAULT:         [8.3220, 4.9517],
};

// Bias geocoding results toward Calabar/Cross River State
const GEOCODE_PROXIMITY = "8.3220,4.9517";

function dbCoordsToMapbox(str) {
  if (!str) return null;
  const p = str.split(",").map(Number);
  return [p[1], p[0]]; // DB stores lat,lng → Mapbox wants [lng,lat]
}

let customerTrackingMap  = null;
let driverNavMap         = null;
let driverMarker         = null;
let pickupMarker         = null;
let destMarker           = null;
let routeLayerId         = null;
let searchDebounceTimer  = null;
let locationPollInterval = null;

// ── Theme-aware map style ─────────────────────────────────────────────────────
function getMapStyle() {
  const theme = document.documentElement.getAttribute("data-theme") || "dark";
  return theme === "dark"
    ? "mapbox://styles/mapbox/dark-v11"
    : "mapbox://styles/mapbox/light-v11";
}

// Called from toggleTheme() in app.js when user switches light/dark
function refreshMapTiles() {
  const style = getMapStyle();
  [customerTrackingMap, driverNavMap].forEach(map => {
    if (!map) return;
    map.once("style.load", () => {
      // Markers persist independently — nothing extra needed here
    });
    map.setStyle(style);
  });
}

// ── Marker helpers ────────────────────────────────────────────────────────────
function makeMarkerEl(iconName, color) {
  const el = document.createElement("div");
  el.style.cssText = "display:flex;align-items:center;justify-content:center;filter:drop-shadow(0 2px 6px rgba(0,0,0,0.6))";
  el.innerHTML = `<span class="material-icons-outlined" style="font-size:30px;color:${color}">${iconName}</span>`;
  return el;
}

function makeDriverMarkerEl() {
  const el = document.createElement("div");
  el.style.cssText = "background:#00E87A;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.5);border:2px solid #fff";
  el.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#000"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>`;
  return el;
}

// ── Mapbox Geocoding — replaces Nominatim (better Nigerian coverage) ──────────
async function geocodeAddress(query, callback) {
  if (!query || query.length < 3) { callback([]); return; }
  try {
    const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json`
      + `?access_token=${MAPBOX_TOKEN}`
      + `&country=NG`
      + `&proximity=${GEOCODE_PROXIMITY}`
      + `&limit=6`;
    const res  = await fetch(url);
    const data = await res.json();
    const results = (data.features || []).map(f => ({
      place_name: f.place_name,
      short_name: f.text,
      context:    (f.context || []).map(c => c.text).join(", "),
      lng:        f.center[0],
      lat:        f.center[1],
    }));
    callback(results);
  } catch(e) { callback([]); }
}

// Legacy alias — keeps any old searchAddress() calls working
async function searchAddress(query, callback) {
  geocodeAddress(query, callback);
}

// ── Mapbox Reverse Geocoding — replaces Nominatim reverse ────────────────────
async function reverseGeocode(lng, lat) {
  try {
    const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${lng},${lat}.json`
      + `?access_token=${MAPBOX_TOKEN}`
      + `&types=address,poi,place`
      + `&limit=1`;
    const res  = await fetch(url);
    const data = await res.json();
    if (data.features && data.features.length) return data.features[0].place_name;
    return null;
  } catch(e) { return null; }
}

// ── Route drawing ─────────────────────────────────────────────────────────────
async function drawRoute(map, fromLngLat, toLngLat) {
  if (!fromLngLat || !toLngLat) return;
  try {
    const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${fromLngLat[0]},${fromLngLat[1]};${toLngLat[0]},${toLngLat[1]}?geometries=geojson&access_token=${MAPBOX_TOKEN}`;
    const res  = await fetch(url);
    const data = await res.json();
    if (!data.routes || !data.routes.length) return;
    const geojson = { type: "Feature", geometry: data.routes[0].geometry };
    const layerId = "route-" + Date.now();
    if (routeLayerId) {
      try { map.removeLayer(routeLayerId); map.removeSource(routeLayerId); } catch(e) {}
    }
    routeLayerId = layerId;
    map.addSource(layerId, { type: "geojson", data: geojson });
    map.addLayer({
      id: layerId, type: "line", source: layerId,
      layout: { "line-join": "round", "line-cap": "round" },
      paint:  { "line-color": "#C97D3A", "line-width": 4, "line-dasharray": [2, 2] }
    });
    const coords = data.routes[0].geometry.coordinates;
    const bounds = coords.reduce((b, c) => b.extend(c), new mapboxgl.LngLatBounds(coords[0], coords[0]));
    map.fitBounds(bounds, { padding: 60 });
  } catch(e) { console.warn("Route error:", e); }
}

// ── Customer tracking map ─────────────────────────────────────────────────────
function initCustomerTrackingMap(containerId, booking) {
  const container = document.getElementById(containerId);
  if (!container) return;
  if (customerTrackingMap) { customerTrackingMap.remove(); customerTrackingMap = null; }
  const city   = (booking?.city || "").toUpperCase();
  const center = BOOKKAM_COORDS[city] || BOOKKAM_COORDS.DEFAULT;
  customerTrackingMap = new mapboxgl.Map({ container: containerId, style: getMapStyle(), center, zoom: 13 });
  customerTrackingMap.addControl(new mapboxgl.NavigationControl(), "bottom-right");
  customerTrackingMap.on("load", () => {
    const pickupLL = booking?.pickup_coords  ? dbCoordsToMapbox(booking.pickup_coords)  : center;
    const destLL   = booking?.dropoff_coords ? dbCoordsToMapbox(booking.dropoff_coords) : [center[0]+0.02, center[1]+0.02];
    const driverLL = [pickupLL[0]-0.005, pickupLL[1]-0.008];
    pickupMarker = new mapboxgl.Marker({ element: makeMarkerEl("my_location", "var(--cyan)") })
      .setLngLat(pickupLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>Pickup</b><br>${booking?.pickup_address || ""}`))
      .addTo(customerTrackingMap);
    destMarker = new mapboxgl.Marker({ element: makeMarkerEl("location_on", "var(--gold)") })
      .setLngLat(destLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>Destination</b><br>${booking?.dropoff_address || ""}`))
      .addTo(customerTrackingMap);
    driverMarker = new mapboxgl.Marker({ element: makeDriverMarkerEl() })
      .setLngLat(driverLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>${booking?.driver_name || "Your Driver"}</b><br>On the way`))
      .addTo(customerTrackingMap);
    drawRoute(customerTrackingMap, driverLL, destLL);
  });
  return customerTrackingMap;
}

// ── Self-drive map ────────────────────────────────────────────────────────────
function initSelfDriveMap(containerId, booking) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const city   = (booking?.city || "").toUpperCase();
  const center = BOOKKAM_COORDS[city] || BOOKKAM_COORDS.DEFAULT;
  const map = new mapboxgl.Map({ container: containerId, style: getMapStyle(), center, zoom: 14 });
  map.addControl(new mapboxgl.NavigationControl(), "bottom-right");
  map.on("load", () => {
    const pickupLL = booking?.pickup_coords ? dbCoordsToMapbox(booking.pickup_coords) : center;
    new mapboxgl.Marker({ element: makeMarkerEl("my_location", "var(--cyan)") })
      .setLngLat(pickupLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>Pickup</b><br>${booking?.pickup_address || ""}`).addTo(map))
      .addTo(map);
  });
  return map;
}

// ── Driver location polling ───────────────────────────────────────────────────
function startPollingDriverLocation(bookingId) {
  if (locationPollInterval) clearInterval(locationPollInterval);
  locationPollInterval = setInterval(async () => {
    const data = await api(`/bookings.php?action=get_driver_location&booking_id=${bookingId}`);
    if (data.location && driverMarker && customerTrackingMap) {
      const lng = parseFloat(data.location.lng), lat = parseFloat(data.location.lat);
      driverMarker.setLngLat([lng, lat]);
      customerTrackingMap.panTo([lng, lat]);
    }
  }, 5000);
}

function stopPollingDriverLocation() {
  if (locationPollInterval) { clearInterval(locationPollInterval); locationPollInterval = null; }
}

// ── Driver navigation map ─────────────────────────────────────────────────────
function initDriverNavMap(containerId, activeBooking) {
  const container = document.getElementById(containerId);
  if (!container) return;
  if (driverNavMap) { driverNavMap.remove(); driverNavMap = null; }
  const city   = (activeBooking?.city || "").toUpperCase();
  const center = BOOKKAM_COORDS[city] || BOOKKAM_COORDS.DEFAULT;
  driverNavMap = new mapboxgl.Map({ container: containerId, style: getMapStyle(), center, zoom: 13 });
  driverNavMap.addControl(new mapboxgl.NavigationControl(), "bottom-right");
  driverNavMap.on("load", () => {
    if (!activeBooking) return;
    const pickupLL = activeBooking.pickup_coords  ? dbCoordsToMapbox(activeBooking.pickup_coords)  : center;
    const destLL   = activeBooking.dropoff_coords ? dbCoordsToMapbox(activeBooking.dropoff_coords) : [center[0]+0.02, center[1]+0.02];
    new mapboxgl.Marker({ element: makeMarkerEl("my_location", "var(--cyan)") })
      .setLngLat(pickupLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>Pickup</b><br>${activeBooking.pickup_address || ""}`).addTo(driverNavMap))
      .addTo(driverNavMap);
    new mapboxgl.Marker({ element: makeMarkerEl("location_on", "var(--gold)") })
      .setLngLat(destLL)
      .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML(`<b>Destination</b><br>${activeBooking.dropoff_address || ""}`))
      .addTo(driverNavMap);
    drawRoute(driverNavMap, pickupLL, destLL);
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(pos => {
        const myLL = [pos.coords.longitude, pos.coords.latitude];
        new mapboxgl.Marker({ element: makeDriverMarkerEl() })
          .setLngLat(myLL)
          .setPopup(new mapboxgl.Popup({ offset: 25 }).setHTML("You are here"))
          .addTo(driverNavMap);
        driverNavMap.setCenter(myLL);
        driverNavMap.setZoom(15);
      }, () => {});
    }
  });
  return driverNavMap;
}

// ── Address input widget with Mapbox Geocoding ────────────────────────────────
function initMapAddressInput(inputId, suggestionsId, onSelect) {
  const input       = document.getElementById(inputId);
  const suggestions = document.getElementById(suggestionsId);
  if (!input || !suggestions) return;
  let debounce = null;
  input.addEventListener("input", () => {
    clearTimeout(debounce);
    const val = input.value.trim();
    if (val.length < 3) { suggestions.style.display = "none"; return; }
    debounce = setTimeout(() => {
      geocodeAddress(val, results => {
        if (!results.length) { suggestions.style.display = "none"; return; }
        suggestions.style.display = "block";
        suggestions.innerHTML = results.map((r, i) =>
          `<div class="map-suggestion-item" data-idx="${i}" data-lat="${r.lat}" data-lng="${r.lng}" data-display="${r.place_name.replace(/"/g, "&quot;")}">
            <span class="material-icons-outlined" style="color:var(--gold);flex-shrink:0">location_on</span>
            <span style="display:flex;flex-direction:column;gap:1px;min-width:0">
              <span style="color:var(--text);font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r.short_name}</span>
              ${r.context ? `<span style="color:var(--muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r.context}</span>` : ""}
            </span>
          </div>`
        ).join("");
        suggestions.querySelectorAll(".map-suggestion-item").forEach(item => {
          item.addEventListener("click", () => {
            input.value        = item.dataset.display;
            input.dataset.lat  = item.dataset.lat;
            input.dataset.lng  = item.dataset.lng;
            suggestions.style.display = "none";
            onSelect({ lat: parseFloat(item.dataset.lat), lng: parseFloat(item.dataset.lng), display: item.dataset.display });
          });
        });
      });
    }, 400);
  });
  document.addEventListener("click", e => {
    if (!e.target.closest(`#${inputId}`) && !e.target.closest(`#${suggestionsId}`)) {
      suggestions.style.display = "none";
    }
  });
}
