"use client";

import { MailCheck } from "lucide-react";
import { StatusScreen } from "@/components/auth/status-screen";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useResendVerification } from "@/hooks/use-resend-verification";

type RegistrationSuccessProps = {
  email: string;
  message: string;
  onEdit: () => void;
};

export function RegistrationSuccess({ email, message, onEdit }: RegistrationSuccessProps) {
  const { resend, pending, feedback, secondsLeft } = useResendVerification(60);

  return (
    <StatusScreen
      icon={MailCheck}
      title="Vérifiez votre boîte mail"
      actions={
        <>
          {feedback ? <Alert variant={feedback.variant}>{feedback.message}</Alert> : null}

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

          <Button variant="ghost" size="lg" className="w-full" onClick={onEdit}>
            Modifier l&apos;adresse email
          </Button>
        </>
      }
    >
      <p>{message}</p>
      <p className="text-sm">
        Adresse saisie : <span className="font-medium text-foreground">{email}</span>
      </p>
      <p className="text-sm">Pensez à vérifier vos courriers indésirables.</p>
    </StatusScreen>
  );
}