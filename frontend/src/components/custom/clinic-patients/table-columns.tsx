import type { ClinicPatient } from "@/services/clinic-patients";
import type { ColumnDef } from "@tanstack/react-table";

import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui";
import { MoreHorizontal } from "lucide-react";

export const clinicPatientColumns: ColumnDef<ClinicPatient>[] = [
  {
    accessorKey: "patient.name",
    header: "Patient Name",
    cell: ({ row }) => <span>{row.original.patient?.name}</span>,
  },
  {
    accessorKey: "patient.nic",
    header: "NIC",
    cell: ({ row }) => <span>{row.original.patient?.nic}</span>,
  },
  {
    accessorKey: "clinic.name",
    header: "Clinic Name",
    cell: ({ row }) => <span>{row.original.clinic?.name}</span>,
  },
  {
    accessorKey: "clinic.hospital.name",
    header: "Hospital",
    cell: ({ row }) => <span>{row.original.clinic?.hospital?.name}</span>,
  },
  {
    accessorKey: "created_at",
    header: "Added Date",
    cell: ({ row }) => (
      <span>
        {row.original.created_at
          ? new Date(row.original.created_at).toLocaleDateString()
          : "-"}
      </span>
    ),
  },
  {
    id: "actions",
    cell: ({ table, row }) => {
      const meta = table.options.meta as {
        setSelectedClinicPatient: (clinicPatient: ClinicPatient) => void;
        setOpen: (open: boolean) => void;
        handleDelete: (clinicPatient: ClinicPatient) => void;
        setShowMoreInfo: (show: boolean) => void;
        canManageClinicPatients: boolean;
      };
      const {
        setSelectedClinicPatient,
        setOpen,
        handleDelete,
        setShowMoreInfo,
        canManageClinicPatients,
      } = meta;
      const patientName = row.original.patient?.name ?? "patient";
      const clinicName = row.original.clinic?.name ?? "clinic";
      return (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="h-8 w-8 p-0">
              <span className="sr-only">
                Open actions for {patientName} in {clinicName}
              </span>
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Actions</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onClick={() => {
                setSelectedClinicPatient(row.original);
                setShowMoreInfo(true);
              }}
            >
              View Details
            </DropdownMenuItem>
            {canManageClinicPatients && (
              <>
                <DropdownMenuItem
                  onClick={() => {
                    setSelectedClinicPatient(row.original);
                    setOpen(true);
                  }}
                >
                  Edit Assignment
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => handleDelete(row.original)}
                  className="text-red-600 focus:text-red-600"
                >
                  Remove from Clinic
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      );
    },
  },
];
