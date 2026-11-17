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
