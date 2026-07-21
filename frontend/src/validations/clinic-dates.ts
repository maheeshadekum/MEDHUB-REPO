import { z } from "zod";

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

const localToday = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

const isCalendarDate = (value: string) => {
  if (!DATE_PATTERN.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(year, month - 1, day);
  return (
    date.getFullYear() === year &&
    date.getMonth() === month - 1 &&
    date.getDate() === day
  );
};

export const clinicDateSchema = z
  .object({
    clinic_id: z.coerce.number().int().positive("Clinic is required"),
    date: z
      .string()
      .min(1, "Date is required")
      .refine(isCalendarDate, "Enter a valid date")
      .refine((value) => !DATE_PATTERN.test(value) || value >= localToday(), {
        message: "Date must be today or in the future",
      }),
    start_time: z
      .string()
      .min(1, "Start time is required")
      .regex(TIME_PATTERN, "Start time must be in HH:mm format"),
    end_time: z
      .string()
      .min(1, "End time is required")
      .regex(TIME_PATTERN, "End time must be in HH:mm format"),
  })
  .refine(
    ({ start_time, end_time }) =>
      !TIME_PATTERN.test(start_time) ||
      !TIME_PATTERN.test(end_time) ||
      end_time > start_time,
    { message: "End time must be after start time", path: ["end_time"] },
  );

export const clinicDateStatusSchema = z.object({
  status: z.enum(["scheduled", "completed", "cancelled"]),
});
