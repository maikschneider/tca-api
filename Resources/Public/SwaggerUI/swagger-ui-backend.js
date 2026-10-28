/*
 * Bridge the TYPO3 backend colour scheme to Swagger UI's dark theme.
 *
 * Swagger ships a complete dark theme gated on `html.dark-mode`, but the TYPO3
 * v14 backend signals its scheme via the `data-color-scheme` attribute, which
 * its stylesheet turns into the CSS `color-scheme` property ("only dark",
 * "only light", or "light dark" for "auto", where the OS preference decides).
 * Like the core code editor, read that computed property instead of the
 * attribute, so every value the scheme switcher writes is covered. Mirror the
 * result onto a `dark-mode` class — a class the backend itself never uses, so
 * it only ever flips Swagger's own styling.
 */
(function () {
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function isDark() {
    var scheme = window.getComputedStyle(root).colorScheme;
    if (scheme === 'light' || scheme === 'only light' || scheme === 'light only') {
      return false;
    }
    if (scheme === 'dark' || scheme === 'only dark' || scheme === 'dark only') {
      return true;
    }
    return media.matches;
  }

  function sync() {
    root.classList.toggle('dark-mode', isDark());
  }

  sync();
  media.addEventListener('change', sync);
  new MutationObserver(sync).observe(root, {
    attributes: true,
    attributeFilter: ['data-color-scheme']
  });
})();
