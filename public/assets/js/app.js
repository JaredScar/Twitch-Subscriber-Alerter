/* Page helpers (no inline scripts: the site's CSP only allows files from this origin). */
(() => {
  'use strict';

  const labelOf = btn => btn.querySelector('span') || btn;

  // Show / hide the secret overlay URL
  document.querySelectorAll('[data-reveal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.reveal);
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      labelOf(btn).textContent = show ? 'Hide' : 'Show';
    });
  });

  // Copy to clipboard
  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const input = document.getElementById(btn.dataset.copy);
      const label = labelOf(btn);
      const old = label.textContent;
      try {
        await navigator.clipboard.writeText(input.value);
      } catch (e) {
        const type = input.type;
        input.type = 'text'; input.select(); document.execCommand('copy'); input.type = type;
      }
      label.textContent = 'Copied';
      setTimeout(() => { label.textContent = old; }, 1500);
    });
  });

  // Confirm dangerous forms
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', ev => { if (!confirm(form.dataset.confirm)) ev.preventDefault(); });
  });

  // Live volume read-out
  document.querySelectorAll('input[data-output]').forEach(range => {
    const out = document.getElementById(range.dataset.output);
    range.addEventListener('input', () => { out.textContent = range.value; });
  });

  // Submit form: show the panel for the chosen kind and disable the other panel's inputs
  document.querySelectorAll('[data-kind-switch]').forEach(group => {
    const form = group.closest('form');
    const panels = form.querySelectorAll('.kind-panel');
    const apply = () => {
      const kind = (form.querySelector('input[name=kind]:checked') || {}).value;
      panels.forEach(p => {
        const on = p.dataset.kind === kind;
        p.hidden = !on;
        p.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = !on; });
      });
    };
    group.addEventListener('change', apply);
    apply();
  });

  // Collapse a disclosure that holds a form with an error? (kept simple: nothing to do)

  const ago = iso => {
    const d = Math.max(0, (Date.now() - Date.parse(iso.replace(' ', 'T') + 'Z')) / 1000);
    if (d < 60) return 'just now';
    if (d < 3600) return Math.floor(d / 60) + ' min ago';
    if (d < 86400) return Math.floor(d / 3600) + ' h ago';
    return Math.floor(d / 86400) + ' d ago';
  };

  const typeNames = { test: 'Test', gift: 'Gift', sub: 'New sub', resub: 'Resub' };

  // Live dashboard bits: recent events + "overlay connected" indicator
  const table = document.getElementById('events-table');
  const status = document.getElementById('overlay-status');
  if (table || status) {
    const cell = (tr, text, cls) => { const td = tr.insertCell(); td.textContent = text; if (cls) td.className = cls; return td; };
    const feed = (table && table.dataset.feed) || 'api/events.php';

    const paintOverlay = (seen, now) => {
      if (!status) return;
      const dot = status.querySelector('.dot');
      const text = status.querySelector('.js-text');
      const sub = status.parentElement.querySelector('.js-sub');
      const secs = seen ? Math.max(0, (Date.parse(now.replace(' ', 'T') + 'Z') - Date.parse(seen.replace(' ', 'T') + 'Z')) / 1000) : Infinity;
      const live = secs < Number(status.dataset.liveFor || 45);
      dot.className = 'dot ' + (live ? 'dot-ok' : 'dot-warn');
      text.textContent = live ? 'Connected' : 'Not connected';
      sub.textContent = live ? 'OBS is showing your overlay.' : (seen ? 'Last seen ' + ago(seen) + '.' : 'Add the URL below to OBS.');
    };

    const refresh = async () => {
      try {
        const res = await fetch(feed, { cache: 'no-store', credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        paintOverlay(data.overlay_seen_at, data.now);
        if (!table) return;
        const rows = data.events.map(ev => {
          const tr = document.createElement('tr');
          cell(tr, ago(ev.at)).title = ev.at + ' UTC';
          const type = cell(tr, '');
          const chip = document.createElement('span');
          chip.className = 'chip';
          chip.textContent = typeNames[ev.type] || ev.type;
          type.appendChild(chip);
          cell(tr, ev.actor || '');
          cell(tr, ev.count ?? ev.months ?? '');
          if (ev.alert) cell(tr, ev.label || ev.alert);
          else cell(tr, ev.note || 'none', 'muted');
          return tr;
        });
        if (!rows.length) {
          const tr = document.createElement('tr');
          const td = tr.insertCell(); td.colSpan = 5; td.className = 'muted';
          td.textContent = 'Nothing yet. Send a test above, or wait for the next sub.';
          rows.push(tr);
        }
        table.tBodies[0].replaceChildren(...rows);
      } catch (e) { /* offline: try again next tick */ }
    };
    setInterval(refresh, 5000);
  }
})();
