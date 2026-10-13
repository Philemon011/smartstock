"use client";

import { useSession } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import type { TenantSummary } from "@/lib/api/auth";
import {
  currencyName,
  currentMembership,
  firstName,
  pluralize,
  regionName,
} from "@/lib/session";

function TrialNotice({ tenant }: { tenant: TenantSummary }) {
  if (tenant.status === "trial" && tenant.trial_days_remaining !== null) {
    return (
      <Alert variant="info" title="Essai gratuit">
        Il vous reste {pluralize(tenant.trial_days_remaining, "jour", "jours")} pour découvrir
        toutes les fonctionnalités.
      </Alert>
    );
  }

  if (tenant.status === "expired") {
    return (
      <Alert variant="warning" title="Essai terminé">
        Votre essai gratuit est terminé. Vos données sont conservées et restent consultables.
      </Alert>
    );
  }

  return null;
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-foreground">{value}</dd>
    </div>
  );
}

export function DashboardHome() {
  const { session } = useSession();

  if (session.status !== "authenticated") {
    return null;
  }

  const { me } = session;
  const membership = currentMembership(me);

  return (
    <div className="space-y-8">
      <header className="space-y-1.5">
        <h1 className="text-3xl">Bonjour {firstName(me.user.name)}</h1>
        <p className="text-muted-foreground">
          {membership
            ? `Bienvenue dans l'espace de ${membership.tenant.name}.`
            : "Bienvenue."}
        </p>
      </header>

      {membership === null ? (
        <Alert variant="warning" title="Aucun accès actif">
          Votre accès à une entreprise est suspendu ou n&apos;existe plus. Contactez le
          propriétaire de l&apos;entreprise.
        </Alert>
      ) : (
        <>
          <TrialNotice tenant={membership.tenant} />

          <section className="rounded-xl border bg-card p-6 shadow-card">
            <h2 className="text-lg">Votre entreprise</h2>
            <dl className="mt-5 grid gap-5 sm:grid-cols-2">
              <Detail label="Nom" value={membership.tenant.name} />
              <Detail label="Votre rôle" value={membership.role_label} />
              <Detail label="Pays" value={regionName(membership.tenant.country)} />
              <Detail label="Devise" value={currencyName(membership.tenant.currency)} />
            </dl>
          </section>

          <section className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
            Entrepôts, articles et mouvements de stock arrivent prochainement.
          </section>
        </>
      )}
    </div>
  );
}