import { clinicsServices } from "@/services/clinics";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";

export const useClinicDebouncedValue = <T,>(value: T, delay = 300) => {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedValue(value), delay);
    return () => window.clearTimeout(timeout);
  }, [delay, value]);

  return debouncedValue;
};

export const useClinicHospitalOptions = (search: string, enabled = true) =>
  useQuery({
    queryKey: ["clinic-management-hospitals", search],
    queryFn: ({ signal }) => clinicsServices.getHospitalOptions(search, signal),
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicHospitalOption = (hospitalId: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic-management-hospital", hospitalId],
    queryFn: ({ signal }) => clinicsServices.getHospitalOption(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicDoctorOptions = (hospitalId: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic-management-doctors", hospitalId],
    queryFn: ({ signal }) => clinicsServices.getDoctorOptions(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });
