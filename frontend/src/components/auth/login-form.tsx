
"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { useResendVerification } from "@/hooks/use-resend-verification";
import { fetchMe, login } from "@/lib/api/auth";
import { ApiError } from "@/lib/api/client";
import { pickFieldMessages } from "@/lib/forms";

type LoginFormProps = {
  next: string;
  notice?: "password-reset";
};

type FormError = {
  variant: "danger" | "warning";
  title: string;
  message: string;
  unverified?: boolean;
};

type FieldName = "email" | "password";
type FieldMessages = Partial<Record<FieldName, string>>;

const FIELDS = ["email", "password"] as const;

export function LoginForm({ next, notice }: LoginFormProps) {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [fieldMessages, setFieldMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<FormError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [focusToken, setFocusToken] = useState(0);

  const formRef = useRef<HTMLFormElement>(null);
  const abortRef = useRef<AbortController | null>(null);
  const resend = useResendVerification();

  // Une personne déjà connectée n'a rien à faire ici.
  useEffect(() => {
    const controller = new AbortController();

    fetchMe(controller.signal)
      .then(() => router.replace(next))
      .catch(() => undefined);

    return () => controller.abort();
  }, [next, router]);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  useEffect(() => {
    if (focusToken === 0) return;

    formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  }, [focusToken]);

  function clearMessage(field: FieldName) {
    setFieldMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  function applyError(error: unknown) {
    const generic: FormError = {
      variant: "danger",
      title: "Connexion impossible",
      message: "Une erreur inattendue est survenue. Réessayez.",
    };

    if (!(error instanceof ApiError)) {
      setFormError(generic);
      return;
    }

    if (error.code === "validation_error") {
      const credentials = error.fieldErrors.credentials?.[0];

      if (credentials) {
        setFormError({ ...generic, message: credentials });
        return;
      }

      const messages = pickFieldMessages(error.fieldErrors, FIELDS);

      if (Object.keys(messages).length > 0) {
        setFieldMessages(messages);
        setFocusToken((token) => token + 1);
        return;
      }
    }

    if (error.code === "email_not_verified") {
      setFormError({
        variant: "warning",
        title: "Adresse email non vérifiée",
        message: error.message,
        unverified: true,
      });
      return;
    }

    if (error.code === "too_many_requests") {
      setFormError({ variant: "warning", title: "Trop de tentatives", message: error.message });
      return;
    }

    setFormError({ ...generic, message: error.message });
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setFieldMessages({});

    const controller = new AbortController();
    abortRef.current = controller;
    let succeeded = false;

    try {
      await login({ email, password, remember }, controller.signal);
      succeeded = true;
      router.replace(next);
    } catch (error) {
      if (controller.signal.aborted) return;

      applyError(error);
    } finally {
      if (!succeeded && !controller.signal.aborted) {
        setSubmitting(false);
      }
    }
  }

  return (
    <div>
      <header className="space-y-2">
        <h1 className="text-3xl">Connexion</h1>
        <p className="text-muted-foreground">Accédez à votre espace de gestion de stock.</p>
      </header>

      {notice === "password-reset" ? (
        <Alert variant="success" title="Mot de passe modifié" className="mt-6">
          Connectez-vous avec votre nouveau mot de passe.
        </Alert>
      ) : null}

      <form
        ref={formRef}
        noValidate
        onSubmit={handleSubmit}
        aria-busy={submitting || undefined}
        className="mt-8 space-y-5"
      >
        <Field label="Adresse email" error={fieldMessages.email}>
          <Input
            size="lg"
            type="email"
            name="email"
            autoComplete="email"
            inputMode="email"
            placeholder="nom@entreprise.com"
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
              clearMessage("email");
            }}
          />
        </Field>

        <Field label="Mot de passe" error={fieldMessages.password}>
          <div className="space-y-2.5">
            <PasswordInput
              size="lg"
              name="password"
              autoComplete="current-password"
              placeholder="Votre mot de passe"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value);
                clearMessage("password");
              }}
            />
            <div className="text-right">
              <Link
                href="/forgot-password"
                className="text-sm font-medium text-primary underline-offset-4 hover:underline"
              >
                Mot de passe oublié ?
              </Link>
            </div>
          </div>
        </Field>

        <Checkbox
          name="remember"
          label="Se souvenir de moi"
          checked={remember}
          onChange={(event) => setRemember(event.target.checked)}
        />

        <div className="space-y-4 pt-1">
          {formError ? (
            <div className="space-y-3">
              <Alert variant={formError.variant} title={formError.title}>
                {formError.message}
              </Alert>

              {formError.unverified ? (
                <>
                  {resend.feedback ? (
                    <Alert variant={resend.feedback.variant}>{resend.feedback.message}</Alert>
                  ) : null}

                  <Button
                    variant="secondary"
                    size="lg"
                    className="w-full"
                    loading={resend.pending}
                    disabled={resend.secondsLeft > 0}
                    onClick={() => void resend.resend(email)}
                  >
                    {resend.secondsLeft > 0
                      ? `Renvoyer le lien dans ${resend.secondsLeft} s`
                      : "Renvoyer le lien de vérification"}
                  </Button>
                </>
              ) : null}
            </div>
          ) : null}

          <Button type="submit" size="lg" className="w-full" loading={submitting}>
            Se connecter
          </Button>
        </div>
      </form>

      <p className="mt-6 text-center text-sm text-muted-foreground">
        Pas encore de compte ?{" "}
        <Link
          href="/register"
          className="font-medium text-primary underline-offset-4 hover:underline"
        >
          Créer mon espace
        </Link>
      </p>
    </div>
  );
}