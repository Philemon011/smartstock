import { CircleAlert, CircleCheck, Info } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";
import { ResendVerificationForm } from "@/components/auth/resend-verification-form";
import { StatusScreen } from "@/components/auth/status-screen";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: "Vérification de l'email",
};

type Status = "verified" | "already-verified" | "invalid";

function parseStatus(value: string | string[] | undefined): Status {
  const status = Array.isArray(value) ? value[0] : value;

  return status === "verified" || status === "already-verified" ? status : "invalid";
}

function LoginLink() {
  return (
    <Link href="/login" className={cn(buttonVariants({ size: "lg" }), "w-full")}>
      Se connecter
    </Link>
  );
}

export default async function VerifyEmailPage({
  searchParams,
}: {
  searchParams: Promise<{ status?: string | string[] }>;
}) {
  const status = parseStatus((await searchParams).status);

  if (status === "verified") {
    return (
      <StatusScreen
        icon={CircleCheck}
        tone="success"
        title="Email vérifié"
        actions={<LoginLink />}
      >
        <p>Votre espace est prêt et votre essai gratuit vient de démarrer.</p>
      </StatusScreen>
    );
  }

  if (status === "already-verified") {
    return (
      <StatusScreen
        icon={Info}
        tone="info"
        title="Email déjà vérifié"
        actions={<LoginLink />}
      >
        <p>Votre adresse est déjà confirmée. Vous pouvez vous connecter.</p>
      </StatusScreen>
    );
  }

  return (
    <StatusScreen
      icon={CircleAlert}
      tone="danger"
      title="Ce lien n'est plus valide"
      actions={<ResendVerificationForm />}
    >
      <p>
        Il a peut-être expiré ou a été modifié. Saisissez votre adresse email pour
        recevoir un nouveau lien.
      </p>
    </StatusScreen>
  );
}