import type { z } from "zod";
import type { clinicDateSchema } from "@/validations/clinic-dates";

import { api } from "@/services/api";

export type ClinicDateFormValues = z.infer<typeof clinicDateSchema>;
export type ClinicDateStatus = "scheduled" | "completed" | "cancelled";
export type Slots = {
  start_time: string;
  end_time: string;
  available_slots: number;
};

export type ClinicDateHospitalOption = {
  id: number;
  name: string;
  identifier?: string;
  district?: string;
};

export type ClinicDateClinicOption = {
  id: number;
  name: string;
  location?: string;
  hospital_id: number;
  doctor?: { id: number; name: string; email?: string };
};

export type ClinicDate = ClinicDateFormValues & {
  id: number;
  status: ClinicDateStatus;
  clinic?: ClinicDateClinicOption & {
    hospital?: ClinicDateHospitalOption;
  };
  created_at?: string;
  updated_at?: string;
};

export type ClinicDateListParams = {
  pageSize: number;
  currentPage: number;
  search?: string;
  clinic_id?: number;
  status?: ClinicDateStatus;
  future_only?: boolean;
};

export type UpdateClinicDateInput = ClinicDateFormValues & { id: number };

export const clinicDatesServices = {
  getClinicDates: async (params: ClinicDateListParams) => {
    const { data } = await api.get("/clinic-dates", {
      params: {
        page: params.currentPage,
        size: params.pageSize,
        search: params.search || undefined,
        clinic_id: params.clinic_id,
        status: params.status,
        future_only: params.future_only,
      },
    });
    return {
      clinicDates: data.data as ClinicDate[],
      total: data.total as number,
      from: data.from as number,
      to: data.to as number,
      endPage: data.last_page as number,
    };
  },

  getClinicDateById: async (id: number) => {
    const { data } = await api.get(`/clinic-dates/${id}`);
    return data as ClinicDate;
  },

  getClinicDatesByClinic: async (clinicId: number) => {
    const { data } = await api.get(`/clinic-dates/clinic/${clinicId}`);
    return data as ClinicDate[];
  },

  createClinicDate: async (clinicDate: ClinicDateFormValues) => {
    const { data } = await api.post("/clinic-dates", clinicDate);
    return data as ClinicDate;
  },

  updateClinicDate: async ({ id, ...clinicDate }: UpdateClinicDateInput) => {
    const { data } = await api.put(`/clinic-dates/${id}`, clinicDate);
    return data as ClinicDate;
  },

  updateClinicDateStatus: async (param: {
    id: number;
    status: ClinicDateStatus;
  }) => {
    const { data } = await api.patch(`/clinic-dates/${param.id}/status`, {
      status: param.status,
    });
    return data as ClinicDate;
  },

  getHospitalOptions: async (search: string, signal?: AbortSignal) => {
    const { data } = await api.get("/hospitals", {
      params: { page: 1, size: 20, search: search || undefined },
      signal,
    });
    return data.data as ClinicDateHospitalOption[];
  },

  getHospitalOption: async (hospitalId: number, signal?: AbortSignal) => {
    const { data } = await api.get(`/hospitals/single/${hospitalId}`, { signal });
    return data as ClinicDateHospitalOption;
  },

  getClinicOptions: async (hospitalId: number, signal?: AbortSignal) => {
    const { data } = await api.get(`/clinics/hospital/${hospitalId}`, { signal });
    return data as ClinicDateClinicOption[];
  },
};
