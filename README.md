# TYPO3 + ChEddi <img src="Resources/Public/Icons/Extension.png" alt="ChEddi" width="40" align="top">

> 🧪 **Beta (`0.2.2`).** The feature set and the HTTP contract (routes, the `TurnResult`
> response envelope, the tool-severity classification) are stabilising, but smaller changes
> are still possible between minor versions while the extension matures. Pin a version and
> review the changelog before upgrading.

An AI assistant that lives **inside the TYPO3 backend**. `cheddi` puts a resizable chat
**drawer** on every backend page, and editors say in plain language what they need
("translate this page into English", "write a meta description for the news list", "which
pages have no image?"). ChEddi then works on the real records: page tree, content elements,
translations, metadata, images, record CRUD, always as the logged-in backend user and with
exactly that user's permissions.

Nothing changes behind the editor's back. Read-only steps run on their own, every write is
surfaced inline and needs a click, destructive ones a second click. In the default workspace
write mode the result lands in a draft the editor can publish or discard from the drawer.

[AI Suite](https://www.autodudes.de/) is the technical foundation this builds on: it ships
the extension infrastructure, the backend-group permission model, the TYPO3
version-compatibility layer and the shared services, while
[`ai_suite_mcp`](../ai-suite-mcp) contributes the MCP `ToolRegistry` whose tools ChEddi
drives from inside the backend instead of from an external MCP client. Both are hard
dependencies and Composer pulls them in for you.

## What you can do with it

- 💬 **Chat from anywhere in the backend**: a floating bubble opens a drawer, either floating on
  top of the current module or docked to the right edge, where it narrows the backend shell
  instead of covering it. The drawer is resizable, and its size, the dock state, the composer
  height and the open state persist per browser.
- 🧰 **Work on real records, not suggestions**: the assistant calls the same MCP tools an
  external client would (page tree, content generation, translation, metadata, image
  generation, record CRUD), gated to exactly what the current BE user is allowed to do.
- ✅ **Confirm before anything changes**: read-only tools auto-run; write tools require a
  click; destructive tools require a second confirming click. When one answer proposes several
  calls, the card also offers "execute all" and "decline all"; "execute all" covers only what a
  single click would have approved, so destructive and blocked entries still need their own
  decision. Decisions are recorded inline in the conversation as an audit trail.
- 🧠 **Location-aware**: when the editor has a record open in an edit form, the drawer names its
  table and uid when a task starts, and ChEddi reads that record for the model up front (cut at
  8,000 characters). The table is checked against the TCA before anything is read with it. The
  page the editor is looking at is not sent.
- 📎 **Attach documents**: PDF, Word, spreadsheets and plain text are uploaded into FAL and
  their *text* is pulled in on demand via the `readAttachmentText` tool. The upload answer says
  per file whether it can be read at all, so the editor learns that before sending. Images are
  **not** an accepted attachment type (see *Limitations*). Up to 5 files per message, with size
  caps per type (text 5 MB, PDF 50 MB, office documents 25 MB); long text is read in slices of
  50,000 characters.
- 🚧 **Research the web** *(experimental)*: two separate capabilities behind the
  `enable_web_research` backend-group flag. `searchWeb` runs through the AI Suite Server
  (`/api/webSearch`) on the server's search provider (its EU provider in GDPR mode) and costs
  credits; models with a search of their own use it instead, outside GDPR mode. `readWebPage`
  fetches one URL the editor named directly from this installation, with no AI provider in the
  path and no credits. Both feed
  their sources into the drawer so the editor can check the answer against them. See
  *Limitations* before enabling it.
- 🔗 **Jump to what was found or changed**: records a task wrote, and records a pure read found
  (search hits, a page tree, a record list), become buttons under the final answer; found records
  only when the task changed nothing. Child records such as accordion items or file references
  link the element they belong to.
- 📊 **Audits are kept**: an SEO, accessibility or other audit run through the chat is stored in the
  AI Suite Audit module for the page it audited, with buttons to open the result (if the editor may
  open the Audit module) and, for SEO and accessibility, to download it as CSV. Audits cost credits
  and therefore need a click.
- 📄 **CSV on request**: when the editor asks for a CSV or spreadsheet file, the answer carries a
  download button (`createCsvDownload`). The file is built from the stored tool call, so it is still
  available after reopening the conversation, until the history is summarised.
- 🧾 **Review what changed**: when writes land in a draft workspace (the default), the
  actions menu lists exactly the records this conversation touched and offers Publish or
  Discard per record or for all of them.
- 🗂️ **Conversation history**: sessions are stored per BE user, auto-titled from the first
  message, listed in a panel, and can be reopened, switched or deleted.
- 💰 **Credit feedback**: remaining credits show in the header, the badge turns amber below 50
  credits, a warning appears in the conversation, and the input locks for this conversation once
  credits are exhausted.
- 🧭 **Orientation while working**: the drawer states the operating context (write mode, GDPR
  mode, web research, where a confirmed write lands, retention) and that ChEddi is an AI system;
  a help modal explains the topics on cards; every message shows its time; a progress indicator
  names the tool that is running.
- 🧩 **Starter templates and housekeeping**: a templates dropdown offers the AI Suite prompt
  templates of scope `cheddi` or `general`; a context-fill notice offers to summarise a long
  conversation; the page tree refreshes after a write to pages; the drawer waits behind TYPO3
  modals instead of closing.
- 🔑 **Bring your own keys**: on BYOK tariffs the turn runs on the provider keys stored under
  *Bring your own keys* in the AI Suite extension configuration, and models without a stored
  key never appear in the picker. On credit tariffs the AI Suite Server's own keys are used
  and every turn is billed against your credits. Either way the request goes through the
  AI Suite Server; ChEddi never calls a provider directly.
- 🔒 **Markdown rendered safely**: assistant Markdown is rendered with `marked` and
  sanitised with `DOMPurify` (both vendored locally) before it ever touches `innerHTML`.
- 🧹 **Self-cleaning**: a scheduler command soft-deletes idle sessions and hard-deletes
  them after a grace period.

## Limitations & accepted trade-offs

These are deliberate design decisions, not bugs, and worth knowing before you deploy:

- **Every turn goes through the AI Suite Server.** ChEddi never calls a model provider
  directly, so the server has to be reachable even on BYOK tariffs, where it forwards the
  request using your own keys.
- **Images are not supported as attachments.** Messages travel to the server as plain strings,
  so no image content could reach the model anyway; image extensions are therefore not in the
  upload allowlist and are rejected. Documents (PDF, Word, spreadsheets, plain text) *are* read,
  as text, on demand.
- **Write reversibility comes from the workspace draft, not from ChEddi.** With the default
  `workspace` write mode a confirmed change lands in a draft you can publish or discard from the
  drawer; in `live` mode a confirmed change is immediate. Note that the underlying `generateImage`
  tool stores its file directly, so no workspace discard undoes it (see the `ai_suite_mcp`
  README); `uploadMedia` is not offered in ChEddi at all.
- **Web research is experimental.** 🚧 It is off by default (`enable_web_research`) and its
  behaviour and configuration may change between minor versions; treat it as a preview, not as
  a stable interface. Three consequences worth knowing before you switch it on:
  - **`searchWeb` sends the editor's query out of the installation**, to a search provider by way
    of the AI Suite Server, and it costs credits. Without GDPR mode that provider is US-hosted.
    With `chatForceGdpa` the search runs through the server's EU search provider instead, and it
    is withheld when the server has none or when that provider does not serve the site's market;
    the drawer names which of the two applies. Models with a search of their own search natively
    outside GDPR mode, never in it, and they are never offered `searchWeb`. `readWebPage` is
    unaffected by GDPR mode: it is an HTTP GET from your server to a page the editor already named, which is the same request they could make
    with a browser.
  - **Which provider is used is a server-side decision**, not a setting in this extension. On a
    bring-your-own-key plan the search authenticates with your own key for whichever provider
    the server researches with; without that key the tool is withheld from the model entirely,
    so a turn cannot fail halfway through a search it could never run.
  - **Page text is extracted heuristically.** Scripts, styles, `noscript` and embedded elements (`svg`,
    `iframe`, `template`) are removed, the text is taken from the first `main`, `article` or
    `body` (navigation, header and footer included) and cut at 20,000 characters, so the model
    sees an excerpt rather than the page.
- **No streaming.** A turn is a blocking request; the thinking indicator stands in for token
  streaming.

## Requirements

| Dependency | Constraint | Notes |
|---|---|---|
| TYPO3 CMS | `12.4.11` to `14.3.x` | Backend extension for TYPO3 v12, v13 and v14 |
| PHP | `^8.1` | |
| `autodudes/ai-suite` | `^12.23.0 \|\| ^13.17.0 \|\| ^14.5.0` | The shared services (requests, settings, models, workspace context, localization, CSV export, prompt templates), `AbstractRepository` and the backend-group permission model |
| `autodudes/ai-suite-mcp` | `^0.8` | Provides the `ToolRegistry`, `ToolGateway`, `PermissionService`, `McpUserContext` and `SurfaceSettingOverrides` |
| `typo3/cms-workspaces` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required; the default `workspace` write mode routes confirmed changes through a draft |
| `typo3/cms-scheduler` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required. The session auto-delete command also runs straight from the CLI, but the dependency is hard |
| `typo3/cms-reports` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required; the Reports module shows the *ChEddi Environment* check |
| `smalot/pdfparser`, `phpoffice/phpword`, `phpoffice/phpspreadsheet` | `^2.12`, `^1.4`, `^3.10` | Text extraction for attached PDF / Word / spreadsheet documents; bundled for classic mode |
| `symfony/clock` | `^6.4 \|\| ^7.0 \|\| ^8.0` | Shipped by the TYPO3 core |
| PHP extensions | `ctype`, `dom`, `fileinfo`, `gd`, `iconv`, `json`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib` | Needed by the document libraries. Without them the affected attachment types stay unreadable |

> ChEddi does **not** talk to AI providers directly. It posts each turn to the
> **AI Suite Server** `/api/chatTurn` endpoint and each `searchWeb` call to `/api/webSearch` (the
> same server AI Suite already uses), which holds the credentials and runs the LLM. The server URL and API key come from the AI Suite
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
   (`tx_aisuite_models:<Model>`) for at least one chat-capable model (ChEddi registers
   `OpenAiLuna`, `IonosQwen35`, `ClaudeHaiku45`, `ClaudeSonnet5` and `ClaudeOpus5`; in GDPR mode only
   `IonosQwen35`); otherwise the
   model dropdown is empty and the input area shows a hint.
4. Optionally grant `tx_aisuite_features:enable_web_research` to groups that may use the
   experimental web research (see *Limitations*).

### Classic mode (no Composer)

ChEddi also runs in TYPO3 12, 13 and 14 installations without Composer. The document libraries
and their dependencies are bundled in `Resources/Private/PHP`. TYPO3 v14 loads them through
`providesPackages` in `composer.json`, v12 and v13 through the `autoload` section of
`ext_emconf.php`.

1. Activate the system extensions `workspaces`, `reports` and `scheduler`.
2. Install `ai_suite`, `ai_suite_mcp` and `cheddi` in that order. The classic mode notes in the
   `ai_suite_mcp` README apply as well (paths under `typo3temp/var/`, host root only).
3. Open **System → Reports → Status Report**. *ChEddi Environment* names attachment types that
   cannot be read, together with the missing library or PHP extension, and warns when `max_execution_time`
   is below the 180 seconds a chat turn may wait for the AI Suite Server. Web server and proxy
   timeouts (`fastcgi_read_timeout`, `ProxyTimeout`, load balancer idle timeouts) need the same
   headroom but are not visible to PHP.
4. Wherever this README calls `vendor/bin/typo3`, use `typo3/sysext/core/bin/typo3` from the
   TYPO3 root instead, and schedule `cheddi:auto-delete-sessions` as a Scheduler task.

## Permissions & security model

Access is gated in two layers, both reusing the existing AI Suite permission system:

1. **Feature flag**: `tx_aisuite_features:enable_cheddi_interface`. Gates both the drawer
   injection (`InjectChatDrawerListener`) and every AJAX route (`ChatController`,
   `AttachmentController` and `WorkspaceReviewController` check it on every request).
   A second, independent flag `tx_aisuite_features:enable_web_research` gates the experimental
   web research tools (`WebResearchPolicy`); it is off unless granted, and under `chatForceGdpa`
   `searchWeb` only runs through the server's EU search provider, where one serves the site's market.
2. **Per-model + per-tool**: the model dropdown only lists models the BE group holds
   `tx_aisuite_models:<Model>` for. The `ToolBridge` filters the MCP tool catalogue through
   the `PermissionService` of `ai_suite_mcp` (via `ToolGateway`) so the LLM only ever sees tools the user may run, and re-validates
   on execution (the chat-only tools are gated by `WebResearchPolicy` and file-mount checks instead). Tool calls run under an `McpUserContext` seeded from the BE user's own
   permission-derived scopes (admins bypass via TYPO3's `BackendUserAuthentication::check()`).

### Tool risk classification

`ToolPolicyResolver` assigns each tool a `Severity`, which decides whether confirmation is
required before it runs:

| Severity | Behaviour | Resolved from |
|---|---|---|
| `ReadOnly` | Runs automatically, no confirmation | `readOnlyHint` |
| `Write` | Inline confirm (one click) | Default catch-all |
| `Destructive` | Inline confirm + a second "really?" click | `destructiveHint` |

Resolution order: `destructiveHint` wins, then `readOnlyHint`, otherwise `Write`. The tool's
own MCP behavioural hints decide, the same hints every MCP client sees, with one addition: a
read-only tool with a fixed credit cost (the `audit*` tools) is confirmed like a write. That does
not cover every billed call: `searchWeb` costs credits per search but is read-only without a fixed
cost, so it runs without a click. There is no name heuristic: a tool that annotates nothing is
treated as a write and asks for confirmation, so an unannotated tool can never mutate records
silently.

One exception, in `ToolBridge::isModelDiscoveryCall()`, checked before the hints: a tool of a
credit-spending scope (`mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:workflow`) whose schema
has a `model` parameter, called *without* one (or with an empty value), is only listing the models
it could use, so it counts as `ReadOnly` and does not spend credits, even when the tool itself is
destructive. `translatePage` and `translateRecord` are excluded from that shortcut: without a model
they do not list anything, they create the translation records and hand the fields back, so they are
confirmed like any other write.

## How a turn works

```
Browser (chat-drawer.js)                ChatController            ChatService / ToolBridge / ChatRequestService
────────────────────────                ─────────────            ──────────────────────────────────────────────
user types ──► POST /cheddi/turn ─► startTurnAction ───────► startTurn()
                                                                    ├─ resolve/create ChatSession (per BE user)
                                                                    ├─ persist user message, auto-title session
                                                                    ├─ build system prompt; pre-read the focused record (start only)
                                                                    ├─ ChatRequestService ─► server /api/chatTurn
                                                                    └─ inspect the assistant's tool calls:
                                                                        • none      → status=final
                                                                        • ReadOnly  → execute now, status=continuing
                                                                        • Write/Destr → status=needsConfirm (reads in the same answer still run)
   ◄──────────────── TurnResult (JSON) ◄──────────────────────────┘
status == continuing  ─► POST /cheddi/turn/continue ─► continueTurnAction ─► continueTurn()  (loop, capped)
status == needsConfirm ─► render inline confirm UI
   user approves/declines ─► POST /cheddi/confirm ─► applyConfirmationsAction ─► applyConfirmations()
                                                                    └─ run approved tools, then run next turn
status == final ─► done
```

Other terminal/secondary statuses a `TurnResult` can carry: `error`, `creditsExhausted`,
and `aborted` (e.g. the tool-call cap was hit).

While a turn runs, the drawer polls `/cheddi/turn/progress` for the tool currently executing.
Attachments go through `AttachmentController`, the draft review through
`WorkspaceReviewController`.

### Tool-call cap

To stop runaway tool loops, `ChatService` counts tool calls back to the last message the
editor typed. A confirmation does not reset the count, because an approved write is not a new
instruction:

- **soft warning** at `TOOL_CAP_SOFT_WARNING = 20`: attaches a notice the drawer surfaces;
- **hard abort** when a turn would take the count past `TOOL_CAP_HARD_LIMIT = 40`: returns
  `aborted` / `toolCapReached`, and the requested calls do not run.

The client carries its own higher ceiling (`AUTO_CONTINUE_SAFETY_LIMIT = 50` in `chat-drawer.js`)
purely as a backstop in case the server ever forgets to terminate a loop.

### History summarisation

The history is summarised only when the editor asks for it, never on the way through a turn.
Once `TurnResult.contextFill` reports that the conversation fills the model's context window
(`notice` from 80 %, `warning` from 95 %), the drawer offers a summarize button. It calls
`/cheddi/summarize`, and `ChatService::summarizeHistory()` makes a second, billed model call for
the summary. `ChatMessageRepository::replaceWithSummary()` then replaces the session's stored
messages with a single `role=summary` row inside a transaction. The drawer shows a collapsible
summary block together with the number of messages it replaced.

## AJAX routes

All routes are backend AJAX routes (`Configuration/Backend/AjaxRoutes.php`), all guarded by
the feature flag:

| Route identifier | Path | Action |
|---|---|---|
| `cheddi_status` | `/cheddi/status` | Everything the drawer needs on open: models, operating context, credits, templates, attachment limits |
| `cheddi_turn` | `/cheddi/turn` | Start a turn (creates the session on first call) |
| `cheddi_turn_continue` | `/cheddi/turn/continue` | Fire the next turn after auto-run read-only tools |
| `cheddi_confirm` | `/cheddi/confirm` | Apply Write/Destructive confirmations |
| `cheddi_sessions_list` | `/cheddi/sessions` | List the BE user's sessions |
| `cheddi_session_load` | `/cheddi/session/load` | Load full session detail + message history |
| `cheddi_session_delete` | `/cheddi/session/delete` | Soft-delete a session |
| `cheddi_attachment_upload` | `/cheddi/attachment/upload` | Store an attachment in FAL (extension allowlist + size cap + folder write permission) |
| `cheddi_ws_changes` | `/cheddi/workspace/changes` | List the draft records this session changed |
| `cheddi_ws_publish` | `/cheddi/workspace/publish` | Publish selected drafts |
| `cheddi_ws_discard` | `/cheddi/workspace/discard` | Discard selected drafts |
| `cheddi_help` | `/cheddi/help` | Help text for the drawer's help modal |
| `cheddi_turn_progress` | `/cheddi/turn/progress` | Poll which tool the running turn is on (no rate limit, by design) |
| `cheddi_summarize` | `/cheddi/summarize` | Roll the conversation up into one summary, on the editor's request |
| `cheddi_download_csv` | `/cheddi/download/csv` | Serve a CSV the model offered with `createCsvDownload`, rebuilt from that stored tool call (own sessions only) |

Validation errors and permission denials use `4xx` with `{ "error": { "message": ... } }`. A
`chatErrorCode`, which the drawer maps to a translated message, comes with a malformed request
(`400`, `badRequest`), a missing feature flag (`403`, `featureDisabled`) and a rejected model
(`403`, `modelNotChat`, `modelNotPermitted`, `gdprModelBlocked` or `missingAiModelApiKey`). Without
a code: the upload and workspace review routes answer `400` on a malformed request, `403` for a
missing upload folder or a refused publish/discard, `404` for an unknown
session, download or workspace review session, `409` on publish/discard without workspaces,
`413` / `415` for an oversized or unsupported attachment, and `500` when an upload cannot be
stored.

The turn routes answer with a `TurnResult`. A turn that ends in `status=error` comes back as
`422` (an unknown session on continue, confirm or summarize included, and never `5xx`, it is
user-correctable, and carrying `chatErrorCode: noOpenTurn` when a continue finds nothing to
resume, `nothingToSummarize` on an empty conversation and `chatServerTimeout` when the server
does not answer within the request timeout, which is never retried automatically); a hit rate
limit comes back as `429` with `chatErrorCode: rateLimited`
(separate buckets per backend user for `turn`, `continue` and `confirm`; summarize shares the
`turn` bucket); `creditsExhausted` and `aborted` come back as `200`.

## Architecture

```
Classes/
├── Controller/
│   ├── ChatController.php            Turns, sessions, status, help, summary, CSV download; permission and rate-limit guards
│   ├── AttachmentController.php      Attachment upload into FAL
│   └── WorkspaceReviewController.php List, publish and discard the drafts of a session
├── Service/Chat/
│   ├── ChatService.php               Turn orchestrator (sessions, messages, tool routing, caps, summary)
│   ├── ChatRequestService.php        HTTP to the AI Suite Server /api/chatTurn (180 s timeout; 1 retry on 5xx/connect, none on a timeout)
│   ├── ToolBridge.php                Adapter to the MCP tool pipeline (filter / project / execute, excluded tools)
│   ├── ToolPolicyResolver.php        Maps a tool to a Severity
│   ├── ContextCollector.php          System prompt: context, research abilities, orientation
│   ├── ContextPrefillService.php     Pre-reads the record the editor has open when a task starts
│   ├── WebSearchRequestService.php, HtmlTextExtractor.php   searchWeb via /api/webSearch, page text for readWebPage
│   ├── ChatCatalogService.php        Model catalogue and starter templates
│   ├── ChatModelPolicy.php, GdprModelPolicy.php, WebResearchPolicy.php   Which models and research tools a turn may use
│   ├── ChatSettingsService.php       Reads the chat* settings and hands overrides to ai_suite_mcp
│   ├── ChatOrientationService.php    Operating-context statements shown in the drawer
│   ├── ChatServerCapabilityService.php, ChatCreditsService.php   What the AI Suite Server reports (rates, own keys, native search models, GDPR search provider, credits)
│   ├── AttachmentService.php, DocumentExtractorService.php   Attachment storage and text extraction
│   ├── PendingPreviewService.php, WorkspaceReviewService.php, ChangeTracker.php, ChatWriteCaptureService.php   Confirmation previews, draft review and the write capture window
│   ├── ChatHelpService.php, ChatProgressService.php   Help topics and the turn progress the drawer polls
│   ├── CsvDownloadPayload.php, UploadedFileWriter.php, NetworkTimeoutDetector.php   CSV download payload, attachment writing, timeout detection
│   └── ChatSessionAutoDeleter.php    Two-stage retention (sessions, messages, changes, unused attachments)
├── Mcp/Tool/                         Chat-only tools: readAttachmentText, searchWeb, readWebPage, createCsvDownload
├── Command/
│   └── AutoDeleteChatSessionsCommand.php   Scheduler/CLI entry point for retention
├── EventListener/                    Drawer injection, change-log cleanup on publish, usage statistics
├── Hook/ChatWriteCaptureHook.php     Captures the records a confirmed write touched
├── StatusReport/CheddiEnvironmentStatus.php   Reports module check (readable attachment types, execution time)
└── Domain/
    ├── Model/{ChatSession,ChatMessage}.php   Plain row objects (fromRow factories)
    ├── Model/Dto/{TurnResult,ChatTurnAnswer,ChatToolContext}.php   Typed turn in/out envelopes
    ├── Repository/{ChatSession,ChatMessage,ChatChange}Repository.php   extend AI Suite AbstractRepository
    └── Enum/Severity.php             ReadOnly | Write | Destructive

Resources/Public/
├── JavaScript/chat-drawer.js         The drawer: turn loop, messages, confirmations, model state
├── JavaScript/{sessions-panel,templates-dropdown,help-modal,intro-card,workspace-review,attachments,confirm-preview,status-bar,…}.js   Self-contained pieces of the surface
├── JavaScript/{chat-api-client,state,backend-context,navigation-targets,drawer-resize,markdown,i18n,labels,…}.js   Transport, persisted state, context and helpers
├── JavaScript/vendor/{marked.esm.js,dompurify.es.mjs}   Locally vendored, CVE-audited
└── Css/chat-drawer.css               Drawer styling
```

### Frontend

`chat-drawer.js` is an ES module registered via `Configuration/JavaScriptModules.php` that
composes the sibling modules listed above; apart from the vendored `marked` and `DOMPurify` it
has no third-party dependencies. It mounts on the `[data-cheddi-drawer]` div the event
listener appends to the rendered backend page. It owns the bubble/drawer, model selector, the
auto-continue loop and the inline confirm UI; the credits badge comes from `status-bar.js`, safe
Markdown rendering from `markdown.js`, and the session list from `sessions-panel.js`. `state.js`
persists the drawer size, composer height and open state, the active session UUID and the
session's model to `localStorage`.

`marked` and `DOMPurify` are **vendored locally** (not pulled from a CDN) to keep ChEddi
self-contained; the import map in `JavaScriptModules.php` records the audited versions and
the CVEs they patch; re-run the audit and update that comment when bumping them.

## Database tables

| Table | Purpose |
|---|---|
| `tx_cheddi_session` | One row per conversation: `session_uuid`, `be_user`, `title`, `model`, `last_activity`, soft-delete `deleted` flag |
| `tx_cheddi_message` | One row per message: `session`, `sort`, `role` (`user`/`assistant`/`tool`/`summary`), `content`, `tool_calls`, `tool_call_id`, `tool_status`, `attachments` (file references, never bytes), `provider_items`, `sources` (web research sources, JSON) |
| `tx_cheddi_change` | Audit trail of the records a session mutated: `session`, `tablename`, `record_uid`, `workspace_record_uid`, `workspace`, `page_id`, `action`. Only filled in workspace write mode; rows are removed once the record is published or discarded |

There are no FK constraints between them; the auto-deleter cascades message, change and
attachment deletion in application code.

## Session retention

Two-stage, handled by `ChatSessionAutoDeleter` and invoked by the
`cheddi:auto-delete-sessions` command:

1. **Soft-delete** sessions whose `last_activity` is older than `chatSessionLifetimeDays`
   (ext-conf, default **20** days). The row stays (`deleted=1`) so a future restore window
   is possible.
2. **Hard-delete** sessions soft-deleted for at least `HARD_DELETE_GRACE_DAYS` (**7** days),
   together with their messages, their `tx_cheddi_change` rows and the attachments still in
   ChEddi's upload folder that nothing else references any more.

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
| `chatForceGdpa` | `inherit` | Restrict ChEddi to GDPR-compliant models; `searchWeb` then runs only through the server's EU search provider and is withheld where none serves the site's market (experimental; `readWebPage` stays available, it contacts no AI provider). |
| `chatWriteMode` | `inherit` | Where writes land: draft `workspace` or `live`. |
| `chatAllowRawHtmlWrite` | `inherit` | Whether ChEddi may store raw markup in code editor fields. |
| `chatExcludedTables` | *(empty)* | Tables ChEddi must never read or write. |
| `chatExcludedTools` | *(empty)* | MCP tool names ChEddi must never offer, on top of the built-in list below. |
| `chatLogVerbose` | `inherit` | Whether the INFO trace goes to `var/log/cheddi.log`. Warnings and errors always go to `var/log/cheddi_warnings.log`, which no setting turns off. |
| `chatLogRedactionPatterns` | *(empty)* | Extra regex patterns redacted in the ChEddi log, on top of `mcpLogRedactionPatterns`. |
| `chatWebResearchAllowedDomains` | *(empty)* | Domains web research is restricted to, at most 10. A hard filter, not a ranking hint: a question whose answer lives on none of them returns no sources. Takes precedence over the block list. |
| `chatWebResearchBlockedDomains` | *(empty)* | Domains web research never returns, at most 10. Ignored while an allow list is set. |
| `chatWebResearchCountry` | *(empty)* | Two-letter ISO country the results are localised for. Empty takes the region of the site's default language. |
| `chatWebResearchMaxSearches` | `0` | Searches one turn may run. `0` leaves it to the server. |

ChEddi never offers these tools, whatever `chatExcludedTools` says, because each is reachable
through a backend module instead: `readServerInfo`, `compareWithLive`, `uploadMedia`,
`batchGenerateMetadata`, `batchGenerateFileMetadata`, `batchTranslatePage`,
`batchTranslateFileMetadata`, `readTaskStatus`, `readTaskResults` and `applyTaskResults`. `chatExcludedTools` adds to that list.

Every key with a counterpart in `ai_suite_mcp` (`mcpWriteMode`, `mcpAllowRawHtmlWrite`,
`mcpExcludedTables`, `mcpLogVerbose`, `mcpLogRedactionPatterns`) or in `ai_suite` (`forceGdpa`)
defaults to **inherit**: left empty or on `inherit`, ChEddi behaves exactly like the surface it
inherits from. For most keys a configured value may only **tighten**: the table list and redaction
patterns are added to the MCP ones and never shorten them, `chatAllowRawHtmlWrite = Yes` cannot
re-enable what MCP forbids, and `chatForceGdpa = No` cannot take ChEddi out of an
installation-wide GDPR requirement. Two keys are ChEddi's own decision: `chatWriteMode` replaces
`mcpWriteMode` for ChEddi, so `live` writes live even while MCP writes to a workspace, and
`chatLogVerbose` switches `var/log/cheddi.log` independently of `mcpLogVerbose`.
`ChatSettingsService` resolves this and pushes what the shared MCP services need into
`SurfaceSettingOverrides` (`ai_suite_mcp`), which the MCP transport itself leaves empty.

The server endpoint and API key are **not** configured here; they are read from the parent
AI Suite ext-conf (`aiSuiteServer`, `aiSuiteApiKey`) via
`SettingsFactory::mergeExtConfAndUserGroupSettings()`, so per-BE-group overrides apply
identically to the rest of AI Suite. The `chat*` keys above are read from this extension's own
configuration and have no per-BE-group override.

Without `aiSuiteApiKey` ChEddi does not start a turn at all: the drawer shows the same hint the
AI Suite module shows ("Please enter your AI Suite API key …") as a system message and keeps the
input locked, and a turn requested anyway comes back as `422` with `chatErrorCode: apiKeyMissing`
instead of a raw HTTP 401 from the server.

## Development

Static analysis uses the extension-local `phpstan.neon`. Code style follows the repo-wide
PHP-CS-Fixer setup.

## License

GPL-2.0-or-later. © AutoDudes, <https://www.autodudes.de/>
