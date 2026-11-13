"use strict";
// Printable vouchers of one paid order. The order id in the link is the only key; the page never changes state.
const id = new URLSearchParams(location.search).get("o") || "";
const $ = x => document.getElementById(x);
const I = HeartshopI18n, t = I.t;

function card(o, v) {
  const el = $("voucher-template").content.firstElementChild.cloneNode(true);
  const set = (sel, text) => { const n = el.querySelector(sel); n.textContent = text; n.hidden = !text; };
  // The demo label sits on the printed face, so a forwarded sandbox PDF still says it.
  el.querySelector(".gift-demo").hidden = !o.demo;
  // Older orders may have no recipient: print no name rather than an invented one.
  set(".gift-for", o.recipient ? t("voucher.for", { name: o.recipient }) : "");
  set(".gift-message", o.dedication || t("voucher.defaultDedication"));
  set(".service-cover h2", o.product);
  set(".booking-code", v.code);
  set(".booking-expiry", v.expires);
  set(".gift-status", v.status === "used" ? t("voucher.used") : "");
  if (o.contact) set(".booking-note", o.contact);
  return el;
}

async function load() {
  let o = {};
  try {
    const res = await fetch(`${I.api}status.php?o=${encodeURIComponent(id)}`, { cache: "no-store" });
    o = await res.json();
  } catch {}
  if (o.status !== "paid" || !Array.isArray(o.vouchers) || !o.vouchers.length) {
    $("vouchers").hidden = true;
    $("print").hidden = true;
    $("wait").hidden = false;
    $("wait-title").textContent = t(o.status === "unknown" ? "thanks.unknown.title" : "voucher.wait.title");
    $("wait-text").textContent = t(o.status === "unknown" ? "thanks.unknown.text" : "voucher.wait.text");
    return;
  }
  $("vouchers").replaceChildren(...o.vouchers.map(v => card(o, v)));
  $("print").hidden = false;
  $("download").hidden = false;
}

let pageImages;
async function outputVoucher(action) {
  const button = $(action);
  const label = button.textContent;
  $("print").disabled = $("download").disabled = true;
  button.textContent = "Przygotowuję…";
  $("download-error").hidden = true;
  try {
    const { renderVoucherPages, downloadVoucher, preparePrint } = await import("./voucher-download.js?v=2");
    pageImages ??= await renderVoucherPages($("vouchers"));
    await preparePrint(pageImages);
    if (action === "download") await downloadVoucher(pageImages);
    else print();
  } catch {
    if (action === "print") print();
    else $("download-error").hidden = false;
  } finally {
    button.textContent = label;
    $("print").disabled = $("download").disabled = false;
  }
}
$("print").addEventListener("click", () => outputVoucher("print"));
$("download").addEventListener("click", () => outputVoucher("download"));
load();
