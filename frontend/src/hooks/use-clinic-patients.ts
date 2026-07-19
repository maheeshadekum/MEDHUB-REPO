import { clinicPatientsServices } from "@/services/clinic-patients";
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";

// Get clinic patients with pagination and filters
export const useClinicPatients = (data: {
  pageSize: number;
  currentPage: number;
  search?: string;
  clinic_id?: number;
}) =>
  useQuery({
    queryKey: ["clinic-patients", data],
    queryFn: () => clinicPatientsServices.getClinicPatients(data),
    placeholderData: keepPreviousData,
    retry: false,
    refetchOnWindowFocus: false,
  });

// Get single clinic patient
export const useClinicPatient = (id: number) => {
  return useQuery({
    queryKey: ["clinic-patient", id],
    queryFn: async () => {
      try {
        const clinicPatient =
          await clinicPatientsServices.getClinicPatientById(id);
        return clinicPatient;
      } catch {
        return null;
      }
    },
    retry: false,
    refetchOnWindowFocus: false,
  });
};

// Create clinic patient
export const useCreateClinicPatient = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: clinicPatientsServices.createClinicPatient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["clinic-patients"] });
    },
    onError: (error) => {
      return error;
    },
  });
};

// Update clinic patient
export const useUpdateClinicPatient = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: clinicPatientsServices.updateClinicPatient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["clinic-patients"] });
      queryClient.invalidateQueries({ queryKey: ["clinic-patient"] });
    },
    onError: (error) => {
      return error;
    },
  });
};

// Delete clinic patient
export const useDeleteClinicPatient = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: clinicPatientsServices.deleteClinicPatient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["clinic-patients"] });
    },
    onError: (error) => {
      return error;
    },
  });
};
