import { z } from "zod";

export const inventorySchema = z.object({
  id: z.coerce.number().optional(),
  hospital_id: z.coerce
    .number()
    .int()
    .positive({ message: "Hospital is required" }),
  drug_name: z
    .string()
    .trim()
    .min(1, { message: "Drug Name is required" })
    .max(255, { message: "Drug name should contain maximum 255 characters" }),
  brand_name: z
    .string()
    .trim()
    .min(1, { message: "Brand Name is required" })
    .max(255, { message: "Brand name should contain maximum 255 characters" }),
  weight: z.coerce
    .number()
    .positive({ message: "Weight must be greater than zero" }),
  type: z.enum(["tablet", "capsule", "syrup", "injection", "ointment"]),
});

export const batchSchema = z.object({
  id: z.coerce.number().optional(),
  batch_number: z
    .string()
    .trim()
    .min(1, { message: "Batch Number is required" })
    .max(255, {
      message: "Batch number should contain maximum 255 characters",
    }),
  quantity: z.coerce
    .number()
    .int({ message: "Quantity must be a whole number" })
    .min(1, { message: "Quantity must be at least 1" }),
  expiry_date: z.coerce.date().refine((date) => date > new Date(), {
    message: "Expiry Date must be in the future",
  }),
  inventory_id: z.coerce
    .number()
    .int()
    .positive({ message: "Inventory item is required" }),
});
