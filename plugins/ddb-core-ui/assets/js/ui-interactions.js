(() => {
  "use strict";

  const cfg = window.DDBCoreUIConfig || {};
  const cookieName = cfg.themeCookie || "ddb_theme";
  const defaultTheme = cfg.defaultTheme || "system";
  const validModes = new Set(["system", "light", "dark"]);

  const readCookie = (name) => {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const match = document.cookie.match(new RegExp(`(?:^|; )${escaped}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : "";
  };

  const writeCookie = (name, value) => {
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; path=/; max-age=${oneYear}; SameSite=Lax`;
  };

  const setTheme = (mode) => {
    const normalized = validModes.has(mode) ? mode : defaultTheme;
    document.documentElement.setAttribute("data-theme", normalized);
    window.localStorage.setItem(cookieName, normalized);
    writeCookie(cookieName, normalized);
    document.querySelectorAll("[data-ui-theme-value]").forEach((node) => {
      node.textContent = normalized;
    });
  };

  const initTheme = () => {
    const fromStorage = window.localStorage.getItem(cookieName) || "";
    const fromCookie = readCookie(cookieName);
    const resolved = validModes.has(fromStorage)
      ? fromStorage
      : validModes.has(fromCookie)
      ? fromCookie
      : defaultTheme;
    setTheme(resolved);
  };

  const initThemeToggles = () => {
    document.querySelectorAll("[data-ui-theme-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const current = document.documentElement.getAttribute("data-theme") || defaultTheme;
        const next = current === "system" ? "dark" : current === "dark" ? "light" : "system";
        setTheme(next);
      });
    });
  };

  const initAccordion = () => {
    document.querySelectorAll("[data-ui-accordion-trigger]").forEach((trigger) => {
      trigger.addEventListener("click", () => {
        const targetId = trigger.getAttribute("data-ui-accordion-trigger");
        if (!targetId) {
          return;
        }

        const panel = document.querySelector(`[data-ui-accordion-panel="${targetId}"]`);
        if (!(panel instanceof HTMLElement)) {
          return;
        }

        const expanded = trigger.getAttribute("aria-expanded") === "true";
        trigger.setAttribute("aria-expanded", expanded ? "false" : "true");
        panel.hidden = expanded;
      });
    });
  };

  const initNavToggle = () => {
    document.querySelectorAll("[data-ui-nav-toggle]").forEach((toggle) => {
      const targetId = toggle.getAttribute("data-ui-nav-toggle");
      if (!targetId) {
        return;
      }

      const target = document.querySelector(`[data-ui-nav-panel="${targetId}"]`);
      if (!(target instanceof HTMLElement)) {
        return;
      }

      toggle.addEventListener("click", () => {
        const open = toggle.getAttribute("aria-expanded") === "true";
        toggle.setAttribute("aria-expanded", open ? "false" : "true");
        target.hidden = open;
      });
    });
  };

  const initListingCardToggles = () => {
    document.querySelectorAll("[data-ui-card-save]").forEach((button) => {
      button.addEventListener("click", () => {
        const active = button.getAttribute("aria-pressed") === "true";
        button.setAttribute("aria-pressed", active ? "false" : "true");
        button.classList.toggle("is-active", !active);
      });
    });
  };

  const markMissingImage = (image) => {
    image.classList.add("is-ddb-image-missing");
    const media = image.closest(
      "figure, .ui-listing-card__media, .ddb-spot-card__media, .ao-spot-card__media, .woocommerce-product-gallery__image, .sbdp-combi-thumb"
    );
    if (media instanceof HTMLElement) {
      media.classList.add("is-ddb-media-missing");
    }
  };

  const initMissingImageFallbacks = () => {
    document
      .querySelectorAll(
        ".ui-listing-card__image, .ddb-spot-card__image, .ao-spot-card__image, .attachment-woocommerce_thumbnail, .woocommerce-product-gallery img"
      )
      .forEach((image) => {
        if (!(image instanceof HTMLImageElement)) {
          return;
        }

        image.addEventListener("error", () => markMissingImage(image), { once: true });

        if (image.complete && image.naturalWidth === 0) {
          markMissingImage(image);
        }
      });
  };

  const initShellFooterFallback = () => {
    if (document.querySelector("footer, .site-footer, [role='contentinfo']")) {
      return;
    }

    const footer = document.createElement("footer");
    footer.id = "ddb-runtime-footer";
    footer.className = "ddb-runtime-footer site-footer";
    footer.setAttribute("role", "contentinfo");
    footer.innerHTML = `
      <div class="ddb-runtime-footer__inner">
        <a class="ddb-runtime-footer__brand" href="/">DagjeDenBosch.nl</a>
        <nav class="ddb-runtime-footer__nav" aria-label="Footer">
          <a href="/activiteiten/">Activiteiten</a>
          <a href="/spots/">Plekken</a>
          <a href="/plan-je-dag/">Plan je dag</a>
          <a href="/offerte/">Offerte aanvragen</a>
        </nav>
      </div>
    `;
    document.body.appendChild(footer);
  };

  const initMainLandmarkFallback = () => {
    if (document.querySelector("main, [role='main']")) {
      return;
    }

    const mainCandidate = document.querySelector(
      ".elementor:not(.elementor-location-header):not(.elementor-location-footer), .sbdp-day-planner, .ddb-spots-listing, .woocommerce, .entry-content"
    );
    if (!(mainCandidate instanceof HTMLElement)) {
      return;
    }

    mainCandidate.setAttribute("role", "main");
    if (!mainCandidate.id) {
      mainCandidate.id = "content";
    }
  };

  initTheme();
  initThemeToggles();
  initAccordion();
  initNavToggle();
  initListingCardToggles();
  initMissingImageFallbacks();
  initShellFooterFallback();
  initMainLandmarkFallback();
})();
