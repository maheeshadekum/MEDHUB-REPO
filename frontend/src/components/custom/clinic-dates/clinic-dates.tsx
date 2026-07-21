import type { ClinicDate, ClinicDateStatus } from "@/services/clinic-dates";
import type { z } from "zod";

import { ClinicDateTable } from "@/components/custom/clinic-dates/table";
import { clinicDateColumns, formatClinicDate } from "@/components/custom/clinic-dates/table-columns";
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
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui";
import { permissions } from "@/constants/permissions";
import { useAuth } from "@/hooks/use-auth";
import {
  useClinicDateClinicOptions,
  useClinicDateDebouncedValue,
  useClinicDateHospitalOption,
  useClinicDateHospitalOptions,
} from "@/hooks/use-clinic-date-options";
import {
  useClinicDateById,
  useClinicDates,
  useCreateClinicDate,
  useUpdateClinicDate,
  useUpdateClinicDateStatus,
} from "@/hooks/use-clinic-dates";
import { clinicDateSchema, clinicDateStatusSchema } from "@/validations/clinic-dates";
import { zodResolver } from "@hookform/resolvers/zod";
import { Plus } from "lucide-react";
import React, { useEffect, useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { PiSpinnerGapBold } from "react-icons/pi";
import { toast } from "sonner";

type FormValues = z.infer<typeof clinicDateSchema>;
type FieldErrors = Partial<Record<keyof FormValues | "status" | "message", string>>;
const MANAGER_ROLES = ["super_admin", "hospital_admin"];
const FILTER_ROLES = ["super_admin", "hospital_admin", "receptionist"];

const getLocalToday = () => {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
};

const defaults: FormValues = { clinic_id: 0, date: getLocalToday(), start_time: "", end_time: "" };

const getApiErrors = (error: unknown): FieldErrors => {
  if (!error || typeof error !== "object" || !("response" in error)) {
    return { message: "Unable to save the Clinic Date. Check your connection and try again." };
  }
  const response = (error as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } }).response;
  const fieldErrors = response?.data?.errors;
  const mapped: FieldErrors = {};
  if (fieldErrors) {
    (["clinic_id", "date", "start_time", "end_time", "status"] as const).forEach((field) => {
      if (fieldErrors[field]?.[0]) mapped[field] = fieldErrors[field][0];
    });
    if (fieldErrors.schedule?.[0]) mapped.message = fieldErrors.schedule[0];
  }
  if (Object.keys(mapped).length) return mapped;
  if ([403, 404, 422].includes(response?.status ?? 0) && response?.data?.message) {
    return { message: response.data.message };
  }
  return { message: "Unable to save the Clinic Date. Please try again." };
};

