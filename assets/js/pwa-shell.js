'use strict';

(() => {
  const install = document.querySelector('[data-pwa-install]');
  if (install) {
    let prompt;
    window.addEventListener('beforeinstallprompt', event => {
      event.preventDefault();
      prompt = event;
      install.hidden = false;
    });
    install.addEventListener('click', () => {
      if (!prompt) return;
      void prompt.prompt().catch(() => {});
      prompt = undefined;
      install.hidden = true;
    });
    window.addEventListener('appinstalled', () => {
      prompt = undefined;
      install.hidden = true;
    });
  }

  function mountArt(container) {
    const template = container.querySelector('template[data-pwa-splash-frame]');
    if (template && !container.querySelector('iframe')) container.append(template.content.cloneNode(true));
  }

  const waiting = document.querySelector('[data-pwa-waiting]');
  if (waiting) {
    document.addEventListener('stonefellow:jukebox-state', ({detail}) => {
      waiting.hidden = detail?.phase !== 'upcoming';
      if (waiting.hidden) waiting.querySelector('iframe')?.remove();
      else mountArt(waiting);
    });
  }

  const launch = document.querySelector('[data-pwa-launch]');
  if (!launch || typeof launch.showModal !== 'function') return;
  if (!navigator.standalone && !window.matchMedia('(display-mode: standalone)').matches) return;

  const key = `stonefellow:pwa-launch:${launch.dataset.pwaLaunch}`;
  try {
    if (sessionStorage.getItem(key)) return;
  } catch {}

  function openLaunch() {
    if (document.body.classList.contains('sf-site-booting')) return;
    bootObserver.disconnect();
    launch.showModal();
    mountArt(launch);
    try { sessionStorage.setItem(key, '1'); } catch {}
  }

  const bootObserver = new MutationObserver(openLaunch);
  bootObserver.observe(document.body, {attributes: true, attributeFilter: ['class']});
  launch.addEventListener('close', () => launch.querySelector('iframe')?.remove(), {once: true});
  openLaunch();
})();
