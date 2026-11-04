"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import type { PaginationMeta } from "@/lib/api/pagination";
import { cn } from "@/lib/utils";

type PaginationProps = {
  meta: PaginationMeta;
  itemLabel: { singular: string; plural: string };
  onPageChange: (page: number) => void;
  className?: string;
};

function pageItems(current: number, last: number): (number | "gap")[] {
  const pages = [...new Set([1, last, current - 1, current, current + 1])]
    .filter((page) => page >= 1 && page <= last)
    .sort((a, b) => a - b);

  const items: (number | "gap")[] = [];

  pages.forEach((page, index) => {
    const previous = pages[index - 1];

    if (previous !== undefined && page - previous > 1) {
      items.push(page - previous === 2 ? previous + 1 : "gap");
    }

    items.push(page);
  });

  return items;
}

const navButton =
  "inline-flex h-9 items-center gap-1.5 rounded-md border bg-card px-3 text-sm font-medium text-foreground outline-none transition-colors duration-200 ease-smooth hover:bg-accent focus-visible:ring-4 focus-visible:ring-ring/15 disabled:pointer-events-none disabled:opacity-50";

export function Pagination({ meta, itemLabel, onPageChange, className }: PaginationProps) {
  if (meta.last_page <= 1) {
    return null;
  }

  const { current_page: current, last_page: last } = meta;

  return (
    <nav
      aria-label="Pagination"
      className={cn(
        "flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between",
        className,
      )}
    >
      <p className="hidden text-sm text-muted-foreground sm:block">
        Affichage de {meta.from ?? 0} à {meta.to ?? 0} sur {meta.total}{" "}
        {meta.total > 1 ? itemLabel.plural : itemLabel.singular}
      </p>
      <p className="text-center text-sm text-muted-foreground sm:hidden">
        Page {current} sur {last}
      </p>

      <div className="flex items-center justify-between gap-1.5 sm:justify-end">
        <button
          type="button"
          className={navButton}
          disabled={current <= 1}
          onClick={() => onPageChange(current - 1)}
        >
          <ChevronLeft className="size-4" strokeWidth={1.5} aria-hidden />
          Précédent
        </button>

        <ul className="hidden items-center gap-1.5 sm:flex">
          {pageItems(current, last).map((item, index) =>
            item === "gap" ? (
              <li key={`gap-${index}`} aria-hidden className="px-1 text-sm text-muted-foreground">
                …
              </li>
            ) : (
              <li key={item}>
                <button
                  type="button"
                  aria-label={`Page ${item}`}
                  aria-current={item === current ? "page" : undefined}
                  onClick={() => onPageChange(item)}
                  className={cn(
                    "inline-flex size-9 items-center justify-center rounded-md text-sm font-medium outline-none transition-colors duration-200 ease-smooth focus-visible:ring-4 focus-visible:ring-ring/15",
                    item === current
                      ? "bg-primary text-primary-foreground"
                      : "text-foreground hover:bg-accent",
                  )}
                >
                  {item}
                </button>
              </li>
            ),
          )}
        </ul>

        <button
          type="button"
          className={navButton}
          disabled={current >= last}
          onClick={() => onPageChange(current + 1)}
        >
          Suivant
          <ChevronRight className="size-4" strokeWidth={1.5} aria-hidden />
        </button>
      </div>
    </nav>
  );
}