/**
 * The brand mark, in one place so swapping it is a single edit.
 *
 * This is a placeholder standing in for the real logo: four arrows converging
 * on a centre, which is the same idea as the supplied mark. When the final
 * asset lands, replace the SVG body here — every place the logo appears reads
 * from this component.
 */
export default function AppLogo({ className = 'size-8' }: { className?: string }) {
    return (
        <span
            className={`flex shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground ${className}`}
            aria-hidden
        >
            <svg viewBox="0 0 24 24" fill="none" className="size-[70%]">
                {/* Four arrows pointing inward at a shared centre. */}
                <path
                    d="M4 4l5 5M9 9V5.5M9 9H5.5M20 4l-5 5M15 9v-3.5M15 9h3.5M4 20l5-5M9 15v3.5M9 15H5.5M20 20l-5-5M15 15v3.5M15 15h3.5"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
        </span>
    );
}
