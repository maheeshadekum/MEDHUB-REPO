import { clinicDatesServices } from "@/services/clinic-dates";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";

export const useClinicDateDebouncedValue = <T,>(value: T, delay = 300) => {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedValue(value), delay);
    return () => window.clearTimeout(timeout);
  }, [delay, value]);

  return debouncedValue;
};

export const useClinicDateHospitalOptions = (search: string, enabled = true) =>
  useQuery({
    queryKey: ["clinic-date-hospital-options", search],
    queryFn: ({ signal }) => clinicDatesServices.getHospitalOptions(search, signal),
    enabled,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicDateHospitalOption = (hospitalId: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic-date-hospital-option", hospitalId],
    queryFn: ({ signal }) => clinicDatesServices.getHospitalOption(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });

export const useClinicDateClinicOptions = (hospitalId: number, enabled = true) =>
  useQuery({
    queryKey: ["clinic-date-clinic-options", hospitalId],
    queryFn: ({ signal }) => clinicDatesServices.getClinicOptions(hospitalId, signal),
    enabled: enabled && hospitalId > 0,
    retry: false,
    refetchOnWindowFocus: false,
  });
