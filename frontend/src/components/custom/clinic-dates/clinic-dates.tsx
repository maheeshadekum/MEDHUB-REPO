import type { ClinicDate } from "@/services/clinic-dates";
import type { FC } from "react";
import type { z } from "zod";

import { ClinicDateTable } from "@/components/custom/clinic-dates/table";
import { clinicDateColumns } from "@/components/custom/clinic-dates/table-columns";
import {
  Button,
  Calendar,
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
  Popover,
  PopoverContent,
  PopoverTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui";
import { permissions } from "@/constants/permissions";
import { useAuth } from "@/hooks/use-auth";
import {
  useClinicDates,
  useCreateClinicDate,
  useUpdateClinicDate,
  useUpdateClinicDateStatus,
} from "@/hooks/use-clinic-dates";
import {
  useClinicHospitals,
  useClinics,
  useClinicsByHospital,
} from "@/hooks/use-clinics";
import { PermissionWrapper } from "@/providers/permission-wrapper";
import { cn } from "@/utils";
import { clinicDateSchema } from "@/validations/clinic-dates";
import { zodResolver } from "@hookform/resolvers/zod";
import { addDays, format } from "date-fns";
import { CalendarIcon, Check } from "lucide-react";
import moment from "moment";
import React, { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { BiError } from "react-icons/bi";
import { PiSpinnerGapBold } from "react-icons/pi";
import { toast } from "sonner";

const clinicDateDefaultValues: ClinicDate = {
  hospital_id: 0,
  clinic_id: 0,
  date: new Date(),
  start_time: "",
  end_time: "",
  status: "scheduled",
};

export const ClinicDates: FC = React.memo(() => {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const isHospitalAdmin = user?.role === "hospital_admin";
  const [open, setOpen] = useState<boolean>(false);
  const [showDetails, setShowDetails] = useState<boolean>(false);
  const [showStatusDialog, setShowStatusDialog] = useState<boolean>(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("default");
  const [clinicId, setClinicId] = useState<number | null>(0);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    pageSize: 20,
  });
  const { data } = useClinicDates({
    currentPage: pagination.currentPage,
    pageSize: pagination.pageSize,
    search,
    clinic_id: clinicId === 0 ? undefined : clinicId || undefined,
    status: statusFilter === "default" ? undefined : statusFilter || undefined,
  });
  const [selectedClinicDate, setSelectedClinicDate] =
    useState<ClinicDate | null>(null);

  // Fetch clinics for dropdown
  const { data: allClinicsData } = useClinics(
    {
      currentPage: 1,
      pageSize: 100,
    },
    isSuperAdmin,
  );
  const { data: assignedClinicsData } = useClinicsByHospital(
    user?.hospital_id || 0,
    isHospitalAdmin && Boolean(user?.hospital_id),
  );
  const clinicsForFilter = isSuperAdmin
    ? allClinicsData?.clinics || []
    : assignedClinicsData || [];

  // clear selected clinic date when dialog closes
  const closeDialog = () => {
    setSelectedClinicDate(null);
    setOpen(false);
    setShowDetails(false);
    setShowStatusDialog(false);
  };

  // reset current page when search or status filter is changed
  useEffect(() => {
    setPagination((prev) => ({
      ...prev,
      currentPage: 1,
    }));
  }, [search, statusFilter]);

  return (
    <div className="flex w-full flex-col">
      <h2 className="text-lg font-semibold">Clinic Dates</h2>
      <p className="text-sm text-gray-500">
        Manage clinic schedules and appointments
      </p>

      {/* clinic date dialog */}
      <ClinicDateDialog
        open={open}
        onClose={closeDialog}
        data={selectedClinicDate}
      />

      {/* clinic date status dialog */}
      <ClinicDateStatusDialog
        open={showStatusDialog}
        onClose={closeDialog}
        data={selectedClinicDate}
      />

      {/* clinic dates list */}
      <div className="mt-4 flex w-full justify-center overflow-hidden">
        <ClinicDateTable
          columns={clinicDateColumns}
          data={data?.clinicDates || []}
          clinicsData={clinicsForFilter}
          search={search}
          setSearch={setSearch}
          statusFilter={statusFilter}
          setStatusFilter={setStatusFilter}
          clinicId={clinicId}
          setClinicId={setClinicId}
          setSelectedClinicDate={setSelectedClinicDate}
          setOpen={setOpen}
          setShowDetails={setShowDetails}
          setShowStatusDialog={setShowStatusDialog}
          setPagination={setPagination}
          pagination={{
            currentPage: pagination.currentPage,
            pageSize: pagination.pageSize,
            from: data?.from || 0,
            to: data?.to || 0,
            total: data?.total || 0,
            endPage: data?.endPage || 0,
          }}
        >
          <PermissionWrapper
            permissions={[permissions.manageHospitals]}
            roles={["super_admin", "hospital_admin"]}
          >
            <Button
              size={"sm"}
              variant={"outline"}
              className="w-32"
              onClick={() => setOpen(true)}
            >
              Add New
            </Button>
          </PermissionWrapper>
        </ClinicDateTable>
      </div>

      {/* details dialog */}
      {showDetails && selectedClinicDate && (
        <ShowDetails
          showDetails={selectedClinicDate}
          setShowDetails={setShowDetails}
        />
      )}
    </div>
  );
});

const ClinicDateDialog: FC<{
  open: boolean;
  onClose: () => void;
  data?: ClinicDate | null;
}> = React.memo(({ open, onClose, data }) => {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const isHospitalAdmin = user?.role === "hospital_admin";
  const isEditing = Boolean(data);
  const assignedHospitalId = user?.hospital_id || 0;
  const hasAssignedHospital = assignedHospitalId > 0;
  const [hospitalSearch, setHospitalSearch] = useState("");
  const [errors, setErrors] = useState<{ [key: string]: string[] | string }>(
    {},
  );
  const { mutateAsync: createClinicDate, isPending: createPending } =
    useCreateClinicDate();
  const { mutateAsync: updateClinicDate, isPending: updatePending } =
    useUpdateClinicDate();

  const form = useForm<z.infer<typeof clinicDateSchema>>({
    resolver: zodResolver(clinicDateSchema),
    defaultValues: clinicDateDefaultValues,
  });

  const selectedHospitalId = form.watch("hospital_id");
  const { data: hospitalsData, isLoading: isHospitalsLoading } =
    useClinicHospitals(
      hospitalSearch,
      open && isSuperAdmin && !isEditing,
    );
  const { data: hospitalClinics, isLoading: isClinicsLoading } =
    useClinicsByHospital(
      selectedHospitalId,
      open && !isEditing && selectedHospitalId > 0,
    );

  // form submit handler
  const onSubmit = async (values: z.infer<typeof clinicDateSchema>) => {
    setErrors({});
    // standardize date format
    const localMidnightDate = moment(values.date).local().endOf("day");
    const utcDateStringDate = moment(localMidnightDate).utc().format();
    values.date = new Date(utcDateStringDate);
    if (data) {
      const updatedValues = {
        ...values,
        id: data.id,
      };
      await updateClinicDate(updatedValues)
        .then(() => {
          toast.success("Clinic date updated", {
            description: new Date().toLocaleString(),
          });
          form.reset();
          onClose();
        })
        .catch((error) => {
          setErrors(
            error?.response?.data?.errors || {
              message: error?.response?.data?.message || "Something went wrong",
            },
          );
        });
    } else {
      await createClinicDate(values)
        .then(() => {
          toast.success("Clinic date created", {
            description: new Date().toLocaleString(),
          });
          form.reset();
          onClose();
        })
        .catch((error) => {
          setErrors(
            error?.response?.data?.errors || {
              message: error?.response?.data?.message || "Something went wrong",
            },
          );
        });
    }
  };

  // set form values if data is available
  useEffect(() => {
    if (data) {
      form.reset({
        ...data,
        hospital_id: data.clinic?.hospital_id || 0,
        status: data.status ?? "scheduled",
        date: data.date ? new Date(data.date) : new Date(),
      });
    } else {
      form.reset({
        ...clinicDateDefaultValues,
        hospital_id: isHospitalAdmin ? assignedHospitalId : 0,
      });
    }
    setHospitalSearch("");
  }, [assignedHospitalId, data, form, isHospitalAdmin]);

  const hospitalName = isEditing
    ? data?.clinic?.hospital?.name || "Unknown hospital"
    : user?.hospital || "Assigned hospital";
  const clinicName = data?.clinic?.name || "Unknown clinic";
  const selectedHospitalName =
    hospitalsData?.hospitals.find(
      (hospital) => hospital.id === selectedHospitalId,
    )?.name || hospitalName;
  const savingDisabled =
    createPending ||
    updatePending ||
    (!isEditing && isHospitalAdmin && !hasAssignedHospital);

  return (
    <Dialog
      open={open}
      onOpenChange={() => {
        onClose();
        form.reset({
          ...clinicDateDefaultValues,
          hospital_id: isHospitalAdmin ? assignedHospitalId : 0,
        });
      }}
    >
      <DialogContent className="max-h-[80vh] max-w-xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {data ? "Edit Clinic Date" : "Create Clinic Date"}
          </DialogTitle>
          <DialogDescription>
            {data
              ? "Edit the details of the clinic date."
              : "Fill in the details to create a new clinic date."}
          </DialogDescription>
        </DialogHeader>
        <Form {...form}>
          <form
            onSubmit={form.handleSubmit(onSubmit)}
            className="space-y-3 overflow-y-auto p-1"
          >
            {!isEditing && isSuperAdmin && (
              <FormField
                control={form.control}
                name="hospital_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Hospital</FormLabel>
                    <FormControl>
                      <Combobox
                        isLoading={isHospitalsLoading}
                        items={
                          hospitalsData?.hospitals.map((hospital) => ({
                            label: hospital.name,
                            value: hospital.id?.toString() || "",
                          })) || []
                        }
                        onChange={setHospitalSearch}
                        placeholder="Hospital"
                        search={hospitalSearch}
                        setValue={(value) => {
                          field.onChange(Number(value));
                          form.setValue("clinic_id", 0, {
                            shouldValidate: true,
                          });
                        }}
                        value={field.value > 0 ? field.value.toString() : ""}
                      />
                    </FormControl>
                    <FormMessage>
                      {errors["hospital_id"] && errors["hospital_id"][0]}
                    </FormMessage>
                  </FormItem>
                )}
              />
            )}

            {(isEditing || isHospitalAdmin) && (
              <div className="space-y-2">
                <FormLabel>Hospital</FormLabel>
                <Input value={hospitalName} disabled readOnly />
                {!isEditing && isHospitalAdmin && !hasAssignedHospital && (
                  <p className="text-sm font-medium text-destructive">
                    No hospital is assigned to your account. Contact a Super
                    Admin before creating a Clinic Date.
                  </p>
                )}
              </div>
            )}

            {isEditing ? (
              <div className="space-y-2">
                <FormLabel>Clinic</FormLabel>
                <Input value={clinicName} disabled readOnly />
              </div>
            ) : (
              <FormField
                control={form.control}
                name="clinic_id"
                render={({ field }) => (
                  <FormItem className="w-full">
                    <FormLabel>Clinic</FormLabel>
                    <Select
                      disabled={selectedHospitalId <= 0 || isClinicsLoading}
                      onValueChange={(value) => field.onChange(Number(value))}
                      value={
                        field.value === 0 ? undefined : field.value?.toString()
                      }
                    >
                      <FormControl className="w-full">
                        <SelectTrigger>
                          <SelectValue placeholder="Select a clinic" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {hospitalClinics?.map((clinic) => (
                          <SelectItem
                            key={clinic.id}
                            value={clinic.id?.toString() || ""}
                          >
                            {clinic.name} — {selectedHospitalName}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage>
                      {errors["clinic_id"] && errors["clinic_id"][0]}
                    </FormMessage>
                  </FormItem>
                )}
              />
            )}

            {/* date */}
            <FormField
              control={form.control}
              name="date"
              render={({ field }) => (
                <FormItem className="flex flex-col">
                  <FormLabel>Date</FormLabel>
                  <Popover>
                    <PopoverTrigger asChild>
                      <FormControl>
                        <Button
                          variant={"outline"}
                          className={cn(
                            "w-full pl-3 text-left font-normal",
                            !field.value && "text-muted-foreground",
                          )}
                        >
                          {field.value ? (
                            format(field.value, "PPP")
                          ) : (
                            <span>Pick a date</span>
                          )}
                          <CalendarIcon className="ml-auto h-4 w-4 opacity-50" />
                        </Button>
                      </FormControl>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        startMonth={field.value || new Date()}
                        selected={field.value}
                        onSelect={field.onChange}
                        disabled={(date) =>
                          date < addDays(new Date(), -1) ||
                          // after 7 days
                          date > addDays(new Date(), 7)
                        }
                        captionLayout="label"
                      />
                    </PopoverContent>
                  </Popover>
                  <FormMessage>
                    {errors["date"] && errors["date"][0]}
                  </FormMessage>
                </FormItem>
              )}
            />

            {/* start_time */}
            <FormField
              control={form.control}
              name="start_time"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Start Time</FormLabel>
                  <FormControl>
                    <Input type="time" placeholder="HH:MM" {...field} />
                  </FormControl>
                  <FormMessage>
                    {errors["start_time"] && errors["start_time"][0]}
                  </FormMessage>
                </FormItem>
              )}
            />

            {/* end_time */}
            <FormField
              control={form.control}
              name="end_time"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>End Time</FormLabel>
                  <FormControl>
                    <Input type="time" placeholder="HH:MM" {...field} />
                  </FormControl>
                  <FormMessage>
                    {errors["end_time"] && errors["end_time"][0]}
                  </FormMessage>
                </FormItem>
              )}
            />

            {/* status */}
            <FormField
              control={form.control}
              name="status"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Status</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl className="w-full">
                      <SelectTrigger>
                        <SelectValue placeholder="Select status" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="scheduled">Scheduled</SelectItem>
                      <SelectItem value="completed">Completed</SelectItem>
                      <SelectItem value="cancelled">Cancelled</SelectItem>
                    </SelectContent>
                  </Select>
                  <FormMessage>
                    {errors["status"] && errors["status"][0]}
                  </FormMessage>
                </FormItem>
              )}
            />

            {/*common error message */}
            {errors?.message && (
              <div className="flex justify-center">
                <FormMessage className="mt-3 flex w-full max-w-xl items-center justify-center rounded-sm bg-red-500 py-2 text-center text-white">
                  <BiError className="h-5 w-5" /> {errors?.message}
                </FormMessage>
              </div>
            )}

            <div className="flex justify-end">
              <Button
                disabled={savingDisabled}
                type="submit"
                className="mt-3 w-full max-w-40"
              >
                {(createPending || updatePending) && (
                  <PiSpinnerGapBold className="animate-spin" />
                )}
                Save Clinic Date
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
});

