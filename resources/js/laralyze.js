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

// "Copy as Markdown": copies the text of the element the button names.
(() => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-laralyze-copy]');
        const source = button && document.getElementById(button.dataset.laralyzeCopy);

        if (!source) {
            return;
        }

        const label = button.querySelector('[data-label]') ?? button;
        const done = () => {
            label.textContent = 'Copied';
            setTimeout(() => (label.textContent = button.dataset.label ?? 'Copy'), 1500);
        };

        // The clipboard API needs HTTPS; fall back for plain-HTTP dashboards.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(source.value ?? source.textContent).then(done);
        } else {
            source.select();
            document.execCommand('copy');
            done();
        }
    });
})();

// "Ask AI": opens the assistant panel about what the button names.
(() => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-laralyze-ask]');

        if (button && window.Livewire) {
            window.Livewire.dispatch('laralyze-ask', { kind: button.dataset.kind ?? 'general', key: button.dataset.key ?? '' });
        }
    });
})();
