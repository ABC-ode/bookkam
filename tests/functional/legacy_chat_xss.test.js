// Regression test: chat message text must be HTML-escaped by the LEGACY render functions
// (api/fonts/jss/{customer,driver,admin}.js). It extracts the real functions from the source files, runs them with a
// stubbed DOM / api() and the escape helper from the legacy utils.js, then checks that script/HTML payloads
// delivered as `message_text` never reach innerHTML as markup while ordinary text survives unchanged.
// It covers the chat bubbles (customer/driver/admin) AND the admin chat-list preview (`last_message`).
// Usage: node tests/functional/legacy_chat_xss.test.js [dir-with-the-js-files]   (default: api/fonts/jss; CI also runs it on api/js)
const fs = require("fs");
const path = require("path");

const dir = process.argv[2] || path.join(__dirname, "..", "..", "api", "fonts", "jss");
const read = f => fs.readFileSync(path.join(dir, f), "utf8");

// The escape helper exactly as the legacy utils.js defines it (same approach as esc.test.js).
const utilsSrc = read("utils.js").replace(/^const /gm, "var ");
let esc;
try { esc = new Function(utilsSrc + "; return typeof esc === 'function' ? esc : undefined;")(); } catch (e) { esc = undefined; }

// Pull `async function NAME(...) { ... }` out of a source file by brace matching.
function extractFunction(src, name) {
  const start = src.search(new RegExp("async function " + name + "\\s*\\("));
  if (start < 0) throw new Error("function not found: " + name);
  let i = src.indexOf("{", src.indexOf(")", start)), depth = 0;
  for (let j = i; j < src.length; j++) {
    if (src[j] === "{") depth++;
    else if (src[j] === "}" && --depth === 0) return src.slice(start, j + 1);
  }
  throw new Error("unbalanced braces in " + name);
}

// Run a legacy function in a sandbox: unknown globals resolve to harmless stubs, `esc` is the legacy helper (or missing).
async function render(file, fnName, elementId, data, args) {
  const fnSrc = extractFunction(read(file), fnName);
  const elements = {};
  const el = id => elements[id] || (elements[id] = { innerHTML: "", scrollTop: 0, scrollHeight: 0, value: "" });
  const known = {
    esc, document: { getElementById: el }, api: async () => data, currentUser: { id: 1 },
    currentChatBookingId: 1, driverChatBookingId: 1, fmtTime: () => "10:00", shimmerCards: () => "", showModal: () => {},
    closeModal: () => {}, console,
  };
  if (esc === undefined) delete known.esc;
  const scope = new Proxy(known, {
    has: (t, k) => typeof k === "string" && !(k in globalThis) && k !== "esc" ? true : (k === "esc" && "esc" in t),
    get: (t, k) => (k in t ? t[k] : (k === Symbol.unscopables ? undefined : () => "")),
  });
  const fn = new Function("scope", "with (scope) { return (" + fnSrc + "); }")(scope);
  await fn(...(args || []));
  return elements[elementId] ? elements[elementId].innerHTML : "";
}

const payloads = [
  "<img src=x onerror=alert(1)>",
  "<script>alert(document.cookie)</script>",
  "\"><svg onload=alert(1)>",
  "' onmouseover='alert(1)",
  "</div><iframe src=javascript:alert(1)></iframe>",
  "`${alert(1)}`",
];
const benign = ["See you at 5pm \u2014 thanks! \uD83D\uDE0A", "Tom & Jerry said 5 > 3 and 2 < 4", "Ok"];
const unescape = h => h.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, "\"").replace(/&#39;/g, "'").replace(/&#96;/g, "`").replace(/&amp;/g, "&");
const mk = t => ({ sender_id: 2, sender_name: "Someone", message_text: t, created_at: "2026-01-01 10:00:00", is_flagged: 0 });

const bubbles = list => ({ messages: list.map(mk) });
const sites = [
  { file: "customer.js", fn: "loadChatMessages", id: "chat-messages", args: [], data: bubbles, marker: "chat-bubble" },
  { file: "driver.js", fn: "loadDriverChatMessages", id: "driver-chat-messages", args: [], data: bubbles, marker: "chat-bubble" },
  { file: "admin.js", fn: "openAdminChatView", id: "admin-chat-view", args: [7], data: bubbles, marker: "chat-bubble" },
  // admin chat list: `last_message` is the latest message text shown as a preview
  { file: "admin.js", fn: "loadChatsList", id: "admin-chats-list", args: [false], marker: "card-hover",
    data: list => ({ conversations: list.map((t, i) => ({ booking_id: i + 1, car_name: "Car", customer_name: "Cust", driver_name: "Drv", last_message: t, flag_count: 0, last_at: "2026-01-01 10:00:00" })) }) },
];

let failures = 0;
const fail = (m) => { failures++; console.log("FAIL " + m); };
const pass = (m) => console.log("PASS " + m);

(async () => {
  if (typeof esc !== "function") fail("legacy utils.js does not define esc()");
  for (const s of sites) {
    try {
      const html = await render(s.file, s.fn, s.id, s.data([...payloads, ...benign]), s.args);
      if (!html.includes(s.marker)) { fail(s.file + " " + s.fn + ": render produced no output (test could not exercise the function)"); continue; }
      const tags = (html.match(/<\s*(img|script|svg|iframe)\b/gi) || []).length;
      const rawPayload = payloads.filter(p => /[<>"'`]/.test(p) && html.includes(p));
      const escapedOk = typeof esc === "function" && payloads.every(p => html.includes(esc(p)));
      if (tags === 0 && rawPayload.length === 0 && escapedOk) pass(s.file + " " + s.fn + ": all " + payloads.length + " HTML/script payloads are rendered as inert text");
      else fail(s.file + " " + s.fn + ": payload reached innerHTML unescaped (tag openers=" + tags + ", raw=" + JSON.stringify(rawPayload.slice(0, 2)) + ", escaped-present=" + escapedOk + ")");
      const keep = benign.every(b => html.includes(typeof esc === "function" ? esc(b) : b) && unescape(esc ? esc(b) : b) === b);
      if (keep) pass(s.file + " " + s.fn + ": ordinary message text (unicode, &, <, >) is preserved");
      else fail(s.file + " " + s.fn + ": ordinary message text was altered or lost");
    } catch (e) { fail(s.file + " " + s.fn + ": test harness error: " + e.message); }
  }
  console.log(failures === 0 ? "LEGACY_CHAT_XSS_OK" : "LEGACY_CHAT_XSS_FAILED (" + failures + ")");
  process.exit(failures === 0 ? 0 : 1);
})();
