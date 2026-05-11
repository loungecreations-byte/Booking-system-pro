(() => {
  const config = window.DDBThemeConfig || {};
  const cookieName = config.cookieName || "ddb_theme";
  const legacyThemeKey = "sbdpTheme";
  const legacyDarkKey = "sbdp-dark";
  const defaultTheme = normalizeTheme(config.defaultTheme || "system");
  const allowedThemes = new Set(["light", "dark", "system"]);
  const toggleSelector = "button[data-ddb-theme-toggle], button[data-sbdp-dark-toggle]";
  const explicitThemeSelector = "button[data-ddb-theme]";
  const root = document.documentElement;
  const prefersDarkQuery =
    typeof window.matchMedia === "function"
      ? window.matchMedia("(prefers-color-scheme: dark)")
      : null;
  const megaMenuSelector = ".ddb-mega-menu";

  function normalizeTheme(theme) {
    const value = String(theme || "").toLowerCase().trim();
    return value === "light" || value === "dark" || value === "system" ? value : "system";
  }

  function writeStorage(theme) {
    try {
      localStorage.setItem(cookieName, theme);
      localStorage.setItem(legacyThemeKey, theme);
      localStorage.setItem(legacyDarkKey, theme === "dark" ? "true" : "false");
    } catch (error) {
      // Ignore storage failures (private mode or disabled storage).
    }
  }

  function readStorage() {
    try {
      const stored = localStorage.getItem(cookieName);
      if (stored !== null && stored !== "") {
        return normalizeTheme(stored);
      }

      const legacyTheme = localStorage.getItem(legacyThemeKey);
      if (legacyTheme !== null && legacyTheme !== "") {
        return normalizeTheme(legacyTheme);
      }

      const legacyDark = localStorage.getItem(legacyDarkKey);
      if (legacyDark === "true") {
        return "dark";
      }
      if (legacyDark === "false") {
        return "light";
      }

      return defaultTheme;
    } catch (error) {
      return defaultTheme;
    }
  }

  function writeCookie(theme) {
    const maxAge = 60 * 60 * 24 * 365;
    document.cookie = `${cookieName}=${theme}; Path=/; Max-Age=${maxAge}; SameSite=Lax`;
  }

  function applyTheme(theme, persist = true) {
    const normalized = normalizeTheme(theme);
    const effectiveTheme = resolveDisplayTheme(normalized);
    const isDark = effectiveTheme === "dark";

    root.setAttribute("data-theme", normalized);
    root.setAttribute("data-theme-display", effectiveTheme);
    root.classList.toggle("dark-mode", isDark);

    if (document.body instanceof HTMLElement) {
      document.body.classList.toggle("sbdp-theme-dark", isDark);
      document.body.classList.toggle("sbdp-theme-light", !isDark);
      document.body.classList.toggle("dark-mode", isDark);
    }

    if (persist) {
      writeStorage(normalized);
      writeCookie(normalized);
    }

    window.dispatchEvent(
      new CustomEvent("ddb:theme-change", {
        detail: { theme: normalized, effectiveTheme },
      })
    );

    updateToggleButtons();

    return normalized;
  }

  function toggleTheme() {
    const current = normalizeTheme(root.getAttribute("data-theme"));
    const next = current === "dark" ? "light" : "dark";
    return applyTheme(next, true);
  }

  function handleThemeClick(event) {
    const trigger = event.target.closest(`${toggleSelector}, ${explicitThemeSelector}`);
    if (!trigger) {
      return;
    }

    if (
      trigger.hasAttribute("data-sbdp-dark-toggle") ||
      (trigger.hasAttribute("data-ddb-theme-toggle") && !trigger.hasAttribute("data-ddb-theme"))
    ) {
      toggleTheme();
      return;
    }

    const requested = trigger.getAttribute("data-ddb-theme");
    if (allowedThemes.has(requested)) {
      applyTheme(requested, true);
    }
  }

  function resolveDisplayTheme(theme) {
    const normalized = normalizeTheme(theme);
    if (normalized !== "system") {
      return normalized;
    }

    if (prefersDarkQuery && prefersDarkQuery.matches) {
      return "dark";
    }

    return "light";
  }

  function ensureHeaderToggleHost(navInner) {
    if (!(navInner instanceof HTMLElement)) {
      return null;
    }

    let host = navInner.querySelector(".ddb-theme-toggle-host");
    if (host instanceof HTMLElement) {
      return host;
    }

    host = document.createElement("div");
    host.className = "ddb-theme-toggle-host";
    navInner.appendChild(host);
    return host;
  }

  function normalizeHeaderToggles() {
    if (document.querySelector(megaMenuSelector)) {
      return null;
    }

    const navInners = document.querySelectorAll(".main-nav .e-con-inner");
    let primaryToggle = null;

    navInners.forEach((navInner) => {
      if (!(navInner instanceof HTMLElement)) {
        return;
      }

      const host = ensureHeaderToggleHost(navInner);
      if (!(host instanceof HTMLElement)) {
        return;
      }

      const candidates = Array.from(navInner.querySelectorAll(toggleSelector)).filter(
        (el) => el instanceof HTMLButtonElement
      );

      let primary = candidates.find((btn) => host.contains(btn));
      if (!primary) {
        primary = candidates.find((btn) => btn.closest(".elementor-nav-menu")) || candidates[0] || null;
      }

      if (!primary) {
        const fallback = document.createElement("button");
        fallback.type = "button";
        fallback.className = "ddb-theme-toggle";
        fallback.setAttribute("data-ddb-theme-toggle", "");
        host.appendChild(fallback);
        primary = fallback;
      } else if (!host.contains(primary)) {
        host.appendChild(primary);
      }

      primary.setAttribute("type", "button");
      primary.setAttribute("data-ddb-theme-toggle", "");
      primary.classList.add("ddb-theme-toggle");
      primary.removeAttribute("hidden");
      primary.removeAttribute("aria-hidden");
      primary.setAttribute("data-ddb-theme-primary", "");
      if (!primary.getAttribute("aria-label")) {
        primary.setAttribute("aria-label", "Schakel thema");
      }
      primaryToggle = primary;

      candidates.forEach((btn) => {
        if (btn === primary) {
          return;
        }
        btn.removeAttribute("data-ddb-theme-primary");
        btn.setAttribute("hidden", "hidden");
        btn.setAttribute("aria-hidden", "true");
      });
    });

    return primaryToggle;
  }

  function normalizeGlobalToggleVisibility(primaryToggle) {
    const toggles = Array.from(document.querySelectorAll(toggleSelector));

    if (toggles.length <= 1) {
      return;
    }

    const megaMenuToggles = toggles.filter((btn) => btn.closest(megaMenuSelector));
    if (megaMenuToggles.length > 0) {
      megaMenuToggles.forEach((btn) => {
        btn.removeAttribute("hidden");
        btn.removeAttribute("aria-hidden");
      });

      toggles.forEach((btn) => {
        if (btn.closest(megaMenuSelector)) {
          return;
        }

        btn.removeAttribute("data-ddb-theme-primary");
        btn.setAttribute("hidden", "hidden");
        btn.setAttribute("aria-hidden", "true");
      });
      return;
    }

    // Prefer one global visible toggle in header to prevent duplicate controls.
    let keep = primaryToggle instanceof HTMLButtonElement ? primaryToggle : null;
    if (!keep) {
      keep =
        toggles.find((btn) => btn.closest(".main-nav")) ||
        toggles.find((btn) => !btn.hasAttribute("hidden")) ||
        toggles[0] ||
        null;
    }

    if (!(keep instanceof HTMLButtonElement)) {
      return;
    }

    toggles.forEach((btn) => {
      if (btn === keep) {
        btn.removeAttribute("hidden");
        btn.removeAttribute("aria-hidden");
        return;
      }
      btn.removeAttribute("data-ddb-theme-primary");
      btn.setAttribute("hidden", "hidden");
      btn.setAttribute("aria-hidden", "true");
    });
  }

  function updateToggleButtons() {
    const primaryToggle = normalizeHeaderToggles();
    normalizeGlobalToggleVisibility(primaryToggle);

    const effectiveTheme = resolveDisplayTheme(root.getAttribute("data-theme"));
    const isDark = effectiveTheme === "dark";
    const icon = isDark ? "☀" : "☾";
    const label = isDark ? "Schakel naar licht thema" : "Schakel naar donker thema";
    const title = isDark ? "Licht thema" : "Donker thema";

    document
      .querySelectorAll(toggleSelector)
      .forEach((button) => {
        if (!(button instanceof HTMLElement)) {
          return;
        }

        button.setAttribute("aria-pressed", isDark ? "true" : "false");
        button.setAttribute("aria-label", label);
        button.setAttribute("title", title);
        button.setAttribute("data-theme-display", effectiveTheme);
        button.textContent = icon;
      });
  }

  function syncInitialTheme() {
    const serverTheme = normalizeTheme(root.getAttribute("data-theme"));
    const storageTheme = readStorage();
    const initial =
      serverTheme === "system" && storageTheme !== "system"
        ? storageTheme
        : serverTheme || storageTheme || defaultTheme;

    root.removeAttribute("data-ddb-theme");
    applyTheme(initial, true);
  }

  function setupLateToggleObserver() {
    if (typeof MutationObserver !== "function" || !(document.body instanceof HTMLElement)) {
      return;
    }

    let rafId = null;
    const schedule = () => {
      if (rafId !== null) {
        return;
      }

      rafId = window.requestAnimationFrame(() => {
        rafId = null;
        updateToggleButtons();
      });
    };

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (!mutation.addedNodes || mutation.addedNodes.length === 0) {
          continue;
        }

        for (const node of mutation.addedNodes) {
          if (!(node instanceof HTMLElement)) {
            continue;
          }

          if (node.matches(toggleSelector) || node.querySelector(toggleSelector)) {
            schedule();
            return;
          }
        }
      }
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  syncInitialTheme();
  document.addEventListener("click", handleThemeClick);
  window.addEventListener("resize", updateToggleButtons, { passive: true });
  setTimeout(updateToggleButtons, 180);
  setTimeout(updateToggleButtons, 700);
  setTimeout(updateToggleButtons, 1400);
  setupLateToggleObserver();

  if (prefersDarkQuery) {
    const syncSystemTheme = () => {
      if (normalizeTheme(root.getAttribute("data-theme")) === "system") {
        applyTheme("system", false);
      }
    };

    if (typeof prefersDarkQuery.addEventListener === "function") {
      prefersDarkQuery.addEventListener("change", syncSystemTheme);
    } else if (typeof prefersDarkQuery.addListener === "function") {
      prefersDarkQuery.addListener(syncSystemTheme);
    }
  }

  window.DDBTheme = {
    get: () => normalizeTheme(root.getAttribute("data-theme")),
    getEffectiveTheme: () => resolveDisplayTheme(root.getAttribute("data-theme")),
    set: (theme) => applyTheme(theme, true),
    toggle: toggleTheme,
    sync: () => applyTheme(readStorage(), true),
  };
})();
