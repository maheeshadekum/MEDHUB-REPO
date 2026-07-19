import type { ClinicPatient } from "@/services/clinic-patients";
import type {
  ColumnDef,
  ColumnFiltersState,
  SortingState,
  VisibilityState,
} from "@tanstack/react-table";

import { Pagination } from "@/components/custom";
import { TableState } from "@/components/custom/table-state";
import {
  Button,
  Combobox,
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
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
  getFilteredRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowUpDown } from "lucide-react";
import { useState } from "react";

type Option = { label: string; value: string };

interface ClinicPatientTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  search: string;
  setSearch: (search: string) => void;
  clinicFilter: number | undefined;
  setClinicFilter: (clinicId: number | undefined) => void;
  clinicOptions: Option[];
  showClinicFilter: boolean;
  hospitalFilter?: number;
  setHospitalFilter?: (hospitalId: number | undefined) => void;
  hospitalOptions?: Option[];
  hospitalSearch?: string;
  setHospitalSearch?: (search: string) => void;
  isHospitalOptionsLoading?: boolean;
  setOpen: (open: boolean) => void;
  setShowMoreInfo: (show: boolean) => void;
  setSelectedClinicPatient: (clinicPatient: ClinicPatient) => void;
  handleDelete: (clinicPatient: ClinicPatient) => void;
  canManageClinicPatients: boolean;
  isPending: boolean;
  isFetching: boolean;
  isError: boolean;
  isFiltered: boolean;
  refetch: () => void;
  resetFilters: () => void;
  setPagination: (pagination: {
    currentPage: number;
    pageSize: number;
  }) => void;
  pagination: {
    currentPage: number;
    pageSize: number;
    from: number;
    to: number;
    total: number;
    endPage: number;
  };
  children?: React.ReactNode;
}

export function ClinicPatientTable<TData, TValue>({
  columns,
  data,
  search,
  setOpen,
  setShowMoreInfo,
  setSearch,
  clinicFilter,
  setClinicFilter,
  clinicOptions,
  showClinicFilter,
  hospitalFilter,
  setHospitalFilter,
  hospitalOptions = [],
  hospitalSearch = "",
  setHospitalSearch,
  isHospitalOptionsLoading = false,
  setSelectedClinicPatient,
  handleDelete,
  canManageClinicPatients,
  isPending,
  isFetching,
  isError,
  isFiltered,
  refetch,
  resetFilters,
  setPagination,
  pagination,
  children,
}: ClinicPatientTableProps<TData, TValue>) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({});

  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    onSortingChange: setSorting,
    getSortedRowModel: getSortedRowModel(),
    onColumnFiltersChange: setColumnFilters,
    getFilteredRowModel: getFilteredRowModel(),
    onColumnVisibilityChange: setColumnVisibility,
    state: { sorting, columnFilters, columnVisibility },
    meta: {
      setOpen,
      setSelectedClinicPatient,
      handleDelete,
      setShowMoreInfo,
      canManageClinicPatients,
    },
  });

  const colSpan = Math.max(table.getVisibleLeafColumns().length, 1);
  const hasRows = table.getRowModel().rows.length > 0;
  const showPagination = !isPending && !isError && data.length > 0;

  return (
    <div className="w-full">
      <div className="flex flex-col gap-3 py-4 xl:flex-row xl:items-end xl:justify-between">
        <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="w-full max-w-xl">
            <label htmlFor="clinic-patient-search" className="sr-only">
              Search clinic patient assignments
            </label>
            <Input
              id="clinic-patient-search"
              aria-label="Search clinic patient assignments"
              placeholder="Search by patient, NIC, phone, clinic, or hospital"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>

          {setHospitalFilter && setHospitalSearch && (
            <div className="w-full sm:w-64">
              <span className="sr-only">Filter by Hospital</span>
              <Combobox
                items={hospitalOptions}
                search={hospitalSearch}
                onChange={setHospitalSearch}
                isLoading={isHospitalOptionsLoading}
                placeholder="Hospital for Clinic filter"
                value={hospitalFilter?.toString() ?? ""}
                setValue={(value) =>
                  setHospitalFilter(value ? Number(value) : undefined)
                }
              />
            </div>
          )}

          {showClinicFilter && (
            <div className="w-full sm:w-56">
              <span className="sr-only">Filter by Clinic</span>
              <Select
                value={clinicFilter?.toString() || "all"}
                onValueChange={(value) =>
                  setClinicFilter(value === "all" ? undefined : Number(value))
                }
                disabled={Boolean(setHospitalFilter) && !hospitalFilter}
              >
                <SelectTrigger aria-label="Filter by Clinic">
                  <SelectValue placeholder="Filter by Clinic" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Clinics</SelectItem>
                  {clinicOptions.map((clinic) => (
                    <SelectItem key={clinic.value} value={clinic.value}>
                      {clinic.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {isFiltered && (
            <Button type="button" variant="ghost" size="sm" onClick={resetFilters}>
              Reset Filters
            </Button>
          )}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm">
                <ArrowUpDown className="mr-2 h-4 w-4" />
                Columns
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
                    onCheckedChange={(value) =>
                      column.toggleVisibility(Boolean(value))
                    }
                  >
                    {column.id}
                  </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
          </DropdownMenu>
          {children}
        </div>
      </div>

      {isFetching && !isPending && (
        <p role="status" aria-live="polite" className="pb-2 text-sm text-muted-foreground">
          Refreshing clinic patient assignments...
        </p>
      )}

      <div className="w-full overflow-x-auto rounded-md border">
        <Table className="min-w-[760px]">
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <TableHead key={header.id}>
                    {header.isPlaceholder
                      ? null
                      : flexRender(
                          header.column.columnDef.header,
                          header.getContext(),
                        )}
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
                title="Loading clinic patient assignments..."
              />
            ) : isError ? (
              <TableState
                state="error"
                colSpan={colSpan}
                title="Unable to load clinic patient assignments."
                description="Check your connection and try again."
                action={
                  <Button type="button" variant="outline" size="sm" onClick={refetch}>
                    Retry
                  </Button>
                }
              />
            ) : hasRows ? (
              table.getRowModel().rows.map((row) => (
                <TableRow
                  key={row.id}
                  data-state={row.getIsSelected() && "selected"}
                >
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : isFiltered ? (
              <TableState
                state="filtered-empty"
                colSpan={colSpan}
                title="No clinic patient assignments match the current search or filters."
                action={
                  <Button type="button" variant="outline" size="sm" onClick={resetFilters}>
                    Reset Filters
                  </Button>
                }
              />
            ) : (
              <TableState
                state="empty"
                colSpan={colSpan}
                title="No clinic patient assignments have been added yet."
                action={canManageClinicPatients ? children : undefined}
              />
            )}
          </TableBody>
        </Table>
      </div>

      {showPagination && (
        <div className="my-4 w-full">
          <Pagination setPagination={setPagination} pagination={pagination} />
        </div>
      )}
    </div>
  );
}
