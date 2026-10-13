import type { Me, Membership } from "@/lib/api/auth";

export function currentMembership(me: Me): Membership | null {
  return me.memberships.find((membership) => membership.status === "active") ?? null;
}

export function firstName(fullName: string): string {
  return fullName.trim().split(/\s+/)[0] ?? fullName;
}

export function initials(fullName: string): string {
  const parts = fullName.trim().split(/\s+/).filter(Boolean);
  const first = parts[0]?.charAt(0) ?? "";
  const last = parts.length > 1 ? (parts.at(-1)?.charAt(0) ?? "") : "";

  return (first + last).toUpperCase() || "?";
}

export function pluralize(count: number, singular: string, plural: string): string {
  return `${count} ${count > 1 ? plural : singular}`;
}

export function regionName(code: string): string {
  try {
    return new Intl.DisplayNames(["fr"], { type: "region" }).of(code) ?? code;
  } catch {
    return code;
  }
}

export function currencyName(code: string): string {
  try {
    const name = new Intl.DisplayNames(["fr"], { type: "currency" }).of(code);

    return name ? `${name.charAt(0).toUpperCase()}${name.slice(1)} (${code})` : code;
  } catch {
    return code;
  }
}