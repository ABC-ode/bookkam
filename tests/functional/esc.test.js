// Verifies the shared HTML escaper used for every user-controlled value placed in innerHTML.
const fs = require("fs");
const src = fs.readFileSync(process.argv[2], "utf8").replace(/^const /gm, "var ");
const esc = new Function(src + "; return esc;")();
const payloads = [
  "<img src=x onerror=alert(1)>",
  "\"><script>alert(1)</script>",
  "' onmouseover='alert(1)",
  "`${alert(1)}`",
  "<svg/onload=alert(1)>",
];
let ok = true;
for (const p of payloads) { if (/[<>"'`]/.test(esc(p))) { ok = false; console.log("UNESCAPED:", p); } }
if (esc(null) !== "" || esc(undefined) !== "" || esc(5) !== "5" || esc("a&b") !== "a&amp;b") ok = false;
console.log(ok ? "ESC_OK" : "ESC_BAD");
process.exit(ok ? 0 : 1);
