# SimpLinkX / MedHub Repository Guide

## 1. Project Overview

SimpLinkX/MedHub is a hospital management system containing separate React frontend and Laravel backend applications.

The system handles sensitive patient, appointment, prescription, report, and medicine-inventory information. Accuracy, access control, hospital isolation, clinical-data protection, and data integrity are critical.

Use the following source priority when information conflicts:

1. The user’s current explicit instruction
2. Client-confirmed requirements supplied by the user
3. Approved repository documentation
4. Current code as evidence of existing implementation
5. Older reports and audit findings as supporting references

Current code confirms what the system currently does, but it does not automatically define the intended business rule.

When the sources conflict and the priority order does not resolve the intended behavior, stop and ask the user to provide or confirm the client-approved business rule.

## 2. Repository Structure

- `frontend/` — React frontend application
- `backend/` — Laravel backend and API
- `docs/` — project documentation

Important frontend locations:

- `src/pages`
- `src/components`
- `src/hooks`
- `src/services`
- `src/providers`
- `src/constants`
- `src/types`
- `src/validations`

Important backend locations:

- `app/Http/Controllers`
- `app/Http/Middleware`
- `app/Models`
- `app/Mail`
- `app/Policies`
- `database/migrations`
- `database/seeders`
- `routes/api.php`
- `tests`

The backend currently has no separate service or repository layer. Most business logic is implemented directly in controllers.

## 3. Confirmed Technology Stack

Frontend:

- React 19
- TypeScript
- Vite
- React Router
- TanStack Query
- Axios
- React Hook Form
- Zod
- Tailwind CSS
- Radix UI

Backend:

- PHP 8.2 or later
- Laravel 12
- Laravel Sanctum
- MySQL
- PHPUnit
- Scramble API documentation

## 4. Local Development

Backend:

    cd backend
    php artisan serve --host=127.0.0.1 --port=8001

Frontend:

    cd frontend
    npm run dev

Read-only route inspection:

    cd backend
    php artisan route:list

Backend tests:

    cd backend
    php artisan test

Frontend checks:

    cd frontend
    npm run lint
    npm run build

The frontend development server is configured for port 3000.

The current local backend convention is port 8001. The frontend API URL must come from environment configuration. Verify the environment before changing or hardcoding an API URL. The existing code fallback may use port 8000.

## 5. Prohibited or Approval-Required Commands

Do not run the following without explicit user approval:

- `php artisan migrate`
- `php artisan migrate:fresh`
- `php artisan migrate:rollback`
- `php artisan db:seed`
- Any database reset command
- Destructive SQL
- `composer update`
- `npm update`
- Commands that delete or regenerate lock files
- Commands that clear or replace client data
- Commands that reset Git changes
- `git clean`
- `git reset --hard`
- `git checkout` when used to discard changes

Never call the web-based `/api/cli` endpoints during normal development or testing without explicit approval and a disposable environment. These endpoints may execute migrations, seeders, rollbacks, storage-link operations, or cache and configuration commands.

## 6. Existing Work Protection and Git Operations

- Run `git status` before every task.
- Treat all existing modified and untracked files as user-owned work.
- Ask for approval before editing a file that already contains uncommitted changes.
- Never revert, overwrite, reformat, or discard unrelated user changes.
- Never reformat an entire file for a small change.
- Do not create, delete, rename, switch, merge, or rebase branches without explicit user approval.
- Do not commit or push changes without explicit user approval.
- Do not amend commits.
- Do not stage unrelated files.
- Do not discard any user changes.
- After completing approved work, inspect `git diff` and summarize only the changes made for that task.

## 7. User Roles

Confirmed roles:

- `super_admin`
- `hospital_admin`
- `doctor`
- `pharmacist`
- `receptionist`
- `patient`

Permissions are dynamic, although some controllers also contain hardcoded role checks.

Frontend visibility is not a security boundary. Every protected operation must be authorized by the backend. Never assume that hiding a page or button prevents direct API access.

