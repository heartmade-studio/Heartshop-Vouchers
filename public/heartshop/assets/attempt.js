"use strict";
// A retry is the same purchase, including after reload. Never rotate an uncertain
// attempt silently. Store only a random key, a digest and our order id, not another PII copy.
window.HeartshopAttempt = (() => {
  const { t, scope: namespace, api } = HeartshopI18n;
  const key = `heartshop-attempt:${namespace}`;
  const orderPattern = /^hs_[a-f0-9]{24}$/;
  const hex = bytes => Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  const stored = () => { // null when absent or unreadable; begin() still fails closed on unreadable.
    try { const attempt = JSON.parse(sessionStorage.getItem(key)); return attempt && typeof attempt === 'object' ? attempt : null; }
    catch { return null; }
  };
  return {
    async begin(payload) {
      const fingerprint = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(payload)))));
      const raw = sessionStorage.getItem(key); // Fail closed if storage is unavailable.
      if (raw !== null) {
        const attempt = JSON.parse(raw);
        if (!/^[a-f0-9]{32}$/.test(attempt?.key) || !/^[a-f0-9]{64}$/.test(attempt?.fingerprint)) {
          throw new Error(t('attempt.unreadable'));
        }
        if (attempt.fingerprint !== fingerprint) {
          throw new Error(t('attempt.changed'));
        }
        return attempt.key;
      }
      const attempt = { key: hex(crypto.getRandomValues(new Uint8Array(16))), fingerprint };
      const value = JSON.stringify(attempt);
      sessionStorage.setItem(key, value);
      if (sessionStorage.getItem(key) !== value) throw new Error(t('attempt.unsaved'));
      return attempt.key;
    },
    // Binds the server's order to this attempt, so a later page can release it safely.
    remember(attemptKey, orderId) {
      const attempt = stored();
      if (!orderPattern.test(orderId || '') || attempt?.key !== attemptKey) return;
      try { sessionStorage.setItem(key, JSON.stringify({ ...attempt, orderId })); } catch {}
    },
    // A server-confirmed final state of this attempt's own order ends it; other orders never do.
    clearFor(orderId) {
      if (orderPattern.test(orderId || '') && stored()?.orderId === orderId) this.clear();
    },
    // Changed data after a payment the operator canceled is a new purchase, not a conflict.
    async releaseIfCanceled() {
      const orderId = stored()?.orderId;
      if (!orderPattern.test(orderId || '')) return false;
      try {
        const res = await fetch(`${api}status.php?o=${encodeURIComponent(orderId)}`, { cache: 'no-store' });
        if (!res.ok || (await res.json()).status !== 'canceled') return false;
      } catch { return false; }
      this.clearFor(orderId);
      return stored() === null;
    },
    clear() { sessionStorage.removeItem(key); },
  };
})();
