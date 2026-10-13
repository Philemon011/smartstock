"use client";

import { MailCheck } from "lucide-react";
import Link from "next/link";
import { useCallback, useState, type FormEvent } from "react";
import { StatusScreen } from "@/components/auth/status-screen";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { useEmailAction } from "@/hooks/use-email-action";
import { forgotPassword } from "@/lib/api/auth";
import { cn } from "@/lib/utils";

export function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [sentMessage, setSentMessage] = useState<string | null>(null);

  const action = useCallback(async (address: string, signal: AbortSignal) => {
    const result = await forgotPassword(address, signal);

    setSentMessage(result.message);

    return result;
  }, []);

  const { resend, pending, feedback, fieldError, secondsLeft } = useEmailAction(action);

  const problem = feedback && feedback.variant !== "success" ? feedback : null;

  if (sentMessage) {
    return (
      <StatusScreen
        icon={MailCheck}
        title="Vérifiez votre boîte mail"
        actions={
          <>
            {problem ? <Alert variant={problem.variant}>{problem.message}</Alert> : null}

            <Button
              variant="secondary"
              size="lg"
              className="w-full"
              loading={pending}
              disabled={secondsLeft > 0}
              onClick={() => void resend(email)}
            >
              {secondsLeft > 0 ? `Renvoyer l'email dans ${secondsLeft} s` : "Renvoyer l'email"}
            </Button>

            <Link
              href="/login"
              className={cn(buttonVariants({ variant: "ghost", size: "lg" }), "w-full")}
            >
              Retour à la connexion
            </Link>
          </>
        }
      >
        <p>{sentMessage}</p>
        <p className="text-sm">Pensez à vérifier vos courriers indésirables.</p>
      </StatusScreen>
    );
  }

  return (
    <div>
      <header className="space-y-2">
        <h1 className="text-3xl">Mot de passe oublié</h1>
        <p className="text-muted-foreground">
          Saisissez l&apos;adresse de votre compte. Nous vous enverrons un lien pour choisir un
          nouveau mot de passe.
        </p>
      </header>

      <form
        noValidate
        onSubmit={(event: FormEvent<HTMLFormElement>) => {
          event.preventDefault();
          void resend(email);
        }}
        className="mt-8 space-y-6"
      >
        <Field label="Adresse email" error={fieldError ?? undefined}>
          <Input
            size="lg"
            type="email"
            name="email"
            autoComplete="email"
            inputMode="email"
            placeholder="nom@entreprise.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </Field>

        {problem ? <Alert variant={problem.variant}>{problem.message}</Alert> : null}

        <Button type="submit" size="lg" className="w-full" loading={pending}>
          Envoyer le lien
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted-foreground">
        <Link
          href="/login"
          className="font-medium text-primary underline-offset-4 hover:underline"
        >
          Retour à la connexion
        </Link>
      </p>
    </div>
  );
}