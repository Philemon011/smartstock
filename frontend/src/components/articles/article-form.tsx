"use client";

import { useRouter } from "next/navigation";
import { useRef, useState, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import { PhotoField, type PhotoState } from "@/components/articles/photo-field";
import {
  PackagingsField,
  type PackagingRow,
  type PackagingRowErrors,
} from "@/components/articles/packagings-field";
import { useActiveMembership } from "@/components/session/session-provider";
import { AffixInput } from "@/components/ui/affix-input";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Combobox } from "@/components/ui/combobox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useArticleMutations } from "@/hooks/use-articles";
import { useCategories } from "@/hooks/use-categories";
import { useUnits } from "@/hooks/use-units";
import type { Article, ArticleInput } from "@/lib/api/articles";
import { ApiError } from "@/lib/api/client";
import { pickFieldMessages } from "@/lib/forms";

const NONE = "__none__";

const FIELDS = [
  "name",
  "description",
  "barcode",
  "category_id",
  "unit_id",
  "sale_price",
  "min_stock",
  "expiry_alert_days",
] as const;

type FieldName = (typeof FIELDS)[number];
type FieldMessages = Partial<Record<FieldName, string>>;

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <h2 className="text-lg">{title}</h2>
      {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
      <div className="mt-6 space-y-5">{children}</div>
    </section>
  );
}

