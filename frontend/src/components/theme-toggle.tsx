"use client";

import { Monitor, Moon, Sun } from "lucide-react";
import { useTheme } from "next-themes";
import { useSyncExternalStore } from "react";

const options = [
  { value: "light", label: "Clair", Icon: Sun },
  { value: "dark", label: "Sombre", Icon: Moon },
  { value: "system", label: "Système", Icon: Monitor },
] as const;

const subscribe = () => () => {};

export function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const mounted = useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );

  function select(value: string) {
    if (value === theme) return;

    const root = document.documentElement;
    const reduceMotion = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;

    if (!reduceMotion) {
      root.classList.add("theme-transition");
      window.setTimeout(() => root.classList.remove("theme-transition"), 300);
    }

    setTheme(value);
  }

  return (
    <div
      role="radiogroup"
      aria-label="Thème de l'interface"
      className="inline-flex h-10 items-center gap-1 rounded-md bg-muted p-1"
    >
      {options.map(({ value, label, Icon }) => {
        const active = mounted && theme === value;

        return (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={active}
            aria-label={label}
            title={label}
            onClick={() => select(value)}
            className={`inline-flex size-8 items-center justify-center rounded-sm transition-[background-color,color,box-shadow] duration-200 ease-smooth ${
              active
                ? "bg-card text-foreground shadow-card"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            <Icon className="size-4" strokeWidth={1.5} aria-hidden />
          </button>
        );
      })}
    </div>
  );
}