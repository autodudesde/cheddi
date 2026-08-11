# TYPO3 + ChEddi

> 🧪 **Beta (`0.1.0`).** The feature set and the HTTP contract (routes, the `TurnResult`
> response envelope, the tool-severity classification) are stabilising, but smaller changes
> are still possible between minor versions while the extension matures. Pin a version and
> review the changelog before upgrading.

A conversational backend assistant for TYPO3's [AI Suite](https://www.autodudes.de/).
`cheddi` injects a resizable chat **drawer** into every TYPO3 backend page and lets
editors talk to the AI Suite tools in natural language: read pages, generate or translate
content, fill in metadata, generate images, all from the same MCP `ToolRegistry` that
[`ai_suite_mcp`](../ai-suite-mcp) exposes, but driven from inside the backend instead of an
external MCP client.

The model never mutates content silently: read-only tools run automatically, while every
write or destructive tool call is surfaced inline for explicit confirmation before it is
executed.

## What you can do with it

- 💬 **Chat from anywhere in the backend**: a floating bubble opens a drawer on top of the
  current module. The drawer is resizable and its size/open-state persist per browser.
- 🧰 **Drive AI Suite tools by talking**: the assistant calls the same MCP tools an
  external client would (page tree, content generation, translation, metadata, image
  generation, record CRUD), gated to exactly what the current BE user is allowed to do.
- ✅ **Confirm before anything changes**: read-only tools auto-run; write tools require a
  click; destructive tools require a second confirming click. Decisions are recorded inline
  in the conversation as an audit trail.
- 🧠 **Location-aware**: the drawer sends the current page id and module on every turn, so
  the assistant knows what the editor is looking at.
- 📎 **Attach documents**: PDF, Word, spreadsheets and plain text are uploaded into FAL and
  their *text* is pulled in on demand via the `readAttachmentText` tool. A metadata-only
  preflight tells the editor up front when a file cannot be read. **Images can be attached
  but not analysed**: messages reach the AI Suite Server as plain strings, so no image data
  ever reaches the model.
- 🚧 **Research the web** *(experimental)*: two separate capabilities behind the
  `enable_web_research` backend-group flag. `searchWeb` runs through the AI Suite Server on a
  US-hosted search provider and costs credits; `readWebPage` fetches one URL the editor named
  directly from this installation, with no AI provider in the path and no credits. Both feed
  their sources into the drawer so the editor can check the answer against them. See
  *Limitations* before enabling it.
- 🧾 **Review what changed**: when writes land in a draft workspace (the default), the
  actions menu lists exactly the records this conversation touched and offers Publish or
  Discard per record or for all of them.
- 🗂️ **Conversation history**: sessions are stored per BE user, auto-titled from the first
  message, listed in a panel, and can be reopened, switched or deleted.
- 💰 **Credit + token feedback**: remaining credits show in the header and turn amber below
  a threshold; the input locks when credits are exhausted.
- 🔒 **Markdown rendered safely**: assistant Markdown is rendered with `marked` and
  sanitised with `DOMPurify` (both vendored locally) before it ever touches `innerHTML`.
- 🧹 **Self-cleaning**: a scheduler command soft-deletes idle sessions and hard-deletes
  them after a grace period.

## Limitations & accepted trade-offs

These are deliberate design decisions, not bugs, and worth knowing before you deploy:

- **Chat always runs on the AI Suite Server's keys, and there is no bring-your-own-key path for
  the chat.** Every turn is billed against the configured AI Suite credentials/credits; you
  cannot point the chat at your own provider key. Remaining credits show in the header and the
  input locks when they run out.
- **Images can be attached but not analysed.** Messages travel to the server as plain strings,
  so no image content ever reaches the model. Documents (PDF, Word, spreadsheets, plain text)
  *are* read, as text, on demand.
- **Write reversibility comes from the workspace draft, not from the chat.** With the default
  `workspace` write mode a confirmed change lands in a draft you can publish or discard from the
  drawer; in `live` mode a confirmed change is immediate. Note that the underlying `uploadMedia`
  and `generateImage` tools write to live in every mode (see the `ai_suite_mcp` README).
