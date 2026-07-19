import { z } from "zod";

export const hospitalScopedRoles = [
  "hospital_admin",
  "doctor",
  "pharmacist",
  "receptionist",
] as const;

export const peopleRoles = ["super_admin", ...hospitalScopedRoles] as const;
export const userStatuses = ["working", "retired", "banned"] as const;

const userFields = {
  name: z.string().trim().min(1, { message: "Name is required" }).max(255),
  email: z.string().trim().email({ message: "Invalid email address" }).max(255),
  role: z.enum(peopleRoles, { message: "Role is required" }),
  hospital_id: z.coerce.number().int().positive().nullable().optional(),
  status: z.enum(userStatuses),
};

const validateHospitalForRole = (
  data: { role: (typeof peopleRoles)[number]; hospital_id?: number | null },
  context: z.RefinementCtx,
) => {
  const requiresHospital = hospitalScopedRoles.includes(
    data.role as (typeof hospitalScopedRoles)[number],
  );

  if (requiresHospital && !data.hospital_id) {
    context.addIssue({
      code: "custom",
      path: ["hospital_id"],
      message: "Hospital is required for this role",
    });
  }

  if (data.role === "super_admin" && data.hospital_id) {
    context.addIssue({
      code: "custom",
      path: ["hospital_id"],
      message: "Super Admin cannot be assigned to a hospital",
    });
  }
};

export const createUserSchema = z
  .object({
    ...userFields,
    password: z
      .string()
      .min(8, { message: "Password must be at least 8 characters" }),
    password_confirmation: z
      .string()
      .min(8, { message: "Password confirmation must be at least 8 characters" }),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  })
  .superRefine(validateHospitalForRole);

export const updateUserSchema = z
  .object({
    id: z.coerce.number().int().positive(),
    ...userFields,
  })
  .superRefine(validateHospitalForRole);

// Kept as an alias for imports outside the People form.
export const userSchema = createUserSchema;
