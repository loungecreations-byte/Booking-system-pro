class ThemeToggleHandler extends elementorModules.frontend.handlers.Base {
    getDefaultSettings() {
        return { selectors: { btn: '.theme-toggle-btn', iconLight: '.icon-light', iconDark: '.icon-dark' } };
    }

    getDefaultElements() {
        const selectors = this.getSettings('selectors');
        return {
            $btn: this.$element.find(selectors.btn),
            $iconLight: this.$element.find(selectors.iconLight),
            $iconDark: this.$element.find(selectors.iconDark)
        };
    }

    bindEvents() {
        this.elements.$btn.on('click', this.toggleTheme.bind(this));
    }

    onInit() {
        super.onInit();
        this.initTheme();
    }

    initTheme() {
        const isLight = localStorage.getItem('angie-theme') === 'light';
        if (isLight) {
            document.body.classList.add('theme-light');
            this.elements.$iconDark.hide();
            this.elements.$iconLight.show();
        } else {
            document.body.classList.remove('theme-light');
            this.elements.$iconLight.hide();
            this.elements.$iconDark.show();
        }
    }

    toggleTheme() {
        const isLight = document.body.classList.contains('theme-light');
        if (isLight) {
            document.body.classList.remove('theme-light');
            localStorage.setItem('angie-theme', 'dark');
            this.elements.$iconLight.hide();
            this.elements.$iconDark.show();
        } else {
            document.body.classList.add('theme-light');
            localStorage.setItem('angie-theme', 'light');
            this.elements.$iconDark.hide();
            this.elements.$iconLight.show();
        }
    }
}

jQuery(window).on('elementor/frontend/init', () => {
    const addHandler = ($element) => {
        elementorFrontend.elementsHandler.addHandler(ThemeToggleHandler, { $element });
    };
    elementorFrontend.hooks.addAction('frontend/element_ready/dark_mode_toggle_a59c2cb1.default', addHandler);
});