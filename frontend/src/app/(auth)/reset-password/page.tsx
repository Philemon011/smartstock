import { CircleAlert } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";
import { ReferenceDataError } from "@/components/auth/reference-data-error";
import { ResetPasswordForm } from "@/components/auth/reset-password-form";
import { StatusScreen } from "@/components/auth/status-screen";
import { buttonVariants } from "@/components/ui/button";
import { fetchReferenceData } from "@/lib/api/meta";
import { firstParam } from "@/lib/navigation";
import { cn } from "@/lib/utils";

export const metadata: Metadata = {
  title: "Nouveau mot de passe",
};

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string | string[]; email?: string | string[] }>;
}) {
  const params = await searchParams;
  const token = firstParam(params.token);
  const email = firstParam(params.email);

  if (!token || !email) {
    return (
      <StatusScreen
        icon={CircleAlert}
        tone="danger"
        title="Lien incomplet"
        actions={
          <Link
            href="/forgot-password"
            className={cn(buttonVariants({ size: "lg" }), "w-full")}
          >
            Demander un nouveau lien
          </Link>
        }
      >
        <p>Utilisez le lien reçu par email, ou demandez-en un nouveau.</p>
      </StatusScreen>
    );
  }

  const data = await fetchReferenceData();

  if (data === null) {
    return <ReferenceDataError title="Nouveau mot de passe" />;
  }

  return (
    <ResetPasswordForm
      token={token}
      email={email}
      passwordMinLength={data.password_min_length}
    />
  );
}