/**
 * DDB Admin Dark Mode Toggle — Wave 2
 * =====================================
 * Reads/writes html[data-adm-theme="dark|light"].
 * Theme is applied by an inline <script> in <head> (via EnqueueService::inline_admin_theme_script)
 * so there is no flash of unstyled content on page load.
 *
 * Persistence: localStorage "ddb-admin-theme".
 * Keyboard shortcut: Ctrl/Cmd + Shift + D.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'ddb-admin-theme';
  var ATTR        = 'data-adm-theme';
  var DARK        = 'dark';
  var LIGHT       = 'light';
  var READY_CLASS = 'ddb-theme-ready';

  /**
   * Read the persisted preference, falling back to OS preference.
   */
  function getPreferred() {
    var saved = '';
    try { saved = localStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
    if (saved === DARK || saved === LIGHT) { return saved; }
    try {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? DARK : LIGHT;
    } catch (e) {
      return LIGHT;
    }
  }

  /**
   * Apply a theme value to <html> and persist it.
   * Does NOT enable transitions — call enableTransitions() once DOM is ready.
   */
  function applyTheme(theme) {
    document.documentElement.setAttribute(ATTR, theme);
    try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
    syncToggleUI(theme);
    syncTinyMCE(theme);
  }

  /**
   * Update the admin-bar button tooltip to reflect current theme.
   * Label is hidden (icon-only) — only the title attribute is synced.
   */
  function syncToggleUI(theme) {
    var node = document.getElementById('wp-admin-bar-ddb-dark-mode');
    if (!node) { return; }
    var link = node.querySelector('.ab-item');
    if (link) {
      link.title = theme === DARK ? 'Schakel naar lichtmodus (Ctrl+Shift+D)' : 'Schakel naar donkermodus (Ctrl+Shift+D)';
    }
  }

  /**
   * Toggle the ddb-dark body class inside all active TinyMCE editor iframes.
   * CSS vars don’t cross iframe boundaries, so the editor iframe uses
   * admin-tinymce.css with the .ddb-dark class as the activation hook.
   */
  function syncTinyMCE(theme) {
    if (!window.tinymce) { return; }
    var isDark = theme === DARK;
    try {
      tinymce.editors.forEach(function (ed) {
        var body = ed.getBody ? ed.getBody() : null;
        if (body) { body.classList.toggle('ddb-dark', isDark); }
      });
    } catch (e) {}
  }

  /**
   * Listen for TinyMCE editor init events so newly-created editors
   * get the correct theme applied immediately.
   */
  function initTinyMCETheme() {
    if (!window.tinymce) { return; }
    try {
      tinymce.on('AddEditor', function (e) {
        e.editor.on('init', function () {
          var theme = document.documentElement.getAttribute(ATTR) || LIGHT;
          syncTinyMCE(theme);
        });
      });
    } catch (e) {}
  }

  /**
   * Attach click handler to the admin-bar toggle button.
   */
  function attachToggle() {
    var node = document.getElementById('wp-admin-bar-ddb-dark-mode');
    if (!node) { return; }
    var link = node.querySelector('.ab-item');
    if (!link) { return; }

    link.addEventListener('click', function (e) {
      e.preventDefault();
      var current = document.documentElement.getAttribute(ATTR) || LIGHT;
      applyTheme(current === DARK ? LIGHT : DARK);
    });
  }

  /**
   * Add the CSS transition class after first paint so theme switches animate
   * but the initial page load does not (no flash).
   */
  function enableTransitions() {
    // rAF ensures we're past the first paint before enabling transitions
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        document.documentElement.classList.add(READY_CLASS);
      });
    });
  }

  /**
   * Sync when the OS preference changes, but only if the user has not
   * manually saved a preference.
   */
  function listenToSystemPreference() {
    try {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      mq.addEventListener('change', function (e) {
        var saved = '';
        try { saved = localStorage.getItem(STORAGE_KEY) || ''; } catch (err) {}
        if (!saved) {
          applyTheme(e.matches ? DARK : LIGHT);
        }
      });
    } catch (e) {}
  }

  // ─── Bootstrap ────────────────────────────────────────────────────────────

  // Apply on script execution (theme already set by inline head script,
  // this call ensures syncToggleUI is also run before DOMContentLoaded).
  applyTheme(getPreferred());

  document.addEventListener('DOMContentLoaded', function () {
    attachToggle();
    syncToggleUI(getPreferred());
    enableTransitions();
    listenToSystemPreference();
    initTinyMCETheme();
  });

  // Keyboard shortcut: Ctrl/Cmd + Shift + D
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'D') {
      e.preventDefault();
      var current = document.documentElement.getAttribute(ATTR) || LIGHT;
      applyTheme(current === DARK ? LIGHT : DARK);
    }
  });

}());
