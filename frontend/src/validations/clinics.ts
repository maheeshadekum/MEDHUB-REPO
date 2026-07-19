import { z } from "zod";

export const clinicSchema = z
  .object({
    name: z.string().trim().min(1, "Clinic name is required").max(255),
    description: z.string().trim().min(1, "Description is required").max(500),
    doctor_id: z.coerce.number().int().positive("Doctor is required"),
    location: z.string().trim().min(1, "Location is required").max(255),
    total_hourly_tokens: z.coerce.number().int().min(1).max(1000),
    self_hourly_tokens: z.coerce.number().int().min(1).max(1000),
  })
  .refine(
    (values) => values.self_hourly_tokens <= values.total_hourly_tokens,
    {
      path: ["self_hourly_tokens"],
      message: "Self hourly tokens cannot exceed total hourly tokens",
    },
  );
