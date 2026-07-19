import type {
  CreateClinicInput,
  UpdateClinicInput,
} from "@/services/clinics";

import { clinicsServices } from "@/services/clinics";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";

export const useClinics = (data: {
  pageSize: number;
  currentPage: number;
  search?: string;
  hospitalId?: number;
}) =>
  useQuery({
    queryKey: ["clinics", data],
    queryFn: () => clinicsServices.getClinics(data),
    placeholderData: keepPreviousData,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicById = (id: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic", id],
    queryFn: () => clinicsServices.getClinicById(id),
    enabled: enabled && id > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useCreateClinic = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateClinicInput) => clinicsServices.createClinic(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["clinics"] }),
  });
};

export const useUpdateClinic = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: UpdateClinicInput) => clinicsServices.updateClinic(data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["clinics"] }),
  });
};
