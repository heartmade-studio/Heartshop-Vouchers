"use strict";
// One-page checkout: quantity, contact, delivery (shipped products only), invoice, then the Paymove modal.
// Prices shown here are a preview; the server recomputes every amount.
// cfg.product is the offer this page sells; a voucher has no delivery, Paczkomat or address.

const $ = id => document.getElementById(id);
const I = HeartshopI18n, t = I.t, pln = I.money;
const DRAFT_KEY = `heartshop-draft:${I.scope}`;
const form = $("order");
const payBtn = $("pay");
const alertBox = $("form-alert");

let cfg = null;
let qty = 1;
let submitting = false;
let touched = false; // after the first submit attempt, errors update live

function syncBannerOffset() {
  const banner = $("demo-banner");
  const h = banner && !banner.hidden ? banner.getBoundingClientRect().height : 0;
  document.documentElement.style.setProperty("--banner-h", h + "px");
}
if (typeof addEventListener === "function") addEventListener("resize", syncBannerOffset);

const store = {
  read() { try { return JSON.parse(sessionStorage.getItem(DRAFT_KEY)) || {}; } catch { return {}; } },
  write(v) { try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(v)); } catch {} },
  clear() { try { sessionStorage.removeItem(DRAFT_KEY); } catch {} },
};

const shipped = () => cfg.product.type !== "voucher";

// ---------- pricing preview ----------
function quote() {
  const items = cfg.product.price * qty;
  if (!shipped()) return { items, ship: 0, free: false, total: items };
  const d = selectedDelivery();
  let ship = d ? cfg.delivery[d].price : null;
  const free = cfg.freeShippingFrom !== null && items >= cfg.freeShippingFrom;
  if (free && ship !== null) ship = 0;
  return { items, ship, free, total: items + (ship || 0) };
}

function render() {
  const q = quote();
  $("qty").textContent = qty;
  $("qty-minus").disabled = qty <= 1;
  $("qty-plus").disabled = qty >= cfg.product.max_qty;
  $("t-items").textContent = pln(q.items);
  $("t-total").textContent = pln(q.total);
  payBtn.textContent = submitting ? t("pay.busy") : t("pay.label", { total: pln(q.total) });
  if (!shipped()) return;
  $("t-ship").textContent = q.ship === null ? t("ship.choose") : q.ship === 0 ? t("ship.free") : pln(q.ship);

  const nudge = $("nudge");
  if (cfg.freeShippingFrom !== null) {
    const missing = cfg.freeShippingFrom - q.items;
    nudge.hidden = false;
    nudge.textContent = missing > 0
      ? t("nudge.more", { n: Math.ceil(missing / cfg.product.price) })
      : t("nudge.free");
  }
  document.querySelectorAll(".option .cost").forEach(el => {
    const base = cfg.delivery[el.dataset.key].price;
    el.textContent = q.free ? t("ship.free") : pln(base);
    el.classList.toggle("free", q.free);
  });
}

// ---------- delivery ----------
function selectedDelivery() {
  return form.querySelector('input[name="delivery"]:checked')?.value || "";
}

function buildDelivery() {
  const host = $("delivery-options");
  host.innerHTML = "";
  for (const [key, d] of Object.entries(cfg.delivery)) {
    const label = document.createElement("label");
    label.className = "option";
    label.innerHTML = `<input type="radio" name="delivery" value="${key}"><span class="dot"></span>
      <span class="body"><span class="title"></span><span class="sub"></span></span><span class="cost" data-key="${key}"></span>`;
    label.querySelector(".title").textContent = I.local(`delivery.${key}`, d.label);
    label.querySelector(".sub").textContent = I.local("eta", d.eta);
    host.append(label);
  }
  host.addEventListener("change", () => { updateReveals(); render(); saveDraft(); if (touched) validate(); });
}

function updateReveals() {
  if ($("reveal-invoice")) $("reveal-invoice").hidden = !$("invoice")?.checked;
  if (!shipped()) return;
  const d = selectedDelivery();
  $("reveal-paczkomat").hidden = d !== "paczkomat";
  $("address-hint").hidden = d !== "paczkomat";
}

// ---------- Paczkomat ----------
let point = { code: "", label: "" };

function showPoint() {
  const hasMap = Boolean(cfg.geowidgetToken);
  const chosen = Boolean(point.code) && hasMap;
  $("point-chosen").hidden = !chosen;
  $("point-open").hidden = !hasMap || chosen;
  $("point-manual").hidden = hasMap;
  if (chosen) {
    $("point-code").textContent = t("point.title", { code: point.code });
    $("point-address").textContent = point.label;
  }
}

