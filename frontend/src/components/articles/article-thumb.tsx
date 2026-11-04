import { Package } from "lucide-react";
import { cn } from "@/lib/utils";

type ArticleThumbProps = {
  name: string;
  imageUrl: string | null;
  className?: string;
};

/** Photo de l'article, ou une icône neutre s'il n'en a pas. */
export function ArticleThumb({ name, imageUrl, className }: ArticleThumbProps) {
  return (
    <span
      className={cn(
        "flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-accent text-muted-foreground",
        className,
      )}
    >
      {imageUrl ? (
        // Photo privée servie par un lien signé : next/image n'apporte rien ici.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={imageUrl} alt={name} className="size-full object-cover" loading="lazy" />
      ) : (
        <Package className="size-5" strokeWidth={1.5} aria-hidden />
      )}
    </span>
  );
}