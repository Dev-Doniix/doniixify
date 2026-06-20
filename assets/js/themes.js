(function () {
    const THEMES = {
        'default': { name: 'Doniixify Dark', author: 'Doniixify', accent: '#e7e9ea', preview: '#000000' },
        'vesper': { name: 'Vesper', author: 'rauno.me', accent: '#ffac4a', preview: '#000000' },
        'spotify-classic': { name: 'Spotify', author: 'Spotify', accent: '#1db954', preview: '#121212' },
        'fluent-dark': { name: 'Fluent Dark', author: 'williamckha', accent: '#00befd', preview: '#272727' },
        'catppuccin-mocha': { name: 'Catppuccin Mocha', author: 'catppuccin', accent: '#cba6f7', preview: '#1e1e2e' },
        'bloom': { name: 'Bloom', author: 'nimsandu', accent: '#00ffa1', preview: '#14141a' },
        'bloom-violet': { name: 'Bloom Violet', author: 'nimsandu', accent: '#be98d9', preview: '#362c48' },
        'bloom-coffee': { name: 'Bloom Coffee', author: 'nimsandu', accent: '#f0ddc2', preview: '#533a28' },
        'comfy': { name: 'Comfy', author: 'NYRI4', accent: '#7289da', preview: '#23283d' },
        'dracula': { name: 'Dracula', author: 'dracula-theme', accent: '#ff79c6', preview: '#282a36' },
        'gruvbox': { name: 'Gruvbox', author: 'Skaytacium', accent: '#ebdbb2', preview: '#282828' },
        'tokyo-night': { name: 'Tokyo Night Storm', author: 'enkia', accent: '#9ece6a', preview: '#24283b' },
        'nord': { name: 'Nord', author: 'arcticicestudio', accent: '#88c0d0', preview: '#2e3440' },
        'rose-pine': { name: 'Rosé Pine', author: 'rose-pine', accent: '#ebbcba', preview: '#191724' },
        'solarized': { name: 'Solarized Dark', author: 'altercation', accent: '#859900', preview: '#002b36' },
        'lucid': { name: 'Lucid Glass', author: 'sanoojes', accent: '#a6c1ff', preview: '#0c0c10' },
    };

    const STORAGE_KEY = 'doniix-theme';
    const DEFAULT_ID = 'default';

    const getThemeId = () => {
        try {
            const stored = localStorage.getItem(STORAGE_KEY) || DEFAULT_ID;
            return THEMES[stored] ? stored : DEFAULT_ID;
        } catch (e) { return DEFAULT_ID; }
    };

    const apply = (id) => {
        if (!THEMES[id]) id = DEFAULT_ID;
        const root = document.documentElement;
        root.dataset.theme = id;
        if (document.body) document.body.dataset.theme = id;
        try {
            const meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', THEMES[id].preview);
        } catch (e) {}
    };

    const set = (id) => {
        if (!THEMES[id]) id = DEFAULT_ID;
        try { localStorage.setItem(STORAGE_KEY, id); } catch (e) {}
        apply(id);
        return true;
    };

    window.__doniixThemes = { list: THEMES, current: getThemeId, apply: set };
    apply(getThemeId());
    document.addEventListener('DOMContentLoaded', () => apply(getThemeId()));
})();
