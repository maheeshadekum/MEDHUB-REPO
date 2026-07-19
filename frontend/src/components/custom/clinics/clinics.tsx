import type {
  Clinic,
  ClinicFormValues,
} from "@/services/clinics";
import type { FC } from "react";
import type { UseFormReturn } from "react-hook-form";

import { ClinicTable } from "@/components/custom/clinics/table";
import { clinicColumns } from "@/components/custom/clinics/table-columns";
import {
  Button,
  Combobox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
  Input,
  Textarea,
} from "@/components/ui";
import { permissions } from "@/constants/permissions";
import {
  useClinicDebouncedValue,
  useClinicDoctorOptions,
  useClinicHospitalOption,
  useClinicHospitalOptions,
} from "@/hooks/use-clinic-management-options";
import { useAuth } from "@/hooks/use-auth";
import {
  useClinics,
  useCreateClinic,
  useUpdateClinic,
} from "@/hooks/use-clinics";
import { clinicSchema } from "@/validations/clinics";
import { zodResolver } from "@hookform/resolvers/zod";
import { useEffect, useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { PiSpinnerGapBold } from "react-icons/pi";
import { toast } from "sonner";

const clinicDefaultValues: ClinicFormValues = {
  name: "",
  description: "",
  doctor_id: 0,
  location: "",
  total_hourly_tokens: 10,
  self_hourly_tokens: 5,
};

type ApiError = {
  response?: {
    status?: number;
    data?: { message?: string; errors?: Record<string, string[]> };
  };
  message?: string;
};

const formatDate = (value?: string) =>
  value ? new Date(value).toLocaleString() : "Not available";

export const Clinics: FC = () => {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const isHospitalAdmin = user?.role === "hospital_admin";
  const canManageClinics =
    !!user?.permissions?.includes(permissions.manageClinic) &&
    (isSuperAdmin || isHospitalAdmin);
  const hasManagementHospital = isSuperAdmin || !!user?.hospital_id;
  const [open, setOpen] = useState(false);
  const [selectedClinic, setSelectedClinic] = useState<Clinic | null>(null);
  const [detailsClinic, setDetailsClinic] = useState<Clinic | null>(null);
  const [search, setSearch] = useState("");
  const debouncedSearch = useClinicDebouncedValue(search);
  const [hospitalFilter, setHospitalFilter] = useState(0);
  const [hospitalFilterSearch, setHospitalFilterSearch] = useState("");
  const debouncedHospitalFilterSearch = useClinicDebouncedValue(hospitalFilterSearch);
  const [pagination, setPagination] = useState({ currentPage: 1, pageSize: 20 });
  const hospitalFilterQuery = useClinicHospitalOptions(
    debouncedHospitalFilterSearch,
    isSuperAdmin,
  );
  const selectedHospitalFilterQuery = useClinicHospitalOption(
    hospitalFilter,
    isSuperAdmin && hospitalFilter > 0,
  );
  const listQuery = useClinics({
    currentPage: pagination.currentPage,
    pageSize: pagination.pageSize,
    search: debouncedSearch,
    hospitalId: hospitalFilter || undefined,
  });

  const hospitalFilterOptions = useMemo(() => {
    const hospitals = [...(hospitalFilterQuery.data ?? [])];
    const selected = selectedHospitalFilterQuery.data;
    if (selected && !hospitals.some((hospital) => hospital.id === selected.id)) {
      hospitals.unshift(selected);
    }
    return hospitals.map((hospital) => ({
      label: hospital.name,
      value: hospital.id.toString(),
    }));
  }, [hospitalFilterQuery.data, selectedHospitalFilterQuery.data]);

  useEffect(() => {
    setPagination((current) => ({ ...current, currentPage: 1 }));
  }, [debouncedSearch, hospitalFilter]);

  useEffect(() => {
    const endPage = listQuery.data?.endPage ?? 0;
    if (endPage > 0 && pagination.currentPage > endPage) {
      setPagination((current) => ({ ...current, currentPage: endPage }));
    }
  }, [listQuery.data?.endPage, pagination.currentPage]);

  const openCreate = () => {
    setSelectedClinic(null);
    setOpen(true);
  };
  const openEdit = (clinic: Clinic) => {
    setSelectedClinic(clinic);
    setOpen(true);
  };
  const resetFilters = () => {
    setSearch("");
    setHospitalFilter(0);
    setHospitalFilterSearch("");
  };

  return (
    <div className="flex w-full flex-col">
      <h2 className="text-lg font-semibold">Clinics</h2>
      <p className="text-sm text-gray-500">
        View Clinic ownership, Doctors, locations, and token capacity.
      </p>
      {canManageClinics && !hasManagementHospital && (
        <p className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          A hospital assignment is required to manage clinics.
        </p>
      )}

      <ClinicDialog
        open={open}
        onClose={() => {
          setOpen(false);
          setSelectedClinic(null);
        }}
        clinic={selectedClinic}
      />

      <div className="mt-4 w-full">
        <ClinicTable
          columns={clinicColumns}
          data={listQuery.data?.clinics ?? []}
          search={search}
          setSearch={setSearch}
          pagination={{
            ...pagination,
            from: listQuery.data?.from ?? 0,
            to: listQuery.data?.to ?? 0,
            total: listQuery.data?.total ?? 0,
            endPage: listQuery.data?.endPage ?? 0,
          }}
          setPagination={setPagination}
          canManageClinics={canManageClinics && hasManagementHospital}
          isPending={listQuery.isPending}
          isError={listQuery.isError}
          hasFilters={search.trim().length > 0 || hospitalFilter > 0}
          onRetry={() => void listQuery.refetch()}
          onResetFilters={resetFilters}
          onCreate={openCreate}
          onEdit={openEdit}
          onView={setDetailsClinic}
        >
          {canManageClinics && hasManagementHospital && (
            <Button size="sm" variant="outline" onClick={openCreate}>
              Create Clinic
            </Button>
          )}
          {isSuperAdmin && (
            <div className="min-w-56">
              <Combobox
                isLoading={hospitalFilterQuery.isPending}
                items={hospitalFilterOptions}
                onChange={setHospitalFilterSearch}
                placeholder="Hospital filter"
                search={hospitalFilterSearch}
                setValue={(value) => setHospitalFilter(Number(value) || 0)}
                value={hospitalFilter ? hospitalFilter.toString() : ""}
              />
            </div>
          )}
        </ClinicTable>
      </div>

      <ClinicDetails clinic={detailsClinic} onClose={() => setDetailsClinic(null)} />
    </div>
  );
};

const ClinicDialog: FC<{
  open: boolean;
  onClose: () => void;
  clinic: Clinic | null;
}> = ({ open, onClose, clinic }) => {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const isEdit = !!clinic;
  const fixedHospitalId = isSuperAdmin ? 0 : (user?.hospital_id ?? 0);
  const [hospitalId, setHospitalId] = useState(0);
  const [hospitalSearch, setHospitalSearch] = useState("");
  const [doctorSearch, setDoctorSearch] = useState("");
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const debouncedHospitalSearch = useClinicDebouncedValue(hospitalSearch);
  const createMutation = useCreateClinic();
  const updateMutation = useUpdateClinic();
  const isPending = createMutation.isPending || updateMutation.isPending;
  const activeHospitalId = isEdit ? (clinic?.hospital_id ?? 0) : hospitalId;
  const hospitalOptionsQuery = useClinicHospitalOptions(
    debouncedHospitalSearch,
    open && isSuperAdmin && !isEdit,
  );
  const hospitalContextQuery = useClinicHospitalOption(
    activeHospitalId,
    open && activeHospitalId > 0,
  );
  const doctorsQuery = useClinicDoctorOptions(
    activeHospitalId,
    open && activeHospitalId > 0,
  );
  const form = useForm<ClinicFormValues>({
    resolver: zodResolver(clinicSchema),
    defaultValues: clinicDefaultValues,
  });

  useEffect(() => {
    if (!open) return;
    form.reset(
      clinic
        ? {
            name: clinic.name,
            description: clinic.description,
            doctor_id: clinic.doctor_id,
            location: clinic.location,
            total_hourly_tokens: clinic.total_hourly_tokens,
            self_hourly_tokens: clinic.self_hourly_tokens,
          }
        : clinicDefaultValues,
    );
    setHospitalId(clinic?.hospital_id ?? fixedHospitalId);
    setHospitalSearch("");
    setDoctorSearch("");
    setServerErrors({});
  }, [clinic, fixedHospitalId, form, open]);

  const hospitalOptions = useMemo(() => {
    const hospitals = [...(hospitalOptionsQuery.data ?? [])];
    const current = hospitalContextQuery.data ?? clinic?.hospital;
    if (current && !hospitals.some((hospital) => hospital.id === current.id)) {
      hospitals.unshift(current);
    }
    return hospitals.map((hospital) => ({
      label: hospital.name,
      value: hospital.id.toString(),
    }));
  }, [clinic?.hospital, hospitalContextQuery.data, hospitalOptionsQuery.data]);

  const doctorOptions = useMemo(() => {
    const normalizedSearch = doctorSearch.trim().toLowerCase();
    const doctors = [...(doctorsQuery.data ?? [])];
    if (clinic?.doctor && !doctors.some((doctor) => doctor.id === clinic.doctor?.id)) {
      doctors.unshift(clinic.doctor);
    }
    return doctors
      .filter(
        (doctor) =>
          !normalizedSearch ||
          doctor.name.toLowerCase().includes(normalizedSearch) ||
          doctor.email.toLowerCase().includes(normalizedSearch),
      )
      .map((doctor) => ({
        label: `${doctor.name} (${doctor.email})`,
        value: doctor.id.toString(),
      }));
  }, [clinic?.doctor, doctorSearch, doctorsQuery.data]);

  const clearServerError = (field: string) => {
    setServerErrors((current) => {
      if (!current[field] && !current.message) return current;
      const next = { ...current };
      delete next[field];
      delete next.message;
      return next;
    });
  };

  const selectHospital = (value: string) => {
    const nextHospitalId = Number(value) || 0;
    if (nextHospitalId !== hospitalId) {
      setHospitalId(nextHospitalId);
      form.setValue("doctor_id", 0, { shouldValidate: false });
      setDoctorSearch("");
    }
    clearServerError("hospital_id");
    clearServerError("doctor_id");
  };

  const handleApiError = (error: unknown) => {
    const apiError = error as ApiError;
    const fields = apiError.response?.data?.errors;
    if (fields) {
      setServerErrors(
        Object.fromEntries(
          Object.entries(fields).map(([field, messages]) => [field, messages[0]]),
        ),
      );
      return;
    }
    const status = apiError.response?.status;
    const fallback =
      status === 403
        ? "You are not authorized to manage this Clinic."
        : status === 404
          ? "The requested Clinic, Hospital, or Doctor was not found."
          : status === 422
            ? "Review the Clinic details and try again."
            : "Unable to save the Clinic. Please try again.";
    setServerErrors({ message: apiError.response?.data?.message ?? fallback });
  };

  const onSubmit = async (values: ClinicFormValues) => {
    setServerErrors({});
    try {
      if (clinic?.id) {
        await updateMutation.mutateAsync({ id: clinic.id, ...values });
        toast.success("Clinic updated");
      } else {
        await createMutation.mutateAsync({
          ...values,
          ...(isSuperAdmin ? { hospital_id: hospitalId } : {}),
        });
        toast.success("Clinic created");
      }
      form.reset(clinicDefaultValues);
      setServerErrors({});
      onClose();
    } catch (error) {
      handleApiError(error);
    }
  };

  const hospitalName =
    hospitalContextQuery.data?.name ?? clinic?.hospital?.name ?? user?.hospital ?? "";
  const missingHospital = !isSuperAdmin && activeHospitalId <= 0;

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onClose()}>
      <DialogContent className="max-h-[85vh] max-w-xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit Clinic" : "Create Clinic"}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? "Update Clinic details. Hospital ownership cannot be changed."
              : "Choose the Hospital and working Doctor, then enter Clinic details."}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4 p-1">
            <div className="space-y-2" role="group" aria-labelledby="clinic-hospital-label">
              <FormLabel id="clinic-hospital-label">Hospital <span aria-hidden="true">*</span></FormLabel>
              {isSuperAdmin && !isEdit ? (
                <Combobox
                  isLoading={hospitalOptionsQuery.isPending}
                  items={hospitalOptions}
                  onChange={setHospitalSearch}
                  placeholder="Hospital"
                  search={hospitalSearch}
                  setValue={selectHospital}
                  value={hospitalId ? hospitalId.toString() : ""}
                />
              ) : (
                <Input value={hospitalName} readOnly aria-label="Clinic Hospital" />
              )}
              {serverErrors.hospital_id && (
                <p className="text-sm text-destructive" role="alert">
                  {serverErrors.hospital_id}
                </p>
              )}
              {(hospitalOptionsQuery.isError || hospitalContextQuery.isError) && (
                <div className="flex items-center gap-2 text-sm text-destructive" role="alert">
                  <span>Unable to load Hospital options.</span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                      void (hospitalOptionsQuery.isError
                        ? hospitalOptionsQuery.refetch()
                        : hospitalContextQuery.refetch())
                    }
                  >
                    Retry
                  </Button>
                </div>
              )}
              {missingHospital && (
                <p className="text-sm text-destructive" role="alert">
                  A hospital assignment is required to manage clinics.
                </p>
              )}
            </div>

            <ClinicTextField
              form={form}
              name="name"
              label="Clinic Name"
              placeholder="Enter Clinic name"
              serverError={serverErrors.name}
              clearServerError={clearServerError}
            />
            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Description <span aria-hidden="true">*</span></FormLabel>
                  <FormControl>
                    <Textarea
                      rows={3}
                      placeholder="Enter Clinic description"
                      {...field}
                      onChange={(event) => {
                        field.onChange(event);
                        clearServerError("description");
                      }}
                    />
                  </FormControl>
                  <FormMessage />
                  {serverErrors.description && (
                    <p className="text-sm text-destructive" role="alert">
                      {serverErrors.description}
                    </p>
                  )}
                </FormItem>
              )}
            />
            <ClinicTextField
              form={form}
              name="location"
              label="Location"
              placeholder="Enter Clinic location"
              serverError={serverErrors.location}
              clearServerError={clearServerError}
            />

            <div className="space-y-2" role="group" aria-labelledby="clinic-doctor-label">
              <FormLabel id="clinic-doctor-label">Doctor <span aria-hidden="true">*</span></FormLabel>
              <Combobox
                disabled={activeHospitalId <= 0 || missingHospital}
                isLoading={doctorsQuery.isPending}
                items={doctorOptions}
                onChange={setDoctorSearch}
                placeholder="Doctor"
                search={doctorSearch}
                setValue={(value) => {
                  form.setValue("doctor_id", Number(value) || 0, {
                    shouldDirty: true,
                    shouldValidate: true,
                  });
                  clearServerError("doctor_id");
                }}
                value={form.watch("doctor_id") || ""}
              />
              {form.formState.errors.doctor_id?.message && (
                <p className="text-sm text-destructive">
                  {form.formState.errors.doctor_id.message}
                </p>
              )}
              {serverErrors.doctor_id && (
                <p className="text-sm text-destructive" role="alert">
                  {serverErrors.doctor_id}
                </p>
              )}
              {doctorsQuery.isError && (
                <div className="flex items-center gap-2 text-sm text-destructive" role="alert">
                  <span>Unable to load Doctor options.</span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => void doctorsQuery.refetch()}
                  >
                    Retry
                  </Button>
                </div>
              )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <ClinicNumberField
                form={form}
                name="total_hourly_tokens"
                label="Total Hourly Tokens"
                serverError={serverErrors.total_hourly_tokens}
                clearServerError={clearServerError}
              />
              <ClinicNumberField
                form={form}
                name="self_hourly_tokens"
                label="Self Hourly Tokens"
                serverError={serverErrors.self_hourly_tokens}
                clearServerError={clearServerError}
              />
            </div>

            {serverErrors.message && (
              <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive" role="alert">
                {serverErrors.message}
              </p>
            )}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button type="button" variant="outline" onClick={onClose} disabled={isPending}>
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={isPending || missingHospital || activeHospitalId <= 0}
              >
                {isPending && <PiSpinnerGapBold className="animate-spin" />}
                {isEdit ? "Save Changes" : "Create Clinic"}
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
};

