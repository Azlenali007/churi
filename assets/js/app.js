/**
 * SMM PRO Shared Frontend JavaScript Utilities
 * Pure Vanilla JS + Alpine.js component factories (zero TS/JSX).
 */

window.showToast = function (message, type = 'success') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const colors = {
    success: 'bg-emerald-600 text-white shadow-emerald-500/25',
    error: 'bg-rose-600 text-white shadow-rose-500/25',
    info: 'bg-purple-600 text-white shadow-purple-500/25',
    warning: 'bg-amber-500 text-white shadow-amber-500/25',
  };

  const el = document.createElement('div');
  el.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-semibold transform transition-all duration-300 translate-y-2 opacity-0 ${colors[type] || colors.info}`;
  el.innerHTML = `<span class="flex-1"></span><button type="button" class="opacity-80 hover:opacity-100 ml-2">&times;</button>`;
  el.querySelector('span').textContent = message;
  el.querySelector('button').onclick = () => el.remove();

  container.appendChild(el);
  requestAnimationFrame(() => {
    el.classList.remove('translate-y-2', 'opacity-0');
  });

  setTimeout(() => {
    el.classList.add('translate-y-2', 'opacity-0');
    setTimeout(() => el.remove(), 300);
  }, 4500);
};

window.flashCountdown = function (endTimestampMs) {
  return {
    days: '00',
    hours: '00',
    minutes: '00',
    seconds: '00',
    expired: false,
    timer: null,
    init() {
      this.tick();
      this.timer = setInterval(() => this.tick(), 1000);
    },
    tick() {
      const diff = endTimestampMs - Date.now();
      if (diff <= 0) {
        this.expired = true;
        if (this.timer) clearInterval(this.timer);
        return;
      }
      const d = Math.floor(diff / 86400000);
      const h = Math.floor((diff % 86400000) / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      this.days = String(d).padStart(2, '0');
      this.hours = String(h).padStart(2, '0');
      this.minutes = String(m).padStart(2, '0');
      this.seconds = String(s).padStart(2, '0');
    },
  };
};

window.promoPopup = function (popupId, frequency) {
  return {
    open: false,
    dontShowToday: false,
    storageKey: `smm_popup_${popupId}`,
    init() {
      try {
        const raw = localStorage.getItem(this.storageKey);
        if (raw) {
          const data = JSON.parse(raw);
          if (frequency === 'once' && data.dismissed) return;
          if (data.hideUntil && Date.now() < data.hideUntil) return;
        }
      } catch (e) {}
      setTimeout(() => {
        this.open = true;
      }, 450);
    },
    close(suppressToday) {
      this.open = false;
      try {
        const payload = { dismissed: true, hideUntil: null };
        if (suppressToday || frequency === 'daily') {
          payload.hideUntil = Date.now() + 86400000;
        } else if (frequency === 'every_visit') {
          return; // Do not persist dismissal
        }
        localStorage.setItem(this.storageKey, JSON.stringify(payload));
      } catch (e) {}
    },
  };
};

window.copyText = function (text, label = 'Copied to clipboard') {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => window.showToast(label, 'success'));
  } else {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    window.showToast(label, 'success');
  }
};

window.SMM_CURRENCY_STATE = window.__SMM_CURRENCY_CFG || {
  activeCode: 'INR',
  activeSymbol: '₹',
  activeName: 'Indian Rupee',
  baseCode: 'INR',
  baseSymbol: '₹',
  currencies: {
    INR: { code: 'INR', name: 'Indian Rupee', symbol: '₹', rate: 1.0, is_base: true },
  },
};

window.smmGetCurrencyInfo = function (code) {
  const state = window.SMM_CURRENCY_STATE;
  const upper = String(code || state.activeCode || 'INR').toUpperCase().trim();
  if (state.currencies && state.currencies[upper]) {
    return state.currencies[upper];
  }
  return {
    code: upper,
    name: state.activeName || upper,
    symbol: state.activeSymbol || `${upper} `,
    rate: 1.0,
    is_base: upper === state.baseCode,
  };
};

window.smmConvertFromBase = function (baseAmount, targetCode) {
  const state = window.SMM_CURRENCY_STATE;
  const target = window.smmGetCurrencyInfo(targetCode || state.activeCode);
  const amt = Number(baseAmount || 0);
  if (!Number.isFinite(amt)) return 0;
  if (target.code === state.baseCode) return amt;
  const rate = Number(target.rate || 1);
  return rate > 0 ? amt * rate : amt;
};

window.smmConvertCurrency = function (amount, fromCode, toCode) {
  const state = window.SMM_CURRENCY_STATE;
  const from = String(fromCode || state.baseCode).toUpperCase().trim();
  const to = String(toCode || state.activeCode).toUpperCase().trim();
  const amt = Number(amount || 0);
  if (!Number.isFinite(amt)) return 0;
  if (from === to) return amt;
  let baseAmt = amt;
  if (from !== state.baseCode) {
    const fromInfo = window.smmGetCurrencyInfo(from);
    const fromRate = Number(fromInfo.rate || 1);
    baseAmt = fromRate > 0 ? amt / fromRate : amt;
  }
  return window.smmConvertFromBase(baseAmt, to);
};

window.smmFormatCurrency = function (numericAmount, code, decimals = 2, showCode = false, rateMode = false) {
  const info = window.smmGetCurrencyInfo(code);
  let val = Number(numericAmount || 0);
  if (!Number.isFinite(val)) val = 0;
  let d = Number(decimals);
  if (rateMode) {
    d = 2;
    if (val > 0 && val < 1) {
      const fixed4 = val.toFixed(4).replace(/0+$/, '');
      const dotIdx = fixed4.indexOf('.');
      const fracLen = dotIdx !== -1 ? fixed4.length - dotIdx - 1 : 2;
      d = Math.max(2, Math.min(4, fracLen));
    }
  }
  const factor = Math.pow(10, d);
  const rounded = Math.round((val + Number.EPSILON) * factor) / factor;
  const neg = rounded < 0;
  const absStr = Math.abs(rounded).toLocaleString('en-US', {
    minimumFractionDigits: d,
    maximumFractionDigits: d,
  });
  return `${neg ? '-' : ''}${info.symbol}${absStr}${showCode ? ' ' + info.code : ''}`;
};

window.smmApplyDisplayCurrency = function (code) {
  const state = window.SMM_CURRENCY_STATE;
  const info = window.smmGetCurrencyInfo(code);
  state.activeCode = info.code;
  state.activeSymbol = info.symbol;
  state.activeName = info.name;

  // 1. Update base-stored monetary amounts (balance, total spent, rates, flash sales, charts)
  document.querySelectorAll('[data-smm-money]').forEach((el) => {
    const mode = el.getAttribute('data-smm-money');
    const baseVal = el.getAttribute('data-base-amount') || '0';
    const showCode = el.getAttribute('data-show-code') === '1';
    const prefix = el.getAttribute('data-prefix') || '';
    const converted = window.smmConvertFromBase(baseVal, info.code);
    if (mode === 'rate') {
      el.textContent = prefix + window.smmFormatCurrency(converted, info.code, 2, showCode, true);
    } else {
      const decimals = Number(el.getAttribute('data-decimals') || 2);
      el.textContent = prefix + window.smmFormatCurrency(converted, info.code, decimals, showCode, false);
    }
  });

  // 2. Update immutable historical orders / transactions (single-step from original currency)
  document.querySelectorAll('[data-smm-equiv]').forEach((el) => {
    const origAmt = el.getAttribute('data-orig-amount') || '0';
    const origCurr = String(el.getAttribute('data-orig-currency') || state.baseCode).toUpperCase();
    const decimals = Number(el.getAttribute('data-decimals') || 2);
    const showCode = el.getAttribute('data-show-code') === '1';
    const prefix = el.getAttribute('data-prefix') || '';
    const converted = window.smmConvertCurrency(origAmt, origCurr, info.code);
    const mainEl = el.querySelector('[data-smm-equiv-main]');
    const origEl = el.querySelector('[data-smm-equiv-orig]');
    if (mainEl) {
      mainEl.textContent = prefix + window.smmFormatCurrency(converted, info.code, decimals, showCode, false);
    }
    if (origEl) {
      if (info.code === origCurr) {
        origEl.classList.add('hidden');
      } else {
        origEl.classList.remove('hidden');
      }
    }
  });

  // 3. Update active currency labels/badges & hidden form currency inputs
  document.querySelectorAll('[data-smm-curr-code]').forEach((el) => {
    el.textContent = info.code;
  });
  document.querySelectorAll('[data-smm-curr-symbol]').forEach((el) => {
    el.textContent = info.symbol;
  });
  document.querySelectorAll('[data-smm-curr-badge]').forEach((el) => {
    const tpl = el.getAttribute('data-smm-curr-badge') || '{symbol} {code}';
    el.textContent = tpl.replace('{symbol}', info.symbol).replace('{code}', info.code);
  });
  document.querySelectorAll('input[name="display_currency"]').forEach((el) => {
    el.value = info.code;
  });

  window.dispatchEvent(
    new CustomEvent('smm:currency-changed', {
      detail: {
        code: info.code,
        symbol: info.symbol,
        name: info.name,
        rate: info.rate,
        baseCode: state.baseCode,
        currencies: state.currencies,
      },
    })
  );
};

window.smmSwitchCurrency = function (code) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
  const targetCode = String(code || '').toUpperCase().trim();
  const body = new URLSearchParams({
    action: 'set_currency',
    currency: targetCode,
    _csrf_token: csrf,
  });

  return fetch('/api/ajax.php', {
    method: 'POST',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrf,
    },
    body: body.toString(),
  })
    .then(async (r) => {
      const text = await r.text();
      let res = null;
      try {
        res = JSON.parse(text);
      } catch (e) {
        throw new Error('Invalid server response.');
      }
      if (res && res.ok) {
        if (res.currencies) {
          window.SMM_CURRENCY_STATE.currencies = res.currencies;
        }
        if (res.base_code) {
          window.SMM_CURRENCY_STATE.baseCode = res.base_code;
        }
        if (res.csrf_token && csrfMeta) {
          csrfMeta.setAttribute('content', res.csrf_token);
        }
        const active = (res.currency && res.currency.code) || targetCode;
        window.smmApplyDisplayCurrency(active);
        window.showToast(`Display currency switched to ${active}`, 'success');
        return res;
      }
      window.showToast((res && res.error) || 'Could not change display currency.', 'error');
      return res;
    })
    .catch(() => {
      window.showToast('Network error while updating display currency.', 'error');
      return null;
    });
};

window.smmCurrencySelector = function (cfg) {
  const initial = cfg || window.SMM_CURRENCY_STATE;
  return {
    openCurr: false,
    switching: false,
    activeCode: initial.activeCode || 'INR',
    activeSymbol: initial.activeSymbol || '₹',
    init() {
      window.addEventListener('smm:currency-changed', (ev) => {
        if (ev.detail && ev.detail.code) {
          this.activeCode = ev.detail.code;
          this.activeSymbol = ev.detail.symbol;
        }
      });
    },
    selectCurrency(code) {
      if (this.switching) return;
      const target = String(code || '').toUpperCase().trim();
      if (!target) return;
      this.openCurr = false;
      if (target === this.activeCode) return;
      this.switching = true;
      window.smmSwitchCurrency(target).finally(() => {
        this.switching = false;
      });
    },
  };
};

// Smooth Dual Store Switching Animation & Double-Click Guard
(function initDualStoreSwitcher() {
  let isSwitching = false;

  function ensureOverlay() {
    let overlay = document.getElementById('store-transition-overlay');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'store-transition-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
      '<div class="store-switch-card px-6 py-4 rounded-3xl bg-white border border-purple-200 shadow-2xl shadow-purple-900/15 flex items-center gap-3.5">' +
      '<div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-violet-600 to-purple-600 text-white flex items-center justify-center shadow-md shadow-purple-600/25 shrink-0">' +
      '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path></svg>' +
      '</div>' +
      '<div>' +
      '<div id="store-switch-title" class="text-sm font-extrabold text-slate-900">Switching Store...</div>' +
      '<div class="text-xs font-medium text-slate-500">Keeping your account &amp; wallet synced</div>' +
      '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  document.addEventListener('click', function (ev) {
    const link = ev.target && ev.target.closest ? ev.target.closest('a[data-store-switch]') : null;
    if (!link) return;
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || link.target === '_blank') return;

    ev.preventDefault();
    if (isSwitching) return;
    isSwitching = true;

    const targetStore = link.getAttribute('data-store-switch') === 'otp' ? 'otp' : 'smm';
    const destHref = link.getAttribute('href') || (targetStore === 'otp' ? '/store-switch.php?to=otp' : '/store-switch.php?to=smm');

    try {
      sessionStorage.setItem('store_switch_enter', targetStore);
    } catch (e) {}

    const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) {
      window.location.href = destHref;
      return;
    }

    const overlay = ensureOverlay();
    const titleEl = document.getElementById('store-switch-title');
    if (titleEl) {
      titleEl.textContent = targetStore === 'otp' ? 'Switching to OTP Store...' : 'Switching to SMM Store...';
    }
    requestAnimationFrame(function () {
      overlay.classList.add('is-active');
    });

    setTimeout(function () {
      window.location.href = destHref;
    }, 180);
  });

  function applyEntranceAnimation() {
    try {
      const entered = sessionStorage.getItem('store_switch_enter');
      if (!entered) return;
      sessionStorage.removeItem('store_switch_enter');
      const target = document.querySelector('main') || document.body;
      if (target) {
        target.classList.add('store-page-enter');
      }
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyEntranceAnimation);
  } else {
    applyEntranceAnimation();
  }
})();