export function ArticleForm({ article }: { article: Article | null }) {
  const router = useRouter();
  const { tenant } = useActiveMembership();
  const mutations = useArticleMutations();
  const units = useUnits({ search: "", status: "all" });
  const categories = useCategories({ search: "", status: "all" });
  const formRef = useRef<HTMLFormElement>(null);

  const [name, setName] = useState(article?.name ?? "");
  const [description, setDescription] = useState(article?.description ?? "");
  const [barcode, setBarcode] = useState(article?.barcode ?? "");
  const [categoryId, setCategoryId] = useState(article?.category?.id ?? NONE);
  const [unitId, setUnitId] = useState(article?.unit.id ?? "");
  const [salePrice, setSalePrice] = useState(article?.sale_price ?? "");
  const [minStock, setMinStock] = useState(article?.min_stock ?? "0");
  const [trackExpiry, setTrackExpiry] = useState(article?.track_expiry ?? false);
  const [expiryAlertDays, setExpiryAlertDays] = useState(String(article?.expiry_alert_days ?? 30));
  const [packagings, setPackagings] = useState<PackagingRow[]>(
    (article?.packagings ?? []).map((item) => ({
      key: item.id,
      id: item.id,
      name: item.name,
      quantity: item.quantity,
    })),
  );
  const [photo, setPhoto] = useState<PhotoState>({ file: null, removed: false });

  const [messages, setMessages] = useState<FieldMessages>({});
  const [rowErrors, setRowErrors] = useState<PackagingRowErrors>({});
  const [packagingsError, setPackagingsError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const unitList = units.data?.data ?? [];
  const selectedUnit = unitList.find((unit) => unit.id === unitId) ?? null;

  const unitOptions = unitList
    .filter((unit) => unit.is_active || unit.id === article?.unit.id)
    .map((unit) => ({
      value: unit.id,
      label: `${unit.name} (${unit.symbol})${unit.is_active ? "" : " — désactivée"}`,
    }));

  const categoryList = categories.data?.data ?? [];
  const categoryNames = new Map(categoryList.map((item) => [item.id, item.name]));
  const categoryOptions = [
    { value: NONE, label: "Aucune catégorie" },
    ...categoryList
      .filter((item) => item.is_active || item.id === article?.category?.id)
      .map((item) => ({
        value: item.id,
        label: item.parent_id ? `${categoryNames.get(item.parent_id) ?? ""} › ${item.name}` : item.name,
      })),
  ];

  function clearMessage(field: FieldName) {
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  function applyError(error: unknown) {
    if (error instanceof ApiError && error.code === "validation_error") {
      const top = pickFieldMessages(error.fieldErrors, FIELDS);
      const rows: PackagingRowErrors = {};

      for (const [key, list] of Object.entries(error.fieldErrors)) {
        const match = /^packagings\.(\d+)\.(name|quantity|id)$/.exec(key);

        if (match && list[0]) {
          const index = Number(match[1]);
          const field = match[2] === "quantity" ? "quantity" : "name";

          rows[index] = { ...rows[index], [field]: list[0] };
        }
      }

      const general = error.fieldErrors.packagings?.[0];

      if (Object.keys(top).length > 0 || Object.keys(rows).length > 0 || general) {
        setMessages(top);
        setRowErrors(rows);
        setPackagingsError(general);
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

  async function syncPhoto(id: string): Promise<string | null> {
    try {
      if (photo.file) {
        await mutations.uploadImage.mutateAsync({ id, file: photo.file });
      } else if (photo.removed && article?.image_url) {
        await mutations.removeImage.mutateAsync(id);
      }

      return null;
    } catch (error) {
      return error instanceof ApiError ? error.message : "Une erreur inattendue est survenue.";
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setMessages({});
    setRowErrors({});
    setPackagingsError(undefined);

    const input: ArticleInput = {
      name,
      description: emptyToNull(description),
      barcode: emptyToNull(barcode),
      category_id: categoryId === NONE ? null : categoryId,
      unit_id: unitId,
      sale_price: salePrice,
      min_stock: minStock.trim() === "" ? "0" : minStock,
      track_expiry: trackExpiry,
      expiry_alert_days: /^\d+$/.test(expiryAlertDays.trim())
        ? Number(expiryAlertDays.trim())
        : expiryAlertDays,
      packagings: packagings.map((row) => ({ id: row.id, name: row.name, quantity: row.quantity })),
    };

    try {
      const saved = article
        ? await mutations.update.mutateAsync({ id: article.id, input })
        : await mutations.create.mutateAsync(input);

      const photoProblem = await syncPhoto(saved.id);

      if (photoProblem) {
        toast.error(
          `Article enregistré, mais la photo n'a pas pu être envoyée : ${photoProblem} Vous pourrez la rajouter en modifiant l'article.`,
        );
      } else {
        toast.success(article ? "Article modifié." : "Article créé.");
      }

      router.push(`/articles/${saved.id}`);
    } catch (error) {
      applyError(error);
      setSubmitting(false);
    }
  }

  const stockSuffix = selectedUnit?.symbol ?? "";

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={handleSubmit}
      aria-busy={submitting || undefined}
      className="space-y-6"
    >
      <Section title="Informations">
        <PhotoField
          name={name}
          currentUrl={article?.image_url ?? null}
          state={photo}
          onChange={setPhoto}
        />

        <Field label="Nom de l'article" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Riz parfumé 25 kg"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              clearMessage("name");
            }}
          />
        </Field>

        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="Catégorie" optional error={messages.category_id}>
            <Combobox
              size="lg"
              options={categoryOptions}
              value={categoryId}
              onValueChange={(value) => {
                setCategoryId(value);
                clearMessage("category_id");
              }}
              searchPlaceholder="Rechercher une catégorie"
              emptyMessage="Aucune catégorie ne correspond."
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
                clearMessage("unit_id");
              }}
              placeholder="Choisir une unité"
            />
          </Field>
        </div>

        <Field label="Description" optional error={messages.description}>
          <Textarea
            name="description"
            placeholder="Marque, format, particularités…"
            value={description}
            onChange={(event) => {
              setDescription(event.target.value);
              clearMessage("description");
            }}
          />
        </Field>

        <Field
          label="Code-barres"
          optional
          error={messages.barcode}
          hint="Celui imprimé sur l'emballage. Le code interne (ART-…) est créé automatiquement."
        >
          <Input
            size="lg"
            name="barcode"
            autoComplete="off"
            placeholder="6001234567890"
            value={barcode}
            onChange={(event) => {
              setBarcode(event.target.value);
              clearMessage("barcode");
            }}
          />
        </Field>
      </Section>

      <Section
        title="Prix et stock"
        description="Le prix d'achat et la date d'expiration se saisissent à chaque entrée de stock, car ils changent d'une livraison à l'autre."
      >
        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="Prix de vente" error={messages.sale_price}>
            <AffixInput
              size="lg"
              name="sale_price"
              inputMode="decimal"
              autoComplete="off"
              placeholder="500"
              suffix={tenant.currency}
              value={salePrice}
              onChange={(event) => {
                setSalePrice(event.target.value);
                clearMessage("sale_price");
              }}
            />
          </Field>

          <Field
            label="Seuil d'alerte de stock"
            error={messages.min_stock}
            hint="Vous serez alerté quand le stock descend à ce niveau."
          >
            <AffixInput
              size="lg"
              name="min_stock"
              inputMode="decimal"
              autoComplete="off"
              placeholder="0"
              suffix={stockSuffix}
              value={minStock}
              onChange={(event) => {
                setMinStock(event.target.value);
                clearMessage("min_stock");
              }}
            />
          </Field>
        </div>

        <div className="space-y-4">
          <Checkbox
            name="track_expiry"
            label="Ce produit a une date d'expiration"
            checked={trackExpiry}
            onChange={(event) => setTrackExpiry(event.target.checked)}
          />

          {trackExpiry ? (
            <Field
              label="Alerter avant l'expiration"
              error={messages.expiry_alert_days}
              hint="Un lot qui expire dans ce délai apparaîtra dans les alertes."
              className="sm:max-w-xs"
            >
              <AffixInput
                size="lg"
                name="expiry_alert_days"
                inputMode="numeric"
                autoComplete="off"
                suffix="jours"
                value={expiryAlertDays}
                onChange={(event) => {
                  setExpiryAlertDays(event.target.value);
                  clearMessage("expiry_alert_days");
                }}
              />
            </Field>
          ) : null}
        </div>
      </Section>

      <Section
        title="Conditionnements"
        description="Pour acheter ou vendre dans plusieurs formats : le stock reste compté dans l'unité de l'article, la conversion est automatique."
      >
        <PackagingsField
          rows={packagings}
          onChange={setPackagings}
          unitSymbol={selectedUnit?.symbol ?? null}
          errors={rowErrors}
          generalError={packagingsError}
        />
      </Section>

      {formError ? (
        <Alert variant="danger" title="Enregistrement impossible">
          {formError}
        </Alert>
      ) : null}

      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <Button
          type="button"
          variant="secondary"
          size="lg"
          disabled={submitting}
          onClick={() => router.push(article ? `/articles/${article.id}` : "/articles")}
        >
          Annuler
        </Button>
        <Button type="submit" size="lg" loading={submitting}>
          {article ? "Enregistrer les modifications" : "Créer l'article"}
        </Button>
      </div>
    </form>
  );
}