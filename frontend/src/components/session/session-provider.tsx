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
import { fetchMe, logout, type Me } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/client";

type SessionState =
  | { status: "loading" }
  | { status: "authenticated"; me: Me }
  | { status: "unauthenticated" }
  | { status: "signed-out" }
  | { status: "error"; message: string };

type SessionContextValue = {
  session: SessionState;
  reload: () => void;
  signOut: () => Promise<void>;
  signOutError: string | null;
  dismissSignOutError: () => void;
};

const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<SessionState>({ status: "loading" });
  const [signOutError, setSignOutError] = useState<string | null>(null);
  const [reloadToken, setReloadToken] = useState(0);

  useEffect(() => {
    const controller = new AbortController();

    fetchMe(controller.signal)
      .then((me) => setSession({ status: "authenticated", me }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;

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

  const signOut = useCallback(async () => {
    setSignOutError(null);

    try {
      await logout();
      setSession({ status: "signed-out" });
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
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
    () => ({ session, reload, signOut, signOutError, dismissSignOutError }),
    [session, reload, signOut, signOutError, dismissSignOutError],
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