- **Web research is experimental.** 🚧 It is off by default (`enable_web_research`) and its
  behaviour and configuration may change between minor versions; treat it as a preview, not as
  a stable interface. Three consequences worth knowing before you switch it on:
  - **`searchWeb` sends the editor's query out of the installation**, to a US-hosted search
    provider by way of the AI Suite Server, and it costs credits. `chatForceGdpa` blocks it
    outright for that reason. `readWebPage` is unaffected by that block: it is an HTTP GET from
    your server to a page the editor already named, which is the same request they could make
    with a browser.
  - **Which provider is used is a server-side decision**, not a setting in this extension. On a
    bring-your-own-key plan the search authenticates with your own key for whichever provider
    the server researches with; without that key the tool is withheld from the model entirely,
    so a turn cannot fail halfway through a search it could never run.
  - **Page text is extracted heuristically.** Scripts, styles and boilerplate are stripped and
    the result is truncated, so the model sees a cleaned-up excerpt rather than the page.
- **No streaming.** A turn is a blocking request; the thinking indicator stands in for token
  streaming.

## Requirements

| Dependency | Constraint | Notes |
|---|---|---|
| TYPO3 CMS | `12.4.11 – 14.3.x` | Backend extension; targets v12/v13/v14 in parallel branches |
| PHP | `^8.1` | |
| `autodudes/ai-suite` | `12.22.0 – 14.x` | Provides `SendRequestService`, `BackendUserService`, `ModelService`, `SettingsFactory`, `UuidService`, `AbstractRepository` |
| `autodudes/ai-suite-mcp` | `0.7.0 – 1.0.0` | Provides the `ToolRegistry`, `McpPermissionService`, `McpUserContext` |
| `typo3/cms-workspaces` | `12.4.11 – 14.3.x` | Required; the default `workspace` write mode routes confirmed changes through a draft |
| `typo3/cms-scheduler` | `12.4.11 – 14.3.x` | Required. The session auto-delete command also runs straight from the CLI, but the dependency is hard |
| `smalot/pdfparser`, `phpoffice/phpword`, `phpoffice/phpspreadsheet` | `^2.12`, `^1.4`, `^3.10` | Text extraction for attached PDF / Word / spreadsheet documents |

> The chat extension does **not** talk to AI providers directly. It posts each turn to the
> **AI Suite Server** `/api/chatTurn` endpoint (the same server AI Suite already uses), which
> holds the credentials and runs the LLM. The server URL and API key come from the AI Suite
> ext-conf (`aiSuiteServer` / `aiSuiteApiKey`), merged with per-BE-group overrides.

## Installation

```bash
composer require autodudes/cheddi
```

Then in the TYPO3 backend:

1. Activate the extension (it ships its DB tables via `ext_tables.sql`).
2. Grant the **Enable Chat Interface** permission to the relevant BE groups
   (Access → custom permission option `tx_aisuite_features:enable_cheddi_interface`).
   Without it the drawer is not injected and every chat AJAX route returns `403`.
3. Make sure each BE group also holds the per-model permissions
   (`tx_aisuite_models:<Model>`) for at least one chat-capable model; otherwise the
   model dropdown is empty and the input area shows a hint.

## Permissions & security model

Access is gated in two layers, both reusing the existing AI Suite permission system:

1. **Feature flag**: `tx_aisuite_features:enable_cheddi_interface`. Gates both the drawer
   injection (`InjectChatDrawerListener`) and every AJAX route (`ChatController::guardPermission`).
   A second, independent flag `tx_aisuite_features:enable_web_research` gates the experimental
   web research tools (`WebResearchPolicy`); it is off unless granted, and `chatForceGdpa`
   additionally blocks `searchWeb` regardless of the flag.
