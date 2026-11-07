// Batch 4: location, city selection, map button, and "Mapbox only when the server says so".
// Runs the REAL js files (utils, maps, location, customer) in a vm context with a stubbed browser: no network, no Mapbox account, no token.
//   node tests/functional/location_frontend.test.js
const fs = require("fs"), path = require("path"), vm = require("vm");
const API = path.join(__dirname, "..", "..", "api");
const src = f => fs.readFileSync(path.join(API, f), "utf8");
let failed = 0;
const check = (n, ok, d) => { console.log((ok ? "PASS " : "FAIL ") + n + (!ok && d !== undefined ? " :: " + JSON.stringify(d).slice(0, 220) : "")); if (!ok) failed++; };
let finished = false;   // a hung promise would let node exit quietly with no failure: make that a failure
process.on("exit", () => { if (!finished) { console.log("FAIL the test run stopped early (something never finished)"); process.exitCode = 1; } });
const withTimeout = (p, ms = 400) => Promise.race([p, new Promise(r => setTimeout(() => r("TIMEOUT"), ms))]);
const flush = async () => { for (let i = 0; i < 6; i++) await new Promise(r => setImmediate(r)); };

// ---------- static checks ----------
const index = src("index.php"), sw = src("sw.js"), maps = src("js/maps.js"), customer = src("js/customer.js");
const walk = d => fs.readdirSync(d, { withFileTypes: true }).flatMap(e => e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]);
const leaked = walk(API).filter(f => /\.(php|js|html|json|md|css)$/.test(f)).filter(f => /pk\.eyJ[A-Za-z0-9_-]{20,}/.test(fs.readFileSync(f, "utf8")));
check("S1 no Mapbox token is stored anywhere under api/ (including the stale copies)", leaked.length === 0, leaked);
check("S2 index.php loads no Mapbox script or stylesheet at page load", !/api\.mapbox\.com\/mapbox-gl-js/.test(index));
check("S3 the event popup has no token and asks loadMapbox() first", !/GY_TOKEN/.test(index) && /loadMapbox\(\)\.then/.test(index));
check("S4 the ride modal no longer uses Leaflet (it is not loaded anywhere)", !/\bL\.(map|marker|latLng|polyline|tileLayer|divIcon)\b/.test(customer));
check("S5 geolocation is requested only inside location.js (never on modal open)", (customer.match(/getCurrentPosition/g) || []).length === 0 && (src("js/location.js").match(/getCurrentPosition/g) || []).length === 1);
const tagVer = {}; for (const m of index.matchAll(/(?:src|href)="(\/(?:js|css)\/[a-z-]+\.(?:js|css))\?v=(\d+)"/g)) tagVer[m[1]] = m[2];
const assets = {}; for (const m of sw.matchAll(/'(\/(?:js|css)\/[a-z-]+\.(?:js|css))\?v=(\d+)'/g)) assets[m[1]] = m[2];
check("S6 location.js is loaded and cached, and every tag version matches the service worker", tagVer["/js/location.js"] && Object.keys(tagVer).every(k => assets[k] === tagVer[k]), { tagVer, assets });
check("S7 the service-worker cache name moved on (v8+)", /CACHE_NAME = 'bookkam-v([89]|\d\d)'/.test(sw));

