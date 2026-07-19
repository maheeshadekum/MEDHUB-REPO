import type { Pharmacy } from "@/services/pharmacy";
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
import { permissions } from "@/constants/permissions";
import { PermissionWrapper } from "@/providers/permission-wrapper";
import { MoreHorizontal } from "lucide-react";

export const pharmacyColumns: ColumnDef<Pharmacy>[] = [
  {
    accessorKey: "name",
    header: "Name",
    enableHiding: false,
    cell: ({ row }) => <span>{row.getValue("name")}</span>,
  },
  {
    accessorKey: "address",
    header: "Address",
    cell: ({ row }) => <span>{row.getValue("address")}</span>,
  },
  {
    accessorKey: "phone",
    header: "Phone",
    cell: ({ row }) => <span>{row.getValue("phone")}</span>,
  },
  {
    accessorKey: "email",
    header: "Email",
    cell: ({ row }) => <span>{row.getValue("email")}</span>,
  },
  {
    accessorKey: "district",
    header: "District",
    cell: ({ row }) => <span>{row.getValue("district")}</span>,
  },
  {
    accessorKey: "location_url",
    header: "Location",
    cell: ({ row }) => (
      <a
        href={row.getValue("location_url")}
        target="_blank"
        rel="noopener noreferrer"
        className="text-blue-600 underline"
      >
        Map
      </a>
    ),
  },
  {
    id: "actions",
    enableHiding: false,
    cell: ({ table, row }) => {
      const meta = table.options.meta as {
        setOpen: (open: boolean) => void;
        setSelectedPharmacy: (pharmacy: Pharmacy) => void;
        setShowDetails: (show: boolean) => void;
      };
      const { setOpen, setSelectedPharmacy, setShowDetails } = meta;
      return (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              className="h-8 w-8 p-0"
              aria-label={`Open actions for ${row.original.name}`}
            >
              <span className="sr-only">
                Open actions for {row.original.name}
              </span>
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Actions</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <PermissionWrapper permissions={[permissions.managePharmacy]}>
              <DropdownMenuItem
                onClick={() => {
                  setShowDetails(true);
                  setSelectedPharmacy(row.original);
                }}
              >
                View Details
              </DropdownMenuItem>
            </PermissionWrapper>
            <PermissionWrapper permissions={[permissions.managePharmacy]}>
              <DropdownMenuItem
                onClick={() => {
                  setSelectedPharmacy(row.original);
                  setOpen(true);
                }}
              >
                Edit Outlet
              </DropdownMenuItem>
            </PermissionWrapper>
          </DropdownMenuContent>
        </DropdownMenu>
      );
    },
  },
];
