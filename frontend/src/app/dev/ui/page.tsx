"use client";

import { useState } from "react";
import { ThemeToggle } from "@/components/theme-toggle";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";

const countries = [
  { value: "DZ", label: "Algérie" },
  { value: "BE", label: "Belgique" },
  { value: "BJ", label: "Bénin" },
  { value: "BF", label: "Burkina Faso" },
  { value: "CM", label: "Cameroun" },
  { value: "CA", label: "Canada" },
  { value: "CG", label: "Congo" },
  { value: "CI", label: "Côte d'Ivoire" },
  { value: "FR", label: "France" },
  { value: "GA", label: "Gabon" },
  { value: "GH", label: "Ghana" },
  { value: "GN", label: "Guinée" },
  { value: "ML", label: "Mali" },
  { value: "MA", label: "Maroc" },
  { value: "NE", label: "Niger" },
  { value: "NG", label: "Nigéria" },
  { value: "CD", label: "RD Congo" },
  { value: "SN", label: "Sénégal" },
  { value: "CH", label: "Suisse" },
  { value: "TG", label: "Togo" },
  { value: "TN", label: "Tunisie" },
];

const currencies = [
  { value: "XOF", label: "Franc CFA (BCEAO)" },
  { value: "XAF", label: "Franc CFA (BEAC)" },
  { value: "EUR", label: "Euro" },
  { value: "USD", label: "Dollar américain" },
];

export default function Home() {
  const [country, setCountry] = useState<string>();
  const [currency, setCurrency] = useState<string>("XOF");
  const [loading, setLoading] = useState(false);

  return (
    <main className="mx-auto max-w-3xl space-y-10 p-6 sm:p-10">
      <header className="flex items-start justify-between gap-4">
        <h1 className="text-3xl">Composants de base</h1>
        <ThemeToggle />
      </header>

      <section className="space-y-4 rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Boutons</h2>
        <div className="flex flex-wrap items-center gap-3">
          <Button>Principal</Button>
          <Button variant="secondary">Secondaire</Button>
          <Button variant="ghost">Discret</Button>
          <Button variant="destructive">Supprimer</Button>
          <Button disabled>Désactivé</Button>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button size="sm">Petit 36</Button>
          <Button size="md">Moyen 40</Button>
          <Button size="lg">Grand 44</Button>
          <Button
            loading={loading}
            onClick={() => {
              setLoading(true);
              window.setTimeout(() => setLoading(false), 2000);
            }}
          >
            Tester le chargement
          </Button>
        </div>
      </section>

      <section className="space-y-5 rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-lg">Champs</h2>
        <Field label="Nom de l'entreprise" hint="Il apparaîtra sur vos documents.">
          <Input size="lg" placeholder="Boutique Soleil" />
        </Field>
        <Field
          label="Adresse email"
          error="Cette adresse email n'est pas valide. Exemple : nom@entreprise.com"
        >
          <Input size="lg" type="email" defaultValue="pas-un-email" />
        </Field>
        <Field label="Téléphone" optional>
          <Input size="lg" placeholder="+229 ..." />
        </Field>
        <Field
          label="Pays"
          error={country ? undefined : "Choisissez le pays de votre entreprise."}
        >
          <Combobox
            size="lg"
            options={countries}
            value={country}
            onValueChange={setCountry}
            placeholder="Choisir un pays"
            searchPlaceholder="Rechercher un pays"
            emptyMessage="Aucun pays ne correspond à votre recherche."
          />
        </Field>
        <Field label="Devise">
          <Select
            size="lg"
            options={currencies}
            value={currency}
            onValueChange={setCurrency}
          />
        </Field>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg">Alertes</h2>
        <Alert variant="info" title="Information">
          Votre essai gratuit dure 14 jours.
        </Alert>
        <Alert variant="success" title="Email vérifié">
          Votre espace est prêt.
        </Alert>
        <Alert variant="warning" title="Essai bientôt terminé">
          Il vous reste 3 jours.
        </Alert>
        <Alert variant="danger" title="Impossible de joindre le serveur">
          Vérifiez votre connexion internet, puis réessayez.
        </Alert>
      </section>
    </main>
  );
}