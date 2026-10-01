// Theme: follows the OS until someone picks light or dark, then remembers it.
(() => {
    const key = 'laralyze.theme';
    const root = document.documentElement;

    const apply = (theme) => {
        if (theme === 'light' || theme === 'dark') {
            root.dataset.theme = theme;
        } else {
            delete root.dataset.theme;
        }
    };

    try {
        apply(localStorage.getItem(key));
    } catch {}

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-laralyze-theme]')) {
            return;
        }

        const next = { system: 'light', light: 'dark', dark: 'system' }[root.dataset.theme ?? 'system'];

        apply(next);

        try {
            next === 'system' ? localStorage.removeItem(key) : localStorage.setItem(key, next);
        } catch {}
    });
})();
