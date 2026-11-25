import {
  identifierLines,
  legalMentionLines,
  letterheadLines,
  receiptFooter,
  type LetterheadSource,
} from "@/lib/company-lines";

type LetterheadPreviewProps = {
  source: LetterheadSource;
  logoUrl: string | null;
};

/**
 * Aperçu miniature d'un reçu A4 : l'en-tête et le pied de page, alimentés par le profil.
 * La feuille reste blanche en thème sombre, comme à l'impression.
 */
export function LetterheadPreview({ source, logoUrl }: LetterheadPreviewProps) {
  const lines = letterheadLines(source);
  const identifiers = identifierLines(source);
  const mentions = legalMentionLines(source);
  const hasLegal = identifiers.length > 0 || mentions.length > 0;
  const name = source.name.trim();

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-foreground">Aperçu sur vos reçus</p>

      <div className="rounded-lg border bg-white p-5 text-[#0f172a] shadow-card">
        <div className="flex items-start gap-3 border-b-2 border-[#0f172a] pb-4">
          {logoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt="" className="h-11 max-w-[5.5rem] shrink-0 object-contain" />
          ) : null}

          <div className="min-w-0 flex-1">
            <p className="break-words font-display text-[15px] font-semibold leading-tight tracking-heading">
              {name || "Nom de l'entreprise"}
            </p>
            {lines.map((line) => (
              <p key={line} className="mt-1 break-words text-[10px] leading-snug text-[#64748b]">
                {line}
              </p>
            ))}
          </div>

          <div className="shrink-0 text-right">
            <p className="text-[8px] font-medium uppercase tracking-[0.12em] text-[#64748b]">Reçu n°</p>
            <p className="mt-0.5 text-[11px] font-semibold tabular-nums">VTE-000123</p>
          </div>
        </div>

        <div aria-hidden className="space-y-2 py-4">
          <div className="h-2 w-full rounded-sm bg-[#f1f5f9]" />
          <div className="h-2 w-5/6 rounded-sm bg-[#f1f5f9]" />
          <div className="h-2 w-2/3 rounded-sm bg-[#f1f5f9]" />
        </div>

        <div className="space-y-1.5 border-t border-[#e2e8f0] pt-3 text-center">
          <p className="text-[10px] font-medium">{receiptFooter(source)}</p>

          {hasLegal ? (
            <div className="space-y-0.5 text-[8.5px] leading-snug text-[#64748b]">
              {identifiers.length > 0 ? <p>{identifiers.join("  ·  ")}</p> : null}
              {mentions.map((line, index) => (
                <p key={`${index}-${line}`}>{line}</p>
              ))}
            </div>
          ) : null}
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        Les champs vides ne sont pas imprimés. L&apos;aperçu se met à jour pendant la saisie.
      </p>
    </div>
  );
}