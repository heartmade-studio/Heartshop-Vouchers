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
    // The built price list is the no-JavaScript fallback. With JavaScript the list shows exactly what the
    // server sells now, the same set as the checkout selector, adding rows a production build left out.
    const list = document.querySelector(".voucher-pricing");
    const closed = product.type === "voucher" && "sellable" in product && !choices.length;
    // Sales closed: replace every buy link and "from" price with a notice instead of inviting a refused order.
    if (closed) document.querySelectorAll("[data-buy]").forEach(el => {
      const note = document.createElement("span");
      note.className = "sales-closed";
      note.textContent = I.t("sales.closedShort");
      if (el.tagName === "A") el.replaceWith(note); else el.replaceChildren(note); // containers keep their layout
    });
    if (list && product.type === "voucher" && "sellable" in product) {
      list.hidden = !choices.length;
      const body = list.querySelector("tbody");
      if (body) body.replaceChildren(...choices.map(o => {
        const row = document.createElement("tr"), name = document.createElement("td"), cost = document.createElement("td");
        const link = document.createElement("a");
        link.href = `${I.page.order}?offer=${encodeURIComponent(o.id)}`;
        link.textContent = I.lang === "en" ? o.name_en || o.name : o.name;
        name.append(link);
        cost.dataset.offerPrice = o.id;
        cost.textContent = pln(o.price);
        row.append(name, cost);
        return row;
      }));
    }
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