type ClinicFieldName = "name" | "location";
type ClinicNumberFieldName = "total_hourly_tokens" | "self_hourly_tokens";
type ClinicForm = UseFormReturn<ClinicFormValues>;

const ClinicTextField = ({
  form,
  name,
  label,
  placeholder,
  serverError,
  clearServerError,
}: {
  form: ClinicForm;
  name: ClinicFieldName;
  label: string;
  placeholder: string;
  serverError?: string;
  clearServerError: (field: string) => void;
}) => (
  <FormField
    control={form.control}
    name={name}
    render={({ field }) => (
      <FormItem>
        <FormLabel>{label} <span aria-hidden="true">*</span></FormLabel>
        <FormControl>
          <Input
            placeholder={placeholder}
            {...field}
            onChange={(event) => {
              field.onChange(event);
              clearServerError(name);
            }}
          />
        </FormControl>
        <FormMessage />
        {serverError && <p className="text-sm text-destructive" role="alert">{serverError}</p>}
      </FormItem>
    )}
  />
);

const ClinicNumberField = ({
  form,
  name,
  label,
  serverError,
  clearServerError,
}: {
  form: ClinicForm;
  name: ClinicNumberFieldName;
  label: string;
  serverError?: string;
  clearServerError: (field: string) => void;
}) => (
  <FormField
    control={form.control}
    name={name}
    render={({ field }) => (
      <FormItem>
        <FormLabel>{label} <span aria-hidden="true">*</span></FormLabel>
        <FormControl>
          <Input
            type="number"
            min={1}
            max={1000}
            {...field}
            onChange={(event) => {
              field.onChange(event);
              clearServerError(name);
            }}
          />
        </FormControl>
        <FormMessage />
        {serverError && <p className="text-sm text-destructive" role="alert">{serverError}</p>}
      </FormItem>
    )}
  />
);

