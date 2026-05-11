(() => {
  const config = window.DDBThemeConfig || {};
  const storageKey = config.cookieName || "ddb_theme";
  const legacyThemeKey = "sbdpTheme";
  const legacyDarkKey = "sbdp-dark";
  const root = document.documentElement;

  function normalize(theme) {
    const value = String(theme || "").toLowerCase().trim();
    if (value === "dark" || value === "light" || value === "system") {
      return value;
    }
    return "system";
  }

  function resolveDisplay(theme) {
    if (theme === "dark" || theme === "light") {
      return theme;
    }
    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
      return "dark";
    }
    return "light";
  }

  function persist(theme) {
    try {
      localStorage.setItem(storageKey, theme);
      localStorage.setItem(legacyThemeKey, theme);
      localStorage.setItem(legacyDarkKey, theme === "dark" ? "true" : "false");
    } catch (error) {
      // no-op
    }

    document.cookie = `${storageKey}=${theme}; Path=/; Max-Age=${60 * 60 * 24 * 365}; SameSite=Lax`;
  }

  function apply(theme, shouldPersist) {
    const normalized = normalize(theme);
    const display = resolveDisplay(normalized);
    const isDark = display === "dark";

    root.setAttribute("data-theme", normalized);
    root.setAttribute("data-theme-display", display);
    root.classList.toggle("dark-mode", isDark);

    if (document.body instanceof HTMLElement) {
      document.body.classList.toggle("sbdp-theme-dark", isDark);
      document.body.classList.toggle("sbdp-theme-light", !isDark);
      document.body.classList.toggle("dark-mode", isDark);
    }

    if (shouldPersist) {
      persist(normalized);
    }

    return normalized;
  }

  function detectInitialTheme() {
    try {
      const stored = localStorage.getItem(storageKey);
      if (stored) {
        return normalize(stored);
      }

      const legacyTheme = localStorage.getItem(legacyThemeKey);
      if (legacyTheme) {
        return normalize(legacyTheme);
      }

      const legacyDark = localStorage.getItem(legacyDarkKey);
      if (legacyDark === "true") {
        return "dark";
      }
      if (legacyDark === "false") {
        return "light";
      }
    } catch (error) {
      // no-op
    }

    const existing = normalize(root.getAttribute("data-theme"));
    return existing || "system";
  }

  function setupLegacyButtons() {
    document.querySelectorAll("[data-sbdp-dark-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const current = normalize(root.getAttribute("data-theme"));
        const next = current === "dark" ? "light" : "dark";
        apply(next, true);
      });
    });
  }

  function boot() {
    if (window.DDBTheme && typeof window.DDBTheme.sync === "function") {
      window.DDBTheme.sync();
      return;
    }

    apply(detectInitialTheme(), true);
    setupLegacyButtons();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