export const ClinicDates = React.memo(() => {
  const { user } = useAuth();
  const role = user?.role ?? "";
  const isSuperAdmin = role === "super_admin";
  const canManageClinicDates = Boolean(
    user?.permissions?.includes(permissions.manageHospitals) && MANAGER_ROLES.includes(role),
  );
  const hasManagementContext = isSuperAdmin || Boolean(user?.hospital_id);
  const canUseManagement = canManageClinicDates && hasManagementContext;
  const showClinicFilter = FILTER_ROLES.includes(role);

  const [dialog, setDialog] = useState<"form" | "details" | "status" | null>(null);
  const [selected, setSelected] = useState<ClinicDate | null>(null);
  const [search, setSearch] = useState("");
  const debouncedSearch = useClinicDateDebouncedValue(search);
  const [status, setStatus] = useState<ClinicDateStatus | undefined>();
  const [clinicId, setClinicId] = useState<number | undefined>();
  const [filterHospitalId, setFilterHospitalId] = useState<number | undefined>();
  const [hospitalSearch, setHospitalSearch] = useState("");
  const debouncedHospitalSearch = useClinicDateDebouncedValue(hospitalSearch);
  const [pagination, setPagination] = useState({ currentPage: 1, pageSize: 20 });

  const listQuery = useClinicDates({
    currentPage: pagination.currentPage,
    pageSize: pagination.pageSize,
    search: debouncedSearch || undefined,
    clinic_id: clinicId,
    status,
  });

  const hospitalOptionsQuery = useClinicDateHospitalOptions(
    debouncedHospitalSearch,
    isSuperAdmin && showClinicFilter,
  );
  const selectedHospitalQuery = useClinicDateHospitalOption(
    filterHospitalId ?? 0,
    isSuperAdmin && Boolean(filterHospitalId),
  );
  const optionHospitalId = isSuperAdmin ? (filterHospitalId ?? 0) : (user?.hospital_id ?? 0);
  const clinicOptionsQuery = useClinicDateClinicOptions(
    optionHospitalId,
    showClinicFilter && optionHospitalId > 0,
  );
  const hospitalOptions = useMemo(() => {
    const values = [...(hospitalOptionsQuery.data ?? [])];
    const selectedHospital = selectedHospitalQuery.data;
    if (selectedHospital && !values.some(({ id }) => id === selectedHospital.id)) values.unshift(selectedHospital);
    return values.map((hospital) => ({
      value: String(hospital.id),
      label: [hospital.name, hospital.district, hospital.identifier].filter(Boolean).join(" — "),
    }));
  }, [hospitalOptionsQuery.data, selectedHospitalQuery.data]);
  const clinicOptions = useMemo(
    () => (clinicOptionsQuery.data ?? []).map((clinic) => ({
      value: String(clinic.id),
      label: [clinic.name, clinic.location, clinic.doctor?.name].filter(Boolean).join(" — "),
    })),
    [clinicOptionsQuery.data],
  );

  useEffect(() => {
    setPagination((current) => ({ ...current, currentPage: 1 }));
  }, [debouncedSearch]);
  useEffect(() => {
    const endPage = listQuery.data?.endPage ?? 0;
    if (!listQuery.isFetching && pagination.currentPage > Math.max(endPage, 1)) {
      setPagination((current) => ({ ...current, currentPage: Math.max(endPage, 1) }));
    }
  }, [listQuery.data?.endPage, listQuery.isFetching, pagination.currentPage]);

  const changeHospitalFilter = (value?: number) => {
    setFilterHospitalId(value);
    setClinicId(undefined);
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };
  const changeClinicFilter = (value?: number) => {
    setClinicId(value);
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };
  const resetFilters = () => {
    setSearch("");
    setStatus(undefined);
    setClinicId(undefined);
    setFilterHospitalId(undefined);
    setHospitalSearch("");
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };
  const closeDialog = () => {
    setDialog(null);
    setSelected(null);
  };
  const openDialog = (kind: "details" | "status" | "form", value?: ClinicDate) => {
    setSelected(value ?? null);
    setDialog(kind);
  };
  const data = listQuery.data;
  const isFiltered = Boolean(search || status || clinicId || (isSuperAdmin && filterHospitalId));

  return (
    <div className="flex w-full flex-col">
      <div>
        <h2 className="text-lg font-semibold">Clinic Dates</h2>
        <p className="text-sm text-muted-foreground">View and manage Clinic schedules.</p>
        {canManageClinicDates && !hasManagementContext && (
          <p role="alert" className="mt-2 text-sm font-medium text-destructive">
            A hospital assignment is required to manage Clinic Dates.
          </p>
        )}
      </div>
      {dialog === "form" && canUseManagement && (
        <ClinicDateDialog open clinicDate={selected} onClose={closeDialog} />
      )}
      {dialog === "status" && selected && canUseManagement && (
        <StatusDialog open clinicDate={selected} onClose={closeDialog} />
      )}
      {dialog === "details" && selected && (
        <DetailsDialog open clinicDate={selected} onClose={closeDialog} />
      )}
      <ClinicDateTable
        columns={clinicDateColumns}
        data={data?.clinicDates ?? []}
        search={search}
        setSearch={setSearch}
        status={status}
        setStatus={(value) => { setStatus(value as ClinicDateStatus | undefined); setPagination((current) => ({ ...current, currentPage: 1 })); }}
        clinicId={clinicId}
        setClinicId={changeClinicFilter}
        clinicOptions={clinicOptions}
        showClinicFilter={showClinicFilter}
        hospitalId={filterHospitalId}
        setHospitalId={isSuperAdmin && showClinicFilter ? changeHospitalFilter : undefined}
        hospitalOptions={hospitalOptions}
        hospitalSearch={hospitalSearch}
        setHospitalSearch={isSuperAdmin && showClinicFilter ? setHospitalSearch : undefined}
        hospitalOptionsLoading={hospitalOptionsQuery.isPending}
        canManageClinicDates={canUseManagement}
        hideFilters={role === "pharmacist"}
        viewDetails={(value) => openDialog("details", value)}
        editClinicDate={(value) => openDialog("form", value)}
        updateStatus={(value) => openDialog("status", value)}
        isPending={listQuery.isPending && !data}
        isFetching={listQuery.isFetching}
        isError={listQuery.isError}
        isFiltered={isFiltered}
        refetch={() => void listQuery.refetch()}
        resetFilters={resetFilters}
        setPagination={setPagination}
        pagination={{ ...pagination, from: data?.from ?? 0, to: data?.to ?? 0, total: data?.total ?? 0, endPage: data?.endPage ?? 0 }}
      >
        {canManageClinicDates && (
          <Button size="sm" disabled={!hasManagementContext} onClick={() => openDialog("form")}>
            <Plus className="mr-2 h-4 w-4" />Schedule Clinic Date
          </Button>
        )}
      </ClinicDateTable>
    </div>
  );
});

