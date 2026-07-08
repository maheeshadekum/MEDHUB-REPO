# SimpLinkX / MedHub

SimpLinkX / MedHub is a final-year project with a Laravel API backend and a React frontend.

## Project Structure

```text
backend/   Laravel 12 API
frontend/  React 19 + Vite frontend
docs/      Local setup notes and diagnostics
```

## Requirements

- PHP `^8.2`
- Composer
- Node.js and npm
- MySQL or MariaDB
- A local database named `medhub_simplinkx`

This local setup was verified against XAMPP MariaDB on `127.0.0.1:3306`.

## Database Setup

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -uroot -h127.0.0.1 -P3306 \
  -e "CREATE DATABASE IF NOT EXISTS medhub_simplinkx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

If your MySQL client works normally, this is also fine:

```bash
mysql -uroot -h127.0.0.1 -P3306 \
  -e "CREATE DATABASE IF NOT EXISTS medhub_simplinkx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan config:clear
php artisan cache:clear
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Local backend `.env` values:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=medhub_simplinkx
DB_USERNAME=root
DB_PASSWORD=
MAIL_MAILER=log
QUEUE_CONNECTION=sync
FRONTEND_URL=http://127.0.0.1:3000
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:3000,localhost:3000,127.0.0.1:8000,localhost:8000
ACCESS_TOKEN_EXPIRATION_TIME=10800
REMEMBER_ME_EXPIRATION_TIME=2592000
```

## Frontend Setup

```bash
cd frontend
npm install
cp .env.example .env
npm run dev -- --host 127.0.0.1
```

Local frontend `.env` values:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
VITE_STORAGE_URL=http://127.0.0.1:8000/storage
```

## Run Commands

Backend:

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend:

```bash
cd frontend
npm run dev -- --host 127.0.0.1
```

Open:

- Frontend: `http://127.0.0.1:3000`
- Backend API: `http://127.0.0.1:8000/api`

If the servers were started in detached `screen` sessions, stop them with:

```bash
screen -S medhub-backend -X quit
screen -S medhub-frontend -X quit
```

## Login Credentials

Seeded super admin:

- Email: `superadmin@simplinkx.com`
- Password: `abcd1234`

More detail is in `docs/local-login-credentials.md`.

## Troubleshooting

- If `mysql` fails with a `mysql_native_password` plugin error on macOS, use XAMPP's bundled client:
  `/Applications/XAMPP/xamppfiles/bin/mysql`
- If the frontend cannot call the backend, confirm the frontend URL is `http://127.0.0.1:3000` or `http://localhost:3000`; both are allowed by backend CORS.
- If config changes do not apply, run:
  `php artisan config:clear && php artisan cache:clear`
- If ports are already in use, stop the old process or run Laravel/Vite on different ports and update env URLs.

## Remaining Known Issues

- Only the super admin login is seeded by default. Other roles exist, but users for those roles must be created through the app.
- Fresh seed data does not include hospitals, pharmacies, inventory, appointments, or prescriptions, so many pages load with empty tables.
- The frontend production build warns that the main JS chunk is larger than 500 kB; this is not blocking local development.