// ---------- sandbox ----------
function env({ config, scriptFails = false, geo = null } = {}) {
  const els = {}, fetches = [], appended = [], toasts = [], modals = [], apiCalls = [], store = {};
  const mk = id => ({ id, innerHTML: "", textContent: "", value: "", checked: false, disabled: false, title: "", style: {}, dataset: {}, attrs: {}, listeners: {},
    setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; if (k === "aria-disabled") delete this.attrs[k]; }, addEventListener(t, f) { this.listeners[t] = f; },
    appendChild(n) { appended.push(n); }, remove() { this.removed = true; }, querySelector: () => null, focus() {}, getAttribute(k) { return this.attrs[k]; } });
  const el = id => els[id] || (els[id] = mk(id));
  const ctx = {
    console, setTimeout: () => 0, clearTimeout() {}, setImmediate, requestAnimationFrame: () => 0, Promise, JSON, Math, Number, String, Object, Array, Date, isFinite, parseFloat, parseInt,
    localStorage: { getItem: () => null, setItem: (k, v) => store[k] = v, removeItem() {} }, sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    location: { hash: "", pathname: "/", search: "", reload() {} }, history: { replaceState() {} },
    document: { getElementById: el, createElement: t => Object.assign(mk("new-" + t), { tag: t }), querySelector: () => null, querySelectorAll: () => [], addEventListener() {}, head: { appendChild: n => { appended.push(n); } }, body: { appendChild: n => { appended.push(n); } }, documentElement: { getAttribute: () => "dark", style: { setProperty() {} } } },
    navigator: geo ? { geolocation: geo } : {},
    fetch: async (url, opts) => {
      fetches.push(String(url));
      if (String(url).includes("/api/public-config.php")) { if (config === "reject") throw new Error("offline"); if (config === "badjson") return { json: async () => { throw new Error("bad json"); } }; return { json: async () => config || {} }; }
      return { json: async () => ({ features: [{ place_name: "Mapbox Street 1" }] }) };
    },
    api: async (ep, m, b) => { apiCalls.push({ ep, m, b }); if (ep.includes("get_cities")) return { cities: [{ name: "Calabar", is_active: 1 }, { name: "Ikom", is_active: 1 }, { name: "Uyo", is_active: 0 }] };
      if (ep.includes("cars.php?action=get_one")) return { car: { id: 7, name: "Camry", city: "Calabar", make: "Toyota", model: "Camry", year: 2019, price_per_hour: 5000, price_per_day: 30000, base_price: 15000 } }; return { success: true }; },
    showToast: (m, t) => toasts.push([m, t]), showModal: h => modals.push(h), closeModal() {}, requireAuth: () => true, shimmerCards: () => "", fmt: n => String(n), openPaymentModal() {},
    customerCity: "Calabar", showCitySelector: () => { ctx.__citySelectorOpened = (ctx.__citySelectorOpened || 0) + 1; }, selectCity: n => { ctx.__citySelected = n; },
  };
  ctx.window = ctx; ctx.self = ctx;
  vm.createContext(ctx);
  for (const f of ["js/utils.js", "js/maps.js", "js/location.js", "js/customer.js"]) vm.runInContext(src(f), ctx, { filename: f });
  const run = code => vm.runInContext(code, ctx);
  // when Mapbox is enabled the loader appends a <script>; simulate the browser finishing (or failing) that download
  const finishScript = () => { const s = appended.find(n => n.tag === "script"); if (!s) return false; if (scriptFails) s.onerror(); else { ctx.mapboxgl = fakeGL(ctx); s.onload(); } return true; };
  return { ctx, run, els, el, fetches, appended, toasts, modals, apiCalls, finishScript };
}
function fakeGL(ctx) {
  const centers = { c: { lat: 4.9517, lng: 8.322 } };
  class Map { constructor(o) { this.o = o; ctx.__maps = (ctx.__maps || 0) + 1; } addControl() {} remove() { this.removed = true; } getCenter() { return ctx.__center || centers.c; } flyTo() {} resize() {} on() {} }
  class Marker { constructor() {} setLngLat() { return this; } addTo() { return this; } remove() {} }
  return { Map, Marker, NavigationControl: class {}, accessToken: "", LngLatBounds: class { extend() { return this; } } };
}
const mapboxCalls = e => e.fetches.filter(u => /api\.mapbox\.com/.test(u));
const OFF_CONFIGS = [["config says disabled", { mapbox: { enabled: false } }], ["no mapbox key at all", {}], ["enabled but no token", { mapbox: { enabled: true } }],
  ["a SECRET (sk.) token is never accepted", { mapbox: { enabled: true, token: "sk.abc" } }], ["enabled as a string, not a boolean", { mapbox: { enabled: "true", token: "pk.abc" } }],
  ["public-config unreachable", "reject"], ["public-config is not JSON", "badjson"]];