## 8. Authentication Rules

Authentication uses Laravel Sanctum personal access tokens. The frontend sends the token as a Bearer token.

Review authentication changes across all relevant areas:

- `AuthController`
- Axios configuration
- Cookie and token handling
- `/api/user`
- `PrivateRoute`
- Permission wrappers
- Logout
- Token expiration
- User status

Never change authentication behavior based only on frontend behavior. Login, refresh, Remember Me, logout, token lifetime, and banned or retired user behavior must be tested together.

Never expose tokens, passwords, `APP_KEY`, or environment secrets. Do not print or include `.env` contents in output.

## 9. Authorization and Hospital Isolation

- Patient, prescription, appointment, report, clinic, and inventory endpoints require backend authorization.
- Hospital-scoped staff must not modify another hospital’s records unless a client-confirmed rule explicitly permits it.
- Treat read authorization and write authorization separately.
- National or shared patient visibility does not automatically grant national write access.
- Destructive operations require explicit ownership or hospital checks.
- Never treat an ID supplied by the frontend as proof of ownership.
- Patient self-service operations must derive the patient identity from the authenticated account instead of trusting a submitted `patient_id`.
- Any Super Admin exception must be explicit and documented.
- When authorization changes, inspect route middleware, controller checks, models, and frontend access together.

## 10. Core Functional Areas

- Authentication and account management
- Hospital management
- Staff management
- Roles and permissions
- Patient registration
- Patient profiles
- Clinic management
- Clinic-patient enrollment
- Clinic dates
- OPD dates
- OPD appointments
- Clinic appointments
- Token generation
- Prescriptions
- Reports
- Medicine prescribing
- Pharmacist dispensing
- Inventory and batches
- Medicine availability
- Rajya Osusala outlets
- Email notifications
- Hospital settings
- NCD risk calculator

## 11. Appointment and Token Safety Rules

- Do not trust a submitted patient ID for patient self-booking.
- Treat capacity checking and token creation as one atomic operation.
- Do not rely on a count-plus-one token number without adequate uniqueness protection.
- Rescheduling capacity calculations must exclude the appointment being rescheduled.
- Check appointment ownership and hospital ownership for view, update, and delete operations.
- Validate dates and times using complete calendar dates, not weekday numbers.
- OPD and clinic capacity must use the applicable stored configuration rather than unrelated hardcoded values.
- Consider concurrent requests whenever booking behavior changes.
- Do not change cancellation or token-number reuse behavior without asking the user to provide or confirm the client-approved business rule.

## 12. Prescription and Report Safety Rules

- Scope prescription access using the authorized patient, doctor, hospital, and role rules.
- Update and delete operations require stronger authorization checks than general visibility.
- Prescription status changes must follow an explicit state transition.
- A dispensed prescription must not be dispensed repeatedly.
- Attach reports only to an authorized prescription.
- Knowing a prescription or report ID must not be sufficient to modify records across hospitals.
- Do not change the national or shared medical-record model without asking the user to provide or confirm the client-approved business rule.

## 13. Inventory and Medicine Safety Rules

- Do not treat expired batches as usable stock.
- Scope stock operations to the applicable hospital.
- Consider concurrency during every stock update.
- Keep inventory totals and batch totals consistent.
- Do not allow inventory to be oversold.
- Use appropriate transactions and locking for multi-record stock operations.
- Do not decide whether stock is reserved during prescribing or deducted during dispensing until the user provides or confirms the client-approved workflow.
- Do not implement partial dispensing, substitution, or reservation reversal without confirmed requirements.
- Public visibility of exact stock quantities remains an unresolved business requirement.

## 14. Frontend and Backend Consistency

Before changing a form, inspect its:

- Zod schema
- Component
- Hook
- Service
- API route
- Controller validation
- Related database fields

Frontend validation must match backend validation. Frontend permission wrappers must correspond to backend route permissions.

Check actual API response shapes before changing frontend types. Do not fix only the frontend when the underlying backend rule is incorrect, or only the backend when the frontend contract must also change.

