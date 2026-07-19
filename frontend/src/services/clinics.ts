import type { clinicSchema } from "@/validations/clinics";
import type { z } from "zod";

import { api } from "@/services/api";

export type ClinicFormValues = z.infer<typeof clinicSchema>;

export type ClinicHospital = {
  id: number;
  name: string;
  identifier?: string;
  district?: string;
};

export type ClinicDoctor = {
  id: number;
  name: string;
  email: string;
};

export type Clinic = ClinicFormValues & {
  id?: number;
  hospital_id: number;
  hospital?: ClinicHospital;
  doctor?: ClinicDoctor;
  created_at?: string;
  updated_at?: string;
};

export type CreateClinicInput = ClinicFormValues & { hospital_id?: number };
export type UpdateClinicInput = ClinicFormValues & { id: number; hospital_id?: number };

export const clinicsServices = {
  getClinics: async (params: {
    pageSize: number;
    currentPage: number;
    search?: string;
    hospitalId?: number;
  }) => {
    const { data } = await api.get("/clinics", {
      params: {
        page: params.currentPage,
        size: params.pageSize,
        search: params.search || undefined,
        hospital_id: params.hospitalId || undefined,
      },
    });

    return {
      clinics: data.data as Clinic[],
      total: data.total as number,
      from: data.from as number,
      to: data.to as number,
      endPage: data.last_page as number,
    };
  },

  getClinicById: async (id: number) => {
    const { data } = await api.get(`/clinics/${id}`);
    return data as Clinic;
  },

  createClinic: async (clinic: CreateClinicInput) => {
    const { data } = await api.post("/clinics", clinic);
    return data as Clinic;
  },

  updateClinic: async (clinic: UpdateClinicInput) => {
    const { id, ...payload } = clinic;
    const { data } = await api.put(`/clinics/${id}`, payload);
    return data as Clinic;
  },

  getHospitalOptions: async (search: string, signal?: AbortSignal) => {
    const { data } = await api.get("/hospitals", {
      params: { page: 1, size: 20, search: search || undefined },
      signal,
    });
    return data.data as ClinicHospital[];
  },

  getHospitalOption: async (hospitalId: number, signal?: AbortSignal) => {
    const { data } = await api.get(`/hospitals/single/${hospitalId}`, { signal });
    return data as ClinicHospital;
  },

  getDoctorOptions: async (hospitalId: number, signal?: AbortSignal) => {
    const { data } = await api.get(`/hospitals/${hospitalId}/doctors`, { signal });
    return data as ClinicDoctor[];
  },
};
