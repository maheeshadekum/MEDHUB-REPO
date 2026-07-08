# Local Login Credentials

These credentials are created by the existing seeders when running:

```bash
cd backend
php artisan migrate:fresh --seed
```

## Super Admin

- Email: `superadmin@simplinkx.com`
- Password: `abcd1234`
- Role: `super_admin`

## Other Roles

The seeders create the role records for:

- `hospital_admin`
- `doctor`
- `pharmacist`
- `receptionist`
- `patient`

The current seeders do not create login users for those roles. Use the super admin account to create additional users locally if a workflow needs role-specific testing.
