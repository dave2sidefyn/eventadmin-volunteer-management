#!/usr/bin/env node
/**
 * EventAdmin MCP server — a thin wrapper around the EventAdmin – Volunteer Management
 * plugin's REST API (/wp-json/eventadmin/v1/), so an MCP client (Claude Desktop, Claude
 * Code, etc.) can list/create shifts and read dashboard KPIs directly.
 *
 * Requires:
 *   - The plugin's Settings → General → "Allow API access" turned on.
 *   - A WordPress Application Password for an Administrator or Shift Manager account
 *     (Users → your profile → Application Passwords).
 *
 * Configuration (environment variables):
 *   EVENTADMIN_SITE_URL     e.g. https://example.com  (no trailing slash)
 *   EVENTADMIN_USERNAME     the WordPress username
 *   EVENTADMIN_APP_PASSWORD the Application Password (spaces are fine, as WP shows it)
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const SITE_URL = process.env.EVENTADMIN_SITE_URL;
const USERNAME = process.env.EVENTADMIN_USERNAME;
const APP_PASSWORD = process.env.EVENTADMIN_APP_PASSWORD;

if (!SITE_URL || !USERNAME || !APP_PASSWORD) {
  console.error(
    'Missing configuration. Set EVENTADMIN_SITE_URL, EVENTADMIN_USERNAME and ' +
      'EVENTADMIN_APP_PASSWORD (see mcp-server/README.md).'
  );
  process.exit(1);
}

const AUTH_HEADER =
  'Basic ' + Buffer.from(`${USERNAME}:${APP_PASSWORD.replace(/\s+/g, '')}`).toString('base64');

/**
 * Calls one endpoint of the plugin's REST API and returns its parsed JSON body. Throws
 * with the API's own error message when the response isn't ok, so tool handlers can
 * surface a useful message back to the model instead of a bare HTTP status.
 */
async function apiRequest(method, path, body) {
  const url = `${SITE_URL.replace(/\/$/, '')}/wp-json/eventadmin/v1${path}`;
  const response = await fetch(url, {
    method,
    headers: {
      Authorization: AUTH_HEADER,
      'Content-Type': 'application/json',
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const message = data?.message || `HTTP ${response.status}`;
    throw new Error(`EventAdmin API error: ${message}`);
  }

  return data;
}

function toolResult(data) {
  return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
}

const server = new McpServer(
  {
    name: 'eventadmin-volunteer-management',
    version: '1.0.0',
  },
  {
    instructions:
      'Tools for the EventAdmin – Volunteer Management WordPress plugin: manage shifts, read the ' +
      'dashboard, and email a shift\'s roster. Before creating a shift in a department by name, call ' +
      'list_departments to resolve its numeric ID — a department with no shifts yet has no other way ' +
      'to be discovered. Before answering a question about how a specific EventAdmin feature or ' +
      'setting works, call get_documentation rather than guessing. get_settings reports the active ' +
      'sign-up rules (per-volunteer shift limits, overlap policy, cancellation deadline) — check it ' +
      'before assuming a shift-creation or cancellation request is unconstrained; it requires an ' +
      'Administrator-connected account.',
  }
);

server.tool(
  'list_shifts',
  'List EventAdmin shifts, optionally filtered by department (category slug), time window, or a specific date.',
  {
    category: z.string().optional().describe('Department (shift category) slug to filter by'),
    time_filter: z.enum(['future', 'past', 'all']).optional().describe('Defaults to "future"'),
    date: z.string().optional().describe('YYYY-MM-DD — shifts starting on this exact date'),
    page: z.number().int().min(1).optional(),
    per_page: z.number().int().min(1).max(100).optional(),
  },
  async (args) => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(args)) {
      if (value !== undefined && value !== '') params.set(key, String(value));
    }
    const query = params.toString() ? `?${params.toString()}` : '';
    return toolResult(await apiRequest('GET', `/shifts${query}`));
  }
);

server.tool(
  'get_shift',
  'Get the full details of a single EventAdmin shift by ID.',
  {
    id: z.number().int().describe('The shift (post) ID'),
  },
  async ({ id }) => toolResult(await apiRequest('GET', `/shifts/${id}`))
);

server.tool(
  'create_shift',
  'Create a new EventAdmin shift.',
  {
    title: z.string().describe('Shift title'),
    start: z.string().describe('Start date/time, e.g. "2026-10-01 09:00"'),
    end: z.string().describe('End date/time, e.g. "2026-10-01 11:00"'),
    description: z.string().optional(),
    category_id: z.number().int().optional().describe('Department (shift category) term ID — look it up with list_departments if you only have the department name'),
    min_volunteers: z.number().int().min(0).optional(),
    max_volunteers: z.number().int().min(1).optional(),
    organizer_name: z.string().optional(),
    organizer_email: z.string().email().optional(),
  },
  async (args) => toolResult(await apiRequest('POST', '/shifts', args))
);

server.tool(
  'get_dashboard',
  'Get the same KPI numbers shown on the EventAdmin Overview dashboard: total/open/filled shifts, volunteers without a shift, per-department counts, and the next upcoming shifts.',
  {},
  async () => toolResult(await apiRequest('GET', '/dashboard'))
);

server.tool(
  'notify_shift',
  "Email everyone assigned to a specific shift (its roster — see the assigned_volunteers field from list_shifts/get_shift for who that is). Requires the connected account to have volunteer-management access. {first_name} and {last_name} in the message are replaced per recipient; volunteers with no real email address (offline volunteers) are silently skipped and listed in the response's `skipped` field.",
  {
    id: z.number().int().describe('The shift (post) ID'),
    subject: z.string().describe('Email subject'),
    message: z.string().describe('Email body. Supports {first_name} and {last_name} placeholders.'),
  },
  async ({ id, subject, message }) =>
    toolResult(await apiRequest('POST', `/shifts/${id}/notify`, { subject, message }))
);

server.tool(
  'list_departments',
  'List every EventAdmin department (shift category): id, name, color, parent department, and whether it\'s hidden from volunteers. Use this to resolve a department name to the numeric category_id create_shift needs — especially important for a department that has no shifts yet, since there\'s otherwise no way to discover its ID.',
  {},
  async () => toolResult(await apiRequest('GET', '/departments'))
);

server.tool(
  'get_settings',
  'Get the EventAdmin rules that affect shift sign-up: max shifts per day/week/month/year per volunteer (0 = unlimited), whether overlapping shifts are allowed, and the cancellation deadline in hours before a shift starts. Requires an Administrator account (Shift Managers can\'t see these in wp-admin either).',
  {},
  async () => toolResult(await apiRequest('GET', '/settings'))
);

server.tool(
  'get_documentation',
  "Get the EventAdmin plugin's own documentation (the same content as its in-admin Documentation page), as plain text. Use this before answering a question about how a specific EventAdmin feature works, what a setting does, or how to set something up, instead of guessing.",
  {},
  async () => toolResult(await apiRequest('GET', '/documentation'))
);

const transport = new StdioServerTransport();
await server.connect(transport);
