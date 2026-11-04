"use client";

import { Plus, Trash2 } from "lucide-react";
import { AffixInput } from "@/components/ui/affix-input";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

export type PackagingRow = {
  key: string;
  id: string | null;
  name: string;
  quantity: string;
};

export type PackagingRowErrors = Record<number, { name?: string; quantity?: string }>;

const MAX_PACKAGINGS = 10;

type PackagingsFieldProps = {
  rows: PackagingRow[];
  onChange: (rows: PackagingRow[]) => void;
  unitSymbol: string | null;
  errors: PackagingRowErrors;
  generalError?: string;
};

export function PackagingsField({
  rows,
  onChange,
  unitSymbol,
  errors,
  generalError,
}: PackagingsFieldProps) {
  function update(key: string, patch: Partial<PackagingRow>) {
    onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));
  }

  return (
    <div className="space-y-4">
      {rows.length === 0 ? (
        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
          Aucun conditionnement. Ajoutez-en si vous achetez ou vendez ce produit dans d&apos;autres
          formats que son unité, par exemple « Sac = 25 kg » pour un produit compté en kilos.
        </p>
      ) : (
        <ul className="space-y-4">
          {rows.map((row, index) => (
            <li key={row.key} className="flex flex-col gap-3 sm:flex-row sm:items-start">
              <Field label="Nom" error={errors[index]?.name} className="sm:flex-1">
                <Input
                  size="lg"
                  autoComplete="off"
                  placeholder="Sac"
                  value={row.name}
                  onChange={(event) => update(row.key, { name: event.target.value })}
                />
              </Field>

              <Field
                label={unitSymbol ? `Contient (en ${unitSymbol})` : "Quantité contenue"}
                error={errors[index]?.quantity}
                className="sm:flex-1"
              >
                <AffixInput
                  size="lg"
                  inputMode="decimal"
                  autoComplete="off"
                  placeholder="25"
                  suffix={unitSymbol ?? ""}
                  value={row.quantity}
                  onChange={(event) => update(row.key, { quantity: event.target.value })}
                />
              </Field>

              <Button
                type="button"
                variant="ghost"
                size="lg"
                className="self-start px-3 sm:mt-[1.625rem]"
                aria-label={`Retirer le conditionnement ${row.name || index + 1}`}
                onClick={() => onChange(rows.filter((item) => item.key !== row.key))}
              >
                <Trash2 strokeWidth={1.5} aria-hidden />
              </Button>
            </li>
          ))}
        </ul>
      )}

      {generalError ? <p className="text-sm text-danger">{generalError}</p> : null}

      <Button
        type="button"
        variant="secondary"
        size="sm"
        disabled={rows.length >= MAX_PACKAGINGS}
        onClick={() =>
          onChange([...rows, { key: crypto.randomUUID(), id: null, name: "", quantity: "" }])
        }
      >
        <Plus strokeWidth={1.5} aria-hidden />
        Ajouter un conditionnement
      </Button>
    </div>
  );
}