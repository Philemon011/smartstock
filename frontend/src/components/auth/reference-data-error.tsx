"use client";

import { useRouter } from "next/navigation";
import { useTransition } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

export function ReferenceDataError() {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  return (
    <div className="space-y-5">
      <h1 className="text-3xl">Créez votre espace</h1>

      <Alert variant="danger" title="Le formulaire ne peut pas s'afficher">
        Impossible de joindre le serveur pour le moment. Vérifiez votre connexion
        internet, puis réessayez.
      </Alert>

      <Button
        size="lg"
        className="w-full"
        loading={pending}
        onClick={() => startTransition(() => router.refresh())}
      >
        Réessayer
      </Button>
    </div>
  );
}