const ClinicDateDialog = ({ open, clinicDate, onClose }: { open: boolean; clinicDate: ClinicDate | null; onClose: () => void }) => {
  const { user } = useAuth();
  const isEdit = Boolean(clinicDate);
  const isSuperAdmin = user?.role === "super_admin";
  const [hospitalId, setHospitalId] = useState<number>(clinicDate?.clinic?.hospital_id ?? user?.hospital_id ?? 0);
  const [hospitalSearch, setHospitalSearch] = useState("");
  const [serverErrors, setServerErrors] = useState<FieldErrors>({});
  const debouncedHospitalSearch = useClinicDateDebouncedValue(hospitalSearch);
  const hospitalsQuery = useClinicDateHospitalOptions(debouncedHospitalSearch, isSuperAdmin && !isEdit);
  const hospitalQuery = useClinicDateHospitalOption(hospitalId, hospitalId > 0);
  const clinicsQuery = useClinicDateClinicOptions(hospitalId, hospitalId > 0 && !isEdit);
  const createMutation = useCreateClinicDate();
  const updateMutation = useUpdateClinicDate();
  const pending = createMutation.isPending || updateMutation.isPending;
  const form = useForm<FormValues>({ resolver: zodResolver(clinicDateSchema), defaultValues: defaults });

  useEffect(() => {
    setServerErrors({});
    setHospitalId(clinicDate?.clinic?.hospital_id ?? user?.hospital_id ?? 0);
    form.reset(clinicDate ? {
      clinic_id: clinicDate.clinic_id,
      date: clinicDate.date.slice(0, 10),
      start_time: clinicDate.start_time.slice(0, 5),
      end_time: clinicDate.end_time.slice(0, 5),
    } : defaults);
  }, [clinicDate, form, open, user?.hospital_id]);

  const hospitals = useMemo(() => {
    const options = [...(hospitalsQuery.data ?? [])];
    if (hospitalQuery.data && !options.some(({ id }) => id === hospitalQuery.data?.id)) options.unshift(hospitalQuery.data);
    return options.map((hospital) => ({ value: String(hospital.id), label: [hospital.name, hospital.district, hospital.identifier].filter(Boolean).join(" — ") }));
  }, [hospitalQuery.data, hospitalsQuery.data]);
  const clinics = clinicsQuery.data ?? [];
  const changeHospital = (value: string) => {
    const id = value ? Number(value) : 0;
    setHospitalId(id);
    form.setValue("clinic_id", 0, { shouldValidate: false });
    form.clearErrors("clinic_id");
    setServerErrors((current) => ({ ...current, clinic_id: undefined, message: undefined }));
  };
  const submit = async (values: FormValues) => {
    setServerErrors({});
    try {
      if (isEdit && clinicDate) await updateMutation.mutateAsync({ id: clinicDate.id, ...values });
      else await createMutation.mutateAsync(values);
      toast.success(isEdit ? "Clinic Date schedule updated" : "Clinic Date scheduled");
      form.reset(defaults);
      onClose();
    } catch (error) {
      setServerErrors(getApiErrors(error));
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !next && !pending && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Edit Clinic Date" : "Schedule Clinic Date"}</DialogTitle>
          <DialogDescription>{isEdit ? "Update the schedule. Hospital and Clinic ownership cannot be changed." : "Choose the Hospital and Clinic, then enter the schedule."}</DialogDescription>
        </DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
            <FormItem>
              <FormLabel>Hospital <span aria-hidden="true">*</span></FormLabel>
              {isSuperAdmin && !isEdit ? (
                <Combobox value={hospitalId ? String(hospitalId) : ""} items={hospitals} placeholder="Hospital" isLoading={hospitalsQuery.isPending} search={hospitalSearch} onChange={setHospitalSearch} setValue={changeHospital} />
              ) : (
                <Input readOnly value={clinicDate?.clinic?.hospital?.name ?? hospitalQuery.data?.name ?? "Assigned Hospital"} aria-label="Hospital" />
              )}
            </FormItem>
            <FormField control={form.control} name="clinic_id" render={({ field }) => (
              <FormItem>
                <FormLabel>Clinic <span aria-hidden="true">*</span></FormLabel>
                {isEdit ? (
                  <Input readOnly value={clinicDate?.clinic?.name ?? "Clinic"} aria-label="Clinic" />
                ) : (
                  <Select value={field.value > 0 ? String(field.value) : undefined} disabled={!hospitalId || clinicsQuery.isPending} onValueChange={(value) => { field.onChange(Number(value)); setServerErrors((current) => ({ ...current, clinic_id: undefined, message: undefined })); }}>
                    <FormControl><SelectTrigger><SelectValue placeholder={hospitalId ? "Select a Clinic" : "Select a Hospital first"} /></SelectTrigger></FormControl>
                    <SelectContent>{clinics.map((clinic) => <SelectItem key={clinic.id} value={String(clinic.id)}>{[clinic.name, clinic.location, clinic.doctor?.name].filter(Boolean).join(" — ")}</SelectItem>)}</SelectContent>
                  </Select>
                )}
                <FormMessage>{serverErrors.clinic_id}</FormMessage>
              </FormItem>
            )} />
            <FormField control={form.control} name="date" render={({ field }) => <FormItem><FormLabel>Date <span aria-hidden="true">*</span></FormLabel><FormControl><Input type="date" min={getLocalToday()} {...field} onChange={(event) => { field.onChange(event); setServerErrors((current) => ({ ...current, date: undefined, message: undefined })); }} /></FormControl><FormMessage>{serverErrors.date}</FormMessage></FormItem>} />
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField control={form.control} name="start_time" render={({ field }) => <FormItem><FormLabel>Start Time <span aria-hidden="true">*</span></FormLabel><FormControl><Input type="time" {...field} onChange={(event) => { field.onChange(event); setServerErrors((current) => ({ ...current, start_time: undefined, message: undefined })); }} /></FormControl><FormMessage>{serverErrors.start_time}</FormMessage></FormItem>} />
              <FormField control={form.control} name="end_time" render={({ field }) => <FormItem><FormLabel>End Time <span aria-hidden="true">*</span></FormLabel><FormControl><Input type="time" {...field} onChange={(event) => { field.onChange(event); setServerErrors((current) => ({ ...current, end_time: undefined, message: undefined })); }} /></FormControl><FormMessage>{serverErrors.end_time}</FormMessage></FormItem>} />
            </div>
            {serverErrors.message && <p role="alert" className="text-sm font-medium text-destructive">{serverErrors.message}</p>}
            <DialogFooter>
              <Button type="button" variant="outline" disabled={pending} onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={pending || !hospitalId}>{pending && <PiSpinnerGapBold className="mr-2 h-4 w-4 animate-spin" />}{isEdit ? "Save Schedule Changes" : "Schedule Clinic Date"}</Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
};

const StatusDialog = ({ open, clinicDate, onClose }: { open: boolean; clinicDate: ClinicDate; onClose: () => void }) => {
  const [status, setStatus] = useState<ClinicDateStatus>(clinicDate.status);
  const [error, setError] = useState("");
  const mutation = useUpdateClinicDateStatus();
  const submit = async () => {
    const parsed = clinicDateStatusSchema.safeParse({ status });
    if (!parsed.success) return setError("Select a valid status.");
    setError("");
    try {
      await mutation.mutateAsync({ id: clinicDate.id, status });
      toast.success("Clinic Date status updated");
      onClose();
    } catch (apiError) {
      setError(getApiErrors(apiError).status ?? getApiErrors(apiError).message ?? "Unable to update status.");
    }
  };
  return (
    <Dialog open={open} onOpenChange={(next) => !next && !mutation.isPending && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader><DialogTitle>Update Clinic Date Status</DialogTitle><DialogDescription>Review the schedule context before changing its status.</DialogDescription></DialogHeader>
        <dl className="grid gap-2 text-sm sm:grid-cols-[auto_1fr]"><dt className="font-medium">Clinic</dt><dd>{clinicDate.clinic?.name ?? "N/A"}</dd><dt className="font-medium">Hospital</dt><dd>{clinicDate.clinic?.hospital?.name ?? "N/A"}</dd><dt className="font-medium">Date</dt><dd>{formatClinicDate(clinicDate.date)}</dd><dt className="font-medium">Current Status</dt><dd className="capitalize">{clinicDate.status}</dd></dl>
        <div><label className="mb-2 block text-sm font-medium" htmlFor="clinic-date-status">New Status</label><Select value={status} onValueChange={(value) => { setStatus(value as ClinicDateStatus); setError(""); }}><SelectTrigger id="clinic-date-status"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="scheduled">Scheduled</SelectItem><SelectItem value="completed">Completed</SelectItem><SelectItem value="cancelled">Cancelled</SelectItem></SelectContent></Select></div>
        {status === "cancelled" && <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Cancelling this Clinic Date retains the schedule, issued tokens and related clinical records. It does not delete historical information.</p>}
        {error && <p role="alert" className="text-sm font-medium text-destructive">{error}</p>}
        <DialogFooter><Button type="button" variant="outline" disabled={mutation.isPending} onClick={onClose}>Keep Current Status</Button><Button type="button" disabled={mutation.isPending || status === clinicDate.status} onClick={submit}>{mutation.isPending && <PiSpinnerGapBold className="mr-2 h-4 w-4 animate-spin" />}Confirm Status Update</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

const DetailsDialog = ({ open, clinicDate, onClose }: { open: boolean; clinicDate: ClinicDate; onClose: () => void }) => {
  const query = useClinicDateById(clinicDate.id, open);
  const data = query.data ?? clinicDate;
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader><DialogTitle>Clinic Date Details</DialogTitle><DialogDescription>View the Clinic, Hospital, Doctor, schedule, and record dates.</DialogDescription></DialogHeader>
        {query.isPending ? <p role="status">Loading Clinic Date details...</p> : query.isError ? <div role="alert" className="space-y-3"><p>Unable to load complete Clinic Date details.</p><Button type="button" variant="outline" size="sm" onClick={() => void query.refetch()}>Retry</Button></div> : (
          <dl className="grid gap-3 text-sm sm:grid-cols-[auto_1fr]"><dt className="font-medium">Clinic</dt><dd>{data.clinic?.name ?? "N/A"}</dd><dt className="font-medium">Hospital</dt><dd>{data.clinic?.hospital?.name ?? "N/A"}</dd><dt className="font-medium">Doctor</dt><dd>{data.clinic?.doctor?.name ?? "N/A"}</dd><dt className="font-medium">Date</dt><dd>{formatClinicDate(data.date)}</dd><dt className="font-medium">Start Time</dt><dd>{data.start_time}</dd><dt className="font-medium">End Time</dt><dd>{data.end_time}</dd><dt className="font-medium">Status</dt><dd className="capitalize">{data.status}</dd><dt className="font-medium">Created Date</dt><dd>{data.created_at ? new Date(data.created_at).toLocaleString() : "N/A"}</dd><dt className="font-medium">Updated Date</dt><dd>{data.updated_at ? new Date(data.updated_at).toLocaleString() : "N/A"}</dd></dl>
        )}
      </DialogContent>
    </Dialog>
  );
};
