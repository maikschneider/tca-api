/*
 * Bridge the TYPO3 backend colour scheme to Swagger UI's dark theme.
 *
 * Swagger ships a complete dark theme gated on `html.dark-mode`, but the TYPO3
 * v14 backend signals its scheme via the `data-color-scheme` attribute (or the
 * OS preference when set to "auto"). Mirror the effective scheme onto a
 * `dark-mode` class — a class the backend itself never uses, so it only ever
 * flips Swagger's own styling.
 */
(function () {
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function sync() {
    var scheme = root.getAttribute('data-color-scheme');
    var dark = scheme === 'dark' || (scheme === null && media.matches);
    root.classList.toggle('dark-mode', dark);
  }

  sync();
  media.addEventListener('change', sync);
  new MutationObserver(sync).observe(root, {
    attributes: true,
    attributeFilter: ['data-color-scheme']
  });
})();
