import { apiRequest } from "./client";

export type RegisterPayload = {
  company_name: string;
  country: string;
  currency: string;
  name: string;
  email: string;
  password: string;
};

export type LoginPayload = {
  email: string;
  password: string;
  remember: boolean;
};

export type ResetPasswordPayload = {
  token: string;
  email: string;
  password: string;
};

export type MessageResponse = {
  message: string;
};

export type TenantStatus = "trial" | "active" | "expired" | "suspended";
export type MembershipRole = "owner" | "admin" | "stock_manager" | "seller" | "viewer";
export type MembershipStatus = "active" | "suspended";

export type TenantSummary = {
  id: string;
  name: string;
  country: string;
  currency: string;
  timezone: string;
  status: TenantStatus;
  trial_ends_at: string | null;
  trial_days_remaining: number | null;
    read_only_message: string | null;
};

export type Membership = {
  role: MembershipRole;
  role_label: string;
  status: MembershipStatus;
  tenant: TenantSummary;
};

export type SessionUser = {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
};

export type Me = {
  user: SessionUser;
  memberships: Membership[];
};

export function register(payload: RegisterPayload, signal?: AbortSignal): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/register", {
    method: "POST",
    body: payload,
    signal,
  });
}

export function resendVerificationEmail(email: string, signal?: AbortSignal): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/email/resend", {
    method: "POST",
    body: { email },
    signal,
  });
}

export function login(payload: LoginPayload, signal?: AbortSignal): Promise<Me> {
  return apiRequest<Me>("/api/v1/auth/login", {
    method: "POST",
    body: payload,
    signal,
  });
}

export function logout(signal?: AbortSignal): Promise<null> {
  return apiRequest<null>("/api/v1/auth/logout", { method: "POST", signal });
}

export function fetchMe(signal?: AbortSignal): Promise<Me> {
  return apiRequest<Me>("/api/v1/me", { signal });
}

export function forgotPassword(email: string, signal?: AbortSignal): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/forgot-password", {
    method: "POST",
    body: { email },
    signal,
  });
}

export function resetPassword(payload: ResetPasswordPayload, signal?: AbortSignal): Promise<MessageResponse> {
  return apiRequest<MessageResponse>("/api/v1/auth/reset-password", {
    method: "POST",
    body: payload,
    signal,
  });
}