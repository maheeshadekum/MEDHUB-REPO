# Setup Diagnosis

This project contains a Laravel API in `backend` and a React frontend in `frontend`.

## Backend

- Laravel is required as `laravel/framework ^12.0`; `composer.lock` resolves it to Laravel `v12.31.1`.
- PHP requirement is `^8.2`.
- Sanctum is installed and API authentication uses bearer tokens stored in an `auth_token` cookie.
- `.env.example` is configured for MySQL but uses placeholder values.
- The example database name is `laravel`, but local setup should use `medhub_simplinkx`.
- `QUEUE_CONNECTION=database` and `CACHE_STORE=database` can block a fresh local setup when the required queue/cache tables are not present. Local setup should use `QUEUE_CONNECTION=sync`.
- `ACCESS_TOKEN_EXPIRATION_TIME_REMEMBER` in `.env.example` does not match the code, which reads `REMEMBER_ME_EXPIRATION_TIME`.
- Seeders exist for roles, permissions, role permissions, and one super admin user.
- Seeded default login found: `superadmin@simplinkx.com` / `abcd1234`.

## Routes And API Compatibility

- Frontend calls `GET /pharmacies/single/{id}`, but backend defines `GET /pharmacies/{pharmacy}`.
- Frontend calls `GET /roles/{id}` and `DELETE /roles/{id}`, but backend only defines list, create, and update routes for roles.
- Frontend calls `DELETE /users/{id}`, but backend does not define a delete route.
- Some user routes use route parameters named `{id}` while controller methods type-hint `User $user`, which prevents implicit model binding from working reliably.

## Frontend

- The frontend uses Vite, not Create React App.
- React version is `^19.1.0`.
- Vite version is `^6.3.5`.
- The frontend has `VITE_API_URL` and `VITE_STORAGE_URL` in `.env.example`.
- API base URL currently falls back to `http://localhost:8000/api`.
- Local setup should use `http://127.0.0.1:8000/api` to match the requested Laravel serve host.

## Local Artifact Policy

Do not commit `.env`, `node_modules`, `vendor`, storage logs, database dumps, or generated build output.
