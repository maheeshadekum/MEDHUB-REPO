import { HospitalSettings, Layout, Loader } from "@/components/custom";
import { Button } from "@/components/ui";
import { permissions } from "@/constants/permissions";
import { useAuth } from "@/hooks/use-auth";
import { PermissionWrapper } from "@/providers/permission-wrapper";
import { PrivateRoute } from "@/providers/private-route";
import { Suspense } from "react";
import { Link, useParams } from "react-router";

const breadcrumb = [
  {
    title: "Home",
    url: "/",
  },
  {
    title: "Settings",
  },
];

export const HospitalSettingsPage = () => {
  const { user } = useAuth();
  const { hospitalId } = useParams();
  const routeHospitalId = Number(hospitalId);
  const hasRouteHospitalId = hospitalId !== undefined;
  const hasValidRouteHospitalId =
    Number.isInteger(routeHospitalId) && routeHospitalId > 0;
  const hasInvalidRouteHospitalId =
    hasRouteHospitalId && !hasValidRouteHospitalId;
  const isSuperAdmin = user?.role === "super_admin";
  const isHospitalAdmin = user?.role === "hospital_admin";

  const selectedHospitalId = isSuperAdmin
    ? hasValidRouteHospitalId
      ? routeHospitalId
      : null
    : isHospitalAdmin
      ? user?.hospital_id || null
      : null;

  const hospitalAdminRouteMismatch =
    isHospitalAdmin &&
    hasValidRouteHospitalId &&
    routeHospitalId !== user?.hospital_id;

  return (
    <PrivateRoute>
      <PermissionWrapper
        permissions={[permissions.manageHospitals]}
        roles={["super_admin", "hospital_admin"]}
        is404
      >
        <Suspense fallback={<Loader />}>
          <Layout breadcrumbs={breadcrumb}>
            {hasInvalidRouteHospitalId ? (
              <div className="w-full rounded-md border p-6 text-center text-red-600">
                Hospital not found.
              </div>
            ) : hospitalAdminRouteMismatch ? (
              <div className="w-full rounded-md border p-6 text-center">
                <p className="text-red-600">
                  You cannot manage settings for another hospital.
                </p>
              </div>
            ) : selectedHospitalId ? (
              <HospitalSettings hospitalId={selectedHospitalId} />
            ) : (
              <div className="w-full rounded-md border p-6 text-center">
                <p className="text-gray-600">
                  {isSuperAdmin
                    ? "Select a hospital from the Hospitals page to configure its settings."
                    : "No hospital is assigned to your account."}
                </p>
                {isSuperAdmin && (
                  <Button asChild className="mt-4">
                    <Link to="/hospitals">Choose Hospital</Link>
                  </Button>
                )}
              </div>
            )}
          </Layout>
        </Suspense>
      </PermissionWrapper>
    </PrivateRoute>
  );
};
