# Claude Project Instructions

This is a Laravel 13, PHP 8.4, Vue 3, Tailwind 4 application. Follow the project rules in `AGENTS.md` as the source of truth for architecture, coding style, Laravel Boost usage, and verification.

## Required Tests

- Every coding change must include or update both backend and frontend tests.
- Backend behavior must be covered with PHPUnit tests and verified with `php artisan test --compact` using the narrowest relevant file or filter.
- Frontend behavior must be covered with Vitest tests and verified with `npm run test:frontend`.
- If a coding change genuinely has no backend or no frontend behavioral surface, explicitly state why that side has no applicable test before finalizing.
- Do not remove existing tests without explicit approval.

## Automatic Claude Verification

- This repository has a Claude Code `Stop` hook in `.claude/settings.json`.
- When Claude edits PHP, Vue, JavaScript, CSS, package files, or migrations, the hook runs the necessary migrations, formatting, tests, and frontend build before Claude can finish.
- If the hook blocks completion, Claude must fix the reported failure and let the hook pass before telling the user the work is complete.
- Blank-screen risks are treated as frontend failures: Vue changes require Vitest coverage and a successful `npm run build`.
- When the user asks to remove a visible line from a page, remove the complete rendered UI row, including all adjacent value/badge text and now-unused reactive state or timers. Do not remove only one child element if the quoted text spans multiple elements.
- For localhost page edits, verify the actual route response after the change. If `npm run dev` is running, `public/hot` must exist and point at the Vite server with the same browser host family the user is opening, normally `http://localhost:5173`; otherwise Laravel may serve stale assets or the browser may block cross-origin Vite modules and show a blank screen.
- Never start Vite with a raw `vite` command in this workspace. Always use `npm run dev`, which stops stale local Vite processes on port `5173` before starting the dev server.
