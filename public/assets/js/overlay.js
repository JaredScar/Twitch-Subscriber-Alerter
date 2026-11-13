/*
  OBS browser source runtime.

  Polls api/poll.php for new subscriber events and plays the matching alert. The server decides
  which alert belongs to an event (the streamer's rules), so this page only has to play what it is
  told: built-in alerts are drawn by alerts/engine.js, custom alerts (approved by the site owner) by
  assets/js/custom-player.js using the descriptors the poll response carries.

  When events arrive close together the streamer's play mode (sent with every poll) decides:
    queue  one alert at a time, a short pause between them (the default)
    stack  up to MAX_STACK alerts on screen at the same time; any more wait in the queue

  Options:  ?debug=1   show a small status line (testing outside OBS)
*/
(() => {
  'use strict';

  const body = document.body;
  const key = body.dataset.key;
  const pollUrl = body.dataset.poll;
  const interval = Math.max(500, parseInt(body.dataset.interval, 10) || 1500);
  const debug = new URLSearchParams(location.search).has('debug');
  const MAX_QUEUE = 10;         // events waiting for a free slot; more are dropped
  const MAX_STACK = 4;          // most alerts on screen at once in stack mode (a built-in one is a full-screen canvas)
  const GAP_MS = 600;           // queue mode: pause between consecutive alerts

  const statusEl = document.getElementById('status');
  const setStatus = text => { if (debug) { statusEl.hidden = false; statusEl.textContent = text; } };

  // Built-in alerts come from the optional alert pack (alerts/engine.js); without it only custom alerts play.
  const builtIns = window.GiftedAlertData || {};
  const Player = window.GiftedAlerts ? window.GiftedAlerts.Player : null;
  const customRoot = document.getElementById('custom');

  // A built-in alert is drawn on a canvas, and a Player stops whatever it is playing when it starts the next one.
  // So every alert on screen needs its own Player and canvas. The page ships with one; stack mode adds more
  // (a canvas + vignette pair each, reused afterwards) and queue mode never needs a second.
  const layers = [];
  if (Player) {
    const first = new Player({ canvas: document.getElementById('stage'), vignette: document.getElementById('vig') });
    if (first.preload) first.preload().catch(() => { /* assets load again on first use */ });
    layers.push({ player: first, busy: false });
  }
  const player = layers.length ? layers[0].player : null;

  function acquireLayer() {
    let layer = layers.find(l => !l.busy);
    if (!layer) {
      const canvas = document.createElement('canvas');
      canvas.width = window.GiftedAlerts.W || 1920;
      canvas.height = window.GiftedAlerts.H || 1080;
      canvas.className = 'alert-stage';
      const vignette = document.createElement('div');
      vignette.className = 'alert-vig';
      customRoot.before(canvas, vignette);   // below the custom alerts, above earlier layers
      layer = { player: new Player({ canvas, vignette }), busy: false };
      layers.push(layer);
    }
    layer.busy = true;
    return layer;
  }

  let cursor = null;
  let volume = 0.8;
  let enabled = true;
  let mode = 'queue';
  let failures = 0;
  let playing = 0;              // alerts holding a slot: on screen, or (queue mode) in the pause after one
  const queue = [];
  const custom = {};            // descriptors of custom alerts, keyed by alert id, filled from poll responses

  const isPlayable = id => (player && !!builtIns[id]) || !!custom[id];
  const capacity = () => (mode === 'stack' ? MAX_STACK : 1);

  function enqueue(ev) {
    if (!isPlayable(ev.alert)) return;
    if (queue.length >= MAX_QUEUE) { console.warn('alert queue full, dropping', ev); return; }
    queue.push(ev);
    next();
  }

  function next() {
    while (queue.length && playing < capacity()) start(queue.shift());
  }

  function start(ev) {
    playing++;
    const builtIn = player ? builtIns[ev.alert] : null;
    const layer = builtIn ? acquireLayer() : null;
    const seconds = builtIn ? builtIn.duration : (custom[ev.alert].duration || 10);
    let released = false;
    const release = () => {
      if (released) return;
      released = true;
      clearTimeout(watchdog);
      if (layer) layer.busy = false;
      // The slot stays taken through the pause, so in queue mode the next alert never starts right on the heels of this one.
      setTimeout(() => {
        playing--;
        setStatus(playing ? 'playing (' + playing + ' on screen, ' + queue.length + ' queued)' : 'idle');
        next();
      }, mode === 'queue' ? GAP_MS : 0);
    };
    const watchdog = setTimeout(() => {      // never get stuck on a broken alert
      if (layer) layer.player.stop();
      release();
    }, (seconds + 10) * 1000);
    setStatus('playing ' + ev.alert + ' (' + playing + ' on screen, ' + queue.length + ' queued, ' + mode + ')');
    const run = builtIn
      ? layer.player.play(ev.alert, { volume, replayOnClick: false, onend: release })
      : window.CustomAlerts.play(custom[ev.alert], ev, { volume }).then(release);
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
        mode = data.mode === 'stack' ? 'stack' : 'queue';
        Object.assign(custom, data.custom || {});
        if (enabled) data.events.forEach(enqueue);
        next();   // the mode may just have changed to one with more room
        if (!playing) setStatus(enabled ? 'connected' : 'account disabled');
        failures = 0;
      }
    } catch (err) {
      failures++;
      delay = Math.min(30000, interval * Math.pow(2, failures));
      setStatus('offline, retrying (' + failures + ')');
    }
    setTimeout(poll, delay);
  }

  window.__overlay = {
    player, enqueue,
    state: () => ({ cursor, volume, enabled, mode, queued: queue.length, playing, busy: playing > 0, layers: layers.length }),
  };
  setStatus('starting');
  poll();
})();
