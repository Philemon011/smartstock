"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { PasswordRequirement } from "@/components/auth/password-requirement";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { resetPassword } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/client";

type ResetPasswordFormProps = {
  token: string;
  email: string;
  passwordMinLength: number;
};

type FormError = {
  title: string;
  message: string;
  linkExpired?: boolean;
};

export function ResetPasswordForm({ token, email, passwordMinLength }: ResetPasswordFormProps) {
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [passwordError, setPasswordError] = useState<string | undefined>();
  const [formError, setFormError] = useState<FormError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [focusToken, setFocusToken] = useState(0);

  const formRef = useRef<HTMLFormElement>(null);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  useEffect(() => {
    if (focusToken === 0) return;

    formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  }, [focusToken]);

  const longEnough = Array.from(password).length >= passwordMinLength;

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setPasswordError(undefined);

    const controller = new AbortController();
    abortRef.current = controller;
    let succeeded = false;

    try {
      await resetPassword({ token, email, password }, controller.signal);
      succeeded = true;
      router.replace("/login?reset=success");
    } catch (error) {
      if (controller.signal.aborted) return;

      if (error instanceof ApiError && error.code === "validation_error") {
        const tokenMessage = error.fieldErrors.token?.[0];
        const passwordMessage = error.fieldErrors.password?.[0];

        if (tokenMessage) {
          setFormError({
            title: "Lien invalide ou expiré",
            message: tokenMessage,
            linkExpired: true,
          });
        } else if (passwordMessage) {
          setPasswordError(passwordMessage);
          setFocusToken((current) => current + 1);
        } else {
          setFormError({ title: "Modification impossible", message: error.message });
        }
      } else {
        setFormError({
          title: "Modification impossible",
          message:
            error instanceof ApiError
              ? error.message
              : "Une erreur inattendue est survenue. Réessayez.",
        });
      }
    } finally {
      if (!succeeded && !controller.signal.aborted) {
        setSubmitting(false);
      }
    }
  }

  return (
    <div>
      <header className="space-y-2">
        <h1 className="text-3xl">Nouveau mot de passe</h1>
        <p className="text-muted-foreground">Choisissez un nouveau mot de passe pour votre compte.</p>
      </header>

      <form
        ref={formRef}
        noValidate
        onSubmit={handleSubmit}
        aria-busy={submitting || undefined}
        className="mt-8 space-y-5"
      >
        <Field label="Adresse email">
          <Input size="lg" type="email" name="email" autoComplete="username" value={email} readOnly />
        </Field>

        <Field label="Nouveau mot de passe" error={passwordError}>
          <div className="space-y-2.5">
            <PasswordInput
              size="lg"
              name="password"
              autoComplete="new-password"
              placeholder="Choisissez un mot de passe"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value);
                setPasswordError(undefined);
              }}
            />
            <div className="space-y-1.5">
              <PasswordRequirement met={longEnough}>
                Au moins {passwordMinLength} caractères
              </PasswordRequirement>
              <p className="text-xs text-subtle-foreground">
                Les mots de passe déjà apparus dans des fuites de données sont refusés.
              </p>
            </div>
          </div>
        </Field>

        <div className="space-y-4 pt-1">
          {formError ? (
            <Alert variant="danger" title={formError.title}>
              <p>{formError.message}</p>
              {formError.linkExpired ? (
                <Link
                  href="/forgot-password"
                  className="mt-1 inline-block font-medium text-primary underline-offset-4 hover:underline"
                >
                  Demander un nouveau lien
                </Link>
              ) : null}
            </Alert>
          ) : null}

          <Button type="submit" size="lg" className="w-full" loading={submitting}>
            Modifier mon mot de passe
          </Button>
        </div>
      </form>
    </div>
  );
}