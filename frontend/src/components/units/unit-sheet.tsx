"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useUnitMutations } from "@/hooks/use-units";
import { ApiError } from "@/lib/api/client";
import type { Unit } from "@/lib/api/units";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "symbol", "allows_decimals"] as const;

type FieldName = (typeof FIELDS)[number];
type FieldMessages = Partial<Record<FieldName, string>>;

type UnitSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  unit: Unit | null;
};

function UnitForm({ unit, onClose }: { unit: Unit | null; onClose: () => void }) {
  const mutations = useUnitMutations();
  const formRef = useRef<HTMLFormElement>(null);

  const [name, setName] = useState(unit?.name ?? "");
  const [symbol, setSymbol] = useState(unit?.symbol ?? "");
  const [allowsDecimals, setAllowsDecimals] = useState(unit?.allows_decimals ?? false);
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  const pending = mutations.create.isPending || mutations.update.isPending;

  function clearMessage(field: FieldName) {
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (pending) return;

    setFormError(null);
    setMessages({});

    const input = { name, symbol, allows_decimals: allowsDecimals };

    try {
      if (unit) {
        await mutations.update.mutateAsync({ id: unit.id, input });
        toast.success("Unité modifiée.");
      } else {
        await mutations.create.mutateAsync(input);
        toast.success("Unité créée.");
      }

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
      aria-busy={pending || undefined}
      className="flex min-h-0 flex-1 flex-col"
    >
      <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
        <Field label="Nom de l'unité" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Kilogramme"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              clearMessage("name");
            }}
          />
        </Field>

        <Field
          label="Symbole"
          error={messages.symbol}
          hint="Ce qui s'affiche à côté des quantités, par exemple kg."
        >
          <Input
            size="lg"
            name="symbol"
            autoComplete="off"
            placeholder="kg"
            maxLength={10}
            value={symbol}
            onChange={(event) => {
              setSymbol(event.target.value);
              clearMessage("symbol");
            }}
          />
        </Field>

        <div className="space-y-2">
          <Checkbox
            name="allows_decimals"
            label="Accepte les décimales"
            checked={allowsDecimals}
            onChange={(event) => {
              setAllowsDecimals(event.target.checked);
              clearMessage("allows_decimals");
            }}
          />
          <p className="text-xs text-muted-foreground">
            Cochez pour ce qui se mesure (kg, litre, mètre) : 2,5 est possible. Laissez décoché
            pour ce qui se compte (sac, carton, pièce).
          </p>
          {messages.allows_decimals ? (
            <p className="text-sm text-danger">{messages.allows_decimals}</p>
          ) : null}
        </div>

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
        <Button type="submit" size="lg" className="flex-1" loading={pending}>
          {unit ? "Enregistrer" : "Créer l'unité"}
        </Button>
      </div>
    </form>
  );
}

export function UnitSheet({ open, onOpenChange, unit }: UnitSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title={unit ? "Modifier l'unité" : "Nouvelle unité"}
        description="L'unité dans laquelle vous comptez, entrez et vendez un produit."
      >
        <UnitForm unit={unit} onClose={() => onOpenChange(false)} />
      </SheetContent>
    </Sheet>
  );
}