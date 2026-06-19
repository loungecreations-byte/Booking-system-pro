/**
 * DagjeDenBosch Mega Navigation
 * Desktop: hover + click panels
 * Mobile: accordion
 */
(function () {
    'use strict';

    var MOBILE_BP = 1024;
    var CLOSE_DELAY = 150;
    var nav = document.querySelector('.ddb-mega-nav');
    if (!nav) return;

    var overlay = document.querySelector('.ddb-mega-nav__overlay');
    var burger = nav.querySelector('.ddb-mega-nav__burger');
    var list = nav.querySelector('.ddb-mega-nav__list');
    var items = nav.querySelectorAll('.ddb-mega-nav__item--has-panel');
    var themeToggle = nav.querySelector('[data-ddb-theme-toggle]');
    var closeTimers = {};
    var THEME_COOKIE = 'ddb_theme';

    function isMobile() {
        return window.innerWidth <= MOBILE_BP;
    }

    function normalizeTheme(value) {
        value = String(value || '').toLowerCase();
        return value === 'dark' ? 'dark' : 'light';
    }

    function getEffectiveTheme() {
        if (window.DDBTheme && typeof window.DDBTheme.getEffectiveTheme === 'function') {
            return normalizeTheme(window.DDBTheme.getEffectiveTheme());
        }
        var theme = document.documentElement.getAttribute('data-theme');
        return normalizeTheme(theme);
    }

    function applyTheme(theme) {
        theme = normalizeTheme(theme);
        if (window.DDBTheme && typeof window.DDBTheme.set === 'function') {
            window.DDBTheme.set(theme);
            return;
        }
        document.documentElement.setAttribute('data-theme', theme);
        try {
            window.localStorage.setItem(THEME_COOKIE, theme);
        } catch (error) {}
        document.cookie = THEME_COOKIE + '=' + encodeURIComponent(theme) + '; Path=/; Max-Age=31536000; SameSite=Lax';
        window.dispatchEvent(new CustomEvent('ddb:theme-change', { detail: { theme: theme, effectiveTheme: theme } }));
    }

    function syncThemeToggle() {
        if (!themeToggle) return;
        var isDark = getEffectiveTheme() === 'dark';
        themeToggle.textContent = isDark ? '☀' : '☾';
        themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        themeToggle.setAttribute('aria-label', isDark ? 'Schakel naar licht thema' : 'Schakel naar donker thema');
        themeToggle.setAttribute('title', isDark ? 'Licht thema' : 'Donker thema');
        themeToggle.setAttribute('data-theme-display', isDark ? 'dark' : 'light');
    }

    function closeAllPanels(except) {
        items.forEach(function (item) {
            if (item === except) return;
            var trigger = item.querySelector('.ddb-mega-nav__trigger');
            var panel = item.querySelector('.ddb-mega-nav__panel');
            if (trigger && panel) {
                trigger.setAttribute('aria-expanded', 'false');
                panel.setAttribute('aria-hidden', 'true');
            }
        });
        if (!except && overlay) {
            overlay.classList.remove('ddb-mega-nav__overlay--active');
        }
    }

    function togglePanel(item) {
        var trigger = item.querySelector('.ddb-mega-nav__trigger');
        var panel = item.querySelector('.ddb-mega-nav__panel');
        if (!trigger || !panel) return;

        var isOpen = trigger.getAttribute('aria-expanded') === 'true';

        if (isMobile()) {
            /* Accordion: close others, toggle this */
            closeAllPanels(item);
            trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            panel.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
        } else {
            closeAllPanels(item);
            trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            panel.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
            if (!isOpen && overlay) {
                overlay.classList.add('ddb-mega-nav__overlay--active');
            } else if (overlay) {
                overlay.classList.remove('ddb-mega-nav__overlay--active');
            }
        }
    }

    /* Click handlers for triggers */
    items.forEach(function (item, idx) {
        var trigger = item.querySelector('.ddb-mega-nav__trigger');
        if (!trigger) return;

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePanel(item);
        });

        /* Desktop hover */
        item.addEventListener('mouseenter', function () {
            if (isMobile()) return;
            if (closeTimers[idx]) {
                clearTimeout(closeTimers[idx]);
                closeTimers[idx] = null;
            }
            closeAllPanels(item);
            var t = item.querySelector('.ddb-mega-nav__trigger');
            var p = item.querySelector('.ddb-mega-nav__panel');
            if (t && p) {
                t.setAttribute('aria-expanded', 'true');
                p.setAttribute('aria-hidden', 'false');
                if (overlay) overlay.classList.add('ddb-mega-nav__overlay--active');
            }
        });

        item.addEventListener('mouseleave', function () {
            if (isMobile()) return;
            closeTimers[idx] = setTimeout(function () {
                var t = item.querySelector('.ddb-mega-nav__trigger');
                var p = item.querySelector('.ddb-mega-nav__panel');
                if (t && p) {
                    t.setAttribute('aria-expanded', 'false');
                    p.setAttribute('aria-hidden', 'true');
                }
                /* Check if any panel is still open */
                var anyOpen = false;
                items.forEach(function (it) {
                    var tr = it.querySelector('.ddb-mega-nav__trigger');
                    if (tr && tr.getAttribute('aria-expanded') === 'true') anyOpen = true;
                });
                if (!anyOpen && overlay) {
                    overlay.classList.remove('ddb-mega-nav__overlay--active');
                }
            }, CLOSE_DELAY);
        });
    });

    /* Burger toggle */
    if (burger && list) {
        burger.addEventListener('click', function () {
            var isOpen = burger.getAttribute('aria-expanded') === 'true';
            burger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            burger.setAttribute('aria-label', isOpen ? 'Menu openen' : 'Menu sluiten');
            list.classList.toggle('ddb-mega-nav__list--open', !isOpen);
            document.body.style.overflow = isOpen ? '' : 'hidden';
            if (isOpen) closeAllPanels();
        });
    }

    if (themeToggle) {
        syncThemeToggle();
        themeToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            applyTheme(getEffectiveTheme() === 'dark' ? 'light' : 'dark');
            syncThemeToggle();
        });
        window.addEventListener('ddb:theme-change', syncThemeToggle);
        setTimeout(syncThemeToggle, 300);
    }

    /* Overlay click closes everything */
    if (overlay) {
        overlay.addEventListener('click', function () {
            closeAllPanels();
            if (burger) {
                burger.setAttribute('aria-expanded', 'false');
                burger.setAttribute('aria-label', 'Menu openen');
                list.classList.remove('ddb-mega-nav__list--open');
                document.body.style.overflow = '';
            }
        });
    }

    /* Escape key */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllPanels();
            if (isMobile() && burger) {
                burger.setAttribute('aria-expanded', 'false');
                burger.setAttribute('aria-label', 'Menu openen');
                list.classList.remove('ddb-mega-nav__list--open');
                document.body.style.overflow = '';
            }
            /* Return focus to the last active trigger */
            var activeTrigger = nav.querySelector('.ddb-mega-nav__trigger:focus');
            if (activeTrigger) activeTrigger.blur();
        }
    });

    /* Close panels on click outside nav */
    document.addEventListener('click', function (e) {
        if (!nav.contains(e.target) && !isMobile()) {
            closeAllPanels();
        }
    });

    /* Handle resize: reset mobile state when going to desktop */
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (!isMobile()) {
                if (burger) {
                    burger.setAttribute('aria-expanded', 'false');
                    burger.setAttribute('aria-label', 'Menu openen');
                }
                if (list) list.classList.remove('ddb-mega-nav__list--open');
                document.body.style.overflow = '';
                closeAllPanels();
            }
        }, 150);
    });
})();
