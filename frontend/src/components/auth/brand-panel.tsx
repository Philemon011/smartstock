import { Check } from "lucide-react";

const benefits = [
  "Stock et valorisation toujours exacts",
  "Équipe, rôles et entrepôts multiples",
  "Données isolées et sécurisées",
];

export function BrandPanel() {
  return (
    <div className="relative flex h-full flex-col justify-between overflow-hidden rounded-xl bg-brand-panel p-12 text-white xl:p-14">
      <div
        aria-hidden
        className="pointer-events-none absolute -right-24 -top-24 size-96 rounded-full bg-white/[0.06]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-32 -left-20 size-[28rem] rounded-full bg-white/[0.05]"
      />

      <span className="relative inline-flex w-fit items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-brand-panel">
        Essai gratuit, sans carte bancaire
      </span>

      <div className="relative max-w-md space-y-5">
        <h2 className="text-4xl font-semibold leading-[1.1] tracking-display xl:text-[2.75rem]">
          Pilotez votre stock avec précision.
        </h2>
        <p className="text-base leading-relaxed text-white/75">
          Entrées, sorties, inventaires et valorisation : tout est calculé avec
          exactitude, pour toute votre équipe.
        </p>
      </div>

      <ul className="relative space-y-3.5">
        {benefits.map((benefit) => (
          <li key={benefit} className="flex items-center gap-3 text-sm text-white/90">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-white/15">
              <Check className="size-3" strokeWidth={2} aria-hidden />
            </span>
            {benefit}
          </li>
        ))}
      </ul>
    </div>
  );
}