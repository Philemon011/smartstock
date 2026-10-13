import type { Metadata } from "next";
import { LoginForm } from "@/components/auth/login-form";
import { firstParam, safeNextPath } from "@/lib/navigation";

export const metadata: Metadata = {
  title: "Connexion",
};

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[]; reset?: string | string[] }>;
}) {
  const params = await searchParams;

  return (
    <LoginForm
      next={safeNextPath(params.next)}
      notice={firstParam(params.reset) === "success" ? "password-reset" : undefined}
    />
  );
}