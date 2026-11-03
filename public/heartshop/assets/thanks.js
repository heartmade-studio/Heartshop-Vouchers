"use strict";
// Shows the order state written by the webhook. This page never marks anything as paid.
const id = new URLSearchParams(location.search).get("o") || "";
const $ = x => document.getElementById(x);
const I = HeartshopI18n, t = I.t, pln = I.money;
const started = Date.now();

function identify(o) {
  const notice = document.querySelector(".demo-context");
  if (notice) notice.hidden = !o.demo;
  if (o.type !== "shipping") return;
  const legacy = o.offer === "1";
  const name = I.lang === "en" ? (legacy ? "Magic candle" : "Magic box") : (legacy ? "Magiczna świeca" : "Magiczne pudełko");
  const brand = document.querySelector(".brand");
  const photo = document.querySelector(".thanks-photo");
  if (brand) brand.textContent = name;
  if (photo) { photo.src = (I.lang === "en" ? "../assets/" : "assets/") + (legacy ? "magic-box.webp" : "magic-box.webp"); photo.hidden = false; }
  document.title = name;
}

function show(state, o = {}) {
  if (o.offer) identify(o);
  const badge = $("badge");
  if (state === "paid") {
    badge.className = "badge-mark";
    badge.textContent = "✓";
    $("title").textContent = t("thanks.paid.title", { name: o.name ? ", " + o.name : "" });
    $("text").textContent = t(o.type === "voucher" ? "thanks.voucher.text" : "thanks.paid.text", { number: o.number, total: pln(o.total), email: o.email });
    if (o.type === "voucher") {
      $("action").textContent = t("thanks.voucher.action");
      $("action").href = `twoj-voucher.html?o=${encodeURIComponent(id)}`;
    }
    try { sessionStorage.removeItem(`heartshop-draft:${I.scope}`); } catch {}
    try { HeartshopAttempt.clearFor(id); } catch {} // the next purchase is a new attempt
  } else if (state === "review") {
    badge.className = "badge-mark wait";
    badge.textContent = "!";
    $("title").textContent = t("thanks.review.title");
    $("text").textContent = t("thanks.review.text", { number: o.number });
  } else if (state === "canceled") {
    badge.className = "badge-mark wait";
    badge.textContent = "!";
    $("title").textContent = t("thanks.canceled.title");
    $("text").textContent = t("thanks.canceled.text");
    try { HeartshopAttempt.clearFor(id); } catch {}
    $("action").textContent = t("thanks.canceled.action");
    if (o.type === "shipping" && o.offer === "1") {
      $("action").hidden = true;
      $("text").textContent = t("thanks.legacy.canceled");
    } else $("action").href = I.page.order;
  } else if (state === "slow") {
    badge.className = "badge-mark wait";
    badge.textContent = "✉";
    $("title").textContent = t("thanks.slow.title");
    $("text").textContent = t("thanks.slow.text");
  } else if (state === "unknown") {
    badge.className = "badge-mark wait";
    badge.textContent = "?";
    $("title").textContent = t("thanks.unknown.title");
    $("text").textContent = t("thanks.unknown.text");
  }
}

async function poll() {
  try {
    const res = await fetch(`${I.api}status.php?o=${encodeURIComponent(id)}`, { cache: "no-store" });
    const o = await res.json();
    if (o.offer) identify(o);
    if (o.status === "paid" || o.status === "review" || o.status === "canceled" || o.status === "unknown") return show(o.status, o);
  } catch {}
  if (Date.now() - started > 45000) return show("slow");
  setTimeout(poll, 2000);
}
// The language switch keeps the order id, so the other language shows the same order.
const alt = document.getElementById("lang-alt");
if (alt) alt.search = location.search;
poll();
