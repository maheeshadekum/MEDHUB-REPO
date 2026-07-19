import type { ClinicPatient } from "@/services/clinic-patients";
import type { FC } from "react";

import { ClinicPatientDialog } from "@/components/custom/clinic-patients/clinic-patient-dialog";
import { ClinicPatientTable } from "@/components/custom/clinic-patients/table";
import { clinicPatientColumns } from "@/components/custom/clinic-patients/table-columns";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui";
import { permissions } from "@/constants/permissions";
import {
  useClinicPatientClinicOptions,
  useClinicPatientHospitalOption,
  useClinicPatientHospitalOptions,
  useDebouncedValue,
} from "@/hooks/use-clinic-patient-options";
import { useAuth } from "@/hooks/use-auth";
import {
  useClinicPatients,
  useDeleteClinicPatient,
} from "@/hooks/use-clinic-patients";
import { Plus } from "lucide-react";
import React, { useEffect, useMemo, useState } from "react";
import { PiSpinnerGapBold } from "react-icons/pi";
import { toast } from "sonner";

const MUTATION_ROLES = ["super_admin", "hospital_admin", "receptionist"];

const getSafeDeleteError = (error: unknown) => {
  if (!error || typeof error !== "object" || !("response" in error)) {
    return "Unable to remove this clinic assignment. Please try again.";
  }

  const message = (
    error as { response?: { data?: { message?: string } } }
  ).response?.data?.message;

  if (
    message &&
    (message.toLowerCase().includes("authorized") ||
      message.toLowerCase().includes("hospital") ||
      message.toLowerCase().includes("remove"))
  ) {
    return message;
  }

  return "Unable to remove this clinic assignment. Please try again.";
};