function setPoint(code, label) {
  point = { code: (code || "").toUpperCase(), label: label || "" };
  showPoint();
  saveDraft();
  if (touched) validate();
}

let mapLoaded = null;
function openMap() {
  const dlg = $("map-dialog");
  if (!mapLoaded) {
    const base = cfg.geowidgetSandbox ? "https://sandbox-easy-geowidget-sdk.easypack24.net" : "https://geowidget.inpost.pl";
    const css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = `${base}/inpost-geowidget.css`;
    document.head.append(css);
    mapLoaded = new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = `${base}/inpost-geowidget.js`;
      s.onload = resolve;
      s.onerror = reject;
      document.head.append(s);
    }).then(() => {
      const w = document.createElement("inpost-geowidget");
      w.setAttribute("token", cfg.geowidgetToken);
      w.setAttribute("language", I.lang);
      w.setAttribute("config", "parcelcollect");
      w.setAttribute("onpoint", "heartshopPoint");
      $("map-host").append(w);
    });
  }
  dlg.showModal();
}

window.heartshopPoint = p => {
  const label = [p?.address?.line1, p?.address?.line2].filter(Boolean).join(", ");
  setPoint(p?.name, label);
  $("map-dialog").close();
  $("point-change").focus();
};

// ---------- validation (mirrors api/lib.php validateOrder) ----------
function validNip(nip) {
  if (!/^\d{10}$/.test(nip)) return false;
  const w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
  const sum = w.reduce((s, x, i) => s + x * Number(nip[i]), 0);
  return sum % 11 === Number(nip[9]);
}

function collect() {
  const v = id => $(id).value.trim();
  // Pages without data-offer send exactly what they always sent, so an open attempt survives a deploy.
  const data = I.offer ? { offer: I.offer } : {};
  Object.assign(data, { qty, email: v("email") });
  if (shipped()) {
    // Key order is part of the attempt fingerprint (JSON.stringify): keep it as it always was.
    const d = selectedDelivery();
    Object.assign(data, { phone: v("phone"), name: v("name"), delivery: d,
      invoice: Boolean($("invoice")?.checked), terms: $("terms").checked });
    if (d === "paczkomat") Object.assign(data, cfg.geowidgetToken
      ? { point: point.code, point_label: point.label }
      : { point: v("point-input").toUpperCase(), point_label: "" });
    Object.assign(data, { street: v("street"), postcode: v("postal"), city: v("city") });
  } else {
    Object.assign(data, { name: v("name"), recipient: v("recipient"), dedication: v("dedication"),
      invoice: Boolean($("invoice")?.checked), terms: $("terms").checked });
  }
  if (data.invoice) Object.assign(data, { company: v("company"), nip: v("nip").replace(/\D/g, ""), company_address: v("company_address") });
  return data;
}

function check(data) {
  const e = {};
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(data.email)) e.email = t("err.email");
  if (data.name.length < 3 || !data.name.includes(" ")) e.name = t("err.name");
  if (shipped()) {
    if (!/^\d{9}$/.test(data.phone.replace(/[\s\-()]/g, "").replace(/^(\+48|0048)/, ""))) e.phone = t("err.phone");
    if (!data.delivery) e.delivery = t("err.delivery");
    if (data.delivery === "paczkomat" && !/^[A-Z0-9-]{4,20}$/.test(data.point)) e.point = t("err.point");
    if (data.street.length < 3 || !/\d/.test(data.street)) e.street = t("err.street");
    if (!/^\d{2}-\d{3}$/.test(data.postcode)) e.postcode = t("err.postcode");
    if (data.city.length < 2) e.city = t("err.city");
  } else if (!data.recipient) {
    e.recipient = t("err.recipient");
  }
  if (data.invoice) {
    if (data.company.length < 2) e.company = t("err.company");
    if (!validNip(data.nip)) e.nip = t("err.nip");
    if (!/^.+?,?\s+\d{2}-\d{3}\s+.+$/.test(data.company_address)) e.company_address = t("err.company_address");
  }
  if (!data.terms) e.terms = t("err.terms");
  return e;
}

function showErrors(errors) {
  form.querySelectorAll("[data-field]").forEach(el => {
    const msg = errors[el.dataset.field];
    el.classList.toggle("invalid", Boolean(msg));
    const box = el.dataset.field === "delivery" ? $("delivery-error") : el.querySelector(".error");
    if (box) { box.textContent = msg || ""; box.style.display = msg ? "block" : ""; }
    el.querySelector("input")?.setAttribute("aria-invalid", msg ? "true" : "false");
  });
}

