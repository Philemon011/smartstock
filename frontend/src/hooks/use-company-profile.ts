"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useActiveMembership, useSession } from "@/components/session/session-provider";
import {
  getCompanyProfile,
  removeCompanyLogo,
  updateCompanyProfile,
  uploadCompanyLogo,
  type CompanyProfile,
  type CompanyProfileInput,
} from "@/lib/api/company-profile";

export function useCompanyProfile() {
  const { tenant } = useActiveMembership();

  return useQuery({
    queryKey: ["company-profile", tenant.id],
    queryFn: ({ signal }) => getCompanyProfile(signal),
    // Le lien du logo est valable plusieurs heures : on recharge bien avant qu'il n'expire.
    staleTime: 10 * 60_000,
  });
}

export function useCompanyProfileMutations() {
  const { tenant } = useActiveMembership();
  const { refresh } = useSession();
  const queryClient = useQueryClient();

  const store = (profile: CompanyProfile) => queryClient.setQueryData(["company-profile", tenant.id], profile);

  return {
    update: useMutation({
      mutationFn: (input: CompanyProfileInput) => updateCompanyProfile(input),
      onSuccess: async (profile) => {
        store(profile);
        // Le nom de l'entreprise s'affiche dans l'en-tête et le menu.
        await refresh();
      },
    }),
    uploadLogo: useMutation({
      mutationFn: (file: File) => uploadCompanyLogo(file),
      onSuccess: store,
    }),
    removeLogo: useMutation({
      mutationFn: () => removeCompanyLogo(),
      onSuccess: store,
    }),
  };
}