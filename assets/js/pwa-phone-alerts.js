'use strict';

(() => {
  const appRoot = document.currentScript?.src
    ? new URL('../../', document.currentScript.src)
    : new URL('./', document.baseURI);
  const workerUrl = new URL('service-worker.js', appRoot);
  const canRegister = window.isSecureContext && 'serviceWorker' in navigator;
  const isAppleMobile = /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const needsHomeScreen = isAppleMobile && !navigator.standalone
    && !window.matchMedia('(display-mode: standalone)').matches;
  const supportsPush = canRegister && !needsHomeScreen && 'PushManager' in window && 'Notification' in window;

  let registrationPromise;
  let registration;
  let subscription;
  let config;
  let signedIn;
  let subscribed = false;
  let deviceBusy = false;
  let deviceMessage = '';
  let refreshPromise;
  let session;
  let followBusy = false;
  let followMessage = '';
  let deviceControls = [];
  let followControls = [];
  let initialized = false;

  function register() {
    if (!canRegister) return Promise.resolve(null);
    if (!registrationPromise) {
      registrationPromise = navigator.serviceWorker.register(workerUrl, {scope: appRoot.pathname})
        .then(async worker => {
          if (worker.active) return worker;
          let timer;
          try {
            const active = await Promise.race([
              navigator.serviceWorker.ready,
              new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error('Phone alerts are still getting ready.')), 12000);
              })
            ]);
            if (active.scope !== worker.scope) throw new Error('Phone alerts could not start.');
            return active;
          } finally {
            clearTimeout(timer);
          }
        }).catch(error => {
          registrationPromise = undefined;
          throw error;
        });
    }
    return registrationPromise;
  }

  async function request(path, body, csrf) {
    const options = body === undefined ? {} : {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf || ''},
      body: JSON.stringify(body)
    };
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(new URL(path, appRoot), {
        ...options, credentials: 'same-origin', signal: controller.signal
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result?.ok) {
        const error = new Error(result?.message || 'Phone alerts are unavailable.');
        error.status = response.status;
        error.code = result?.error;
        throw error;
      }
      return result;
    } catch (error) {
      if (controller.signal.aborted) throw new Error('Phone alerts took too long to respond. Try again.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  function pushRequest(action, values) {
    return request('api/web-push.php', {action, ...values}, config?.csrf);
  }

  function applicationServerKey(value) {
    const encoded = value.replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(encoded), character => character.charCodeAt(0));
  }

  function needsSubscriptionReset() {
    const previous = subscription?.options?.applicationServerKey;
    if (!previous || !config?.public_key) return false;
    const current = applicationServerKey(config.public_key);
    const bytes = new Uint8Array(previous);
    return current.length !== bytes.length || !current.every((value, index) => value === bytes[index]);
  }

  function setText(element, value) {
    if (element.textContent !== value) element.textContent = value;
  }

  function renderDevice() {
    const known = signedIn !== undefined;
    const permitted = supportsPush && Notification.permission !== 'denied';
    const needsReset = needsSubscriptionReset();
    const canEnable = Boolean(config?.available && config.public_key && registration && permitted);
    const canDisable = Boolean((subscribed || needsReset) && subscription);
    let status = deviceMessage;
    if (!status) {
      if (needsHomeScreen) status = (session && !session.following ? 'Follow this session first. ' : '')
        + 'On iPhone or iPad, use Share → Add to Home Screen. Open Stonefellow from the Home Screen and sign in if prompted. Find followed sessions under Live Sessions → Following.';
      else if (!supportsPush) status = 'This browser does not support phone alerts.';
      else if (signedIn === false) status = 'Sign in to enable alerts on this device.';
      else if (!known || refreshPromise && !config) status = 'Checking phone alerts…';
      else if (needsReset) status = 'Phone alert settings changed. Reset this device’s alerts, then enable them again.';
      else if (canDisable) status = Notification.permission === 'granted' ? 'Phone alerts are on for this account and device.'
        : 'Notifications are blocked in this browser. You can remove this device’s alert subscription.';
      else if (!permitted) status = 'Notifications are blocked. To enable them, allow notifications in your browser settings.';
      else if (!canEnable) status = 'Phone alerts are unavailable right now.';
      else status = 'Enable notifications to receive alerts for sessions you follow.';
    }
    for (const {root, button, message, signIn} of deviceControls) {
      root.hidden = false;
      button.hidden = !supportsPush || signedIn === false;
      button.disabled = deviceBusy || Boolean(refreshPromise) || (!canEnable && !canDisable);
      setText(button, needsReset ? 'Reset phone alerts on this device'
        : canDisable ? 'Turn off phone alerts on this device' : 'Enable phone alerts on this device');
      setText(message, status);
      if (signIn) signIn.hidden = signedIn !== false || !supportsPush;
    }
    renderFollow();
  }

  async function refreshDevice() {
    if (!deviceControls.length || refreshPromise || deviceBusy) return refreshPromise;
    if (!supportsPush) {
      renderDevice();
      return;
    }
    refreshPromise = (async () => {
      try {
        config = await request('api/web-push.php');
        signedIn = true;
        registration = await register();
        subscription = await registration.pushManager.getSubscription();
        subscribed = subscription
          ? Boolean((await pushRequest('status', {endpoint: subscription.endpoint})).subscribed)
          : false;
        deviceMessage = '';
      } catch (error) {
        subscribed = false;
        subscription = undefined;
        config = undefined;
        if (error.status === 401) {
          signedIn = false;
          deviceMessage = '';
        } else {
          deviceMessage = error.message || 'Phone alerts could not be checked. Try again after refreshing.';
        }
      }
    })().finally(() => {
      refreshPromise = undefined;
      renderDevice();
    });
    renderDevice();
    return refreshPromise;
  }

  async function toggleDevice() {
    if (deviceBusy || refreshPromise || !registration || !config) return;
    deviceBusy = true;
    deviceMessage = '';
    renderDevice();
    try {
      const needsReset = needsSubscriptionReset();
      if ((subscribed || needsReset) && subscription) {
        if (subscribed) await pushRequest('unsubscribe', {endpoint: subscription.endpoint});
        subscribed = false;
        await subscription.unsubscribe().catch(() => {});
        subscription = await registration.pushManager.getSubscription();
        if (needsReset && subscription) throw new Error('This browser could not reset phone alerts. Check its notification settings and try again.');
        deviceMessage = needsReset ? 'Phone alerts were reset. You can enable them again.' : 'Phone alerts are off on this device.';
      } else {
        // Calling subscribe from the button gesture lets the browser request permission.
        const current = subscription || await registration.pushManager.subscribe({
          userVisibleOnly: true, applicationServerKey: applicationServerKey(config.public_key)
        });
        subscription = current;
        await pushRequest('subscribe', {subscription: current.toJSON()});
        subscribed = true;
        deviceMessage = 'Phone alerts are on for this account and device.';
      }
    } catch (error) {
      if (error.status === 401) {
        subscribed = false;
        signedIn = false;
        config = undefined;
      }
      deviceMessage = Notification.permission === 'denied'
        ? 'Notifications are blocked. To enable them, allow notifications in your browser settings.'
        : (error.message || 'Phone alerts could not be changed. Try again.');
    } finally {
      deviceBusy = false;
      renderDevice();
    }
  }

  function renderFollow() {
    const active = session && ['upcoming', 'live'].includes(session.phase);
    let status = followMessage;
    if (!status) {
      if (!session?.signedIn) status = 'Sign in to follow this session.';
      else if (session.following && session.phase === 'upcoming') {
        status = subscribed && !needsSubscriptionReset() && supportsPush && Notification.permission === 'granted'
          ? 'You’re following this session. Phone alerts are on for this device.'
          : 'You’re following this session. Enable phone alerts to get reminders and updates on this device.';
      } else if (session.following) status = 'You’re following this session.';
      else if (session.phase === 'upcoming') status = 'Follow this session for reminders and updates.';
    }
    for (const {root, button, message, signIn} of followControls) {
      root.hidden = !active && !session?.following;
      button.hidden = !session?.signedIn || !active && !session?.following;
      button.disabled = followBusy;
      setText(button, session?.following ? 'Unfollow this session' : 'Follow this session');
      setText(message, status);
      if (signIn) signIn.hidden = !active || !!session?.signedIn;
    }
  }

  function updateSession(result) {
    const state = result?.state;
    if (!state || !/^[a-f0-9]{32}$/.test(state.id)) return;
    const next = {
      id: state.id, phase: state.phase, signedIn: result.signed_in === true,
      following: followBusy ? session?.following === true : result.following === true
    };
    if (session?.signedIn !== next.signedIn) {
      followMessage = '';
      void refreshDevice();
    }
    session = next;
    const login = new URL(appRoot);
    login.searchParams.set('view', 'login');
    login.hash = 'return_jukebox=' + state.id;
    for (const {signIn} of [...deviceControls, ...followControls]) {
      if (signIn && signIn.href !== login.href) signIn.href = login.href;
    }
    renderDevice();
  }

  async function toggleFollow() {
    if (!session?.signedIn || followBusy) return;
    const id = session.id;
    const following = !session.following;
    followBusy = true;
    followMessage = '';
    renderFollow();
    try {
      const query = new URLSearchParams({action: 'follow', id});
      const path = 'api/jukebox.php?' + query;
      const csrf = config?.csrf || document.querySelector('meta[name=sf-csrf]')?.content;
      let result;
      try {
        result = await request(path, {following}, csrf);
      } catch (error) {
        if (error.code !== 'csrf_failed') throw error;
        const auth = await request('api/auth.php');
        if (!auth.authenticated) throw error;
        result = await request(path, {following}, auth.csrf);
      }
      if (session?.id === id && session.signedIn) {
        session.following = result.following === true;
        followMessage = session.following ? '' : 'You’ve unfollowed this session.';
      }
    } catch (error) {
      if (error.status === 401 && session?.id === id) session.signedIn = false;
      followMessage = error.message || 'This session could not be updated. Try again.';
    } finally {
      followBusy = false;
      renderDevice();
    }
  }

  function controls(container, type) {
    return [...document.querySelectorAll(container)].map(root => ({
      root, button: root.querySelector('[data-pwa-' + type + '-toggle]'),
      message: root.querySelector('[data-pwa-' + type + '-status]'),
      signIn: root.querySelector('[data-pwa-' + type + '-signin]')
    })).filter(item => item.button && item.message);
  }

  function init() {
    if (initialized) return;
    initialized = true;
    deviceControls = controls('[data-pwa-phone-alerts]', 'alert');
    followControls = controls('[data-pwa-session-follow]', 'follow');
    for (const {button} of deviceControls) button.addEventListener('click', toggleDevice);
    for (const {button} of followControls) button.addEventListener('click', toggleFollow);
    if (deviceControls.length) {
      void register().catch(() => {});
      void refreshDevice();
      window.addEventListener('focus', refreshDevice);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refreshDevice();
      });
      new MutationObserver(() => { void refreshDevice(); }).observe(document.documentElement, {
        attributes: true, attributeFilter: ['data-auth-state']
      });
    }
    renderFollow();
  }

  window.StonefellowPwaAlerts = {init, register, updateSession};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