const ClinicDateStatusDialog: FC<{
  open: boolean;
  data: ClinicDate | null;
  onClose: () => void;
}> = React.memo(({ open, data, onClose }) => {
  const { mutateAsync: updateStatus, isPending: isUpdating } =
    useUpdateClinicDateStatus();

  const handleStatusChange = async (status: string) => {
    if (data && data.id) {
      await updateStatus({ id: data.id, status })
        .then(() => {
          toast.success(`OPD date status updated to ${status}`, {
            description: new Date().toLocaleString(),
          });
          onClose();
        })
        .catch((error) => {
          toast.error(
            `Failed to update status: ${error?.response?.data?.message || "Something went wrong"}`,
          );
        });
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Update Clinic Date Status</DialogTitle>
          <DialogDescription>
            Select a new status for the Clinic date.
          </DialogDescription>
        </DialogHeader>
        <div className="flex gap-3">
          {["scheduled", "completed", "cancelled"].map((status) => (
            <Button
              key={status}
              variant={data?.status === status ? "default" : "outline"}
              onClick={() => handleStatusChange(status)}
              disabled={isUpdating}
              className="capitalize"
            >
              {data?.status === status && <Check className="mr-2 h-4 w-4" />}{" "}
              {status}
            </Button>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  );
});

const ShowDetails: FC<{
  showDetails: ClinicDate | null;
  setShowDetails: (show: boolean) => void;
}> = React.memo(({ showDetails, setShowDetails }) => {
  return (
    <Dialog open={!!showDetails} onOpenChange={() => setShowDetails(false)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Clinic Date Details</DialogTitle>
          <DialogDescription className="sr-only">
            Here are the details of the clinic date you selected.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-0.5 text-sm">
          <div>
            <span className="font-medium">Hospital:</span>{" "}
            {showDetails?.clinic?.hospital?.name || "—"}
          </div>
          <div>
            <span className="font-medium">Clinic:</span>{" "}
            {showDetails?.clinic?.name || "—"}
          </div>
          <div>
            <span className="font-medium">Date:</span>{" "}
            {showDetails?.date &&
              new Date(showDetails.date).toLocaleDateString()}
          </div>
          <div>
            <span className="font-medium">Start Time:</span>{" "}
            {showDetails?.start_time}
          </div>
          <div>
            <span className="font-medium">End Time:</span>{" "}
            {showDetails?.end_time}
          </div>
          <div>
            <span className="font-medium">Status:</span>{" "}
            <span className="capitalize">{showDetails?.status}</span>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
});
