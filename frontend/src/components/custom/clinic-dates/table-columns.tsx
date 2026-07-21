import type { ClinicDate } from "@/services/clinic-dates";
import type { ColumnDef } from "@tanstack/react-table";

import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui";
import { MoreHorizontal } from "lucide-react";

export const formatClinicDate = (value?: string) => {
  if (!value) return "N/A";
  const parts = value.slice(0, 10).split("-").map(Number);
  if (parts.length !== 3 || parts.some(Number.isNaN)) return value;
  return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString();
};

const statusVariant = (status: string) => {
  if (status === "completed") return "secondary" as const;
  if (status === "cancelled") return "destructive" as const;
  return "default" as const;
};

export const clinicDateColumns: ColumnDef<ClinicDate>[] = [
  {
    accessorKey: "clinic.name",
    header: "Clinic",
    enableHiding: false,
    cell: ({ row }) => row.original.clinic?.name ?? "N/A",
  },
  {
    accessorKey: "clinic.hospital.name",
    header: "Hospital",
    cell: ({ row }) => row.original.clinic?.hospital?.name ?? "N/A",
  },
  {
    accessorKey: "clinic.doctor.name",
    header: "Doctor",
    cell: ({ row }) => row.original.clinic?.doctor?.name ?? "N/A",
  },
  {
    accessorKey: "date",
    header: "Date",
    cell: ({ row }) => formatClinicDate(row.original.date),
  },
  { accessorKey: "start_time", header: "Start Time" },
  { accessorKey: "end_time", header: "End Time" },
  {
    accessorKey: "status",
    header: "Status",
    cell: ({ row }) => (
      <Badge variant={statusVariant(row.original.status)} className="capitalize">
        {row.original.status}
      </Badge>
    ),
  },
  {
    id: "actions",
    enableHiding: false,
    cell: ({ table, row }) => {
      const meta = table.options.meta as {
        viewDetails: (clinicDate: ClinicDate) => void;
        editClinicDate: (clinicDate: ClinicDate) => void;
        updateStatus: (clinicDate: ClinicDate) => void;
        canManageClinicDates: boolean;
      };
      const clinic = row.original.clinic?.name ?? "clinic";
      return (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="h-8 w-8 p-0">
              <span className="sr-only">
                Open actions for {clinic} on {row.original.date}
              </span>
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Actions</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => meta.viewDetails(row.original)}>
              View Details
            </DropdownMenuItem>
            {meta.canManageClinicDates && (
              <>
                <DropdownMenuItem onClick={() => meta.editClinicDate(row.original)}>
                  Edit Clinic Date
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => meta.updateStatus(row.original)}>
                  Update Status
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      );
    },
  },
];
