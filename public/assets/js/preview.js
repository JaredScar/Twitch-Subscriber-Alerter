/* Preview page for a custom alert. */
(() => {
  'use strict';

  const card = document.getElementById('preview');
  const descriptor = JSON.parse(card.dataset.descriptor);
  const stage = document.getElementById('custom');
  const hint = document.querySelector('.stage-hint');
  const $ = id => document.getElementById(id);

  $('p-play').addEventListener('click', () => {
    window.CustomAlerts.stopAll();
    if (hint) hint.hidden = true;
    const count = Math.max(1, parseInt($('p-count').value, 10) || 1);
    window.CustomAlerts.play(
      descriptor,
      { type: 'gift', actor: $('p-name').value.trim() || 'Someone', count },
      { volume: Number($('p-volume').value) / 100, root: stage },
    ).then(() => { if (hint && !stage.children.length) hint.hidden = false; });
  });

  $('p-stop').addEventListener('click', () => {
    window.CustomAlerts.stopAll();
    if (hint) hint.hidden = false;
  });
})();
