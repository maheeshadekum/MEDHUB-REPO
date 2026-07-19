import type { Clinic } from "@/services/clinics";
import type {
  ColumnDef,
  SortingState,
  VisibilityState,
} from "@tanstack/react-table";

import { Pagination } from "@/components/custom";
import { TableState } from "@/components/custom/table-state";
import {
  Button,
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
  Input,
  Label,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowUpDown } from "lucide-react";
import { useState } from "react";

interface ClinicTableProps {
  columns: ColumnDef<Clinic>[];
  data: Clinic[];
  search: string;
  setSearch: (search: string) => void;
  pagination: {
    currentPage: number;
    pageSize: number;
    from: number;
    to: number;
    total: number;
    endPage: number;
  };
  setPagination: (pagination: { currentPage: number; pageSize: number }) => void;
  canManageClinics: boolean;
  isPending: boolean;
  isError: boolean;
  hasFilters: boolean;
  onRetry: () => void;
  onResetFilters: () => void;
  onCreate: () => void;
  onEdit: (clinic: Clinic) => void;
  onView: (clinic: Clinic) => void;
  children?: React.ReactNode;
}

export function ClinicTable({
  columns,
  data,
  search,
  setSearch,
  pagination,
  setPagination,
  canManageClinics,
  isPending,
  isError,
  hasFilters,
  onRetry,
  onResetFilters,
  onCreate,
  onEdit,
  onView,
  children,
}: ClinicTableProps) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({});
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    onSortingChange: setSorting,
    getSortedRowModel: getSortedRowModel(),
    onColumnVisibilityChange: setColumnVisibility,
    state: { sorting, columnVisibility },
    meta: { canManageClinics, onEdit, onView },
  });
  const colSpan = table.getVisibleLeafColumns().length;

  return (
    <div className="w-full">
      <div className="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div className="w-full lg:max-w-xl">
          <Label htmlFor="clinic-search" className="sr-only">
            Search clinics
          </Label>
          <Input
            id="clinic-search"
            placeholder="Search by clinic, hospital, doctor, description, or location"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="w-full !ring-0"
          />
        </div>
        <div className="flex w-full flex-wrap justify-between gap-2 lg:w-auto lg:justify-end">
          {children}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="w-32">
                Columns <ArrowUpDown className="ml-2 h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {table
                .getAllColumns()
                .filter((column) => column.getCanHide())
                .map((column) => (
                  <DropdownMenuCheckboxItem
                    key={column.id}
                    className="capitalize"
                    checked={column.getIsVisible()}
                    onCheckedChange={(value) => column.toggleVisibility(!!value)}
                  >
                    {column.id.replace(/_/g, " ")}
                  </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <div className="w-full overflow-x-auto rounded-md border border-gray-200">
        <Table className="min-w-[900px]">
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <TableHead key={header.id} className="bg-gray-100">
                    {header.isPlaceholder
                      ? null
                      : flexRender(header.column.columnDef.header, header.getContext())}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isPending ? (
              <TableState
                state="loading"
                colSpan={colSpan}
                title="Loading clinics..."
              />
            ) : isError ? (
              <TableState
                state="error"
                colSpan={colSpan}
                title="Unable to load clinics."
                description="Check your connection or access and try again."
                action={<Button onClick={onRetry}>Retry</Button>}
              />
            ) : table.getRowModel().rows.length ? (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : hasFilters ? (
              <TableState
                state="filtered-empty"
                colSpan={colSpan}
                title="No clinics match the current search or filters."
                action={<Button onClick={onResetFilters}>Reset Filters</Button>}
              />
            ) : (
              <TableState
                state="empty"
                colSpan={colSpan}
                title="No clinics have been added yet."
                action={
                  canManageClinics ? (
                    <Button onClick={onCreate}>Create Clinic</Button>
                  ) : undefined
                }
              />
            )}
          </TableBody>
        </Table>
      </div>

      {!isPending && !isError && data.length > 0 && pagination.endPage > 0 && (
        <div className="my-4 w-full">
          <Pagination pagination={pagination} setPagination={setPagination} />
        </div>
      )}
    </div>
  );
}
