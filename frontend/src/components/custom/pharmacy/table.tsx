import type { Pharmacy } from "@/services/pharmacy";
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
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
  Input,
  Label,
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
import { districts } from "@/constants/districts";
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowUpDown } from "lucide-react";
import { useState } from "react";

interface PharmacyTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  searchInput: string;
  district: string;
  isPending: boolean;
  isError: boolean;
  isFetching: boolean;
  setSearchInput: (search: string) => void;
  resetFilters: () => void;
  retry: () => void;
  setOpen: (open: boolean) => void;
  setDistrict: (district: string) => void;
  setShowDetails: (show: boolean) => void;
  setSelectedPharmacy: (pharmacy: Pharmacy) => void;
  setPagination: ({
    currentPage,
    pageSize,
  }: {
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

export function PharmacyTable<TData, TValue>({
  columns,
  data,
  searchInput,
  district,
  isPending,
  isError,
  isFetching,
  setDistrict,
  setOpen,
  setSearchInput,
  resetFilters,
  retry,
  setSelectedPharmacy,
  setShowDetails,
  setPagination,
  pagination,
  children,
}: PharmacyTableProps<TData, TValue>) {
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
    state: {
      sorting,
      columnFilters,
      columnVisibility,
    },
    meta: {
      setOpen,
      setSelectedPharmacy,
      setShowDetails,
    },
  });
  const visibleColumnCount = table.getVisibleLeafColumns().length;
  const hasActiveFilters = searchInput.trim().length > 0 || district !== "";
  const hasRows = table.getRowModel().rows.length > 0;
  const showTrueEmpty = !isPending && !isError && !hasRows && !hasActiveFilters;
  const showFilteredEmpty =
    !isPending && !isError && !hasRows && hasActiveFilters;
  const showPagination = !isPending && !isError && pagination.total > 0;

  return (
    <div className="w-full">
      <div className="mb-5 flex flex-col items-center justify-center gap-3 sm:flex-row sm:justify-between">
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center">
          <div className="w-full sm:max-w-sm">
            <Label htmlFor="outlet-search" className="sr-only">
              Search outlets by name
            </Label>
            <Input
              id="outlet-search"
              type="search"
              aria-label="Search outlets by name"
              placeholder="Search outlets by name"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              className="w-full !ring-0"
            />
          </div>

          <div>
            <Label htmlFor="outlet-district" className="sr-only">
              Filter outlets by district
            </Label>
            <Select
              value={district === "" ? undefined : district}
              onValueChange={(value) =>
                setDistrict(value === "default" ? "" : value)
              }
            >
              <SelectTrigger
                className="w-full cursor-pointer sm:w-40"
                id="outlet-district"
                aria-label="Filter outlets by district"
              >
                <SelectValue placeholder="Select District" />
              </SelectTrigger>
              <SelectContent side="top" className="max-h-60 overflow-y-auto">
                <SelectItem value="default">All Districts</SelectItem>
                {districts.map((district) => (
                  <SelectItem
                    key={district}
                    value={district}
                    className="capitalize"
                  >
                    {district}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <div className="flex gap-2 w-full justify-between sm:w-fit">
          {!showTrueEmpty && children}
          <DropdownMenu>
            <DropdownMenuTrigger asChild className="w-32 h-8">
              <Button variant="outline" className="">
                Columns <ArrowUpDown className="ml-2 h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {table
                .getAllColumns()
                .filter((column) => column.getCanHide())
                .map((column) => {
                  return (
                    <DropdownMenuCheckboxItem
                      key={column.id}
                      className="capitalize"
                      checked={column.getIsVisible()}
                      onCheckedChange={(value) =>
                        column.toggleVisibility(!!value)
                      }
                    >
                      {column.id.replace(/_/g, " ")}
                    </DropdownMenuCheckboxItem>
                  );
                })}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {isFetching && !isPending && !isError && (
        <p className="sr-only" role="status" aria-live="polite">
          Refreshing Rajya Osusala outlets.
        </p>
      )}

      <div className="w-full overflow-x-auto rounded-md border border-gray-200">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => {
                  return (
                    <TableHead key={header.id} className="bg-gray-100">
                      {header.isPlaceholder
                        ? null
                        : flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                          )}
                    </TableHead>
                  );
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isPending ? (
              <TableState
                state="loading"
                colSpan={visibleColumnCount}
                title="Loading Rajya Osusala outlets..."
              />
            ) : isError ? (
              <TableState
                state="error"
                colSpan={visibleColumnCount}
                title="Unable to load Rajya Osusala outlets."
                description="Please try again."
                action={
                  <Button type="button" variant="outline" onClick={retry}>
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
                      {flexRender(
                        cell.column.columnDef.cell,
                        cell.getContext(),
                      )}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : showFilteredEmpty ? (
              <TableState
                state="filtered-empty"
                colSpan={visibleColumnCount}
                title="No outlets match the current search or filters."
                action={
                  <Button
                    type="button"
                    variant="outline"
                    onClick={resetFilters}
                  >
                    Reset Filters
                  </Button>
                }
              />
            ) : (
              <TableState
                state="empty"
                colSpan={visibleColumnCount}
                title="No Rajya Osusala outlets have been added yet."
                description="Add an outlet to begin managing locations."
                action={showTrueEmpty ? children : undefined}
              />
            )}
          </TableBody>
        </Table>
      </div>

      {/* pagination */}
      {showPagination && (
        <div className="my-4 w-full">
          <Pagination
            setPagination={setPagination}
            pagination={{
              currentPage: pagination.currentPage,
              pageSize: pagination.pageSize,
              from: pagination?.from || 0,
              to: pagination?.to || 0,
              total: pagination?.total || 0,
              endPage: pagination?.endPage || 0,
            }}
          />
        </div>
      )}
    </div>
  );
}
