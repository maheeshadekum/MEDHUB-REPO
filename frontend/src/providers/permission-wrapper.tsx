import type { FC } from "react";

import { useAuth } from "@/hooks/use-auth";
import { NotFoundPage } from "@/pages";

export const PermissionWrapper: FC<{
  children: React.ReactNode;
  permissions: string[] | null;
  roles?: string[];
  is404?: boolean;
}> = ({ children, permissions, roles, is404 = false }) => {
  const { user } = useAuth();

  const hasPermission =
    !permissions || user?.permissions?.some((perm) => permissions.includes(perm));
  const hasRole = !roles || (user?.role && roles.includes(user.role));

  if (!user || !hasPermission || !hasRole) {
    if (is404) {
      return <NotFoundPage />;
    }
    return null;
  } else {
    // If the user has the required permissions, render the children
    return <>{children}</>;
  }
};
