import type { Clinic } from "@/services/clinics";
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

export const clinicColumns: ColumnDef<Clinic>[] = [
  {
    accessorKey: "name",
    header: "Clinic Name",
    enableHiding: false,
  },
  {
    id: "hospital",
    accessorFn: (clinic) => clinic.hospital?.name ?? "—",
    header: "Hospital",
  },
  {
    id: "doctor",
    accessorFn: (clinic) => clinic.doctor?.name ?? "—",
    header: "Doctor",
  },
  {
    accessorKey: "location",
    header: "Location",
  },
  {
    accessorKey: "total_hourly_tokens",
    header: "Total Tokens",
  },
  {
    accessorKey: "self_hourly_tokens",
    header: "Self Tokens",
  },
  {
    id: "actions",
    enableHiding: false,
    cell: ({ table, row }) => {
      const clinic = row.original;
      const meta = table.options.meta as {
        canManageClinics: boolean;
        onEdit: (clinic: Clinic) => void;
        onView: (clinic: Clinic) => void;
      };
      const hospitalName = clinic.hospital?.name ?? "unknown hospital";

      return (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="h-8 w-8 p-0">
              <span className="sr-only">
                Open actions for {clinic.name} at {hospitalName}
              </span>
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Actions</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => meta.onView(clinic)}>
              View Details
            </DropdownMenuItem>
            {meta.canManageClinics && (
              <DropdownMenuItem onClick={() => meta.onEdit(clinic)}>
                Edit Clinic
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      );
    },
  },
];
