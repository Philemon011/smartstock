"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
import { AffixInput } from "@/components/ui/affix-input";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useArticleMutations } from "@/hooks/use-articles";
import { useUnits } from "@/hooks/use-units";
import type { Article } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "unit_id", "sale_price"] as const;

type FieldName = (typeof FIELDS)[number];
type FieldMessages = Partial<Record<FieldName, string>>;

type QuickArticleSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (article: Article) => void;
};

function QuickArticleForm({
  onCreated,
  onClose,
}: {
  onCreated: (article: Article) => void;
  onClose: () => void;
}) {
  const { tenant } = useActiveMembership();
  const mutations = useArticleMutations();
  const units = useUnits({ search: "", status: "active" });
  const formRef = useRef<HTMLFormElement>(null);

  const [name, setName] = useState("");
  const [unitId, setUnitId] = useState("");
  const [salePrice, setSalePrice] = useState("");
  const [trackExpiry, setTrackExpiry] = useState(false);
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  const unitOptions = (units.data?.data ?? []).map((unit) => ({
    value: unit.id,
    label: `${unit.name} (${unit.symbol})`,
  }));

  function clear(field: FieldName) {
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // Ce formulaire est dans un panneau rendu hors de la page : on empêche l'événement de remonter au formulaire de l'entrée.
    event.stopPropagation();

    if (mutations.create.isPending) return;

    setFormError(null);
    setMessages({});

    try {
      const article = await mutations.create.mutateAsync({
        name,
        description: null,
        barcode: null,
        category_id: null,
        unit_id: unitId,
        sale_price: salePrice,
        min_stock: "0",
        track_expiry: trackExpiry,
        expiry_alert_days: 30,
        packagings: [],
      });

      toast.success(`Article créé (${article.code}).`);
      onCreated(article);
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.code === "validation_error") {
        const fieldMessages = pickFieldMessages(error.fieldErrors, FIELDS);

        if (Object.keys(fieldMessages).length > 0) {
          setMessages(fieldMessages);
          window.setTimeout(() => {
            formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
          }, 0);
          return;
        }
      }

      setFormError(
        error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.",
      );
    }
  }

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={handleSubmit}
      className="flex min-h-0 flex-1 flex-col"
    >
      <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
        <Field label="Nom de l'article" error={messages.name}>
          <Input
            size="lg"
            autoComplete="off"
            placeholder="Riz parfumé 25 kg"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              clear("name");
            }}
          />
        </Field>

        <Field
          label="Unité"
          error={messages.unit_id}
          hint="L'unité dans laquelle vous comptez ce produit."
        >
          <Select
            size="lg"
            options={unitOptions}
            value={unitId}
            onValueChange={(value) => {
              setUnitId(value);
              clear("unit_id");
            }}
            placeholder="Choisir une unité"
          />
        </Field>

        <Field label="Prix de vente" error={messages.sale_price}>
          <AffixInput
            size="lg"
            inputMode="decimal"
            autoComplete="off"
            placeholder="500"
            suffix={tenant.currency}
            value={salePrice}
            onChange={(event) => {
              setSalePrice(event.target.value);
              clear("sale_price");
            }}
          />
        </Field>

        <Checkbox
          label="Ce produit a une date d'expiration"
          checked={trackExpiry}
          onChange={(event) => setTrackExpiry(event.target.checked)}
        />

        <p className="text-xs text-muted-foreground">
          La photo, la catégorie, le code-barres et les conditionnements se complètent ensuite depuis
          la fiche de l&apos;article.
        </p>

        {formError ? (
          <Alert variant="danger" title="Enregistrement impossible">
            {formError}
          </Alert>
        ) : null}
      </div>

      <div className="flex gap-3 border-t px-6 py-4">
        <Button type="button" variant="secondary" size="lg" className="flex-1" onClick={onClose}>
          Annuler
        </Button>
        <Button type="submit" size="lg" className="flex-1" loading={mutations.create.isPending}>
          Créer et choisir
        </Button>
      </div>
    </form>
  );
}

export function QuickArticleSheet({ open, onOpenChange, onCreated }: QuickArticleSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title="Nouvel article"
        description="Créez-le sans quitter votre entrée : il sera choisi sur la ligne."
      >
        <QuickArticleForm onCreated={onCreated} onClose={() => onOpenChange(false)} />
      </SheetContent>
    </Sheet>
  );
}