(function () {
  const $ = (sel, root = document) => root.querySelector(sel);

  function pad(n) { return String(n).padStart(2, '0'); }
  function fmtCountdown(ms) {
    if (ms <= 0) return 'nu';
    const s = Math.floor(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return `${pad(h)}:${pad(m)}:${pad(sec)}`;
  }

  function hashString(input) {
    let hash = 0;
    if (!input) return hash;
    for (let i = 0; i < input.length; i++) {
      hash = (hash << 5) - hash + input.charCodeAt(i);
      hash |= 0;
    }
    return hash;
  }

  async function api(path, opts = {}) {
    const url = `${DDBSpinwheel.restUrl}${path}`;
    const headers = Object.assign({}, opts.headers || {}, {
      'X-WP-Nonce': DDBSpinwheel.nonce,
      'Content-Type': 'application/json',
    });
    const res = await fetch(url, Object.assign({}, opts, { headers }));
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(json?.error || `HTTP ${res.status}`);
      err.data = json;
      throw err;
    }
    return json;
  }

  const wheelPalette = [
    { base: '#0ea5e9', dark: '#0369a1', accent: '#38bdf8' },
    { base: '#f59e0b', dark: '#b45309', accent: '#fbbf24' },
    { base: '#ef4444', dark: '#b91c1c', accent: '#fb7185' },
    { base: '#10b981', dark: '#047857', accent: '#34d399' },
    { base: '#f97316', dark: '#c2410c', accent: '#fdba74' },
    { base: '#84cc16', dark: '#3f6212', accent: '#bef264' },
    { base: '#22c55e', dark: '#15803d', accent: '#4ade80' },
    { base: '#14b8a6', dark: '#0f766e', accent: '#2dd4bf' },
  ];

  function buildWheel($wheel, segments) {
    const n = segments.length;
    const step = 360 / n;

    const paletteBySegment = segments.map((seg, i) => {
      const seed = seg?.key || seg?.label || String(i);
      const idx = Math.abs(hashString(seed)) % wheelPalette.length;
      return wheelPalette[idx];
    });

    const stops = [];
    for (let i = 0; i < n; i++) {
      const a1 = i * step;
      const a2 = (i + 1) * step;
      const palette = paletteBySegment[i];
      const mid = a1 + step * 0.6;
      stops.push(`${palette.base} ${a1}deg ${mid}deg`);
      stops.push(`${palette.dark} ${mid}deg ${a2}deg`);
    }
    $wheel.style.background = `conic-gradient(${stops.join(',')})`;

    $wheel.innerHTML = '';
    segments.forEach((seg, i) => {
      const el = document.createElement('div');
      el.className = 'ddb-spinwheel__seg';
      const rotate = i * step + step / 2;
      el.style.transform = `rotate(${rotate}deg) translateY(-46%)`;
      const palette = paletteBySegment[i];
      el.style.setProperty('--seg-accent', palette.accent);
      const label = document.createElement('span');
      label.className = 'ddb-spinwheel__seg-label';
      label.textContent = seg.label;
      el.appendChild(label);
      $wheel.appendChild(el);
    });
  }

  function computeTargetRotation(targetIndex, count, extraTurns, currentRotation) {
    const step = 360 / count;
    const targetCenter = targetIndex * step + step / 2; // measured from 3 o'clock, clockwise
    const pointerAngle = 270; // pointer sits at top (12 o'clock) which is -90deg / 270deg from 3 o'clock
    const current = ((currentRotation % 360) + 360) % 360;
    const desired = ((pointerAngle - targetCenter) % 360 + 360) % 360;
    const offset = (desired - current + 360) % 360;
    return extraTurns * 360 + offset;
  }

  function notice($wrap, text, kind = 'info') {
    const $n = $('[data-notice]', $wrap);
    if (!$n) return;
    $n.dataset.kind = kind;
    $n.textContent = text || '';
  }

  function modal($wrap, show) {
    const $m = $('[data-modal]', $wrap);
    if (!$m) return;
    $m.hidden = !show;
    $m.style.display = show ? 'grid' : 'none';
    if (show) $m.classList.add('is-open'); else $m.classList.remove('is-open');
  }

  function setPrizeModal($wrap, prize) {
    $('[data-prize]', $wrap).textContent = prize?.label || '-';
    const detail = $('[data-prize-detail]', $wrap);

    let lines = [];
    if (prize?.type === 'coupon' && prize?.award_meta?.coupon_code) {
      lines.push(`Couponcode: ${prize.award_meta.coupon_code}`);
      if (prize.award_meta.coupon_amount) lines.push(`Waarde: EUR ${prize.award_meta.coupon_amount}`);
      if (prize.award_meta.coupon_expires_in_days) lines.push(`Geldig: ${prize.award_meta.coupon_expires_in_days} dagen`);
      if (prize.award_meta.auto_apply_coupon) lines.push('Automatisch toegepast in je winkelwagen');
    } else if (prize?.type === 'credit') {
      if (prize?.award_meta?.credit_added) lines.push(`Tegoed erbij: EUR ${prize.award_meta.credit_added}`);
      if (prize?.award_meta?.credit_total != null) lines.push(`Totaal tegoed: EUR ${prize.award_meta.credit_total}`);
    } else if (prize?.value) {
      lines.push(String(prize.value));
    }

    detail.textContent = lines.join(' | ');
  }

  function attach(container) {
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const $wheel = $('[data-wheel]', container);
    const $spinButtons = container.querySelectorAll('[data-spin-btn]');
    const $refreshBtn = $('[data-refresh-btn]', container);
    const $testSpinBtn = $('[data-test-spin-btn]', container);
    const $balance = $('[data-balance]', container);
    const $countdown = $('[data-countdown]', container);
    const $close = $('[data-close-modal]', container);
    const $referral = $('[data-referral]', container);
    const $lastCoupon = $('[data-last-coupon]', container);
    const $couponBanner = $('[data-coupon-banner]', container);
    const $freeBadge = $('[data-free-badge]', container);
    const $earnButtons = container.querySelectorAll('[data-earn-action]');
    const $statusChip = $('[data-status-chip]', container);

    let state = { segments: [], busy: false, rotation: 0, countdownTimer: null, canSpin: false, freeSpinAvailable: false };

    function stopCountdown() {
      if (state.countdownTimer) clearInterval(state.countdownTimer);
      state.countdownTimer = null;
    }

    function startCountdown(nextFreeEpoch, serverEpoch) {
      stopCountdown();
      if (!nextFreeEpoch) { $countdown.textContent = 'nu'; return; }

      const clientNow = Date.now();
      const serverNow = serverEpoch * 1000;
      const skew = serverNow - clientNow;

      state.countdownTimer = setInterval(() => {
        const now = Date.now() + skew;
        const ms = nextFreeEpoch * 1000 - now;
        $countdown.textContent = fmtCountdown(ms);
        if (ms <= 0) { stopCountdown(); loadStatus(); }
      }, 250);
    }

    function spinLabel(btn, isFree) {
      const base = btn.getAttribute('data-spin-label') || 'SPIN';
      const free = btn.getAttribute('data-free-label') || 'GRATIS SPIN';
      const busy = btn.getAttribute('data-busy-label') || '...';
      if (state.busy) return busy;
      return isFree ? free : base;
    }

    function setButtons(canSpin) {
      const isFree = !!(canSpin && !state.busy && state.freeSpinAvailable);
      $spinButtons.forEach((btn) => {
        btn.disabled = !canSpin || state.busy;
        btn.textContent = spinLabel(btn, isFree);
        if (isFree) btn.classList.add('is-free'); else btn.classList.remove('is-free');
      });
      if ($freeBadge) $freeBadge.hidden = !state.freeSpinAvailable;
      if ($testSpinBtn) $testSpinBtn.disabled = state.busy;
    }

    function setStatusChip(text, kind = 'neutral') {
      if (!$statusChip) return;
      $statusChip.textContent = text;
      $statusChip.dataset.kind = kind;
    }

    async function loadStatus() {
      notice(container, '');
      setButtons(false);
      setStatusChip('Jeroen Bosch laadt...', 'neutral');
      container.classList.remove('is-spinning');

      let data;
      try { data = await api('/status'); }
      catch { notice(container, 'Kan status niet laden. Probeer opnieuw.', 'error'); return; }

      if (!data.logged_in) {
        $balance.textContent = '-';
        $countdown.textContent = '-';
        notice(container, 'Log in om te draaien.', 'info');
        setButtons(false);
        return;
      }

      state.segments = data.segments || [];
      if (state.segments.length >= 2) buildWheel($wheel, state.segments);

      $balance.textContent = String(data.spinners_balance ?? 0);
      startCountdown(data.next_free_spin_at, data.server_time || Math.floor(Date.now() / 1000));

      const canSpin = !!data.free_spin_available || (data.spinners_balance ?? 0) > 0;
      state.canSpin = canSpin;
      state.freeSpinAvailable = !!data.free_spin_available;
      setButtons(canSpin);

      if (state.freeSpinAvailable) {
        setStatusChip('Gratis spin klaar', 'ready');
      } else if (canSpin) {
        setStatusChip('Spins klaar', 'neutral');
      } else {
        setStatusChip('Geen spins. Verdien er een of wacht.', 'muted');
        notice(container, 'Geen spins klaar. Wacht op gratis spin of verdien er een via review/referral/check-in.', 'info');
      }

      if ($referral && data.referral_code) $referral.textContent = data.referral_code;
      if ($lastCoupon) {
        if (data.last_coupon?.code) {
          const exp = data.last_coupon.expires_at ? Number(data.last_coupon.expires_at) * 1000 : null;
          const now = (data.server_time || Math.floor(Date.now()/1000)) * 1000;
          let left = '';
          if (exp) {
            const ms = exp - now;
            if (ms > 0) {
              const hrs = Math.ceil(ms / 3600000);
              left = ` (nog ${hrs}u)`;
            }
          }
          $lastCoupon.textContent = `${data.last_coupon.code}${left}`;
        } else {
          $lastCoupon.textContent = '-';
        }
      }

      if ($couponBanner) {
        if (data.last_coupon?.code) {
          const exp = data.last_coupon.expires_at ? Number(data.last_coupon.expires_at) * 1000 : null;
          const now = (data.server_time || Math.floor(Date.now()/1000)) * 1000;
          let left = '';
          if (exp) {
            const ms = exp - now;
            if (ms > 0) {
              const hrs = Math.ceil(ms / 3600000);
              left = `Verloopt over ${hrs}u - gebruik 'm nu`;
            }
          }
          $couponBanner.textContent = `Coupon ${data.last_coupon.code} actief. ${left}`;
          $couponBanner.hidden = false;
        } else {
          $couponBanner.hidden = true;
          $couponBanner.textContent = '';
        }
      }
    }

    async function doSpin(isTest = false) {
      if (state.busy) return;
      if (!state.canSpin && !isTest) return;
      state.busy = true;
      setButtons(state.canSpin);
      notice(container, isTest ? 'Test spin - Jeroen Bosch draait zonder saldo te raken.' : 'Jeroen Bosch draait het rad...');
      setStatusChip(isTest ? 'Test spin (geen saldo)' : 'Rad draait...', 'active');

      let result;
      try { result = await api('/execute', { method: 'POST', body: JSON.stringify(isTest ? { test_spin: true } : {}) }); }
      catch (e) {
        state.busy = false;
        setButtons(state.canSpin);
        notice(container, e?.data?.error === 'no_spins_available' ? 'Geen spins klaar. Wacht op gratis spin of verdien er een.' : 'Jeroen Bosch kreeg het rad niet rond. Probeer opnieuw.', 'error');
        setStatusChip('Geen spins. Verdien er een of wacht.', 'muted');
        container.classList.remove('is-spinning');
        return;
      }

      const count = result.segment_count || state.segments.length || 1;
      const targetIndex = result.target_index || 0;
      const extraTurns = 6 + Math.floor(Math.random() * 3);

      const delta = computeTargetRotation(targetIndex, count, extraTurns, state.rotation);
      state.rotation = (state.rotation + delta) % 360000;

      const duration = prefersReducedMotion ? 2.2 : 4.6;
      const easing = prefersReducedMotion ? 'linear' : 'cubic-bezier(.12,.66,.12,1)';
      $wheel.style.transition = `transform ${duration}s ${easing}`;
      $wheel.style.transform = `rotate(${state.rotation}deg)`;
      container.classList.add('is-spinning');
      if (!prefersReducedMotion) {
        container.classList.add('is-pushing');
        setTimeout(() => container.classList.remove('is-pushing'), 260);
      }

      const onDone = () => {
        $wheel.removeEventListener('transitionend', onDone);
        $wheel.style.transition = '';
        container.classList.remove('is-spinning');

        $balance.textContent = String(result.spinners_balance ?? 0);
        startCountdown(result.next_free_spin_at, result.server_time || Math.floor(Date.now() / 1000));

        state.busy = false;
        if (!isTest) {
          state.canSpin = (result.spinners_balance ?? 0) > 0;
          if (result.used_free_spin) state.freeSpinAvailable = false;
        }
        setButtons(state.canSpin);

        setPrizeModal(container, result.prize);
        notice(container, isTest ? 'Test spin - saldo blijft gelijk.' : '');
        const statusText = state.freeSpinAvailable ? 'Gratis spin klaar' : (state.canSpin ? 'Spins klaar' : 'Geen spins');
        setStatusChip(statusText, (state.freeSpinAvailable || state.canSpin) ? 'ready' : 'muted');
        modal(container, true);
      };

      $wheel.addEventListener('transitionend', onDone, { once: true });
    }

    $spinButtons.forEach((btn) => {
      btn.addEventListener('click', () => doSpin());
    });
    $refreshBtn.addEventListener('click', loadStatus);
    if ($testSpinBtn) $testSpinBtn.addEventListener('click', () => doSpin(true));
    $close.addEventListener('click', () => modal(container, false));
    $('[data-modal]', container).addEventListener('click', (e) => {
      if (e.target && e.target.matches('[data-modal]')) modal(container, false);
    });

    async function earn(action) {
      if (state.busy) return;
      state.busy = true;
      setButtons(state.canSpin);
      notice(container, '');
      try {
        const res = await api('/earn', { method: 'POST', body: JSON.stringify({ action }) });
        $balance.textContent = String(res.spinners_balance ?? '-');
        notice(container, `+${res.spins_added} spin(s) verdiend via ${action}.`, 'info');
        await loadStatus();
      } catch (e) {
        state.busy = false;
        setButtons(state.canSpin);
        if (e?.data?.error === 'cooldown_active') {
          const hrs = e.data.cooldown_hours ?? '?';
          notice(container, `Cooldown actief. Probeer later (${hrs}u).`, 'info');
        } else {
          notice(container, 'Kon spins niet toevoegen. Probeer opnieuw.', 'error');
        }
        return;
      }
      state.busy = false;
      setButtons(state.canSpin);
    }

    $earnButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.getAttribute('data-earn-action');
        if (action) earn(action);
      });
    });

    loadStatus();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-ddb-spinwheel]').forEach(attach);
  });
})();
