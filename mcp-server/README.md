# EventAdmin MCP server

A small MCP server that wraps the EventAdmin – Volunteer Management plugin's REST API, so an
MCP client (Claude Desktop, Claude Code, etc.) can list/create shifts and read dashboard KPIs
directly, without going through wp-admin.

This is a companion tool distributed alongside the plugin's source on GitHub — it is **not**
part of the WordPress.org plugin package and does not need to be installed on the WordPress
site itself.

## Requirements

1. The plugin's **Settings → General → API access → "Allow API access"** checkbox turned on.
2. A WordPress **Application Password** for an Administrator or Shift Manager account:
   Users → your profile → "Application Passwords" → add a new one (e.g. named "MCP").
3. Node.js 18 or later.

## Setup

```bash
cd mcp-server
npm install
```

Set these environment variables (either in your shell, or in your MCP client's server config):

| Variable | Example | Notes |
|---|---|---|
| `EVENTADMIN_SITE_URL` | `https://example.com` | No trailing slash |
| `EVENTADMIN_USERNAME` | `admin` | The WordPress username, not the display name |
| `EVENTADMIN_APP_PASSWORD` | `abcd 1234 efgh 5678 ijkl 9012` | Paste exactly as WordPress shows it — spaces are stripped automatically |

## Using it with Claude Desktop

Published on npm, so there's nothing to clone or build — `npx` fetches it on first run. Open
**Settings → Developer → Edit Config** (this opens or creates `claude_desktop_config.json`). If
the file already lists other servers, add `"eventadmin"` alongside them inside the same
`"mcpServers"` section instead of replacing the file; otherwise paste the whole thing:

```json
{
  "mcpServers": {
    "eventadmin": {
      "command": "npx",
      "args": ["-y", "eventadmin-mcp-server"],
      "env": {
        "EVENTADMIN_SITE_URL": "https://example.com",
        "EVENTADMIN_USERNAME": "admin",
        "EVENTADMIN_APP_PASSWORD": "abcd 1234 efgh 5678 ijkl 9012"
      }
    }
  }
}
```

Save the file, then **fully quit and reopen** Claude Desktop (closing the window isn't enough).

## Using it with Claude Code

Run this in a terminal (one line), filling in your own site URL/username/password:

```bash
claude mcp add-json eventadmin '{"command":"npx","args":["-y","eventadmin-mcp-server"],"env":{"EVENTADMIN_SITE_URL":"https://example.com","EVENTADMIN_USERNAME":"admin","EVENTADMIN_APP_PASSWORD":"abcd 1234 efgh 5678 ijkl 9012"}}'
```

Then run `claude mcp list` to confirm it registered.

## Tools

- **list_shifts** — filter by department, time window (`future`/`past`/`all`), or an exact date. Each shift includes its `assigned_volunteers` (id + name).
- **get_shift** — full details of one shift by ID, including its roster.
- **create_shift** — create a new shift (title, start, end, department, capacity, organizer).
- **get_dashboard** — the same KPI numbers as the plugin's Overview dashboard.
- **notify_shift** — email everyone assigned to a shift (subject + message, with `{first_name}`/`{last_name}` placeholders). Volunteers with no real email address are skipped.

Most requests are subject to the same `eventadmin_manage_shifts` capability check as wp-admin
itself — the Application Password's account needs the Administrator or Shift Manager role.
`notify_shift` is the exception: like the plugin's "Send Announcement" feature, it only needs
`eventadmin_manage_volunteers`, so a Volunteer Manager account (no shift access at all) can
still use it.