const ClinicDetails: FC<{ clinic: Clinic | null; onClose: () => void }> = ({
  clinic,
  onClose,
}) => (
  <Dialog open={!!clinic} onOpenChange={(open) => !open && onClose()}>
    <DialogContent className="max-h-[85vh] max-w-xl overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Clinic Details</DialogTitle>
        <DialogDescription>
          Clinic ownership, Doctor, location, and token capacity.
        </DialogDescription>
      </DialogHeader>
      {clinic && (
        <dl className="grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
          <Detail label="Clinic Name" value={clinic.name} />
          <Detail label="Hospital" value={clinic.hospital?.name ?? "Not available"} />
          <Detail label="Doctor" value={clinic.doctor?.name ?? "Not available"} />
          <Detail label="Description" value={clinic.description} />
          <Detail label="Location" value={clinic.location} />
          <Detail label="Total Hourly Tokens" value={clinic.total_hourly_tokens.toString()} />
          <Detail label="Self Hourly Tokens" value={clinic.self_hourly_tokens.toString()} />
          <Detail label="Created" value={formatDate(clinic.created_at)} />
          <Detail label="Updated" value={formatDate(clinic.updated_at)} />
        </dl>
      )}
    </DialogContent>
  </Dialog>
);

const Detail = ({ label, value }: { label: string; value: string }) => (
  <>
    <dt className="font-medium text-muted-foreground">{label}</dt>
    <dd className="break-words">{value}</dd>
  </>
);
