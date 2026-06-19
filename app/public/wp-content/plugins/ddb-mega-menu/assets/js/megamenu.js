(() => {
  "use strict";

  const cfg = window.DDBMegaMenuConfig || {};
  const DESKTOP_MIN = Number(cfg.desktopMin) || 1024;
  const ESC_KEY = cfg.escKey || "Escape";
  const roots = document.querySelectorAll(".ddb-mega-menu");

  if (!roots.length) {
    return;
  }

  const darkMediaQuery = window.matchMedia("(prefers-color-scheme: dark)");

  const updateResolvedTheme = (root) => {
    const mode = root.dataset.theme || "auto";

    if (mode === "light" || mode === "dark") {
      root.dataset.themeResolved = mode;
      return;
    }

    const htmlMode = document.documentElement.getAttribute("data-theme");
    if (htmlMode === "light" || htmlMode === "dark") {
      root.dataset.themeResolved = htmlMode;
      return;
    }

    root.dataset.themeResolved = darkMediaQuery.matches ? "dark" : "light";
  };

  const onThemeMediaChange = () => {
    roots.forEach((root) => {
      if ((root.dataset.theme || "auto") === "auto") {
        updateResolvedTheme(root);
      }
    });
  };

  if (typeof darkMediaQuery.addEventListener === "function") {
    darkMediaQuery.addEventListener("change", onThemeMediaChange);
  } else if (typeof darkMediaQuery.addListener === "function") {
    darkMediaQuery.addListener(onThemeMediaChange);
  }

  roots.forEach((root) => {
    const htmlRoot = document.documentElement;
    htmlRoot.classList.add("ddb-has-mega-menu");

    const legacyNavHost = root.closest(".main-nav");
    if (legacyNavHost instanceof HTMLElement) {
      legacyNavHost.classList.add("ddb-main-nav-host");
    }

    updateResolvedTheme(root);

    const header = root.querySelector(".ddb-header");
    const mega = root.querySelector(".ddb-mega");
    const themeToggles = Array.from(root.querySelectorAll("[data-ddb-theme-toggle]"));
    const triggers = Array.from(root.querySelectorAll("[data-ddb-mega-trigger]"));
    const mobileToggle = root.querySelector(".ddb-header__mobile-toggle");
    const drawer = root.querySelector(".ddb-mobile-drawer");
    const drawerClose = root.querySelector(".ddb-mobile-drawer__close");
    const backdrop = root.querySelector(".ddb-mobile-backdrop");
    const mobileTriggers = Array.from(root.querySelectorAll("[data-ddb-mobile-trigger]"));
    const stickyEnabled = root.dataset.sticky === "1";
    const transparentHome = root.dataset.transparentHome === "1";

    if (!header) {
      return;
    }

    let activeDesktopPanel = "";
    let drawerOpen = false;

    const isDesktop = () => window.innerWidth >= DESKTOP_MIN;

    const panelById = (panelId) =>
      root.querySelector(`.ddb-mega__panel[data-ddb-mega-panel="${panelId}"]`);
    const desktopTriggerById = (panelId) =>
      root.querySelector(`.ddb-header__trigger[data-ddb-mega-trigger="${panelId}"]`);
    const mobilePanelById = (panelId) =>
      root.querySelector(`.ddb-mobile-drawer__panel[data-ddb-mobile-panel="${panelId}"]`);

    const setExpanded = (el, state) => {
      el.setAttribute("aria-expanded", state ? "true" : "false");
    };

    const closeDesktopPanel = () => {
      if (!activeDesktopPanel) {
        return;
      }

      const trigger = desktopTriggerById(activeDesktopPanel);
      const panel = panelById(activeDesktopPanel);

      if (trigger) {
        setExpanded(trigger, false);
      }

      if (panel) {
        panel.hidden = true;
        panel.classList.remove("is-open");
      }

      activeDesktopPanel = "";
      header.classList.remove("is-mega-open");
      if (mega) {
        mega.setAttribute("aria-hidden", "true");
      }
    };

    const openDesktopPanel = (panelId, focusLink = false) => {
      if (!isDesktop()) {
        return;
      }

      const trigger = desktopTriggerById(panelId);
      const panel = panelById(panelId);

      if (!trigger || !panel) {
        return;
      }

      if (activeDesktopPanel === panelId) {
        return;
      }

      closeDesktopPanel();

      activeDesktopPanel = panelId;
      setExpanded(trigger, true);
      panel.hidden = false;
      panel.classList.add("is-open");

      header.classList.add("is-mega-open");
      if (mega) {
        mega.setAttribute("aria-hidden", "false");
      }

      if (focusLink) {
        const firstInteractive = panel.querySelector(
          'a, button, [tabindex]:not([tabindex="-1"])'
        );
        if (firstInteractive instanceof HTMLElement) {
          firstInteractive.focus();
        }
      }
    };

    const closeAllMobilePanels = (exceptId = "") => {
      mobileTriggers.forEach((trigger) => {
        const panelId = trigger.getAttribute("data-ddb-mobile-trigger") || "";
        const panel = mobilePanelById(panelId);
        const shouldOpen = panelId === exceptId;

        setExpanded(trigger, shouldOpen);

        if (panel) {
          panel.hidden = !shouldOpen;
        }
      });
    };

    const openDrawer = () => {
      if (drawerOpen || !drawer || !backdrop) {
        return;
      }

      drawer.hidden = false;
      backdrop.hidden = false;
      root.classList.add("is-drawer-open");
      document.body.classList.add("ddb-mega-lock");
      drawerOpen = true;

      if (mobileToggle) {
        setExpanded(mobileToggle, true);
      }
    };

    const closeDrawer = () => {
      if (!drawerOpen || !drawer || !backdrop) {
        return;
      }

      drawer.hidden = true;
      backdrop.hidden = true;
      root.classList.remove("is-drawer-open");
      document.body.classList.remove("ddb-mega-lock");
      drawerOpen = false;

      if (mobileToggle) {
        setExpanded(mobileToggle, false);
      }

      closeAllMobilePanels();
    };

    const updateHeaderState = () => {
      const scrollY =
        window.scrollY ||
        window.pageYOffset ||
        document.documentElement.scrollTop ||
        0;
      const scrolled = scrollY > 8;

      if (stickyEnabled) {
        header.classList.toggle("is-scrolled", scrolled);
      }

      if (transparentHome) {
        header.classList.toggle("is-top", !scrolled);
      }
    };

    triggers.forEach((trigger) => {
      const panelId = trigger.getAttribute("data-ddb-mega-trigger") || "";
      if (!panelId) {
        return;
      }

      trigger.addEventListener("click", (event) => {
        event.preventDefault();

        if (!isDesktop()) {
          return;
        }

        if (activeDesktopPanel === panelId) {
          closeDesktopPanel();
          return;
        }

        openDesktopPanel(panelId);
      });

      trigger.addEventListener("mouseenter", () => {
        if (isDesktop()) {
          openDesktopPanel(panelId);
        }
      });

      trigger.addEventListener("focus", () => {
        if (isDesktop()) {
          openDesktopPanel(panelId);
        }
      });

      trigger.addEventListener("keydown", (event) => {
        if (event.key === ESC_KEY) {
          closeDesktopPanel();
          trigger.focus();
          return;
        }

        if (event.key === "ArrowDown" && isDesktop()) {
          event.preventDefault();
          openDesktopPanel(panelId, true);
        }
      });
    });

    header.addEventListener("mouseleave", () => {
      if (isDesktop()) {
        closeDesktopPanel();
      }
    });

    root.addEventListener("focusout", (event) => {
      if (!isDesktop()) {
        return;
      }

      const next = event.relatedTarget;
      if (next instanceof Node && root.contains(next)) {
        return;
      }

      closeDesktopPanel();
    });

    document.addEventListener("click", (event) => {
      if (!(event.target instanceof Node)) {
        return;
      }

      if (!root.contains(event.target)) {
        closeDesktopPanel();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== ESC_KEY) {
        return;
      }

      closeDesktopPanel();
      closeDrawer();
    });

    if (mobileToggle) {
      mobileToggle.addEventListener("click", () => {
        if (drawerOpen) {
          closeDrawer();
        } else {
          openDrawer();
        }
      });
    }

    if (drawerClose) {
      drawerClose.addEventListener("click", closeDrawer);
    }

    if (backdrop) {
      backdrop.addEventListener("click", closeDrawer);
    }

    if (drawer) {
      drawer.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        if (target.closest(".ddb-mobile-drawer__link, .ddb-mobile-drawer__action, .ddb-mobile-drawer__cta")) {
          closeDrawer();
        }
      });
    }

    mobileTriggers.forEach((trigger) => {
      trigger.addEventListener("click", () => {
        const panelId = trigger.getAttribute("data-ddb-mobile-trigger") || "";
        const panel = mobilePanelById(panelId);
        const isExpanded = trigger.getAttribute("aria-expanded") === "true";

        if (!panelId || !panel) {
          return;
        }

        if (isExpanded) {
          setExpanded(trigger, false);
          panel.hidden = true;
          return;
        }

        closeAllMobilePanels(panelId);
      });
    });

    window.addEventListener(
      "scroll",
      () => {
        updateHeaderState();
      },
      { passive: true }
    );

    window.addEventListener("resize", () => {
      if (isDesktop()) {
        closeDrawer();
      } else {
        closeDesktopPanel();
      }
      updateHeaderState();
    });

    if (!window.DDBTheme || typeof window.DDBTheme.toggle !== "function") {
      const applyFallbackToggleUi = () => {
        const htmlTheme = (document.documentElement.getAttribute("data-theme") || "system").toLowerCase();
        const dark = htmlTheme === "dark";
        const label = dark ? "Schakel naar licht thema" : "Schakel naar donker thema";
        themeToggles.forEach((button) => {
          button.textContent = dark ? "☀" : "☾";
          button.setAttribute("aria-label", label);
          button.setAttribute("aria-pressed", dark ? "true" : "false");
          button.setAttribute("title", dark ? "Licht thema" : "Donker thema");
          button.setAttribute("data-theme-display", dark ? "dark" : "light");
        });
      };

      const toggleFallbackTheme = () => {
        const current = (document.documentElement.getAttribute("data-theme") || "system").toLowerCase();
        const next = current === "dark" ? "light" : "dark";
        document.documentElement.setAttribute("data-theme", next);
        try {
          window.localStorage.setItem("ddb_theme", next);
        } catch (error) {
          // Ignore storage failures.
        }
        document.cookie = `ddb_theme=${next}; Path=/; Max-Age=31536000; SameSite=Lax`;
        applyFallbackToggleUi();
      };

      themeToggles.forEach((button) => {
        if (!(button instanceof HTMLElement) || button.dataset.ddbFallbackBound === "1") {
          return;
        }
        button.dataset.ddbFallbackBound = "1";
        button.addEventListener("click", (event) => {
          event.preventDefault();
          toggleFallbackTheme();
        });
      });

      applyFallbackToggleUi();
    }

    window.addEventListener("ddb:theme-change", () => {
      updateResolvedTheme(root);
    });

    updateHeaderState();
  });
})();