Avoid hardcoded roles, hospital IDs, capacities, dates, URLs, and status values.

## 15. Database Safety

- Inspect migrations, foreign keys, indexes, and model relationships before changing persistence logic.
- Before proposing a new migration, inspect the current database schema and migration history.
- Do not modify an old migration that may already have run in client environments unless explicitly approved.
- Prefer a new migration for an approved schema change.
- Do not run a migration until explicitly approved.
- Consider composite uniqueness, hospital ownership, cascade deletion, clinical-record retention, and audit requirements.
- Do not introduce destructive cascades for patient or prescription history without client-approved requirements supplied by the user.
- Do not delete client data to make a test pass.
- Never edit production or client data directly to repair application logic.
- Any data-correction script requires separate approval, backup planning, and rollback planning.

## 16. Testing Requirements

Before calling a task complete:

- Run the smallest relevant backend tests.
- Run frontend lint and build checks when frontend files change.
- Add or propose feature tests for security-sensitive backend changes.
- Test authorized and unauthorized roles.
- Test same-hospital and cross-hospital scenarios.
- Test valid and invalid ownership.
- Test direct API access, not only frontend button visibility.
- Test normal, boundary, and failure cases.
- For booking or inventory concurrency changes, state whether concurrency testing was performed.
- Do not change an Actual Result or mark a test as passed without executing it.
- Clearly distinguish automated testing, manual testing, and code inspection.
- If a test cannot be run, state that it was not run.
- Do not claim that a security issue is fixed without testing unauthorized direct API access.
- Never report an issue as fixed only because the application compiles.

Existing automated coverage is limited. New tests may be necessary, especially for authorization, hospital isolation, booking, prescription, and inventory changes.

## 17. Modification Workflow

For every future change:

1. Restate the reported issue.
2. Identify the affected role and workflow.
3. Inspect the relevant frontend and backend files.
4. Inspect related migrations and models.
5. State the current behavior.
6. State the intended behavior.
7. Identify requirement ambiguities.
8. Ask the user to provide or confirm the client-approved business rule when required.
9. Before modifying code, provide the affected file list, current behavior, intended behavior, risks, and proposed change plan.
10. Wait for user approval before editing unless the user explicitly authorizes direct implementation.
11. Make the smallest safe change.
12. Run the relevant tests and checks.
13. Inspect and summarize the Git diff.
14. Report remaining risks and untested areas.

## 18. Requirement Ambiguities

The following questions remain unresolved and must not be converted into assumed business rules:

- Are patient records globally shared, hospital-owned, or shared read-only?
- Which roles may access records belonging to another hospital?
- Is medicine stock reserved during prescribing or deducted only when dispensed?
- Does cancelling a prescription restore inventory?
- Is partial dispensing required?
- Can cancelled token numbers be reused?
- May public users see exact medicine quantities?
- Are hospital feature toggles display-only, or must they block backend APIs?
- Which approved clinical model must the NCD calculator use?
- What audit and clinical-record retention rules are required?
- Are the `/api/cli` endpoints operationally required?
- Which timezone governs appointments?

Before implementing logic that depends on these questions, ask the user to provide or confirm the client-approved business rule.

## 19. Security-Sensitive Areas

The following areas require extra review:

- Authentication and cookies
- User status and token revocation
- Role and permission assignment
- Cross-hospital access
- Patient ownership
- Appointment creation and rescheduling
- Prescription modification
- Medical report uploads
- Pharmacist dispensing
- Inventory and medicine batches
- Server-side URL requests
- Web-accessible Artisan commands
- Environment values and application keys

## 20. Communication Style

- Use clear and simple explanations.
- Do not hide uncertainty.
- Distinguish confirmed code behavior from assumptions and client requirements.
- Refer to exact file paths and methods when reporting technical findings.
- Explain potential data impact before making a change.
- Ask one focused question requesting the client-approved business rule when requirements are unclear.
- Do not make unrelated improvements during a targeted fix.
