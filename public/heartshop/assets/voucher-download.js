// Use the browser's own HTML/CSS compositor for each face. The isolated SVG document
// has the physical print viewport, embedded fonts and photo, and the live print styles.
const width = 576;
const height = 384;
const svgNamespace = "http://www.w3.org/2000/svg";

async function embedAsset(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error("Voucher asset unavailable");
  const blob = await response.blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

async function printStyles() {
  const sheets = Array.from(document.querySelectorAll('link[rel="stylesheet"]'), link => link.sheet);
  const parts = [];
  for (const sheet of sheets) {
    for (const rule of sheet.cssRules) {
      if (rule instanceof CSSFontFaceRule) {
        const src = rule.style.getPropertyValue("src");
        const path = src.match(/url\(["']?([^"')]+)["']?\)/)[1];
        const data = await embedAsset(new URL(path, sheet.href));
        parts.push(rule.cssText.replace(src, 'url("' + data + '")'));
      } else if (rule instanceof CSSMediaRule && rule.conditionText.startsWith("screen")) {
        continue;
      } else if (rule instanceof CSSMediaRule && rule.conditionText === "print") {
        parts.push(...Array.from(rule.cssRules, inner => inner.cssText));
      } else {
        parts.push(rule.cssText);
      }
    }
  }
  return parts.join("\n");
}

async function faceImage(face, css, photo) {
  const root = document.createElementNS(svgNamespace, "svg");
  root.setAttribute("width", width * 3);
  root.setAttribute("height", height * 3);
  root.setAttribute("viewBox", "0 0 " + width + " " + height);
  const object = document.createElementNS(svgNamespace, "foreignObject");
  object.setAttribute("width", width);
  object.setAttribute("height", height);
  const html = document.documentElement.cloneNode(false);
  const style = document.createElement("style");
  // :root in an SVG image is the outer SVG; the HTML element supplies the offer overrides.
  style.textContent = css + "\n.gift-pair {width:6in} .gift-face {height:4in;box-shadow:none}";
  const body = document.body.cloneNode(false);
  const pair = face.parentElement.cloneNode(false);
  const copy = face.cloneNode(true);
  for (const img of copy.querySelectorAll("img")) img.src = photo;
  pair.append(copy);
  body.append(pair);
  html.append(style, body);
  object.append(html);
  root.append(object);
  const image = new Image();
  image.src = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(new XMLSerializer().serializeToString(root));
  await image.decode();
  const canvas = document.createElement("canvas");
  canvas.width = width * 3;
  canvas.height = height * 3;
  canvas.getContext("2d").drawImage(image, 0, 0);
  return canvas.toDataURL("image/png");
}

async function loadPdfLibrary() {
  if (window.jspdf) return;
  await new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = new URL("./vendor/jspdf.umd.min.js", import.meta.url);
    script.onload = resolve;
    script.onerror = reject;
    document.head.append(script);
  });
}

export async function renderVoucherPages(container) {
  const [css, photo] = await Promise.all([
    printStyles(), embedAsset(container.querySelector(".service-cover img").src),
  ]);
  const pages = [];
  for (const face of container.querySelectorAll(".gift-face")) {
    pages.push(await faceImage(face, css, photo));
  }
  return pages;
}

export async function downloadVoucher(pages) {
  await loadPdfLibrary();
  const pdf = new window.jspdf.jsPDF({ orientation: "landscape", unit: "pt", format: [432, 288], compress: true });
  for (const [index, data] of pages.entries()) {
    if (index) pdf.addPage([432, 288], "landscape");
    pdf.addImage(data, "PNG", 0, 0, 432, 288);
  }
  pdf.save("Voucher-na-masaz.pdf");
}

export async function preparePrint(pages) {
  const output = document.getElementById("print-pages");
  if (output.childElementCount) return;
  const images = pages.map(src => {
    const img = new Image();
    img.alt = "";
    img.src = src;
    return img;
  });
  await Promise.all(images.map(img => img.decode()));
  output.replaceChildren(...images);
  document.body.classList.add("print-pages-ready");
}
