import type { ReactNode } from "react";

import { Skeleton, TableCell, TableRow } from "@/components/ui";

type TableStateKind = "loading" | "error" | "empty" | "filtered-empty";

export const TableState = ({
  state,
  colSpan,
  title,
  description,
  action,
}: {
  state: TableStateKind;
  colSpan: number;
  title: string;
  description?: string;
  action?: ReactNode;
}) => (
  <TableRow>
    <TableCell colSpan={colSpan} className="h-48 text-center">
      <div
        className="mx-auto flex max-w-md flex-col items-center justify-center gap-2"
        role={state === "error" ? "alert" : "status"}
        aria-live={state === "error" ? "assertive" : "polite"}
      >
        {state === "loading" && (
          <div className="w-full space-y-3" aria-hidden="true">
            <Skeleton className="mx-auto h-4 w-3/4" />
            <Skeleton className="mx-auto h-4 w-1/2" />
            <Skeleton className="mx-auto h-8 w-28" />
          </div>
        )}
        <p className="font-medium">{title}</p>
        {description && (
          <p className="text-sm text-muted-foreground">{description}</p>
        )}
        {action && <div className="mt-2">{action}</div>}
      </div>
    </TableCell>
  </TableRow>
);
