/*
  OBS browser source runtime.

  Polls api/poll.php for new subscriber events and plays the matching alert, one at a time. The
  server decides which alert belongs to an event (the streamer's rules), so this page only has to
  play what it is told: built-in alerts are drawn by alerts/engine.js, custom alerts (approved by
  the site owner) by assets/js/custom-player.js using the descriptors the poll response carries.

  Options:  ?debug=1   show a small status line (testing outside OBS)
*/
(() => {
  'use strict';

  const body = document.body;
  const key = body.dataset.key;
  const pollUrl = body.dataset.poll;
  const interval = Math.max(500, parseInt(body.dataset.interval, 10) || 1500);
  const debug = new URLSearchParams(location.search).has('debug');
  const MAX_QUEUE = 10;
  const GAP_MS = 600;           // pause between consecutive alerts

  const statusEl = document.getElementById('status');
  const setStatus = text => { if (debug) { statusEl.hidden = false; statusEl.textContent = text; } };

  // Built-in alerts come from the optional alert pack (alerts/engine.js); without it only custom alerts play.
  const builtIns = window.GiftedAlertData || {};
  const player = window.GiftedAlerts ? new window.GiftedAlerts.Player({
    canvas: document.getElementById('stage'),
    vignette: document.getElementById('vig'),
  }) : null;
  if (player && player.preload) player.preload().catch(() => { /* assets load again on first use */ });

  let cursor = null;
  let volume = 0.8;
  let enabled = true;
  let failures = 0;
  let busy = false;
  const queue = [];
  const custom = {};            // descriptors of custom alerts, keyed by alert id, filled from poll responses

  const isPlayable = id => (player && !!builtIns[id]) || !!custom[id];

  function enqueue(ev) {
    if (!isPlayable(ev.alert)) return;
    if (queue.length >= MAX_QUEUE) { console.warn('alert queue full, dropping', ev); return; }
    queue.push(ev);
    next();
  }

  function next() {
    if (busy || !queue.length) return;
    busy = true;
    const ev = queue.shift();
    const builtIn = player ? builtIns[ev.alert] : null;
    const seconds = builtIn ? builtIn.duration : (custom[ev.alert].duration || 10);
    let released = false;
    const release = () => {
      if (released) return;
      released = true;
      clearTimeout(watchdog);
      setTimeout(() => { busy = false; next(); }, GAP_MS);
    };
    const watchdog = setTimeout(release, (seconds + 10) * 1000);   // never get stuck on a broken alert
    setStatus('playing ' + ev.alert + ' (' + queue.length + ' queued)');
    const finished = () => { setStatus('idle'); release(); };
    const run = builtIn
      ? player.play(ev.alert, { volume, replayOnClick: false, onend: finished })
      : window.CustomAlerts.play(custom[ev.alert], ev, { volume }).then(finished);
    run.catch(err => { console.error(err); release(); });
  }

  async function poll() {
    let delay = interval;
    try {
      const url = pollUrl + '?key=' + encodeURIComponent(key) + (cursor === null ? '' : '&after=' + cursor);
      const res = await fetch(url, { cache: 'no-store' });
      if (res.status === 404) {
        setStatus('invalid overlay key');
        delay = 60000;
      } else if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      } else {
        const data = await res.json();
        cursor = data.cursor;
        volume = typeof data.volume === 'number' ? data.volume : volume;
        enabled = data.enabled !== false;
        Object.assign(custom, data.custom || {});
        if (enabled) data.events.forEach(enqueue);
        if (!busy) setStatus(enabled ? 'connected' : 'account disabled');
        failures = 0;
      }
    } catch (err) {
      failures++;
      delay = Math.min(30000, interval * Math.pow(2, failures));
      setStatus('offline, retrying (' + failures + ')');
    }
    setTimeout(poll, delay);
  }

  window.__overlay = { player, enqueue, state: () => ({ cursor, volume, enabled, queued: queue.length, busy }) };
  setStatus('starting');
  poll();
})();
