# Claude Project Instructions

This is a Laravel 13, PHP 8.3, Vue 3, Tailwind 4 application. Follow the project rules in `AGENTS.md` as the source of truth for architecture, coding style, Laravel Boost usage, and verification.

## Required Tests

- Every coding change must include or update both backend and frontend tests.
- Backend behavior must be covered with PHPUnit tests and verified with `php artisan test --compact` using the narrowest relevant file or filter.
- Frontend behavior must be covered with Vitest tests and verified with `npm run test:frontend`.
- If a coding change genuinely has no backend or no frontend behavioral surface, explicitly state why that side has no applicable test before finalizing.
- Do not remove existing tests without explicit approval.
