(function () {
  'use strict';

  var STORAGE_KEY = 'mycoach-theme';
  var COOKIE_KEY = 'mycoach_theme';
  var body = document.body;

  function isMyCoachPage() {
    if (!body) return false;
    var hasMyCoachCss = !!document.querySelector('link[href*="mycoach-app.css"]');
    if (hasMyCoachCss && !body.classList.contains('mycoach-app-theme')) {
      body.classList.add('mycoach-app-theme');
    }
    return body.classList.contains('mycoach-app-theme');
  }

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function writeCookie(name, value) {
    var maxAge = 60 * 60 * 24 * 365;
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }

  function applyTheme(theme) {
    var isLight = theme === 'light';
    body.classList.toggle('mca-theme-light', isLight);
    var btn = document.getElementById('mcaThemeToggle');
    if (btn) {
      btn.innerHTML = isLight
        ? '<i class="fas fa-moon"></i> Tmavý režim'
        : '<i class="fas fa-sun"></i> Světlý režim';
      btn.setAttribute('aria-label', isLight ? 'Přepnout na tmavý režim' : 'Přepnout na světlý režim');
      btn.setAttribute('aria-pressed', isLight ? 'true' : 'false');
      btn.setAttribute('title', isLight ? 'Přepnout na tmavý režim' : 'Přepnout na světlý režim');
    }
  }

  function readTheme() {
    try {
      var localValue = localStorage.getItem(STORAGE_KEY);
      if (localValue === 'light' || localValue === 'dark') {
        return localValue;
      }
    } catch (e) {
      // Continue with cookie fallback.
    }

    var cookieValue = readCookie(COOKIE_KEY);
    if (cookieValue === 'light' || cookieValue === 'dark') {
      return cookieValue;
    }

    return 'dark';
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {
      // Ignore storage failures.
    }
    writeCookie(COOKIE_KEY, theme);
  }

  function toggleTheme() {
    var current = body.classList.contains('mca-theme-light') ? 'light' : 'dark';
    var next = current === 'light' ? 'dark' : 'light';
    applyTheme(next);
    saveTheme(next);
  }

  function ensureToggleButton() {
    var btn = document.getElementById('mcaThemeToggle');
    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'mcaThemeToggle';
      btn.className = 'mca-theme-toggle mca-theme-toggle-fab';
      document.body.appendChild(btn);
    }

    if (!btn.dataset.boundThemeToggle) {
      btn.addEventListener('click', toggleTheme);
      btn.dataset.boundThemeToggle = '1';
    }
  }

  if (!isMyCoachPage()) {
    return;
  }

  applyTheme(readTheme());
  ensureToggleButton();
})();
