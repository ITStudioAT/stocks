# STK

Stocks is a Laravel SPA foundation for creating and editing homepages.

## Stack

- Laravel 13 with Laravel AI SDK
- Vue 3, Vuetify, and Pinia
- Laravel Sanctum
- Spatie Laravel Permission
- MySQL locally through Laragon
- Mailpit for local login-code emails

## Admin Login

The admin login screen is available at:

```text
/admin/login
```

The protected administrator identity is configured with a real
`SUPER_ADMIN_EMAIL`, plus `SUPER_ADMIN_FIRST_NAME` and `SUPER_ADMIN_LAST_NAME`.
For example:

```text
Name: Administrator Protected
Email: admin@your-domain.at
Roles: admin, super_admin
```

Login uses a 6-digit email code. In local development, Mailpit receives the code at:

```text
http://127.0.0.1:8025
```

## Local Setup

```bash
composer install
npm install
php artisan migrate --seed
npm run dev
php artisan serve --host=127.0.0.1 --port=8000
```

The local database defaults are defined in `.env.example`:

```text
DB_DATABASE=stocks
DB_USERNAME=root
DB_PASSWORD=
```
