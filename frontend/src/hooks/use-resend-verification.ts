"use client";

import { resendVerificationEmail } from "@/lib/api/auth";
import { useEmailAction } from "./use-email-action";

export type { EmailActionFeedback as ResendFeedback } from "./use-email-action";

export function useResendVerification(initialCooldown = 0) {
  return useEmailAction(resendVerificationEmail, initialCooldown);
}