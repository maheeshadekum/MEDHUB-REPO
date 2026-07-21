import type { ClinicDate } from "@/services/clinic-dates";
import type { ColumnDef, SortingState, VisibilityState } from "@tanstack/react-table";

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
import { flexRender, getCoreRowModel, getSortedRowModel, useReactTable } from "@tanstack/react-table";
import { ArrowUpDown } from "lucide-react";
import { useState } from "react";

type Option = { label: string; value: string };

type Props = {
  columns: ColumnDef<ClinicDate>[];
  data: ClinicDate[];
  search: string;
  setSearch: (value: string) => void;
  status?: string;
  setStatus: (value?: string) => void;
  clinicId?: number;
  setClinicId: (value?: number) => void;
  clinicOptions: Option[];
  showClinicFilter: boolean;
  hospitalId?: number;
  setHospitalId?: (value?: number) => void;
  hospitalOptions?: Option[];
  hospitalSearch?: string;
  setHospitalSearch?: (value: string) => void;
  hospitalOptionsLoading?: boolean;
  canManageClinicDates: boolean;
  hideFilters?: boolean;
  viewDetails: (value: ClinicDate) => void;
  editClinicDate: (value: ClinicDate) => void;
  updateStatus: (value: ClinicDate) => void;
  isPending: boolean;
  isFetching: boolean;
  isError: boolean;
  isFiltered: boolean;
  refetch: () => void;
  resetFilters: () => void;
  setPagination: (value: { currentPage: number; pageSize: number }) => void;
  pagination: { currentPage: number; pageSize: number; from: number; to: number; total: number; endPage: number };
  children?: React.ReactNode;
};

export function ClinicDateTable({
  columns, data, search, setSearch, status, setStatus, clinicId, setClinicId,
  clinicOptions, showClinicFilter, hospitalId, setHospitalId, hospitalOptions = [],
  hospitalSearch = "", setHospitalSearch, hospitalOptionsLoading = false,
  canManageClinicDates, hideFilters = false, viewDetails, editClinicDate, updateStatus, isPending,
  isFetching, isError, isFiltered, refetch, resetFilters, setPagination,
  pagination, children,
}: Props) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({});
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    onSortingChange: setSorting,
    onColumnVisibilityChange: setColumnVisibility,
    state: { sorting, columnVisibility },
    meta: { viewDetails, editClinicDate, updateStatus, canManageClinicDates },
  });
  const colSpan = Math.max(table.getVisibleLeafColumns().length, 1);
  const hasRows = table.getRowModel().rows.length > 0;
  const showPagination = !isPending && !isError && data.length > 0;

  return (
    <div className="w-full">
      <div className="flex flex-col gap-3 py-4 xl:flex-row xl:items-end xl:justify-between">
        {!hideFilters && <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="w-full max-w-xl">
            <label htmlFor="clinic-date-search" className="sr-only">Search Clinic Dates</label>
            <Input
              id="clinic-date-search"
              aria-label="Search Clinic Dates"
              placeholder="Search by Clinic, Hospital, Doctor or date"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
          <Select value={status ?? "all"} onValueChange={(value) => setStatus(value === "all" ? undefined : value)}>
            <SelectTrigger className="w-full sm:w-44" aria-label="Filter by status">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="scheduled">Scheduled</SelectItem>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>
          {setHospitalId && setHospitalSearch && (
            <div className="w-full sm:w-64">
              <span className="sr-only">Hospital context for Clinic filter</span>
              <Combobox
                items={hospitalOptions}
                search={hospitalSearch}
                onChange={setHospitalSearch}
                isLoading={hospitalOptionsLoading}
                placeholder="Hospital for Clinic filter"
                value={hospitalId?.toString() ?? ""}
                setValue={(value) => setHospitalId(value ? Number(value) : undefined)}
              />
            </div>
          )}
          {showClinicFilter && (
            <Select
              value={clinicId?.toString() ?? "all"}
              onValueChange={(value) => setClinicId(value === "all" ? undefined : Number(value))}
              disabled={Boolean(setHospitalId) && !hospitalId}
            >
              <SelectTrigger className="w-full sm:w-56" aria-label="Filter by Clinic">
                <SelectValue placeholder="All Clinics" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Clinics</SelectItem>
                {clinicOptions.map((clinic) => (
                  <SelectItem key={clinic.value} value={clinic.value}>{clinic.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>}
        <div className="flex flex-wrap items-center gap-2">
          {isFiltered && <Button type="button" variant="ghost" size="sm" onClick={resetFilters}>Reset Filters</Button>}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm"><ArrowUpDown className="mr-2 h-4 w-4" />Columns</Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {table.getAllColumns().filter((column) => column.getCanHide()).map((column) => (
                <DropdownMenuCheckboxItem
                  key={column.id}
                  checked={column.getIsVisible()}
                  onCheckedChange={(value) => column.toggleVisibility(Boolean(value))}
                  className="capitalize"
                >
                  {column.id.replace(/_/g, " ")}
                </DropdownMenuCheckboxItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
          {children}
        </div>
      </div>
      {isFetching && !isPending && <p role="status" aria-live="polite" className="pb-2 text-sm text-muted-foreground">Refreshing Clinic Dates...</p>}
      <div className="w-full overflow-x-auto rounded-md border">
        <Table className="min-w-[980px]">
          <TableHeader>{table.getHeaderGroups().map((group) => <TableRow key={group.id}>{group.headers.map((header) => <TableHead key={header.id}>{header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}</TableHead>)}</TableRow>)}</TableHeader>
          <TableBody>
            {isPending ? (
              <TableState state="loading" colSpan={colSpan} title="Loading Clinic Dates..." />
            ) : isError ? (
              <TableState state="error" colSpan={colSpan} title="Unable to load Clinic Dates." description="Check your access or connection and try again." action={<Button type="button" variant="outline" size="sm" onClick={refetch}>Retry</Button>} />
            ) : hasRows ? (
              table.getRowModel().rows.map((row) => <TableRow key={row.id}>{row.getVisibleCells().map((cell) => <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>)}</TableRow>)
            ) : isFiltered ? (
              <TableState state="filtered-empty" colSpan={colSpan} title="No Clinic Dates match the current search or filters." action={<Button type="button" variant="outline" size="sm" onClick={resetFilters}>Reset Filters</Button>} />
            ) : (
              <TableState state="empty" colSpan={colSpan} title="No Clinic Dates have been scheduled yet." action={canManageClinicDates ? children : undefined} />
            )}
          </TableBody>
        </Table>
      </div>
      {showPagination && <div className="my-4"><Pagination setPagination={setPagination} pagination={pagination} /></div>}
    </div>
  );
}
