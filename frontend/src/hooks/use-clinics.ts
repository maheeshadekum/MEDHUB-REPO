import type { Clinic } from "@/services/clinics";

import { clinicsServices } from "@/services/clinics";
import { hospitalsServices } from "@/services/hospitals";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

// Get clinics with pagination
export const useClinics = (data: {
  pageSize: number;
  currentPage: number;
  search?: string;
}, enabled = true) =>
  useQuery({
    queryKey: ["clinics", data],
    queryFn: async () => {
      try {
        const clinics = await clinicsServices.getClinics(data);
        return clinics;
      } catch {
        return {
          clinics: [],
          total: 0,
          from: 0,
          to: 0,
          endPage: 0,
        };
      }
    },
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });

// Get clinic by id
export const useClinicById = (id: number) =>
  useQuery({
    queryKey: ["clinic", id],
    queryFn: async () => {
      try {
        const clinic = await clinicsServices.getClinicById(id);
        return clinic;
      } catch {
        return null;
      }
    },
    retry: false,
    refetchOnWindowFocus: false,
  });

// Get clinics belonging to the selected or assigned hospital
export const useClinicsByHospital = (hospitalId: number, enabled: boolean) =>
  useQuery({
    queryKey: ["clinics-by-hospital", hospitalId],
    queryFn: () => clinicsServices.getClinicsByHospital(hospitalId),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

// Get hospitals for the Super Admin clinic selector
export const useClinicHospitals = (search: string, enabled: boolean) =>
  useQuery({
    queryKey: ["clinic-hospitals", search],
    queryFn: () =>
      hospitalsServices.getHospitals({
        currentPage: 1,
        pageSize: 100,
        search,
      }),
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });

// Get working doctors assigned to the selected hospital
export const useAvailableClinicDoctors = (
  hospitalId: number,
  search: string,
  enabled: boolean,
) =>
  useQuery({
    queryKey: ["clinic-doctors", hospitalId, search],
    queryFn: () => clinicsServices.getAvailableDoctors(hospitalId, search),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

// Create clinic
export const useCreateClinic = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: Clinic) => clinicsServices.createClinic(data),
    onSettled: () => {
      queryClient.refetchQueries({ queryKey: ["clinics"] });
    },
    onError: (error) => {
      return error;
    },
  });
};

// Update clinic
export const useUpdateClinic = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: Clinic) => clinicsServices.updateClinic(data),
    onSettled: () => {
      queryClient.refetchQueries({ queryKey: ["clinics"] });
    },
    onError: (error) => {
      return error;
    },
  });
};

// Delete clinic
export const useDeleteClinic = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => clinicsServices.deleteClinic(id),
    onSettled: () => {
      queryClient.refetchQueries({ queryKey: ["clinics"] });
    },
    onError: (error) => {
      return error;
    },
  });
};