(async () => {
  // ---------- Mapbox OFF (the default) ----------
  for (const [name, cfg] of OFF_CONFIGS) {
    const e = env({ config: cfg });
    const ok = await withTimeout(e.run("loadMapbox()"));
    check("M-OFF " + name + ": loadMapbox() is false, no script/stylesheet added, only public-config is fetched", ok === false && e.appended.length === 0 && e.fetches.every(u => u.includes("/api/public-config.php")) && e.run("mapboxAvailable()") === false, { ok, appended: e.appended.length, fetches: e.fetches });
  }
  { const e = env({ config: { mapbox: { enabled: false } } });
    let got = null; await e.run("geocodeAddress('Marian Road', r => { globalThis.__g = r; })"); await flush();
    check("M-OFF address search returns [] and sends NOTHING to Mapbox", JSON.stringify(e.ctx.__g) === "[]" && mapboxCalls(e).length === 0, e.fetches);
    const rg = await e.run("reverseGeocode(8.32, 4.95)"); check("M-OFF reverse geocoding returns null without any Mapbox call", rg === null && mapboxCalls(e).length === 0);
    e.el("track-map"); e.run("initCustomerTrackingMap('track-map', {})"); await flush();
    check("M-OFF the tracking map shows a plain message instead of crashing", /not available/i.test(e.els["track-map"].innerHTML) && !e.ctx.mapboxgl);
    check("M-OFF the token getter reveals nothing", e.run("getMapboxToken()") === ""); }

  // ---------- Mapbox ON ----------
  { const e = env({ config: { mapbox: { enabled: true, token: "pk.test-public-token" } } });
    const p = e.run("loadMapbox()"); await flush(); e.finishScript(); const ok = await p;
    const link = e.appended.find(n => n.tag === "link"), scr = e.appended.find(n => n.tag === "script");
    check("M-ON with enabled + a pk. token, Mapbox GL is added once, pinned to v3.3.0", ok === true && /mapbox-gl-js\/v3\.3\.0\/mapbox-gl\.css$/.test(link.href) && /mapbox-gl-js\/v3\.3\.0\/mapbox-gl\.js$/.test(scr.src) && e.appended.length === 2, e.appended.map(n => n.href || n.src));
    check("M-ON the access token is set from the server's answer, and mapboxAvailable() is true", e.ctx.mapboxgl.accessToken === "pk.test-public-token" && e.run("mapboxAvailable()") === true && e.run("getMapboxToken()") === "pk.test-public-token");
    await e.run("loadMapbox()"); check("M-ON asking again does not load it twice", e.appended.length === 2);
    await e.run("geocodeAddress('Marian Road', r => { globalThis.__g = r; })"); await flush();
    check("M-ON address search now reaches Mapbox with the server-provided token", mapboxCalls(e).length === 1 && /access_token=pk\.test-public-token/.test(mapboxCalls(e)[0]), e.fetches); }
  { const e = env({ config: { mapbox: { enabled: true, token: "pk.x" } }, scriptFails: true }); const p = e.run("loadMapbox()"); await flush(); e.finishScript();
    check("M-FAIL if the Mapbox script cannot load, it stays OFF (fail closed) and nothing throws", (await p) === false && e.run("mapboxAvailable()") === false); }

  // ---------- helpers ----------
  const h = env({ config: {} });
  const bad = [[null, 8], [4, null], ["", ""], [NaN, 8], [Infinity, 8], [91, 8], [4, 181], [0, 0], [true, true], ["abc", "8"], [undefined, undefined]];
  check("H1 bkValidCoords refuses empty, NaN, out-of-range and Null Island", bad.every(([a, b]) => h.run("bkValidCoords")(a, b) === false), bad.filter(([a, b]) => h.run("bkValidCoords")(a, b)));
  check("H1b ...and accepts real points, also as numeric strings", h.run("bkValidCoords")(4.9517, 8.322) && h.run("bkValidCoords")("4.95", "8.32") && h.run("bkValidCoords")(-33.9, 151.2));
  check("H2 bkCoordString gives 'lat,lng' with at most 10 decimals (the server's rule) or null", h.run("bkCoordString")(4.951712345678912, 8.32201234567891) === "4.951712,8.322012" && h.run("bkCoordString")(NaN, 1) === null);
  const near = (lat, lng, list) => h.run("bkNearestCity")(lat, lng, list);
  check("H3 a point in Calabar suggests Calabar; one in Ikom suggests Ikom", near(4.96, 8.33, ["Calabar", "Ikom"]) === "Calabar" && near(5.96, 8.71, ["Calabar", "Ikom"]) === "Ikom");
  check("H3b a far-away point (Lagos) suggests nothing", near(6.5244, 3.3792, ["Calabar", "Ikom"]) === null);
  check("H3c only ACTIVE cities from the list can be suggested, unknown names are ignored", near(5.0377 + 0.01, 7.9128, ["Calabar", "Ikom"]) === null && near(5.04, 7.91, ["Calabar", "Ikom", "Uyo"]) === "Uyo" && near(4.96, 8.33, ["Atlantis"]) === null);
  check("H3d invalid coordinates suggest nothing", near(NaN, 8, ["Calabar"]) === null && near(0, 0, ["Calabar"]) === null);
  const inp = { value: "", dataset: {} }; h.run("bkSetPickup")(inp, { address: "X", lat: 4.9, lng: 8.3, source: "gps" });
  check("H4 bkSetPickup stores text + coordinates, and bkPickupCoords reads them back validated", inp.value === "X" && h.run("bkPickupCoords")(inp) === "4.900000,8.300000" && inp.dataset.source === "gps");
  h.run("bkSetPickup")(inp, { address: "Y", lat: NaN, lng: 8.3 }); check("H4b bad coordinates clear the saved point instead of keeping a stale one", h.run("bkPickupCoords")(inp) === null && inp.dataset.lat === undefined);
  inp.dataset.lat = "999"; inp.dataset.lng = "8"; check("H4c a tampered data-lat/data-lng is never sent", h.run("bkPickupCoords")(inp) === null);

  // ---------- the ride modal ----------
  { const geo = { n: 0, getCurrentPosition() { this.n++; } };
    const e = env({ config: { mapbox: { enabled: false } }, geo });
    await e.run("openRideModal(7)"); await flush();
    const html = e.modals[e.modals.length - 1] || "";
    check("R1 opening the ride modal does NOT ask the browser for the location", geo.n === 0, geo.n);
    check("R2 the modal offers: Use my current location / Choose location on map / Choose city manually", /bk-loc-gps/.test(html) && /Use my current location/.test(html) && /bk-loc-map/.test(html) && /Choose location on map/.test(html) && /bk-loc-city/.test(html) && /Choose city manually/.test(html));
    check("R3 with Mapbox OFF the map button is DISABLED and the page explains why", /id="bk-loc-map"[^>]*disabled/.test(html) && /not available yet/i.test(html));
    check("R3b the old auto 'locate me' button is gone", !/locate-me-btn/.test(html));
    check("R4 with Mapbox OFF no map is drawn and no Mapbox request is made", e.els["ride-booking-map"].style.display === "none" && mapboxCalls(e).length === 0 && !e.ctx.__maps);
    const book = e.el("ride-book-btn"); const pick = e.el("modal-pickup");
    await e.run("updateRidePreviewMap()"); check("R5 with no pickup typed the Book button stays disabled", book.disabled === true, book.disabled);
    pick.value = "12 Marian Road"; await e.run("updateRidePreviewMap()");
    check("R6 a TYPED pickup is enough to book while Mapbox is OFF (button enabled, plain status)", book.disabled === false && /typed/i.test(e.el("ride-map-status-text").textContent || e.el("ride-map-status-text").innerHTML), { d: book.disabled, t: e.el("ride-map-status-text") });
    pick.value = "ab"; await e.run("updateRidePreviewMap()"); check("R6b ...but a 2-character pickup is not", book.disabled === true);
    // booking payload
    pick.value = "12 Marian Road"; e.el("modal-dropoff").value = "Airport"; e.el("modal-duration").value = "2";
    await e.run("createRideBooking(7, 'Calabar')"); let call = e.apiCalls.filter(c => c.ep.includes("bookings.php?action=create")).pop();
    check("R7 a typed pickup books with pickup_coords = null (never a made-up point)", call && call.b.pickup_address === "12 Marian Road" && call.b.pickup_coords === null && call.b.city === "Calabar", call);
    e.run("bkSetPickup")(pick, { address: "12 Marian Road", lat: 4.9517, lng: 8.322, source: "gps" }); await e.run("createRideBooking(7, 'Calabar')"); call = e.apiCalls.filter(c => c.ep.includes("bookings.php?action=create")).pop();
    check("R8 a saved point is sent as 'lat,lng' and the city stays the car's city", call.b.pickup_coords === "4.951700,8.322000" && call.b.city === "Calabar", call.b);
    pick.dataset.lat = "abc"; await e.run("createRideBooking(7, 'Calabar')"); call = e.apiCalls.filter(c => c.ep.includes("bookings.php?action=create")).pop();
    check("R9 a corrupted saved point is dropped, not sent", call.b.pickup_coords === null, call.b); }
  { const e = env({ config: { mapbox: { enabled: true, token: "pk.t" } }, geo: { getCurrentPosition() {} } });
    const p = e.run("openRideModal(7)"); await flush(); e.finishScript(); await p; await flush();
    check("R10 with Mapbox ON the preview map is built and the map button is enabled", e.ctx.__maps === 1 && e.els["bk-loc-map"] && e.els["bk-loc-map"].disabled === false);
    check("R10b the map button's own disabled flag is only lifted by the loader (explanation replaced)", /pin the point on the map/i.test(e.el("bk-loc-note").textContent)); }

  // ---------- "Use my current location" ----------
  const loc = async (opts, pos, errCode) => {
    const geo = { n: 0, last: null, getCurrentPosition(ok, err, o) { this.n++; this.last = o; if (errCode) err({ code: errCode }); else ok({ coords: pos }); } };
    const e = env({ config: opts.mapbox ? { mapbox: { enabled: true, token: "pk.t" } } : { mapbox: { enabled: false } }, geo }); e.ctx.customerCity = opts.city || "Calabar";
    const input = e.el("modal-pickup"); e.el("bk-loc-gps"); e.el("bk-loc-note"); e.el("bk-loc-map");
    let changed = 0; e.ctx.__ctx = { inputId: "modal-pickup", carCity: opts.carCity || "Calabar", onChange: () => changed++ };
    const p = e.run("bkInitLocationControls(__ctx)"); if (opts.mapbox) { await flush(); e.finishScript(); } await p; await flush();
    e.run("bkLocateClick()"); await flush();
    return { e, geo, input, changed, note: e.els["bk-loc-note"].textContent };
  };
  { const r = await loc({}, { latitude: 4.9517, longitude: 8.322 });
    check("L1 a tap asks the browser once, with a 10 s timeout", r.geo.n === 1 && r.geo.last.timeout === 10000);
    check("L2 Mapbox OFF: pickup text becomes 'My current location', the point is saved, NO reverse-geocode request", r.input.value === "My current location" && r.e.run("bkPickupCoords")(r.input) === "4.951700,8.322000" && mapboxCalls(r.e).length === 0, { v: r.input.value, f: r.e.fetches });
    check("L3 the booking preview is told to refresh and the note asks for a landmark", r.changed === 1 && /landmark/i.test(r.note), r.note);
    check("L4 the city is untouched (no switch, no selector forced open)", r.e.ctx.customerCity === "Calabar" && r.e.ctx.__citySelected === undefined && !r.e.ctx.__citySelectorOpened); }
  { const r = await loc({ carCity: "Calabar" }, { latitude: 5.9646, longitude: 8.7086 });
    check("L5 a position in Ikom for a Calabar ride only SUGGESTS Ikom in text; city and booking city do not change", /near Ikom/.test(r.note) && /booked there|in Calabar/.test(r.note) && r.e.ctx.customerCity === "Calabar" && r.e.ctx.__citySelected === undefined, r.note);
    check("L5b ...and the point is still saved for the pickup", r.e.run("bkPickupCoords")(r.input) === "5.964600,8.708600"); }
  { const r = await loc({}, { latitude: 6.5244, longitude: 3.3792 }); check("L6 a position outside every service city says so, still no switch", /outside our service cities/i.test(r.note) && r.e.ctx.customerCity === "Calabar"); }
  { const r = await loc({ mapbox: true }, { latitude: 4.9517, longitude: 8.322 });
    check("L7 Mapbox ON: the saved point gets a street label from reverse geocoding", /Mapbox Street 1/.test(r.input.value) && mapboxCalls(r.e).length === 1, { v: r.input.value }); }
  for (const [code, re] of [[1, /denied/i], [2, /not available/i], [3, /too long/i]]) {
    const r = await loc({}, null, code); check("L8 error code " + code + " shows a helpful message, keeps the typed text and saves no point", re.test(r.note) && r.input.value === "" && r.e.run("bkPickupCoords")(r.input) === null && r.e.els["bk-loc-gps"].disabled === false && r.changed === 0, r.note);
  }
  for (const pos of [{ latitude: NaN, longitude: 8 }, { latitude: 0, longitude: 0 }, { latitude: 95, longitude: 8 }, { latitude: null, longitude: null }]) {
    const r = await loc({}, pos); check("L9 an invalid device position " + JSON.stringify(pos) + " is rejected", /valid location/i.test(r.note) && r.e.run("bkPickupCoords")(r.input) === null && r.input.value === "", r.note); }
  { const e = env({ config: {}, geo: null }); e.el("modal-pickup"); e.el("bk-loc-note"); e.ctx.__ctx = { inputId: "modal-pickup", carCity: "Calabar" }; e.run("bkInitLocationControls(__ctx)"); e.run("bkLocateClick()");
    check("L10 a browser without geolocation gets a plain message", /cannot share its location/i.test(e.els["bk-loc-note"].textContent)); }

  // ---------- "Choose location on map" ----------
  { const e = env({ config: { mapbox: { enabled: false } } }); e.el("modal-pickup"); e.el("bk-loc-note"); e.ctx.__ctx = { inputId: "modal-pickup", carCity: "Calabar" }; e.run("bkInitLocationControls(__ctx)"); await flush();
    await e.run("bkChooseOnMapClick()"); check("P1 Mapbox OFF: tapping the map button explains and opens nothing", /not available yet/i.test(e.els["bk-loc-note"].textContent) && !e.appended.some(n => n.id === "bk-map-picker") && !e.ctx.__maps); }
  { const e = env({ config: { mapbox: { enabled: true, token: "pk.t" } } }); const input = e.el("modal-pickup"); e.el("bk-loc-note"); let changed = 0; e.ctx.__ctx = { inputId: "modal-pickup", carCity: "Ikom", onChange: () => changed++ };
    const p0 = e.run("bkInitLocationControls(__ctx)"); await flush(); e.finishScript(); await p0; await flush();
    const p = e.run("bkChooseOnMapClick()"); await flush(); await p;
    const overlay = e.appended.find(n => n.id === "bk-map-picker");
    check("P2 Mapbox ON: the picker opens centred on the car's city and offers Cancel + 'Confirm pickup location'", !!overlay && /Confirm pickup location/.test(overlay.innerHTML) && /Cancel/.test(overlay.innerHTML) && e.ctx.__maps === 1);
    e.ctx.__center = { lat: 5.9646, lng: 8.7086 }; await e.els["bk-map-confirm"].onclick(); await flush();
    check("P3 Confirm saves the pin's point and a label into the pickup, closes the picker, and refreshes the booking", e.run("bkPickupCoords")(input) === "5.964600,8.708600" && /Mapbox Street 1/.test(input.value) && input.dataset.source === "map" && overlay.removed === true && changed === 1, { v: input.value, d: input.dataset });
    input.value = "keep me"; input.dataset = {}; const p2 = e.run("bkChooseOnMapClick()"); await flush(); await p2; e.ctx.__center = { lat: NaN, lng: 8 };
    await e.els["bk-map-confirm"].onclick(); check("P4 an unreadable pin is refused with a message, nothing is saved", input.value === "keep me" && e.run("bkPickupCoords")(input) === null && e.toasts.some(t => t[1] === "error"));
    const p3 = e.run("bkChooseOnMapClick()"); await flush(); await p3; const before = JSON.stringify(input.dataset); await e.els["bk-map-cancel"].onclick(); check("P5 Cancel leaves the pickup exactly as it was", JSON.stringify(input.dataset) === before && input.value === "keep me"); }

  finished = true; console.log(failed ? "LOCATION_FRONTEND_FAILED (" + failed + ")" : "LOCATION_FRONTEND_OK"); process.exit(failed ? 1 : 0);
})().catch(e => { console.log("CRASH", e && e.stack || e); process.exit(2); });
