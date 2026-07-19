import type { Role } from "@/services/roles";
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

interface RoleTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  searchInput: string;
  isPending: boolean;
  isFetching: boolean;
  isError: boolean;
  setSearchInput: (search: string) => void;
  clearSearch: () => void;
  retry: () => void;
  setOpen: (open: boolean) => void;
  setShowDetails: (show: boolean) => void;
  setSelectedRole: (role: Role) => void;
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

export function RoleTable<TData, TValue>({
  columns,
  data,
  searchInput,
  isPending,
  isFetching,
  isError,
  setSearchInput,
  clearSearch,
  retry,
  setOpen,
  setSelectedRole,
  setShowDetails,
  setPagination,
  pagination,
  children,
}: RoleTableProps<TData, TValue>) {
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
      setSelectedRole,
      setShowDetails,
    },
  });
  const visibleColumnCount = table.getVisibleLeafColumns().length;
  const hasActiveSearch = searchInput.trim().length > 0;
  const hasRows = table.getRowModel().rows.length > 0;
  const showTrueEmpty = !isPending && !isError && !hasRows && !hasActiveSearch;
  const showFilteredEmpty =
    !isPending && !isError && !hasRows && hasActiveSearch;
  const showPagination = !isPending && !isError && pagination.total > 0;

  return (
    <div className="w-full">
      <div className="mb-5 flex flex-col items-center justify-center gap-3 sm:flex-row sm:justify-between">
        <div className="w-full sm:max-w-sm">
          <Label htmlFor="role-search" className="sr-only">
            Search roles by name
          </Label>
          <Input
            id="role-search"
            type="search"
            aria-label="Search roles by name"
            placeholder="Search roles by name"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            className="w-full !ring-0"
          />
        </div>

        <div className="flex gap-2 w-full justify-between sm:w-fit">
          {!showTrueEmpty && children}
          <DropdownMenu>
            <DropdownMenuTrigger asChild className="w-32 h-8">
              <Button variant="outline">
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
          Refreshing roles.
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
                title="Loading roles..."
              />
            ) : isError ? (
              <TableState
                state="error"
                colSpan={visibleColumnCount}
                title="Unable to load roles."
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
                title="No roles match the current search."
                action={
                  <Button
                    type="button"
                    variant="outline"
                    onClick={clearSearch}
                  >
                    Clear Search
                  </Button>
                }
              />
            ) : (
              <TableState
                state="empty"
                colSpan={visibleColumnCount}
                title="No roles have been added yet."
                description="Add a role to begin managing access groups."
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
