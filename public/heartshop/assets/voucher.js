"use strict";
// Printable vouchers of one paid order. The order id in the link is the only key; the page never changes state.
const id = new URLSearchParams(location.search).get("o") || "";
const $ = x => document.getElementById(x);
const I = HeartshopI18n, t = I.t;

function card(o, v) {
  const el = $("voucher-template").content.firstElementChild.cloneNode(true);
  const set = (sel, text) => { const n = el.querySelector(sel); n.textContent = text; n.hidden = !text; };
  set(".v-kind", t("voucher.title"));
  el.querySelector(".v-demo").hidden = !o.demo;
  set(".v-product", o.product);
  set(".v-for", o.recipient ? t("voucher.for", { name: o.recipient }) : "");
  set(".v-dedication", o.dedication);
  set(".v-code-label", t("voucher.code"));
  set(".v-code", v.code);
  set(".v-valid", v.status === "used" ? t("voucher.used") : t("voucher.valid", { date: v.expires }));
  set(".v-how", o.contact ? `${t("voucher.how")} ${o.contact}` : t("voucher.how"));
  set(".v-seller", o.seller);
  el.classList.toggle("used", v.status === "used");
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
}

$("print").addEventListener("click", () => print());
load();
