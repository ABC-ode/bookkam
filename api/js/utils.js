// ── UTILS ─────────────────────────────────────────────────────────────────────
const fmt = n => {
  const num = parseFloat(n);
  if (isNaN(num)) return "₦0";
  return "₦" + num.toLocaleString("en-NG", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
};
const greet = () => {
  const h = new Date().getHours();
  return h < 12 ? "Good morning" : h < 17 ? "Good afternoon" : "Good evening";
};
const timeAgo = d => {
  const s = Math.floor((Date.now() - new Date(d)) / 1000);
  if (s < 60) return "just now";
  if (s < 3600) return Math.floor(s / 60) + "m ago";
  if (s < 86400) return Math.floor(s / 3600) + "h ago";
  return Math.floor(s / 86400) + "d ago";
};
const fmtDate     = d => d ? new Date(d).toLocaleDateString("en-NG", { day:"numeric", month:"short", year:"numeric" }) : "—";
const fmtTime     = d => d ? new Date(d).toLocaleTimeString("en-NG", { hour:"2-digit", minute:"2-digit" }) : "—";
const catColor    = c => ({ luxury:"badge-gold", business:"badge-cyan", economy:"badge-green", suv:"badge-orange" }[c] || "badge-muted");
const statusColor = s => ({ pending:"badge-orange", confirmed:"badge-cyan", active:"badge-green", completed:"badge-gold", cancelled:"badge-red" }[s] || "badge-muted");
const capitalize  = s => s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, " ") : "";

// Safely format a stored phone number for display.
// DB stores either "2348012345678" (no +) or "08012345678" (local).
// Always renders as "+234XXXXXXXXXX".
function formatPhoneDisplay(raw) {
  if (!raw) return "";
  raw = String(raw).trim().replace(/\s+/g, "");
  // Strip leading +
  if (raw.startsWith("+")) raw = raw.slice(1);
  // Strip leading country code 234
  if (raw.startsWith("234")) raw = raw.slice(3);
  // Strip leading 0 (local format like 08012345678)
  if (raw.startsWith("0")) raw = raw.slice(1);
  return "+234" + raw;
}
