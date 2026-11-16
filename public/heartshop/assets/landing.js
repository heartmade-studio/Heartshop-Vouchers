"use strict";
// Prices on the landing page come from api/config.php, so they never drift from the checkout.
const I = HeartshopI18n, pln = I.money;

function syncBannerOffset() {
  const banner = document.getElementById("demo-banner");
  const h = banner && !banner.hidden ? banner.getBoundingClientRect().height : 0;
  document.documentElement.style.setProperty("--banner-h", h + "px");
}

fetch(`${I.api}order.php`, { cache: "no-store" })
  .then(r => r.ok ? r.json() : Promise.reject())
  .then(cfg => {
    const product = (I.offer && cfg.offers?.[I.offer]) || cfg.product;
    document.getElementById("demo-banner").hidden = !cfg.sandbox;
    syncBannerOffset();
    const choices = product.type === "voucher" ? I.voucherChoices(cfg.offers, product) : [];
    const price = choices.length ? Math.min(...choices.map(o => o.price)) : product.price;
    // The built price list is the no-JavaScript fallback; hide rows the server would refuse to sell now.
    if (choices.length) document.querySelectorAll("[data-offer-price]").forEach(el => {
      const row = el.closest("tr");
      if (row) row.hidden = !choices.some(o => o.id === el.dataset.offerPrice);
    });
    document.querySelectorAll("[data-price]").forEach(el => el.textContent = pln(price));
    document.querySelectorAll("[data-offer-price]").forEach(el => {
      const offer = cfg.offers?.[el.dataset.offerPrice];
      if (offer) el.textContent = pln(offer.price);
    });
    document.querySelectorAll("[data-ship]").forEach(el => {
      const d = cfg.delivery[el.dataset.ship];
      if (d) el.textContent = pln(d.price);
    });
    document.querySelectorAll("[data-free]").forEach(el => {
      if (cfg.freeShippingFrom) el.textContent = pln(cfg.freeShippingFrom);
    });
  })
  .catch(() => {});

const header = document.querySelector(".site-header");
addEventListener("scroll", () => header.classList.toggle("scrolled", scrollY > 8), { passive: true });
if (typeof addEventListener === "function") addEventListener("resize", syncBannerOffset);
