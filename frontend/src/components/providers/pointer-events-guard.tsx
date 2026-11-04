"use client";

import { useEffect } from "react";

const OPEN_LAYERS = '[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]';

/**
 * Filet de sécurité : si <body> reste bloqué (pointer-events: none) alors qu'aucun panneau,
 * dialogue, menu ou liste n'est ouvert, la page est débloquée.
 */
export function PointerEventsGuard() {
  useEffect(() => {
    let timer: number | undefined;

    function release() {
      if (document.body.style.pointerEvents !== "none" || document.querySelector(OPEN_LAYERS)) {
        return;
      }

      document.body.style.pointerEvents = "";

      if (process.env.NODE_ENV !== "production") {
        console.warn("[PointerEventsGuard] Page bloquée détectée, puis débloquée.");
      }
    }

    function schedule() {
      window.clearTimeout(timer);
      // Laisse le temps aux animations de fermeture de se terminer.
      timer = window.setTimeout(release, 400);
    }

    const observer = new MutationObserver(schedule);
    observer.observe(document.body, {
      attributes: true,
      attributeFilter: ["style"],
      childList: true,
    });

    // Un clic sur une page bloquée la débloque aussitôt.
    window.addEventListener("pointerdown", release, true);

    return () => {
      window.clearTimeout(timer);
      observer.disconnect();
      window.removeEventListener("pointerdown", release, true);
    };
  }, []);

  return null;
}