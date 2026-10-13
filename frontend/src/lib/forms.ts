import type { FieldErrors } from "@/lib/api/client";

export function pickFieldMessages<K extends string>(
  fieldErrors: FieldErrors,
  keys: readonly K[],
): Partial<Record<K, string>> {
  const messages: Partial<Record<K, string>> = {};

  for (const key of keys) {
    const first = fieldErrors[key]?.[0];

    if (first) {
      messages[key] = first;
    }
  }

  return messages;
}