"use strict";
// The seller's orders panel. The server is the source of truth; after every change the list is reloaded.
// All customer text goes through textContent, never innerHTML.
const $ = id => document.getElementById(id);
const API = "../api/panel.php";
const STATUS = { paid: "Opłacone", review: "Do sprawdzenia", pending: "Nieopłacone", canceled: "Anulowane" };
const VIEWS = {
  todo: { label: "Do obsłużenia", match: o => (o.status === "paid" && !o.done_at) || o.status === "review" },
  paid: { label: "Opłacone", match: o => o.status === "paid" },
  review: { label: "Do sprawdzenia", match: o => o.status === "review" },
  unpaid: { label: "Nieopłacone", match: o => o.status === "pending" || o.status === "canceled" },
  all: { label: "Wszystkie", match: () => true },
};
let orders = [];
let view = "todo";

const money = g => (g / 100).toLocaleString("pl-PL", { style: "currency", currency: "PLN" });
const when = ts => ts ? new Date(ts * 1000).toLocaleString("pl-PL", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Warsaw" }) : "—";
const day = ts => new Date(ts * 1000).toLocaleDateString("pl-PL", { timeZone: "Europe/Warsaw" });

// h("p", {class: "x"}, "text", child…): attributes are set as properties, children as text or nodes.
function h(tag, props = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === "class") el.className = v;
    else if (k.startsWith("on")) el.addEventListener(k.slice(2), v);
    else el[k] = v;
  }
  for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) el.append(c instanceof Node ? c : String(c));
  return el;
}

async function api(action, body) {
  const init = body === undefined ? { cache: "no-store" }
    : { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) };
  const res = await fetch(`${API}?action=${action}`, { credentials: "same-origin", ...init });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && action !== "login") { show("login"); }
  return { ok: res.ok, status: res.status, data };
}

function show(which) {
  $("login").hidden = which !== "login";
  $("app").hidden = which !== "app";
  $("session-actions").hidden = which !== "app";
  if (which === "login") $("password").focus();
}

function alertIn(id, text) { $(id).textContent = text || ""; $(id).hidden = !text; }

// ---------- list ----------
function searchable(o) {
  return [o.number, o.name, o.email, o.phone, o.recipient, o.company, o.nip, o.product, o.note, ...o.vouchers.map(v => v.code)]
    .join(" ").toLowerCase();
}

function visible() {
  const q = $("search").value.trim().toLowerCase().replace(/\s+/g, " ");
  const type = $("type").value;
  const list = orders.filter(o => VIEWS[view].match(o) && (!type || o.type === type) && (!q || searchable(o).includes(q)));
  const sort = $("sort").value;
  return list.sort((a, b) => sort === "amount" ? b.total - a.total
    : sort === "old" ? a.created_at - b.created_at : b.created_at - a.created_at);
}

function renderViews() {
  $("views").replaceChildren(...Object.entries(VIEWS).map(([key, v]) => h("button", {
    type: "button", class: "view" + (key === view ? " active" : ""), ariaPressed: String(key === view),
    onclick: () => { view = key; renderViews(); renderList(); },
  }, v.label, h("span", { class: "count" }, orders.filter(v.match).length))));
}

function voucherLine(o, v, index) {
  const expired = v.status === "active" && v.expires_at * 1000 < Date.now();
  const state = v.status === "used" ? `wykorzystany ${day(v.used_at)}` : expired ? `wygasł ${day(v.expires_at)}` : `ważny do ${day(v.expires_at)}`;
  return h("li", { class: "code " + (v.status === "used" ? "used" : expired ? "expired" : "active") },
    h("span", { class: "code-value" }, `Voucher ${index + 1}`), h("span", { class: "code-state" }, state),
    o.status === "paid" && !expired ? h("button", { type: "button", class: "btn btn-ghost btn-sm",
      onclick: () => redeem(v.code, v.status !== "used") }, v.status === "used" ? "Cofnij" : "Wykorzystany") : null);
}

