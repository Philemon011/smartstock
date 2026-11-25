"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { fetchMe, logout, type Me, type Membership } from "@/lib/api/auth";
import { ApiError, setActiveTenantId, setUnauthenticatedHandler } from "@/lib/api/client";
import { currentMembership } from "@/lib/session";

type SessionState =
  | { status: "loading" }
  | { status: "authenticated"; me: Me }
  | { status: "unauthenticated" }
  | { status: "signed-out" }
  | { status: "error"; message: string };

type SessionContextValue = {
  session: SessionState;
    reload: () => void;
  /** Recharge la personne et ses entreprises sans repasser par l'écran de chargement. */
  refresh: () => Promise<void>;
  signOut: () => Promise<void>;
  signOutError: string | null;
  dismissSignOutError: () => void;
};

const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<SessionState>({ status: "loading" });
  const [signOutError, setSignOutError] = useState<string | null>(null);
  const [reloadToken, setReloadToken] = useState(0);

  // Une session expirée en cours d'utilisation ramène à la connexion.
  useEffect(() => {
    setUnauthenticatedHandler(() => {
      setActiveTenantId(null);
      setSession((current) =>
        current.status === "authenticated" ? { status: "unauthenticated" } : current,
      );
    });

    return () => setUnauthenticatedHandler(null);
  }, []);

  useEffect(() => {
    const controller = new AbortController();

    fetchMe(controller.signal)
      .then((me) => {
        // L'entreprise active est connue avant que la moindre page ne s'affiche.
        setActiveTenantId(currentMembership(me)?.tenant.id ?? null);
        setSession({ status: "authenticated", me });
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;

        setActiveTenantId(null);

        if (error instanceof ApiError && error.status === 401) {
          setSession({ status: "unauthenticated" });
          return;
        }

        setSession({
          status: "error",
          message:
            error instanceof ApiError
              ? error.message
              : "Une erreur inattendue est survenue. Réessayez.",
        });
      });

    return () => controller.abort();
  }, [reloadToken]);

  const reload = useCallback(() => {
    setSession({ status: "loading" });
    setReloadToken((token) => token + 1);
  }, []);

    const refresh = useCallback(async () => {
    try {
      const me = await fetchMe();

      setActiveTenantId(currentMembership(me)?.tenant.id ?? null);
      setSession({ status: "authenticated", me });
    } catch {
      // On garde l'état actuel : la page reste utilisable, la prochaine requête dira ce qui ne va pas.
    }
  }, []);

  const signOut = useCallback(async () => {
    setSignOutError(null);

    try {
      await logout();
      setActiveTenantId(null);
      setSession({ status: "signed-out" });
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setActiveTenantId(null);
        setSession({ status: "signed-out" });
        return;
      }

      setSignOutError(
        error instanceof ApiError
          ? error.message
          : "Impossible de vous déconnecter. Réessayez.",
      );
    }
  }, []);

  const dismissSignOutError = useCallback(() => setSignOutError(null), []);

    const value = useMemo(
    () => ({ session, reload, refresh, signOut, signOutError, dismissSignOutError }),
    [session, reload, refresh, signOut, signOutError, dismissSignOutError],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionContextValue {
  const context = useContext(SessionContext);

  if (context === null) {
    throw new Error("useSession doit être utilisé dans un SessionProvider.");
  }

  return context;
}

/**
 * L'entreprise active de la personne connectée. À n'utiliser que dans l'espace connecté :
 * le garde d'accès n'affiche les pages que si une entreprise active existe.
 */
export function useActiveMembership(): Membership {
  const { session } = useSession();

  if (session.status !== "authenticated") {
    throw new Error("useActiveMembership exige une session ouverte.");
  }

  const membership = currentMembership(session.me);

  if (membership === null) {
    throw new Error("Aucune entreprise active.");
  }

  return membership;
}