function validate() {
  const errors = check(collect());
  showErrors(errors);
  return errors;
}

function focusFirstError() {
  const el = form.querySelector(".invalid");
  if (!el) return;
  el.scrollIntoView({ behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "center" });
  (el.querySelector("input:not([type=radio])") || el.querySelector("input") || $("point-open"))?.focus({ preventScroll: true });
}

// ---------- input helpers (shipping fields exist only on a shipped product's page) ----------
$("postal")?.addEventListener("input", e => {
  const digits = e.target.value.replace(/\D/g, "").slice(0, 5);
  e.target.value = digits.length > 2 ? `${digits.slice(0, 2)}-${digits.slice(2)}` : digits;
});
$("point-input")?.addEventListener("input", e => { e.target.value = e.target.value.toUpperCase(); });

// ---------- draft (survives a cancelled payment or a reload) ----------
const DRAFT_FIELDS = ["email", "phone", "name", "street", "postal", "city", "recipient", "dedication",
  "company", "nip", "company_address", "point-input"].filter(id => $(id));
function saveDraft() {
  const d = { qty, delivery: selectedDelivery(), invoice: Boolean($("invoice")?.checked), point };
  DRAFT_FIELDS.forEach(id => d[id] = $(id).value);
  store.write(d);
}
function loadDraft() {
  const d = store.read();
  DRAFT_FIELDS.forEach(id => { if (typeof d[id] === "string") $(id).value = d[id]; });
  if (Number.isInteger(d.qty)) qty = Math.min(Math.max(d.qty, 1), cfg.product.max_qty);
  const radio = d.delivery && form.querySelector(`input[name="delivery"][value="${CSS.escape(d.delivery)}"]`);
  if (radio) radio.checked = true;
  if ($("invoice")) $("invoice").checked = Boolean(d.invoice);
  if (d.point?.code) point = d.point;
}

// ---------- payment ----------
let sdk = null;
function loadSdk() {
  sdk ??= new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = cfg.paymoveSdk;
    s.async = true;
    s.onload = () => window.Paymove.ready().then(resolve, reject);
    s.onerror = reject;
    document.head.append(s);
  });
  return sdk;
}

// Sandbox pricing can return a PAY link for the same priced item. Checkout
// needs the provider's pricing token (not our order ID) in its externalId query.
function checkoutUrl(redirectUrl) {
  const match = /^https:\/\/pay\.sandbox\.paymove\.io\/([\w-]+)(?:\/|\?externalId=)([\w-]+)$/.exec(redirectUrl);
  if (!match) return redirectUrl;
  return `https://checkout.sandbox.paymove.io/${match[1]}?externalId=${match[2]}`;
}

function setBusy(on) {
  submitting = on;
  payBtn.disabled = on;
  render();
}

function showAlert(text, info = false) {
  $("attempt-status").hidden = true;
  alertBox.textContent = text;
  alertBox.classList.toggle("info", info);
  alertBox.hidden = !text;
}

// The modal can close without reporting success, so the webhook's status decides what the buyer sees.
async function paymentConfirmed(orderId) {
  for (let i = 0; i < 4; i++) {
    if (i) await new Promise(r => setTimeout(r, 1000));
    try {
      const res = await fetch(`${I.api}status.php?o=${encodeURIComponent(orderId)}`, { cache: "no-store" });
      if (res.ok && ["paid", "review"].includes((await res.json()).status)) return true;
    } catch {}
  }
  return false;
}

