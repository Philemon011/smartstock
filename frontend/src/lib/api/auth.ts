import { apiRequest } from "./client";

export type RegisterPayload = {
  company_name: string;
  country: string;
  currency: string;
  name: string;
  email: string;
  password: string;
};

export type MessageResponse = {
  message: string;
};

export function register(
  payload: RegisterPayload,
  signal?: AbortSignal,
): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/register", {
    method: "POST",
    body: payload,
    signal,
  });
}

export function resendVerificationEmail(
  email: string,
  signal?: AbortSignal,
): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/email/resend", {
    method: "POST",
    body: { email },
    signal,
  });
}