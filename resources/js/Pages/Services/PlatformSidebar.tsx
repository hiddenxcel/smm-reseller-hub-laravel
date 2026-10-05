import { Layers } from 'lucide-react';
import { compact, platformHue } from './bits';

/**
 * Platforms down the side, with counts.
 *
 * A second level appears under whichever platform is selected, rather than
 * every category being listed at once — a reseller with fifteen platforms and
 * eight categories each would otherwise face a list of 120 links.
 *
 * Counts are computed against the other active filters, so the number next to
 * "Instagram" is what clicking it would actually show.
 */
export default function PlatformSidebar({
    platforms,
    categories,
    selectedPlatform,
    selectedCategory,
    total,
    onSelect,
}: {
    platforms?: Array<{ platform: string; services: number; active: number }>;
    categories?: Array<{ category: string; services: number }>;
    selectedPlatform: string | null;
    selectedCategory: string | null;
    total: number;
    onSelect: (platform: string | null, category?: string | null) => void;
}) {
    return (
        <nav aria-label="Platforms" className="space-y-0.5">
            <Row
                label="All platforms"
                count={total}
                active={selectedPlatform === null}
                onClick={() => onSelect(null, null)}
                icon
            />

            {platforms === undefined
                ? Array.from({ length: 6 }).map((_, index) => (
                      <div key={index} className="px-2 py-1.5">
                          <div className="h-4 animate-pulse rounded bg-muted" />
                      </div>
                  ))
                : platforms.map((entry) => {
                      const isSelected = selectedPlatform === entry.platform;

                      return (
                          <div key={entry.platform}>
                              <Row
                                  label={entry.platform}
                                  count={entry.services}
                                  active={isSelected}
                                  hue={platformHue(entry.platform)}
                                  // Worth surfacing: a platform where most
                                  // services are off is usually a panel that
                                  // stopped listing them.
                                  muted={entry.active === 0}
                                  onClick={() =>
                                      onSelect(isSelected ? null : entry.platform, null)
                                  }
                              />

                              {isSelected && categories && categories.length > 0 && (
                                  <div className="ms-3 mt-0.5 space-y-0.5 border-s border-border ps-2">
                                      {categories.map((category) => (
                                          <Row
                                              key={category.category}
                                              label={category.category}
                                              count={category.services}
                                              active={selectedCategory === category.category}
                                              small
                                              onClick={() =>
                                                  onSelect(
                                                      entry.platform,
                                                      selectedCategory === category.category
                                                          ? null
                                                          : category.category,
                                                  )
                                              }
                                          />
                                      ))}
                                  </div>
                              )}
                          </div>
                      );
                  })}
        </nav>
    );
}

function Row({
    label,
    count,
    active,
    onClick,
    hue,
    icon = false,
    small = false,
    muted = false,
}: {
    label: string;
    count: number;
    active: boolean;
    onClick: () => void;
    hue?: number;
    icon?: boolean;
    small?: boolean;
    muted?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={active ? 'true' : undefined}
            className={[
                'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors',
                small ? 'text-xs' : 'text-sm',
                active
                    ? 'bg-accent font-semibold text-accent-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
            ].join(' ')}
        >
            {icon && <Layers className="size-3.5 shrink-0" aria-hidden />}

            {hue !== undefined && (
                <span
                    className="size-2 shrink-0 rounded-full"
                    style={{ backgroundColor: `oklch(0.70 0.10 ${hue})` }}
                    aria-hidden
                />
            )}

            <span className={`min-w-0 flex-1 truncate ${muted ? 'opacity-60' : ''}`}>
                {label}
            </span>

            <span className="font-data shrink-0 text-[0.7rem] tabular-nums opacity-70">
                {compact(count)}
            </span>
        </button>
    );
}

/**
 * The same filter as the sidebar, laid out for a phone: one scrolling row of
 * chips instead of a column that pushes the list a screen down. Picking a
 * platform adds a second row for its categories.
 */
export function PlatformChips({
    platforms,
    categories,
    selectedPlatform,
    selectedCategory,
    total,
    onSelect,
}: {
    platforms?: Array<{ platform: string; services: number; active: number }>;
    categories?: Array<{ category: string; services: number }>;
    selectedPlatform: string | null;
    selectedCategory: string | null;
    total: number;
    onSelect: (platform: string | null, category?: string | null) => void;
}) {
    if (platforms === undefined) {
        return <div className="h-8 animate-pulse rounded-full bg-muted" />;
    }

    return (
        <div className="space-y-2">
            <div className="scroll-slim -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                <Chip
                    label="All"
                    count={total}
                    active={selectedPlatform === null}
                    onClick={() => onSelect(null, null)}
                />
                {platforms.map((entry) => (
                    <Chip
                        key={entry.platform}
                        label={entry.platform}
                        count={entry.services}
                        active={selectedPlatform === entry.platform}
                        onClick={() =>
                            onSelect(selectedPlatform === entry.platform ? null : entry.platform, null)
                        }
                    />
                ))}
            </div>

            {selectedPlatform !== null && categories && categories.length > 0 && (
                <div className="scroll-slim -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                    {categories.map((category) => (
                        <Chip
                            key={category.category}
                            label={category.category}
                            count={category.services}
                            small
                            active={selectedCategory === category.category}
                            onClick={() =>
                                onSelect(
                                    selectedPlatform,
                                    selectedCategory === category.category ? null : category.category,
                                )
                            }
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function Chip({
    label,
    count,
    active,
    onClick,
    small = false,
}: {
    label: string;
    count: number;
    active: boolean;
    onClick: () => void;
    small?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={[
                'flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 transition-colors',
                small ? 'text-xs' : 'text-sm',
                active
                    ? 'border-primary bg-primary/10 font-semibold text-foreground'
                    : 'border-border text-muted-foreground',
            ].join(' ')}
        >
            {label}
            <span className="font-data text-[0.7rem] tabular-nums opacity-70">{compact(count)}</span>
        </button>
    );
}