2. **Per-model + per-tool**: the model dropdown only lists models the BE group holds
   `tx_aisuite_models:<Model>` for. The `ToolBridge` filters the MCP tool catalogue through
   `McpPermissionService` so the LLM only ever sees tools the user may run, and re-validates
   on execution. Tool calls run under an `McpUserContext` seeded from the BE user's own
   permission-derived scopes (admins bypass via TYPO3's `BackendUserAuthentication::check()`).

### Tool risk classification

`ToolPolicyResolver` assigns each tool a `Severity`, which decides whether confirmation is
required before it runs:

| Severity | Behaviour | Resolved from |
|---|---|---|
| `ReadOnly` | Runs automatically, no confirmation | `readOnlyHint` **or** `mcp:read` scope |
| `Write` | Inline confirm (one click) | Default catch-all |
| `Destructive` | Inline confirm + a second "really?" click | `destructiveHint` **or** `delete*` / `*Delete*` name heuristic |

Resolution order: the tool's own MCP behavioural hints (`readOnlyHint` / `destructiveHint`)
are authoritative, the same hints every MCP client sees; otherwise the `mcp:read` scope
marks read-only tools; otherwise a delete-naming heuristic catches obvious destructive
calls; otherwise it defaults to `Write`, and it will **never** silently
mutate records when a tool author neither annotated nor declared a safe scope.

## How a turn works

```
Browser (chat-drawer.js)                ChatController            ChatService / ToolBridge / ChatRequestService
────────────────────────                ─────────────            ──────────────────────────────────────────────
user types ──► POST /cheddi/turn ─► startTurnAction ───────► startTurn()
                                                                    ├─ resolve/create ChatSession (per BE user)
                                                                    ├─ persist user message, auto-title session
                                                                    ├─ collect system context (page id, module)
                                                                    ├─ ChatRequestService ─► server /api/chatTurn
                                                                    └─ inspect the assistant's tool calls:
                                                                        • ReadOnly  → execute now, status=continuing
                                                                        • Write/Destr → status=needsConfirm
   ◄──────────────── TurnResult (JSON) ◄──────────────────────────┘
status == continuing  ─► POST /chat/turn/continue ─► continueTurnAction ─► continueTurn()  (loop, capped)
status == needsConfirm ─► render inline confirm UI
   user approves/declines ─► POST /chat/confirm ─► applyConfirmationsAction ─► applyConfirmations()
                                                                    └─ run approved tools, then run next turn
status == final ─► done
```

Other terminal/secondary statuses a `TurnResult` can carry: `error`, `creditsExhausted`,
and `aborted` (e.g. the tool-call cap was hit).

### Tool-call cap

To stop runaway tool loops, `ChatService` counts tool calls in the current user-turn
sequence (reset by each new user message):

- **soft warning** at `TOOL_CAP_SOFT_WARNING = 20`: attaches a notice the drawer surfaces;
- **hard abort** at `TOOL_CAP_HARD_LIMIT = 40`: returns `aborted` / `toolCapReached`.

The client carries its own slightly higher ceiling (`MAX_AUTO_CONTINUES = 25`) purely as a
backstop in case the server ever forgets to terminate a loop.

### History summarisation

When the server returns a `historySummary` block, `ChatMessageRepository::replaceWithSummary()`
hard-deletes the listed old messages and inserts a single `role=summary` row inside a
transaction, so the stored history mirrors the LLM's compacted view. The drawer re-renders
a collapsible summary separator in the same position.

## AJAX routes

All routes are backend AJAX routes (`Configuration/Backend/AjaxRoutes.php`), all guarded by
the feature flag:

| Route identifier | Path | Action |
|---|---|---|
| `cheddi_turn` | `/cheddi/turn` | Start a turn (creates the session on first call) |
| `cheddi_turn_continue` | `/cheddi/turn/continue` | Fire the next turn after auto-run read-only tools |
| `cheddi_confirm` | `/cheddi/confirm` | Apply Write/Destructive confirmations |
| `cheddi_models` | `/cheddi/models` | List chat-capable models the BE user may use |
| `cheddi_sessions` | `/cheddi/sessions` | List the BE user's sessions |
| `cheddi_session_load` | `/cheddi/session/load` | Load full session detail + message history |
| `cheddi_session_delete` | `/cheddi/session/delete` | Soft-delete a session |
| `cheddi_attachment_upload` | `/cheddi/attachment/upload` | Store an attachment in FAL (extension allowlist + size cap + folder write permission) |
| `cheddi_attachment_preflight` | `/cheddi/attachment/preflight` | Metadata-only readability check; never loads file contents |
| `cheddi_ws_changes` | `/cheddi/workspace/changes` | List the draft records this session changed |
| `cheddi_ws_publish` | `/cheddi/workspace/publish` | Publish selected drafts |
| `cheddi_ws_discard` | `/cheddi/workspace/discard` | Discard selected drafts |

Validation errors and permission denials use `4xx` with `{ "error": { "message": ... } }`
so the frontend can branch on HTTP status. Domain-level errors from the orchestrator come
back as `422` with a `TurnResult` envelope (never `5xx`, they are user-correctable).

## Architecture

```
Classes/
├── Controller/
│   └── ChatController.php           AJAX entry points; permission guard; JSON envelopes
├── Service/Chat/
│   ├── ChatService.php              Turn orchestrator (sessions, messages, tool routing, caps)
│   ├── ChatRequestService.php       HTTP to the AI Suite Server /api/chatTurn (1 retry on 5xx/connect)
│   ├── ToolBridge.php               Adapter to the MCP ToolRegistry (filter / project / execute)
│   ├── ToolPolicyResolver.php       Maps a tool to a Severity
│   ├── ContextCollector.php         Builds the per-turn system-context preamble
│   └── ChatSessionAutoDeleter.php   Two-stage retention logic (IO-free, testable)
├── Command/
│   └── AutoDeleteChatSessionsCommand.php   Scheduler/CLI entry point for retention
├── EventListener/
│   └── InjectChatDrawerListener.php  Injects the drawer mount point + CSS/JS into the BE
├── Domain/
│   ├── Model/{ChatSession,ChatMessage}.php  Plain row objects (fromRow factories)
│   ├── Model/Dto/{TurnResult,ChatTurnAnswer}.php  Typed turn in/out envelopes
│   ├── Repository/{ChatSession,ChatMessage}Repository.php  extend AI Suite AbstractRepository
│   └── Enum/Severity.php             ReadOnly | Write | Destructive (derived from MCP hints)

Resources/Public/
├── JavaScript/chat-drawer.js        The whole drawer UI (vanilla ES module, no framework)
├── JavaScript/vendor/{marked.esm.js,dompurify.es.mjs}   Locally vendored, CVE-audited
└── Css/chat-drawer.css              Drawer styling
```

### Frontend

`chat-drawer.js` is a single dependency-free ES module registered via
`Configuration/JavaScriptModules.php`. It mounts on the `[data-cheddi-drawer]` div
the event listener appends to the backend `<body>`. It owns the bubble/drawer, model
selector, session panel, the auto-continue loop, inline confirm UI, credit display, and
safe Markdown rendering. State persisted to `localStorage`: drawer size/open-state, the
active session UUID, and the chosen model.

`marked` and `DOMPurify` are **vendored locally** (not pulled from a CDN) to keep the chat
self-contained; the import map in `JavaScriptModules.php` records the audited versions and
the CVEs they patch; re-run the audit and update that comment when bumping them.

## Database tables

| Table | Purpose |
|---|---|
| `tx_cheddi_session` | One row per conversation: `session_uuid`, `be_user`, `title`, `model`, `last_activity`, soft-delete `deleted` flag |
| `tx_cheddi_message` | One row per message: `session`, `sort`, `role` (`user`/`assistant`/`tool`/`summary`), `content`, `tool_calls`, `tool_call_id`, `tool_status` |

There is no FK constraint between the two; the auto-deleter cascades message deletion in
application code.

## Session retention

Two-stage, handled by `ChatSessionAutoDeleter` and invoked by the
`cheddi:auto-delete-sessions` command:

1. **Soft-delete** sessions whose `last_activity` is older than `chatSessionLifetimeDays`
   (ext-conf, default **20** days). The row stays (`deleted=1`) so a future restore window
   is possible.
2. **Hard-delete** sessions soft-deleted for at least `HARD_DELETE_GRACE_DAYS` (**7** days),
   together with their messages.

Run it from the CLI:

```bash
vendor/bin/typo3 cheddi:auto-delete-sessions
```

…or register it as a recurring task in the TYPO3 scheduler module (requires
`typo3/cms-scheduler`). A daily cron is recommended.

## Configuration

Extension configuration (`ext_conf_template.txt`):

| Key | Default | Description |
|---|---|---|
| `chatSessionLifetimeDays` | `20` | Days of inactivity before a session is soft-deleted. |
| `chatForceGdpa` | `inherit` | Restrict the chat to GDPR-compliant models, and block `searchWeb` (experimental; `readWebPage` stays available, it contacts no AI provider). |
| `chatWriteMode` | `inherit` | Where writes land: draft `workspace` or `live`. |
| `chatAllowRawHtmlWrite` | `inherit` | Whether the chat may store raw markup in code editor fields. |
| `chatExcludedTables` | *(empty)* | Tables ChEddi must never read or write. |
| `chatExcludedTools` | *(empty)* | MCP tool names ChEddi must never offer, on top of the built-in list. |
| `chatSearchAdditionalTables` | *(empty)* | Extra tables `searchContent` sweeps in the chat. |
| `chatExcludeAdditionalTablesFromSearch` | *(empty)* | Auto-detected child tables to keep out of chat searches. |
| `chatLogVerbose` | `inherit` | Whether the INFO trace goes to `var/log/cheddi.log`. |
| `chatLogRedactionPatterns` | *(empty)* | Extra regex patterns redacted in the chat log. |

Every key with a counterpart in `ai_suite_mcp` (`mcpWriteMode`, `mcpAllowRawHtmlWrite`,
`mcpExcludedTables`, `mcpSearchAdditionalTables`, `mcpExcludeAdditionalTablesFromSearch`,
`mcpLogVerbose`, `mcpLogRedactionPatterns`) or in `ai_suite` (`forceGdpa`) defaults to
**inherit**: left empty or on `inherit`, ChEddi behaves exactly like the surface it inherits
from. A configured value may only **tighten**: the table lists are added to the MCP ones and
never shorten them, `chatAllowRawHtmlWrite = Yes` cannot re-enable what MCP forbids, and
`chatForceGdpa = No` cannot take the chat out of an installation-wide GDPR requirement.
`ChatSettingsService` resolves this and pushes what the shared MCP services need into
`SurfaceSettingOverrides` (`ai_suite_mcp`), which the MCP transport itself leaves empty.

The server endpoint and API key are **not** configured here; they are read from the parent
AI Suite ext-conf (`aiSuiteServer`, `aiSuiteApiKey`) via
`SettingsFactory::mergeExtConfAndUserGroupSettings()`, so per-BE-group overrides apply
identically to the rest of AI Suite. The `chat*` keys above are read from this extension's own
configuration and have no per-BE-group override.

Without `aiSuiteApiKey` the chat does not start a turn at all: the drawer shows the same hint the
AI Suite module shows ("Please enter your AI Suite API key …") as a system message and keeps the
input locked, and a turn requested anyway comes back with `chatErrorCode: apiKeyMissing` instead
of a raw HTTP 401 from the server.

## Development

Unit tests live under `Tests/Unit/` and run with the bundled config:

```bash
ddev exec .Build/bin/phpunit -c Extensions/cheddi/Tests/UnitTests.xml
```

Static analysis uses the extension-local `phpstan.neon`. Code style follows the repo-wide
PHP-CS-Fixer setup.

## License

GPL-2.0-or-later. © AutoDudes, <https://www.autodudes.de/>