form.addEventListener("submit", async e => {
  e.preventDefault();
  if (submitting) return;
  touched = true;
  showAlert("");
  if (Object.keys(validate()).length) { focusFirstError(); return; }

  setBusy(true);
  try {
    const payload = collect();
    const send = async () => {
      const attemptKey = await HeartshopAttempt.begin(payload);
      const res = await fetch(`${I.api}order.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": attemptKey },
        body: JSON.stringify(payload),
      });
      const body = await res.json().catch(() => ({}));
      HeartshopAttempt.remember(attemptKey, body.orderId);
      return [res, body];
    };
    const [res, body] = await send();
    if (res.status === 422 && body.errors) {
      HeartshopAttempt.clear(); // Validation rejected before an order/payment was created.
      showErrors(I.fieldErrors(body.errors)); focusFirstError(); setBusy(false); return;
    }
    const thanks = `${I.page.thanks}?o=${encodeURIComponent(body.orderId || "")}`;
    if (res.ok && body.orderId && ["paid", "review", "processing"].includes(body.status)) {
      location.assign(thanks); return;
    }
    if (!res.ok || !body.redirectUrl) throw new Error((body.error && I.serverError(body)) || t("alert.uncertain"));
    const url = checkoutUrl(body.redirectUrl);
    if (url.startsWith("https://pay.sandbox.paymove.io/")) {
      setBusy(false);
      location.assign(url); // Preserve unrecognized provider URL formats intact.
      return;
    }
    let Paymove;
    try { Paymove = await loadSdk(); } catch { setBusy(false); location.assign(url); return; }
    let paid = false;
    Paymove.openCheckout(url, {
      onSuccess: () => { paid = true; store.clear(); },
      onComplete: () => location.assign(thanks),
      onCancel: async () => {
        if (paid) { location.assign(thanks); return; }
        showAlert(t("alert.checking"), true);
        if (await paymentConfirmed(body.orderId)) { store.clear(); location.assign(thanks); return; }
        setBusy(false);
        showAlert(t("alert.closed"), true);
      },
      onError: async () => {
        if (await paymentConfirmed(body.orderId)) { store.clear(); location.assign(thanks); return; }
        setBusy(false); showAlert(t("alert.error"));
      },
    });
  } catch (err) {
    setBusy(false);
    showAlert(err.message || t("alert.offline"));
    const orderId = HeartshopAttempt.orderId();
    if (orderId) {
      const link = $("attempt-status");
      link.href = `${I.page.thanks}?o=${encodeURIComponent(orderId)}`;
      link.hidden = false;
    }
  }
});

// ---------- init ----------
function updateGiftPreview() {
  const value = id => $(id).value.trim().replace(/\s+/g, " ");
  $("preview-for").textContent = t("voucher.for", { name: value("recipient") || "…" });
  $("preview-message").textContent = value("dedication") || t("voucher.defaultDedication");
}

if ($("preview-for")) {
  $("recipient").addEventListener("input", updateGiftPreview);
  $("dedication").addEventListener("input", updateGiftPreview);
  updateGiftPreview();
}

async function init() {
  try {
    const res = await fetch(`${I.api}order.php`, { cache: "no-store" });
    cfg = await res.json();
    // This page's offer; pages without data-offer (and older API answers) sell the default product.
    if (I.offer) cfg.product = cfg.offers?.[I.offer];
    if (!res.ok || !cfg.product) throw new Error();
  } catch {
    showAlert(t("alert.down"));
    payBtn.disabled = true;
    return;
  }
  $("demo-banner").hidden = !cfg.sandbox;
  syncBannerOffset();
  $("p-name").textContent = I.local("product.name", cfg.product.name);
  $("p-variant").textContent = I.local("product.variant", cfg.product.variant);
  $("p-price").textContent = pln(cfg.product.price);
  if (!cfg.invoice && $("invoice-toggle")) { $("invoice-toggle").hidden = true; }
  if (shipped()) buildDelivery();
  loadDraft();
  if (!shipped()) updateGiftPreview();
  updateReveals();
  if (shipped()) showPoint();
  render();

  $("qty-minus").addEventListener("click", () => { qty = Math.max(1, qty - 1); render(); saveDraft(); });
  $("qty-plus").addEventListener("click", () => { qty = Math.min(cfg.product.max_qty, qty + 1); render(); saveDraft(); });
  $("invoice")?.addEventListener("change", () => { updateReveals(); saveDraft(); if (touched) validate(); });
  $("terms").addEventListener("change", () => { if (touched) validate(); });
  if (shipped()) {
    $("point-open").addEventListener("click", openMap);
    $("point-change").addEventListener("click", openMap);
    $("map-close").addEventListener("click", () => $("map-dialog").close());
  }
  form.addEventListener("input", () => { saveDraft(); if (touched) validate(); });

  // Method logos under the button; loading the SDK early also makes the modal open faster.
  loadSdk().then(() => {
    const bar = document.createElement("paymove-checkout");
    bar.setAttribute("mode", "readonly");
    bar.setAttribute("label", t("methods"));
    bar.setAttribute("methods", "BLIK,GPAY,APAY,PAY_BY_LINK");
    bar.setAttribute("full-width", "true");
    $("methods").append(bar);
  }).catch(() => {});
}
init();
