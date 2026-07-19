import type { ClinicPatient } from "@/services/clinic-patients";
import type { ClinicPatientSchema } from "@/validations/clinic-patients";
import type { FC } from "react";

import {
  Button,
  Combobox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui";
import {
  useClinicPatientClinicOptions,
  useClinicPatientHospitalOption,
  useClinicPatientHospitalOptions,
  useClinicPatientPatientOptions,
  useDebouncedValue,
} from "@/hooks/use-clinic-patient-options";
import {
  useCreateClinicPatient,
  useUpdateClinicPatient,
} from "@/hooks/use-clinic-patients";
import { useAuth } from "@/hooks/use-auth";
import { clinicPatientSchema } from "@/validations/clinic-patients";
import { zodResolver } from "@hookform/resolvers/zod";
import React, { useEffect, useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

interface ClinicPatientDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  clinicPatient?: ClinicPatient;
}

type ApiErrorData = {
  errors?: Record<string, string[]>;
  message?: string;
};

const getApiErrorData = (error: unknown): ApiErrorData | null => {
  if (!error || typeof error !== "object" || !("response" in error)) {
    return null;
  }

  return (
    error as {
      response?: { data?: ApiErrorData };
    }
  ).response?.data ?? null;
};

export const ClinicPatientDialog: FC<ClinicPatientDialogProps> = React.memo(
  ({ open, onOpenChange, clinicPatient }) => {
    const { user } = useAuth();
    const isEdit = Boolean(clinicPatient);
    const isSuperAdmin = user?.role === "super_admin";
    const fixedHospitalId = isSuperAdmin ? 0 : (user?.hospital_id ?? 0);
    const [selectedHospitalId, setSelectedHospitalId] = useState(0);
    const [hospitalSearch, setHospitalSearch] = useState("");
    const [clinicSearch, setClinicSearch] = useState("");
    const [patientSearch, setPatientSearch] = useState("");
    const [hospitalError, setHospitalError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<
      Record<string, string[] | string>
    >({});
    const [generalError, setGeneralError] = useState("");

    const debouncedHospitalSearch = useDebouncedValue(hospitalSearch);
    const debouncedPatientSearch = useDebouncedValue(patientSearch);
    const activeHospitalId = isSuperAdmin
      ? selectedHospitalId
      : fixedHospitalId;

    const hospitalsQuery = useClinicPatientHospitalOptions(
      debouncedHospitalSearch,
      open && isSuperAdmin,
    );
    const hospitalContextQuery = useClinicPatientHospitalOption(
      activeHospitalId,
      open && activeHospitalId > 0,
    );
    const clinicsQuery = useClinicPatientClinicOptions(
      activeHospitalId,
      open && activeHospitalId > 0,
    );
    const patientsQuery = useClinicPatientPatientOptions(
      debouncedPatientSearch,
      open,
    );

    const { mutateAsync: createClinicPatient, isPending: isCreating } =
      useCreateClinicPatient();
    const { mutateAsync: updateClinicPatient, isPending: isUpdating } =
      useUpdateClinicPatient();

    const form = useForm<ClinicPatientSchema>({
      resolver: zodResolver(clinicPatientSchema),
      defaultValues: {
        clinic_id: 0,
        patient_id: 0,
      },
    });

    useEffect(() => {
      if (!open) return;

      const initialHospitalId = isSuperAdmin
        ? (clinicPatient?.clinic?.hospital_id ?? 0)
        : fixedHospitalId;

      setSelectedHospitalId(initialHospitalId);
      setHospitalSearch("");
      setClinicSearch("");
      setPatientSearch("");
      setHospitalError("");
      setFieldErrors({});
      setGeneralError("");
      form.reset({
        clinic_id: clinicPatient?.clinic_id ?? 0,
        patient_id: clinicPatient?.patient_id ?? 0,
      });
    }, [clinicPatient, fixedHospitalId, form, isSuperAdmin, open]);

    const hospitalOptions = useMemo(() => {
      const hospitals = [...(hospitalsQuery.data ?? [])];
      const currentHospital = hospitalContextQuery.data;

      if (
        currentHospital &&
        !hospitals.some((hospital) => hospital.id === currentHospital.id)
      ) {
        hospitals.unshift(currentHospital);
      }

      return hospitals.map((hospital) => ({
        label: [hospital.name, hospital.district, hospital.identifier]
          .filter(Boolean)
          .join(" — "),
        value: hospital.id.toString(),
      }));
    }, [hospitalContextQuery.data, hospitalsQuery.data]);

    const clinicOptions = useMemo(() => {
      const normalizedSearch = clinicSearch.trim().toLowerCase();
      const clinics = [...(clinicsQuery.data ?? [])];

      if (
        clinicPatient?.clinic &&
        clinicPatient.clinic.hospital_id === activeHospitalId &&
        !clinics.some((clinic) => clinic.id === clinicPatient.clinic?.id)
      ) {
        clinics.unshift({
          id: clinicPatient.clinic.id,
          name: clinicPatient.clinic.name,
          hospital_id: clinicPatient.clinic.hospital_id,
          location: clinicPatient.clinic.location,
        });
      }

      return clinics
        .filter((clinic) => {
          if (!normalizedSearch) return true;
          return `${clinic.name} ${clinic.location ?? ""}`
            .toLowerCase()
            .includes(normalizedSearch);
        })
        .map((clinic) => ({
          label: clinic.location
            ? `${clinic.name} — ${clinic.location}`
            : clinic.name,
          value: clinic.id.toString(),
        }));
    }, [activeHospitalId, clinicPatient, clinicSearch, clinicsQuery.data]);

    const patientOptions = useMemo(() => {
      const patients = [...(patientsQuery.data ?? [])];

      if (
        clinicPatient?.patient &&
        !patients.some((patient) => patient.id === clinicPatient.patient?.id)
      ) {
        patients.unshift(clinicPatient.patient);
      }

      return patients.map((patient) => ({
        label: `${patient.name} (${patient.nic})`,
        value: patient.id.toString(),
      }));
    }, [clinicPatient?.patient, patientsQuery.data]);

    const isPending = isCreating || isUpdating;
    const hasHospitalContext = activeHospitalId > 0;

    const handleHospitalChange = (value: string) => {
      const nextHospitalId = value ? Number(value) : 0;
      setSelectedHospitalId(nextHospitalId);
      setHospitalError("");
      setClinicSearch("");
      setFieldErrors((current) => ({ ...current, clinic_id: [] }));
      form.setValue("clinic_id", 0, { shouldValidate: false });
    };

    const handleClose = () => {
      if (isPending) return;
      form.reset();
      setFieldErrors({});
      setGeneralError("");
      setHospitalError("");
      onOpenChange(false);
    };

    const onSubmit = async (data: ClinicPatientSchema) => {
      setFieldErrors({});
      setGeneralError("");

      if (!hasHospitalContext) {
        const message = isSuperAdmin
          ? "Hospital is required"
          : "A hospital assignment is required to manage clinic patients.";
        setHospitalError(message);
        return;
      }

      const alert = toast.loading(
        isEdit ? "Updating clinic assignment..." : "Adding patient to clinic...",
      );

      try {
        if (isEdit && clinicPatient?.id) {
          await updateClinicPatient({ id: clinicPatient.id, ...data });
          toast.success("Clinic assignment updated successfully", { id: alert });
        } else {
          await createClinicPatient(data);
          toast.success("Patient added to clinic successfully", { id: alert });
        }

        form.reset();
        onOpenChange(false);
      } catch (error: unknown) {
        const errorData = getApiErrorData(error);

        if (errorData?.errors) {
          setFieldErrors(errorData.errors);
          setGeneralError("Please review the highlighted fields.");
          toast.error("Unable to save clinic assignment", { id: alert });
        } else if (errorData?.message) {
          const safeMessage =
            errorData.message.toLowerCase().includes("authorized") ||
            errorData.message.toLowerCase().includes("hospital")
              ? errorData.message
              : "Unable to save the clinic assignment. Please try again.";
          setGeneralError(safeMessage);
          toast.error(safeMessage, { id: alert });
        } else {
          const message = "Unable to save the clinic assignment. Please try again.";
          setGeneralError(message);
          toast.error(message, { id: alert });
        }
      }
    };

    return (
      <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && handleClose()}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? "Edit Clinic Assignment" : "Add Patient to Clinic"}
            </DialogTitle>
            <DialogDescription>
              {isEdit
                ? "Update the Clinic and Patient for this assignment."
                : "Choose a Hospital, Clinic, and Patient to create an assignment."}
            </DialogDescription>
          </DialogHeader>

          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
              {isSuperAdmin ? (
                <FormItem>
                  <FormLabel>Hospital</FormLabel>
                  <FormControl>
                    <Combobox
                      items={hospitalOptions}
                      search={hospitalSearch}
                      onChange={setHospitalSearch}
                      isLoading={hospitalsQuery.isPending}
                      placeholder="hospital"
                      value={selectedHospitalId ? selectedHospitalId.toString() : ""}
                      setValue={handleHospitalChange}
                      disabled={isPending}
                    />
                  </FormControl>
                  {hospitalError && (
                    <p className="text-sm font-medium text-destructive">
                      {hospitalError}
                    </p>
                  )}
                  {hospitalsQuery.isError && (
                    <p className="text-sm font-medium text-destructive">
                      Unable to load Hospitals. Search again or try later.
                    </p>
                  )}
                </FormItem>
              ) : (
                <div aria-labelledby="clinic-patient-hospital-label">
                  <p
                    id="clinic-patient-hospital-label"
                    className="text-sm font-medium"
                  >
                    Hospital
                  </p>
                  <p className="mt-2 rounded-md border bg-muted px-3 py-2 text-sm">
                    {hospitalContextQuery.data?.name ??
                      (fixedHospitalId
                        ? `Hospital #${fixedHospitalId}`
                        : "No hospital assigned")}
                  </p>
                  {!hasHospitalContext && (
                    <p className="mt-2 text-sm font-medium text-destructive">
                      A hospital assignment is required to manage clinic patients.
                    </p>
                  )}
                </div>
              )}

              <FormField
                control={form.control}
                name="clinic_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Clinic</FormLabel>
                    <FormControl>
                      <Combobox
                        items={clinicOptions}
                        search={clinicSearch}
                        onChange={setClinicSearch}
                        isLoading={clinicsQuery.isPending}
                        placeholder={
                          hasHospitalContext
                            ? "clinic"
                            : "a Hospital before selecting a Clinic"
                        }
                        value={field.value ? field.value.toString() : ""}
                        setValue={(value) => {
                          field.onChange(value ? Number(value) : 0);
                          setFieldErrors((current) => ({
                            ...current,
                            clinic_id: [],
                          }));
                        }}
                        disabled={!hasHospitalContext || isPending}
                      />
                    </FormControl>
                    <FormMessage />
                    {fieldErrors.clinic_id && (
                      <p className="text-sm font-medium text-destructive">
                        {Array.isArray(fieldErrors.clinic_id)
                          ? fieldErrors.clinic_id[0]
                          : fieldErrors.clinic_id}
                      </p>
                    )}
                    {clinicsQuery.isError && hasHospitalContext && (
                      <p className="text-sm font-medium text-destructive">
                        Unable to load Clinics for this Hospital.
                      </p>
                    )}
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="patient_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Patient</FormLabel>
                    <FormControl>
                      <Combobox
                        items={patientOptions}
                        search={patientSearch}
                        onChange={setPatientSearch}
                        isLoading={patientsQuery.isPending}
                        placeholder="patient by name or NIC"
                        value={field.value ? field.value.toString() : ""}
                        setValue={(value) => {
                          field.onChange(value ? Number(value) : 0);
                          setFieldErrors((current) => ({
                            ...current,
                            patient_id: [],
                          }));
                        }}
                        disabled={isPending}
                      />
                    </FormControl>
                    <FormMessage />
                    {fieldErrors.patient_id && (
                      <p className="text-sm font-medium text-destructive">
                        {Array.isArray(fieldErrors.patient_id)
                          ? fieldErrors.patient_id[0]
                          : fieldErrors.patient_id}
                      </p>
                    )}
                    {patientsQuery.isError && (
                      <p className="text-sm font-medium text-destructive">
                        Unable to load Patients. Search again or try later.
                      </p>
                    )}
                  </FormItem>
                )}
              />

              {generalError && (
                <p role="alert" className="text-sm font-medium text-destructive">
                  {generalError}
                </p>
              )}

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleClose}
                  disabled={isPending}
                >
                  Cancel
                </Button>
                <Button
                  type="submit"
                  disabled={isPending || !hasHospitalContext}
                >
                  {isPending
                    ? isEdit
                      ? "Updating Assignment..."
                      : "Adding Patient..."
                    : isEdit
                      ? "Update Assignment"
                      : "Add Patient"}
                </Button>
              </DialogFooter>
            </form>
          </Form>
        </DialogContent>
      </Dialog>
    );
  },
);