export const ClinicPatients: FC = React.memo(() => {
  const { user } = useAuth();
  const role = user?.role ?? "";
  const isSuperAdmin = role === "super_admin";
  const isPatient = role === "patient";
  const canManageClinicPatients = Boolean(
    user?.permissions?.includes(permissions.manageClinicPatients) &&
      MUTATION_ROLES.includes(role),
  );
  const hasManagementContext =
    isSuperAdmin || Boolean(user?.hospital_id && user.hospital_id > 0);
  const canUseManagement = canManageClinicPatients && hasManagementContext;
  const showClinicFilter = MUTATION_ROLES.includes(role) && !isPatient;

  const [open, setOpen] = useState(false);
  const [showMoreInfo, setShowMoreInfo] = useState(false);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [clinicFilter, setClinicFilter] = useState<number | undefined>();
  const [hospitalFilter, setHospitalFilter] = useState<number | undefined>();
  const [hospitalFilterSearch, setHospitalFilterSearch] = useState("");
  const [showDeleteAlert, setShowDeleteAlert] = useState(false);
  const [deletingAssignment, setDeletingAssignment] =
    useState<ClinicPatient | null>(null);
  const [deleteError, setDeleteError] = useState("");
  const [pagination, setPagination] = useState({
    currentPage: 1,
    pageSize: 20,
  });
  const [selectedClinicPatient, setSelectedClinicPatient] =
    useState<ClinicPatient | null>(null);

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedSearch(search), 300);
    return () => window.clearTimeout(timeout);
  }, [search]);

  const listQuery = useClinicPatients({
    currentPage: pagination.currentPage,
    pageSize: pagination.pageSize,
    search: debouncedSearch || undefined,
    clinic_id: clinicFilter,
  });

  const debouncedHospitalFilterSearch = useDebouncedValue(
    hospitalFilterSearch,
  );
  const hospitalOptionsQuery = useClinicPatientHospitalOptions(
    debouncedHospitalFilterSearch,
    isSuperAdmin && showClinicFilter,
  );
  const selectedFilterHospitalQuery = useClinicPatientHospitalOption(
    hospitalFilter ?? 0,
    isSuperAdmin && Boolean(hospitalFilter),
  );
  const filterHospitalId = isSuperAdmin
    ? (hospitalFilter ?? 0)
    : (user?.hospital_id ?? 0);
  const clinicOptionsQuery = useClinicPatientClinicOptions(
    filterHospitalId,
    showClinicFilter && filterHospitalId > 0,
  );

  const hospitalOptions = useMemo(() => {
    const hospitals = [...(hospitalOptionsQuery.data ?? [])];
    const selectedHospital = selectedFilterHospitalQuery.data;

    if (
      selectedHospital &&
      !hospitals.some((hospital) => hospital.id === selectedHospital.id)
    ) {
      hospitals.unshift(selectedHospital);
    }

    return hospitals.map((hospital) => ({
      label: [hospital.name, hospital.district, hospital.identifier]
        .filter(Boolean)
        .join(" — "),
      value: hospital.id.toString(),
    }));
  }, [hospitalOptionsQuery.data, selectedFilterHospitalQuery.data]);

  const clinicOptions = useMemo(
    () =>
      (clinicOptionsQuery.data ?? []).map((clinic) => ({
        label: clinic.location
          ? `${clinic.name} — ${clinic.location}`
          : clinic.name,
        value: clinic.id.toString(),
      })),
    [clinicOptionsQuery.data],
  );

  const { mutateAsync: deleteClinicPatient, isPending: isDeleting } =
    useDeleteClinicPatient();

  const handleHospitalFilterChange = (hospitalId: number | undefined) => {
    setHospitalFilter(hospitalId);
    setClinicFilter(undefined);
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };

  const handleClinicFilterChange = (clinicId: number | undefined) => {
    setClinicFilter(clinicId);
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };

  const resetFilters = () => {
    setSearch("");
    setDebouncedSearch("");
    setClinicFilter(undefined);
    setHospitalFilter(undefined);
    setHospitalFilterSearch("");
    setPagination((current) => ({ ...current, currentPage: 1 }));
  };

  const handleDelete = (assignment: ClinicPatient) => {
    setDeletingAssignment(assignment);
    setDeleteError("");
    setShowDeleteAlert(true);
  };

  const closeDeleteDialog = () => {
    if (isDeleting) return;
    setShowDeleteAlert(false);
    setDeletingAssignment(null);
    setDeleteError("");
  };

  const confirmDelete = async () => {
    if (!deletingAssignment?.id || isDeleting) return;

    setDeleteError("");
    const alert = toast.loading("Removing patient from clinic...");

    try {
      await deleteClinicPatient(deletingAssignment.id);
      toast.success("Patient removed from clinic successfully", { id: alert });
      setShowDeleteAlert(false);
      setDeletingAssignment(null);
    } catch (error: unknown) {
      const message = getSafeDeleteError(error);
      setDeleteError(message);
      toast.error(message, { id: alert });
    }
  };

  const closeDialog = () => {
    setSelectedClinicPatient(null);
    setOpen(false);
  };

  useEffect(() => {
    setPagination((current) => ({ ...current, currentPage: 1 }));
  }, [debouncedSearch]);

  useEffect(() => {
    const endPage = listQuery.data?.endPage ?? 0;
    if (listQuery.isFetching || pagination.currentPage <= 1) return;

    const lastAvailablePage = Math.max(endPage, 1);
    if (pagination.currentPage > lastAvailablePage) {
      setPagination((current) => ({
        ...current,
        currentPage: lastAvailablePage,
      }));
    }
  }, [listQuery.data?.endPage, listQuery.isFetching, pagination.currentPage]);

  const isFiltered = Boolean(
    search || clinicFilter || (isSuperAdmin && hospitalFilter),
  );
  const data = listQuery.data;

  const addButton = canManageClinicPatients ? (
    <Button
      variant="default"
      size="sm"
      disabled={!hasManagementContext}
      onClick={() => {
        setSelectedClinicPatient(null);
        setOpen(true);
      }}
    >
      <Plus className="mr-2 h-4 w-4" />
      Add Patient to Clinic
    </Button>
  ) : null;

  return (
    <div className="flex w-full flex-col">
      <div>
        <h2 className="text-lg font-semibold">Clinic Patients</h2>
        <p className="text-sm text-gray-500">
          Manage patient assignments to clinics
        </p>
        {canManageClinicPatients && !hasManagementContext && (
          <p role="alert" className="mt-2 text-sm font-medium text-destructive">
            A hospital assignment is required to manage clinic patients.
          </p>
        )}
      </div>

      {open && canUseManagement && (
        <ClinicPatientDialog
          open={open}
          onOpenChange={closeDialog}
          clinicPatient={selectedClinicPatient || undefined}
        />
      )}

      {showMoreInfo && selectedClinicPatient && (
        <MoreInfo
          clinicPatient={selectedClinicPatient}
          onOpenChange={setShowMoreInfo}
          open={showMoreInfo}
        />
      )}

      <AlertDialog
        open={showDeleteAlert}
        onOpenChange={(nextOpen) => !nextOpen && closeDeleteDialog()}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Patient from Clinic?</AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="space-y-2">
                <p>
                  This removes {deletingAssignment?.patient?.name ?? "the patient"}{" "}
                  from {deletingAssignment?.clinic?.name ?? "the clinic"}. Existing
                  appointments and clinical records are not deleted.
                </p>
                <p>Future clinic access may be affected.</p>
              </div>
            </AlertDialogDescription>
            {deleteError && (
              <p role="alert" className="text-sm font-medium text-destructive">
                {deleteError}
              </p>
            )}
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={closeDeleteDialog} disabled={isDeleting}>
              Cancel
            </AlertDialogCancel>
            <Button
              type="button"
              onClick={confirmDelete}
              disabled={isDeleting}
              className="bg-red-600 text-white hover:bg-red-700"
            >
              {isDeleting && (
                <PiSpinnerGapBold className="mr-2 h-4 w-4 animate-spin" />
              )}
              Remove from Clinic
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <div className="mt-4 flex w-full justify-center">
        <ClinicPatientTable
          columns={clinicPatientColumns}
          data={data?.clinicPatients || []}
          search={search}
          setSearch={setSearch}
          clinicFilter={clinicFilter}
          setClinicFilter={handleClinicFilterChange}
          clinicOptions={clinicOptions}
          showClinicFilter={showClinicFilter}
          hospitalFilter={hospitalFilter}
          setHospitalFilter={
            isSuperAdmin && showClinicFilter
              ? handleHospitalFilterChange
              : undefined
          }
          hospitalOptions={hospitalOptions}
          hospitalSearch={hospitalFilterSearch}
          setHospitalSearch={
            isSuperAdmin && showClinicFilter
              ? setHospitalFilterSearch
              : undefined
          }
          isHospitalOptionsLoading={hospitalOptionsQuery.isPending}
          setSelectedClinicPatient={setSelectedClinicPatient}
          setOpen={setOpen}
          setShowMoreInfo={setShowMoreInfo}
          handleDelete={handleDelete}
          canManageClinicPatients={canUseManagement}
          isPending={listQuery.isPending && !data}
          isFetching={listQuery.isFetching}
          isError={listQuery.isError}
          isFiltered={isFiltered}
          refetch={() => void listQuery.refetch()}
          resetFilters={resetFilters}
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
          {addButton}
        </ClinicPatientTable>
      </div>
    </div>
  );
});

