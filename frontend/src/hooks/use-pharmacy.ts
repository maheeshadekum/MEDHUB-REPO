/* eslint-disable @typescript-eslint/no-unused-vars */
import type { Pharmacy } from "@/services/pharmacy";

import { pharmaciesServices } from "@/services/pharmacy";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

// get pharmacies
export const usePharmacies = (data: {
  pageSize: number;
  currentPage: number;
  search?: string;
  district?: string;
}) =>
  useQuery({
    queryKey: ["pharmacies", data],
    queryFn: () => pharmaciesServices.getPharmacies(data),
    retry: false,
    refetchOnWindowFocus: false,
  });

// get single pharmacy by id
export const usePharmacyById = (id: number) =>
  useQuery({
    queryKey: ["pharmacy", id],
    queryFn: async () => {
      try {
        const pharmacy = await pharmaciesServices.getPharmacyById(id);
        return pharmacy;
      } catch (error) {
        return null;
      }
    },
    retry: false,
    refetchOnWindowFocus: false,
  });

// create pharmacy
export const useCreatePharmacy = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: Pharmacy) =>
      pharmaciesServices.createPharmacy(data),
    onSettled: () => {
      queryClient.refetchQueries({
        queryKey: ["pharmacies"],
      });
    },
    onError: (error) => {
      return error;
    },
  });
};

// update pharmacy
export const useUpdatePharmacy = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: Pharmacy) =>
      pharmaciesServices.updatePharmacy(data),
    onSettled: () => {
      queryClient.refetchQueries({
        queryKey: ["pharmacies"],
      });
    },
    onError: (error) => {
      return error;
    },
  });
};