function card(o) {
  const shipped = o.type !== "voucher";
  const contact = [h("strong", {}, o.name || "—"),
    o.email ? h("a", { href: "mailto:" + o.email }, o.email) : null,
    o.phone ? h("a", { href: "tel:+48" + o.phone }, o.phone) : null];
  const details = shipped
    ? [o.delivery ? h("p", {}, o.delivery, o.point ? ` · ${o.point}` : "") : null,
       o.address ? h("p", { class: "muted" }, o.address) : null,
       o.tracking ? h("p", { class: "muted" }, `Przesyłka: ${o.tracking}`) : null]
    : [o.recipient ? h("p", {}, `Dla: ${o.recipient}`) : null,
       o.dedication ? h("p", { class: "muted dedication" }, `„${o.dedication}”`) : null,
       o.vouchers.length ? h("ul", { class: "codes" }, o.vouchers.map((v, i) => voucherLine(o, v, i)))
         : o.status === "paid" ? null : h("p", { class: "muted" }, "Vouchery pojawią się po potwierdzeniu płatności.")];
  const invoice = o.invoice ? h("div", { class: "invoice" },
    h("p", {}, h("strong", {}, "Faktura: "), o.company, ` · NIP ${o.nip}`),
    h("p", { class: "muted" }, o.company_address),
    tick("Faktura wystawiona", Boolean(o.invoice_done_at), on => update(o.id, { invoice_done: on }))) : null;
  return h("article", { class: `order status-${o.status}` + (o.done_at ? " done" : "") },
    h("header", { class: "order-head" },
      h("span", { class: "order-number" }, o.number),
      h("span", { class: `pill ${o.status}` }, STATUS[o.status] || o.status),
      h("span", { class: "order-date muted" }, when(o.paid_at || o.created_at)),
      h("span", { class: "order-total" }, money(o.total))),
    h("p", { class: "order-product" }, o.product ? `${o.product} × ${o.qty}` : "Zamówienie sprzed zapisu produktu"),
    o.status === "review" ? h("p", { class: "form-alert" }, "Kwota lub identyfikator płatności się nie zgadza. Sprawdź tę płatność w panelu Paymove, zanim cokolwiek wydasz.") : null,
    h("div", { class: "order-body" }, h("div", { class: "contact" }, contact), h("div", {}, details)),
    invoice,
    h("footer", { class: "order-foot" },
      tick("Obsłużone", Boolean(o.done_at), on => update(o.id, { done: on })),
      h("textarea", { class: "note", rows: 1, maxLength: 1000, placeholder: "Notatka (widzisz ją tylko Ty)", value: o.note,
        onchange: e => update(o.id, { note: e.target.value }) }),
      o.status === "paid" ? h("button", { type: "button", class: "btn btn-ghost btn-sm", onclick: () => resend(o) }, "Wyślij potwierdzenie ponownie") : null));
}

function tick(label, checked, onChange) {
  return h("label", { class: "check" }, h("input", { type: "checkbox", checked, onchange: e => onChange(e.target.checked) }), h("span", {}, label));
}

function renderList() {
  const list = visible();
  $("orders").replaceChildren(...list.map(card));
  $("empty").hidden = list.length > 0;
}

async function load() {
  const r = await api("orders");
  if (!r.ok) { if (r.status !== 401) alertIn("app-alert", r.data.error || "Nie udało się wczytać zamówień."); return false; }
  alertIn("app-alert", "");
  orders = r.data.orders;
  $("demo-banner").hidden = !r.data.sandbox;
  renderViews();
  renderList();
  return true;
}

// ---------- actions ----------
async function update(id, change) {
  const r = await api("update", { id, ...change });
  if (!r.ok) alertIn("app-alert", r.data.error || "Nie udało się zapisać.");
  await load();
}

async function resend(o) {
  if (!confirm(`Wysłać ponownie potwierdzenie ${o.number} na ${o.email}?`)) return;
  const r = await api("resend", { id: o.id });
  alertIn("app-alert", r.ok ? "" : r.data.error || "Nie udało się.");
  if (r.ok) say(`Potwierdzenie ${o.number} czeka w kolejce do wysłania.`);
}

function say(text, undo) {
  const box = $("redeem-result");
  box.replaceChildren(text, undo ? h("button", { type: "button", class: "link", onclick: undo }, "Cofnij") : "");
  box.hidden = false;
}

async function redeem(code, used) {
  const r = await api(used ? "redeem" : "unredeem", { code });
  if (r.ok) say(used ? "Voucher oznaczony jako wykorzystany." : "Voucher jest znów aktywny.",
    used ? () => redeem(r.data.voucher.code, false) : null);
  else say(r.data.error || "Nie udało się.");
  await load();
}

$("redeem-form").addEventListener("submit", async e => {
  e.preventDefault();
  const email = $("redeem-code").value.trim();
  if (!email || !$("redeem-code").reportValidity()) return;
  const r = await api("voucherLookup", { email });
  if (!r.ok) { say(r.data.error || "Nie udało się wyszukać voucherów."); return; }
  const matches = r.data.orders;
  const box = $("redeem-result");
  box.replaceChildren(matches.length ? "Wybierz voucher do realizacji." : "Brak opłaconych voucherów dla tego adresu.");
  for (const o of matches) box.append(card(o));
  box.hidden = false;
});
["search", "type", "sort"].forEach(id => $(id).addEventListener("input", renderList));

$("login-form").addEventListener("submit", async e => {
  e.preventDefault();
  $("login-button").disabled = true;
  const r = await api("login", { password: $("password").value });
  $("login-button").disabled = false;
  if (!r.ok) { alertIn("login-alert", r.data.error || "Nie udało się zalogować."); return; }
  $("password").value = "";
  alertIn("login-alert", "");
  if (await load()) show("app");
});
$("logout").addEventListener("click", async () => { await api("logout", {}); orders = []; show("login"); });

(async () => {
  const r = await api("session");
  if (r.data.shop) $("shop-name").textContent = r.data.shop;
  if (!r.ok) { show("login"); alertIn("login-alert", r.data.error || "Panel jest chwilowo niedostępny."); return; }
  if (r.data.authenticated && await load()) show("app"); else show("login");
})();