const MoreInfo: FC<{
  clinicPatient: ClinicPatient;
  onOpenChange: (open: boolean) => void;
  open: boolean;
}> = React.memo(({ clinicPatient, onOpenChange, open }) => (
  <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>
          Clinic Patient Details — {clinicPatient.patient?.name}
        </DialogTitle>
        <DialogDescription>
          View the Patient, Clinic, Hospital, and assignment date.
        </DialogDescription>
      </DialogHeader>

      <dl className="grid gap-3 text-sm sm:grid-cols-[auto_1fr]">
        <dt className="font-medium">Patient Name</dt>
        <dd>{clinicPatient.patient?.name ?? "N/A"}</dd>
        <dt className="font-medium">NIC</dt>
        <dd>{clinicPatient.patient?.nic ?? "N/A"}</dd>
        <dt className="font-medium">Clinic</dt>
        <dd>{clinicPatient.clinic?.name ?? "N/A"}</dd>
        <dt className="font-medium">Hospital</dt>
        <dd>{clinicPatient.clinic?.hospital?.name ?? "N/A"}</dd>
        <dt className="font-medium">Added Date</dt>
        <dd>
          {clinicPatient.created_at
            ? new Date(clinicPatient.created_at).toLocaleDateString()
            : "N/A"}
        </dd>
      </dl>
    </DialogContent>
  </Dialog>
));
