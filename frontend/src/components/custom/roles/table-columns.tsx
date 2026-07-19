import type { Role } from "@/services/roles";
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
import moment from "moment";

const SYSTEM_ROLE_NAMES = [
  "super_admin",
  "hospital_admin",
  "doctor",
  "pharmacist",
  "receptionist",
  "patient",
] as const;

const isSystemRole = (roleName: string) =>
  SYSTEM_ROLE_NAMES.includes(
    roleName as (typeof SYSTEM_ROLE_NAMES)[number],
  );

export const roleColumns: ColumnDef<Role>[] = [
  {
    accessorKey: "id",
    header: "ID",
    cell: ({ row }) => <span>{row.getValue("id")}</span>,
  },
  {
    accessorKey: "name",
    header: "Name",
    enableHiding: false,
    cell: ({ row }) => (
      <div className="flex items-center gap-2">
        <span>{row.getValue("name")}</span>
        {isSystemRole(row.original.name) && (
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
            System role
          </span>
        )}
      </div>
    ),
  },
  {
    id: "created_at",
    header: "Created At",
    cell: ({ row }) => (
      <span className="capitalize">
        {moment
          .utc(row.original.created_at)
          .local()
          .format("YYYY-MM-DD HH:mm:ss")}
      </span>
    ),
  },
  {
    id: "updated_at",
    header: "Updated At",
    cell: ({ row }) => (
      <span className="capitalize">
        {moment
          .utc(row.original.updated_at)
          .local()
          .format("YYYY-MM-DD HH:mm:ss")}
      </span>
    ),
  },
  {
    id: "actions",
    enableHiding: false,
    cell: ({ table, row }) => {
      const meta = table.options.meta as {
        setOpen: (open: boolean) => void;
        setSelectedRole: (role: Role) => void;
        setShowDetails: (show: boolean) => void;
      };
      const { setOpen, setSelectedRole, setShowDetails } = meta;
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
            <PermissionWrapper permissions={[permissions.viewRoles]}>
              <DropdownMenuItem
                onClick={() => {
                  setShowDetails(true);
                  setSelectedRole(row.original);
                }}
              >
                View Details
              </DropdownMenuItem>
            </PermissionWrapper>
            {!isSystemRole(row.original.name) && (
              <PermissionWrapper permissions={[permissions.updateRoles]}>
                <DropdownMenuItem
                  onClick={() => {
                    setSelectedRole(row.original);
                    setOpen(true);
                  }}
                >
                  Edit Role
                </DropdownMenuItem>
              </PermissionWrapper>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      );
    },
  },
];
