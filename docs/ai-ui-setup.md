# AI UI Setup

This workspace is a Laravel, Vue, and Vuetify project. AI-assisted UI work should use the installed stack and the project design guide in `resources/docs/ui-design-system.md`.

## Current Project Inventory

- Laravel: `13.11.2`
- PHP: `^8.3`
- Laravel Boost: installed as `laravel/boost 2.4.8`
- Vue: `3.5.34`
- Vuetify: installed as `4.0.7`
- Vite: `8.0.14`
- Laravel Vite plugin: `3.1.0`
- Vuetify Vite plugin: `2.1.3`
- Primary public route: `/`, rendered by `resources/views/homepage.blade.php`
- Admin Vue route shell: `resources/views/app.blade.php`, mounted from `resources/js/app.js`
- Public homepage Vue entry: `resources/js/homepage.js`
- Shared Vuetify theme factory: `resources/js/plugins/vuetify.js`

Note: the user-facing project direction refers to Vuetify 3, but the installed package is currently `vuetify@4.0.7`. Agents should use the installed Vuetify API and verify version-specific component props before making large UI changes.

## Laravel Boost MCP

Laravel Boost is already installed and available in this project.

Workspace configuration:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp"]
```

This is present in `.codex/config.toml`. Codex can use Boost through the configured MCP server when the workspace is trusted and the Codex client loads project MCP configuration.

If Boost ever needs to be reinstalled, run:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

## Vuetify MCP

No Vuetify MCP configuration was found in this workspace.

Official remote endpoint documented by Vuetify:

```text
https://mcp.vuetifyjs.com/mcp
```

Recommended interactive setup:

```bash
npx -y @vuetify/mcp config --remote
```

For Claude Code, Vuetify documents this command:

```bash
claude mcp add --transport http vuetify-mcp https://mcp.vuetifyjs.com/mcp
```

For Claude Desktop, use stdio because Claude Desktop does not accept direct HTTP transport in `claude_desktop_config.json`:

```json
{
    "mcpServers": {
        "vuetify-mcp": {
            "command": "npx",
            "args": ["-y", "@vuetify/mcp"]
        }
    }
}
```

For Codex CLI or the Codex VS Code extension, add the server through the Codex MCP command if your installed Codex version supports remote MCP:

```bash
codex mcp add vuetify-mcp --url https://mcp.vuetifyjs.com/mcp
```

If your Codex version expects JSON-style MCP server configuration, use the hosted URL form:

```json
{
    "mcpServers": {
        "vuetify-mcp": {
            "url": "https://mcp.vuetifyjs.com/mcp"
        }
    }
}
```

Then restart Codex and confirm the server appears in the MCP/server list. Do not add a fake workspace config entry if the local Codex version does not support remote HTTP MCP servers.

## Recommended Future MCP Servers

### Figma MCP

Use Figma MCP when translating real designs, design tokens, components, or selected frames into Vue/Vuetify implementation work.

Figma's preferred remote MCP endpoint:

```text
https://mcp.figma.com/mcp
```

Claude Code can install Figma's official plugin:

```bash
claude plugin install figma@claude-plugins-official
```

For Codex, use the official Codex MCP add flow for remote MCP servers when available:

```bash
codex mcp add figma --url https://mcp.figma.com/mcp
```

Authentication is handled by Figma during setup.

### Playwright MCP

Use Playwright MCP for browser feedback loops, screenshots, accessibility checks, and interaction testing after UI changes.

Typical Codex/Claude MCP command:

```bash
npx @playwright/mcp@latest
```

If your client supports adding stdio MCP servers from the command line, register it as a Playwright/browser server and restart the client.

### Chrome DevTools MCP

Use Chrome DevTools MCP when live browser debugging, performance traces, console errors, network inspection, or DOM inspection are more important than scripted end-to-end test flows.

Register the official server according to your client:

```bash
npx chrome-devtools-mcp@latest
```

For Chrome-backed workflows, start Chrome with a remote debugging port only when required by your MCP server configuration.

## Agent Workflow Expectations

- Read `resources/docs/ui-design-system.md` before generating UI.
- Keep Vuetify as the primary UI system.
- Prefer existing theme tokens from `resources/js/plugins/vuetify.js`.
- Use Laravel Boost `search-docs` before Laravel, Vite, or framework-specific changes.
- Run `npm run build` after frontend changes.
- Use a browser MCP or Playwright/DevTools MCP for visual QA when available.
