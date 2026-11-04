"use client";

import { ImagePlus } from "lucide-react";
import { useEffect, useMemo, useRef } from "react";
import { ArticleThumb } from "@/components/articles/article-thumb";
import { Button } from "@/components/ui/button";

export type PhotoState = {
  file: File | null;
  removed: boolean;
};

type PhotoFieldProps = {
  name: string;
  currentUrl: string | null;
  state: PhotoState;
  onChange: (state: PhotoState) => void;
  error?: string;
};

export function PhotoField({ name, currentUrl, state, onChange, error }: PhotoFieldProps) {
  const inputRef = useRef<HTMLInputElement>(null);

  const preview = useMemo(() => (state.file ? URL.createObjectURL(state.file) : null), [state.file]);

  useEffect(() => {
    return () => {
      if (preview) URL.revokeObjectURL(preview);
    };
  }, [preview]);

  const shown = preview ?? (state.removed ? null : currentUrl);

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-foreground">
        Photo <span className="ml-1.5 font-normal text-subtle-foreground">Optionnel</span>
      </p>

      <div className="flex items-center gap-4">
        <ArticleThumb name={name || "Aperçu de la photo"} imageUrl={shown} className="size-20 rounded-lg" />

        <div className="space-y-2">
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="secondary" size="sm" onClick={() => inputRef.current?.click()}>
              <ImagePlus strokeWidth={1.5} aria-hidden />
              {shown ? "Changer la photo" : "Ajouter une photo"}
            </Button>

            {shown ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => onChange({ file: null, removed: true })}
              >
                Retirer
              </Button>
            ) : null}
          </div>

          <p className="text-xs text-muted-foreground">JPG, PNG ou WebP, 2 Mo maximum.</p>
        </div>
      </div>

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="sr-only"
        tabIndex={-1}
        aria-label="Choisir une photo"
        onChange={(event) => {
          const file = event.target.files?.[0];

          if (file) {
            onChange({ file, removed: false });
          }

          event.target.value = "";
        }}
      />

      {error ? <p className="text-sm text-danger">{error}</p> : null}
    </div>
  );
}