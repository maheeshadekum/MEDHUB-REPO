# Run Workflow

## What Was Fixed

- Initialized a root git repository on branch `setup/local-running-fix` because the project folder did not contain a `.git` directory.
- Added local artifact ignore rules for `.env`, `node_modules`, `vendor`, logs, dumps, and build output.
- Created `backend/.env` for local MySQL database `medhub_simplinkx`.
- Switched local queue execution to `QUEUE_CONNECTION=sync`.
- Standardized remember-me expiration on `REMEMBER_ME_EXPIRATION_TIME`.
- Added `http://127.0.0.1:3000` to backend CORS.
- Added the backend route used by the frontend: `GET /api/pharmacies/single/{pharmacy}`.
- Added `GET /api/roles/{role}` and `DELETE /api/roles/{role}`.
- Added `DELETE /api/users/{user}`.
- Fixed user route model binding by using `{user}` for routes that type-hint `User $user`.
- Updated super admin seeding so the `super_admin` role receives all defined permissions.
- Added safe inventory read fallbacks for a super admin with no assigned hospital.
- Updated the frontend env wiring to use `VITE_API_BASE_URL`.
- Created local setup and credential documentation.

## What Is Working

- `composer install` completes successfully.
- `php artisan key:generate` completes successfully.
- `php artisan migrate:fresh --seed` completes successfully.
- `php artisan route:list --path=api` includes the frontend-compatible routes.
- `npm install` completes successfully.
- `npm run build` completes successfully.
- Backend dev server runs at `http://127.0.0.1:8000`.
- Frontend dev server runs at `http://127.0.0.1:3000`.
- Servers are currently running in detached `screen` sessions named `medhub-backend` and `medhub-frontend`.
- Login succeeds with `superadmin@simplinkx.com` / `abcd1234`.
- Register endpoint returns `201`.
- Smoke-tested API endpoints return `200`:
  `/api/user`, `/api/hospitals`, `/api/users`, `/api/roles`, `/api/roles/1`,
  `/api/permissions`, `/api/roles/permissions`, `/api/pharmacies`,
  `/api/patients`, `/api/clinic-tokens`, `/api/opd-tokens`,
  `/api/prescriptions`, `/api/inventory`, `/api/inventories/near-expiry`,
  `/api/inventories/low-stock`.
- Temporary role and user create/delete checks returned `201/204`.
- Frontend routes return `200` from Vite:
  `/`, `/login`, `/register`, `/dashboard`, `/hospitals`, `/people`,
  `/roles`, `/permissions`, `/pharmacies`, `/patients`, `/appointments`,
  `/prescriptions`, `/inventories`.

## What Is Still Not Working

- No blocking local-run issue is currently known from HTTP smoke checks.
- End-to-end browser interaction was not automated; verification used build checks, API checks, and Vite route checks.
- Many pages are empty after seeding because the seeders do not create domain data such as hospitals, pharmacies, inventory, prescriptions, or appointments.

## Next Recommended Steps

- Add optional local/demo seed data for hospitals, staff users, patients, pharmacies, inventory, and appointments.
- Add a small browser smoke test with Playwright or Cypress for login and protected page navigation.
- Consider code-splitting the frontend to reduce the production bundle-size warning.
