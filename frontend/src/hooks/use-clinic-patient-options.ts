import { clinicPatientsServices } from "@/services/clinic-patients";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";

export const useDebouncedValue = <T,>(value: T, delay = 300) => {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedValue(value), delay);
    return () => window.clearTimeout(timeout);
  }, [delay, value]);

  return debouncedValue;
};

export const useClinicPatientHospitalOptions = (
  search: string,
  enabled = true,
) =>
  useQuery({
    queryKey: ["clinic-patient-hospital-options", search],
    queryFn: ({ signal }) =>
      clinicPatientsServices.getHospitalOptions(search, signal),
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicPatientHospitalOption = (
  hospitalId: number,
  enabled = true,
) =>
  useQuery({
    queryKey: ["clinic-patient-hospital-option", hospitalId],
    queryFn: ({ signal }) =>
      clinicPatientsServices.getHospitalOption(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicPatientClinicOptions = (
  hospitalId: number,
  enabled = true,
) =>
  useQuery({
    queryKey: ["clinic-patient-clinic-options", hospitalId],
    queryFn: ({ signal }) =>
      clinicPatientsServices.getClinicOptions(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicPatientPatientOptions = (
  search: string,
  enabled = true,
) =>
  useQuery({
    queryKey: ["clinic-patient-patient-options", search],
    queryFn: ({ signal }) =>
      clinicPatientsServices.getPatientOptions(search, signal),
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });
