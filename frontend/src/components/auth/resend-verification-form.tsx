"use client";

import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { useResendVerification } from "@/hooks/use-resend-verification";

export function ResendVerificationForm() {
  const [email, setEmail] = useState("");
  const { resend, pending, feedback, fieldError, secondsLeft } = useResendVerification();

  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        void resend(email);
      }}
      className="space-y-4"
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

      {feedback ? <Alert variant={feedback.variant}>{feedback.message}</Alert> : null}

      <Button
        type="submit"
        size="lg"
        className="w-full"
        loading={pending}
        disabled={secondsLeft > 0}
      >
        {secondsLeft > 0 ? `Renvoyer dans ${secondsLeft} s` : "Recevoir un nouveau lien"}
      </Button>
    </form>
  );
}