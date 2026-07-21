import type {
  ClinicDateFormValues,
  ClinicDateListParams,
  ClinicDateStatus,
  UpdateClinicDateInput,
} from "@/services/clinic-dates";

import { clinicDatesServices } from "@/services/clinic-dates";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

export const useClinicDates = (params: ClinicDateListParams) =>
  useQuery({
    queryKey: ["clinic-dates", params],
    queryFn: () => clinicDatesServices.getClinicDates(params),
    placeholderData: keepPreviousData,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicDateById = (id: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic-date", id],
    queryFn: () => clinicDatesServices.getClinicDateById(id),
    enabled: enabled && id > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicDatesByClinic = (clinicId: number) =>
  useQuery({
    queryKey: ["clinic-dates-by-clinic", clinicId],
    queryFn: () => clinicDatesServices.getClinicDatesByClinic(clinicId),
    enabled: clinicId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

const useRefreshClinicDates = () => {
  const queryClient = useQueryClient();
  return () => {
    void queryClient.invalidateQueries({ queryKey: ["clinic-dates"] });
    void queryClient.invalidateQueries({ queryKey: ["clinic-dates-by-clinic"] });
  };
};

export const useCreateClinicDate = () => {
  const refresh = useRefreshClinicDates();
  return useMutation({
    mutationFn: (data: ClinicDateFormValues) =>
      clinicDatesServices.createClinicDate(data),
    onSuccess: refresh,
  });
};

export const useUpdateClinicDate = () => {
  const refresh = useRefreshClinicDates();
  return useMutation({
    mutationFn: (data: UpdateClinicDateInput) =>
      clinicDatesServices.updateClinicDate(data),
    onSuccess: refresh,
  });
};

export const useUpdateClinicDateStatus = () => {
  const refresh = useRefreshClinicDates();
  return useMutation({
    mutationFn: (data: { id: number; status: ClinicDateStatus }) =>
      clinicDatesServices.updateClinicDateStatus(data),
    onSuccess: refresh,
  });
};
