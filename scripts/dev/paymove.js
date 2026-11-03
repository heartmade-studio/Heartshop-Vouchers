// Local harness only. This file is never part of the public web root.
window.Paymove = {
  ready: async () => {
    const banner = document.getElementById('demo-banner');
    if (banner) banner.textContent = document.documentElement.lang === 'en'
      ? 'LOCAL SIMULATION — no real payments, email or fulfillment.'
      : 'LOKALNA SYMULACJA — bez prawdziwych płatności, wiadomości i realizacji.';
    return window.Paymove;
  },
  openCheckout: async (url, callbacks) => {
    const orderId = new URL(url).pathname.split('/').pop();
    const paid = window.confirm('LOKALNA SYMULACJA — bez pieniędzy i wysyłki.\n\nOK: symuluj opłacenie. Anuluj: symuluj anulowanie.');
    try {
      const response = await fetch('/__dev/settle', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({orderId,cancel:!paid})});
      if (!response.ok) throw new Error('Simulation failed');
      if (paid) { callbacks.onSuccess(); callbacks.onComplete(); }
      else callbacks.onCancel();
    } catch { callbacks.onError(); }
  }
};
