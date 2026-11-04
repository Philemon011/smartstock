export type Decimal = { value: bigint; scale: number };

const pow10 = (exponent: number): bigint => BigInt(`1${"0".repeat(exponent)}`);

/** Lit « 12 500,5 » ou « 12500.5 ». Renvoie null si ce n'est pas un nombre positif valide. */
export function parseDecimal(input: string): Decimal | null {
  const text = input.trim().replace(/\s/g, "").replace(",", ".");

  if (!/^\d+(\.\d+)?$/.test(text)) return null;

  const [integer, fraction = ""] = text.split(".");

  return { value: BigInt(integer + fraction), scale: fraction.length };
}

export function multiply(a: Decimal, b: Decimal): Decimal {
  return { value: a.value * b.value, scale: a.scale + b.scale };
}

export function add(a: Decimal, b: Decimal): Decimal {
  const scale = Math.max(a.scale, b.scale);

  return {
    value: a.value * pow10(scale - a.scale) + b.value * pow10(scale - b.scale),
    scale,
  };
}

/** Arrondi « demi vers le haut » (celui du serveur). */
export function round(a: Decimal, scale: number): Decimal {
  if (a.scale <= scale) {
    return { value: a.value * pow10(scale - a.scale), scale };
  }

  const divisor = pow10(a.scale - scale);

  return { value: (a.value + divisor / BigInt(2)) / divisor, scale };
}

export function divide(a: Decimal, b: Decimal, scale: number): Decimal | null {
  if (b.value === BigInt(0)) return null;

  const numerator = a.value * pow10(b.scale + scale);
  const denominator = b.value * pow10(a.scale);

  return { value: (numerator * BigInt(2) + denominator) / (denominator * BigInt(2)), scale };
}

export function decimalToString(a: Decimal, trim = false): string {
  const digits = a.value.toString().padStart(a.scale + 1, "0");
  const integer = digits.slice(0, digits.length - a.scale);
  const fraction = a.scale > 0 ? digits.slice(digits.length - a.scale) : "";
  const full = fraction ? `${integer}.${fraction}` : integer;

  return trim && fraction ? full.replace(/0+$/, "").replace(/\.$/, "") : full;
}

export const ZERO: Decimal = { value: BigInt(0), scale: 0 };