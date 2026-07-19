import { z } from "zod";

export const clinicSchema = z
  .object({
    id: z.coerce.number().optional(),
    name: z
      .string()
      .min(2, { message: "Name must be at least 2 characters" })
      .max(255, { message: "Name should contain maximum 255 characters" }),
    hospital_id: z.coerce
      .number()
      .int()
      .min(1, { message: "Hospital is required" }),
    description: z
      .string()
      .min(2, { message: "Description must be at least 2 characters" })
      .max(500, {
        message: "Description should contain maximum 500 characters",
      }),
    doctor_id: z.coerce.number().min(1, { message: "Doctor is required" }),
    location: z
      .string()
      .min(2, { message: "Location must be at least 2 characters" })
      .max(255, { message: "Location should contain maximum 255 characters" }),
    total_hourly_tokens: z.coerce
      .number()
      .min(1, { message: "Total hourly tokens must be at least 1" })
      .max(1000, { message: "Total hourly tokens should not exceed 1000" }),
    self_hourly_tokens: z.coerce
      .number()
      .min(1, { message: "Self hourly tokens must be at least 1" })
      .max(1000, { message: "Self hourly tokens should not exceed 1000" }),
  })
  .refine((data) => data.self_hourly_tokens <= data.total_hourly_tokens, {
    message: "Self hourly tokens cannot be greater than total hourly tokens",
    path: ["self_hourly_tokens"],
  });
