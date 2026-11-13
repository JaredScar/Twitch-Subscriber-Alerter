/*
  Plays custom (streamer-submitted, admin-approved) alerts. Used by the OBS overlay and the preview page.

    CustomAlerts.play(descriptor, event, { volume, root }) -> Promise   resolves when the alert has finished
    CustomAlerts.stopAll()                                              ends everything that is playing now

  descriptor  see CustomAlerts::descriptor() in PHP
  event       { type, actor, count, months }

  media alerts  are built from DOM elements (text goes in with textContent, never as HTML)
  html alerts   run inside <iframe sandbox="allow-scripts">: no access to this page, cookies or the network.
                The parent sends the iframe one message once it has loaded:
                  { type: 'alert', name, count, months, kind: 'gift'|'sub'|'resub'|'test', volume }
                and the alert may reply { type: 'done' } to end early. Otherwise it is removed after `duration`.
*/
(() => {
  'use strict';

  const BASE_WIDTH = 1920;                 // font sizes are specified for a 1920px wide stage
  // [justify-content, align-items] for the 3x3 position grid
  const POSITIONS = {
    tl: ['flex-start', 'flex-start'], t: ['center', 'flex-start'], tr: ['flex-end', 'flex-start'],
    l: ['flex-start', 'center'],      c: ['center', 'center'],     r: ['flex-end', 'center'],
    bl: ['flex-start', 'flex-end'],   b: ['center', 'flex-end'],   br: ['flex-end', 'flex-end'],
  };
  const active = new Set();

  const make = (tag, cls) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    return node;
  };

  function fillText(template, ev) {
    return String(template || '').replace(/\{(name|count|months)\}/g, (_, key) => {
      if (key === 'name') return ev.actor || 'Someone';
      return ev[key] ?? '';
    });
  }

  function playMedia(d, ev, root, volume) {
    return new Promise(resolve => {
      const scale = (root.clientWidth || BASE_WIDTH) / BASE_WIDTH;
      const wrap = make('div', 'ca-wrap');
      const [justify, align] = POSITIONS[d.position] || POSITIONS.c;
      wrap.style.justifyContent = justify;
      wrap.style.alignItems = align;

      const box = make('div', 'ca-box ca-anim-' + (d.animation || 'pop'));
      box.style.width = (d.width || 40) + '%';

      let media;
      if (d.media.type === 'video') {
        media = make('video', 'ca-media');
        media.loop = true;
        media.playsInline = true;
        media.volume = volume;
        media.src = d.media.url;
      } else {
        media = make('img', 'ca-media');
        media.alt = '';
        media.src = d.media.url;
      }

      let text = null;
      if (d.textPos !== 'none' && d.text) {
        text = make('div', 'ca-text');
        text.textContent = fillText(d.text, ev);
        text.style.color = d.textColor || '#ffffff';
        text.style.fontSize = ((d.fontSize || 56) * scale) + 'px';
      }
      if (text && d.textPos === 'above') box.append(text, media);
      else if (text) box.append(media, text);
      else box.append(media);
      wrap.append(box);
      root.append(wrap);

      let audio = null;
      if (d.sound) {
        audio = new Audio(d.sound.url);
        audio.volume = volume;
        audio.play().catch(() => { /* blocked autoplay: the alert still shows */ });
      }
      if (media.tagName === 'VIDEO') {
        media.play().catch(() => { media.muted = true; media.play().catch(() => {}); });
      }

      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        active.delete(finish);
        clearTimeout(timer);
        wrap.classList.add('ca-out');
        setTimeout(() => {
          wrap.remove();
          if (audio) audio.pause();
          resolve();
        }, 450);
      };
      const timer = setTimeout(finish, Math.max(1, d.duration || 8) * 1000);
      active.add(finish);
    });
  }

  function playHtml(d, ev, root, volume) {
    return new Promise(resolve => {
      const frame = make('iframe', 'ca-frame');
      frame.setAttribute('sandbox', 'allow-scripts');   // no allow-same-origin: unique origin, no cookies, no DOM access
      frame.setAttribute('title', d.name || 'Custom alert');
      frame.setAttribute('allowtransparency', 'true');

      let done = false;
      const onMessage = m => {
        if (m.source === frame.contentWindow && m.data && m.data.type === 'done') finish();
      };
      const finish = () => {
        if (done) return;
        done = true;
        active.delete(finish);
        clearTimeout(timer);
        window.removeEventListener('message', onMessage);
        frame.remove();
        resolve();
      };
      window.addEventListener('message', onMessage);
      frame.addEventListener('load', () => {
        frame.contentWindow.postMessage({
          type: 'alert',
          name: ev.actor || 'Someone',
          count: ev.count ?? null,
          months: ev.months ?? null,
          kind: ev.type || 'test',
          volume,
        }, '*');   // '*' is required: the sandboxed frame has an opaque origin
      });
      const timer = setTimeout(finish, Math.max(1, d.duration || 10) * 1000);
      active.add(finish);
      frame.src = d.html.url;
      root.append(frame);
    });
  }

  window.CustomAlerts = {
    play(descriptor, ev, opts = {}) {
      const root = opts.root || document.getElementById('custom');
      const volume = typeof opts.volume === 'number' ? Math.max(0, Math.min(1, opts.volume)) : 1;
      const event = ev || {};
      return descriptor.kind === 'html'
        ? playHtml(descriptor, event, root, volume)
        : playMedia(descriptor, event, root, volume);
    },
    stopAll() {
      [...active].forEach(finish => finish());
    },
  };
})();
