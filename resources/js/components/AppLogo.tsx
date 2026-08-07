/**
 * The brand mark, in one place so swapping it is a single edit.
 *
 * Four arrows sweeping inward on a shared centre — the reseller's channels
 * converging into one shop. Drawn as an image rather than inline SVG because
 * the mark carries its own green field and rounded corners; tinting it to the
 * theme would break the logo rather than adapt it.
 *
 * The same source produces the favicons in `public/` (see `make_icons.py`),
 * so the tab icon and the in-app mark can never drift apart.
 */
export default function AppLogo({ className = 'size-8' }: { className?: string }) {
    return (
        <img
            src="/logo.png"
            alt=""
            aria-hidden
            className={`shrink-0 rounded-lg object-contain ${className}`}
        />
    );
}
