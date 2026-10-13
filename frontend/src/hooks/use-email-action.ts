"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { MessageResponse } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/client";

const DEFAULT_COOLDOWN_SECONDS = 60;

export type EmailActionFeedback = {
  variant: "success" | "warning" | "danger";
  message: string;
};

export function useEmailAction(
  action: (email: string, signal: AbortSignal) => Promise<MessageResponse>,
  initialCooldown = 0,
) {
  const [secondsLeft, setSecondsLeft] = useState(initialCooldown);
  const [pending, setPending] = useState(false);
  const [feedback, setFeedback] = useState<EmailActionFeedback | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (secondsLeft <= 0) return;

    const timer = window.setTimeout(() => setSecondsLeft((current) => current - 1), 1000);

    return () => window.clearTimeout(timer);
  }, [secondsLeft]);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  const resend = useCallback(
    async (email: string) => {
      if (pending || secondsLeft > 0) return;

      setPending(true);
      setFeedback(null);
      setFieldError(null);

      const controller = new AbortController();
      abortRef.current = controller;

      try {
        const result = await action(email, controller.signal);

        setFeedback({ variant: "success", message: result.message });
        setSecondsLeft(DEFAULT_COOLDOWN_SECONDS);
      } catch (error) {
        if (controller.signal.aborted) return;

        if (error instanceof ApiError && error.code === "validation_error") {
          setFieldError(error.fieldErrors.email?.[0] ?? error.message);
        } else if (error instanceof ApiError && error.code === "too_many_requests") {
          setFeedback({ variant: "warning", message: error.message });
          setSecondsLeft(error.retryAfter ?? DEFAULT_COOLDOWN_SECONDS);
        } else if (error instanceof ApiError) {
          setFeedback({ variant: "danger", message: error.message });
        } else {
          setFeedback({
            variant: "danger",
            message: "Une erreur inattendue est survenue. Réessayez.",
          });
        }
      } finally {
        if (!controller.signal.aborted) {
          setPending(false);
        }
      }
    },
    [action, pending, secondsLeft],
  );

  return { resend, pending, feedback, fieldError, secondsLeft };
}