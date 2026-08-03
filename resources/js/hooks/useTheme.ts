import { useCallback, useEffect, useState } from 'react';

type Theme = 'light' | 'dark';

/**
 * Light/dark, remembered per browser.
 *
 * The class is already on <html> before React mounts — app.blade.php sets it
 * inline to avoid a flash of the wrong theme — so this reads the DOM as its
 * starting truth rather than re-deriving it and risking a mismatch.
 */
export function useTheme() {
    const [theme, setTheme] = useState<Theme>(() =>
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
            ? 'dark'
            : 'light',
    );

    useEffect(() => {
        const root = document.documentElement;

        root.classList.toggle('dark', theme === 'dark');
        // Tells the browser which way to render its own chrome — scrollbars,
        // form controls, autofill — which CSS variables cannot reach.
        root.style.colorScheme = theme;

        try {
            localStorage.setItem('theme', theme);
        } catch {
            /* storage disabled; the theme still applies for this session */
        }
    }, [theme]);

    const toggle = useCallback(
        () => setTheme((current) => (current === 'dark' ? 'light' : 'dark')),
        [],
    );

    return { theme, toggle, isDark: theme === 'dark' };
}
