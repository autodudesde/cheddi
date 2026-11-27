# TYPO3 + ChEddi <img src="Resources/Public/Icons/Extension.png" alt="ChEddi" width="40" align="top">

> 🧪 **Beta.** The feature set and the HTTP contract (routes, the `TurnResult`
> response envelope, the tool-severity classification) are stabilising, but smaller changes
> are still possible between minor versions while the extension matures. Pin a version and
> review the [changelog](CHANGELOG.md) before upgrading.

An AI assistant that lives **inside the TYPO3 backend**. `cheddi` puts a resizable chat
**drawer** on every backend page, and editors say in plain language what they need
("translate this page into English", "write a meta description for the news list", "which
pages have no image?"). ChEddi then works on the real records: page tree, content elements,
translations, metadata, images, record CRUD, always as the logged-in backend user and with
exactly that user's permissions.

Nothing changes behind the editor's back. Read-only steps run on their own unless they carry a
fixed credit cost (the audits), every write is surfaced inline and needs a click, destructive ones
a second click. In the default workspace write mode the result lands in a draft the editor can
publish or discard from the drawer.

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
- 🖥️ **A full page for longer work**: the maximize button in the drawer header shows the same
  conversation on a whole page, the conversation list next to the chat. The same button returns to
  where the editor came from, and a click on a record opens it in the backend with the drawer docked
  beside it, so the conversation carries on.
- 🧰 **Work on real records, not suggestions**: the assistant calls the same MCP tools an
  external client would (page tree, content generation, translation, metadata, image
  generation, record CRUD), gated to exactly what the current BE user is allowed to do.
- ✅ **Confirm before anything changes**: read-only tools auto-run, except the audits with
  their fixed credit cost, which are confirmed like a write; write tools require a click;
  destructive tools require a second confirming click. When one answer proposes several calls,
  the card also offers "execute all" and "decline all"; "execute all" covers only what a single
  click would have approved, so destructive and blocked entries still need their own
  decision. A generation card names model, credit cost and target; a call the tool would refuse is
  shown as invalid. Decisions are recorded inline in the conversation as an audit trail.
- 🧠 **Location-aware**: when the editor has a record open in an edit form, the drawer names its
  table and uid when a task starts, and ChEddi reads that record for the model up front (cut at
  8,000 characters). The table is checked against the TCA before anything is read with it. The
  page the editor is looking at is not sent; the *Current page* button in the composer adds it to
  the message as visible text, so the editor names it.
- 📎 **Attach documents** with the paper clip, by dropping files onto the drawer, or by pasting a
  file into the input (a clipboard that also holds text pastes the text): PDF, Word, spreadsheets
  and plain text (TXT, CSV, Markdown, JSON, XML) are uploaded into FAL and
  their *text* is pulled in on demand via the `readAttachmentText` tool. The upload answer says
  per file whether it can be read at all, so the editor learns that before sending. Up to 5
  files per message, with size caps per type (text 5 MB, PDF 50 MB, office documents 25 MB);
  long text is read in slices of 50,000 characters. Images are not among the readable
  attachments (see *Bring in media*). Spreadsheets are read up to 2,000 rows and 50
  columns per sheet, formulas as written rather than calculated; a document whose archive holds more
  than 10,000 entries or unpacks to more than 200 MB is refused. Files land in a `cheddi` folder below the
  editor's default upload folder, or below `chatUploadFolder` (of the extension or of a backend
  group) when that is set, under a name with a random prefix unless `chatUploadRandomNames` is off;
  the editor keeps seeing the original name. In a public storage such as `fileadmin`
  that makes the URL hard to guess, not private, and the Reports module says so: point
  `chatUploadFolder` at a non-public storage to keep attachments private. At most 30 uploads per
  editor in ten minutes.
- 🖼️ **Bring in media** (`enable_cheddi_media_upload`): with the flag, images can be attached
  as well and placed on a page by ChEddi (`writeRecords`, `replaceMediaReference`), and `uploadMedia`
  imports an image or video by URL (only with `mcpMediaAllowUrlFetch = 1` of `ai_suite_mcp`, off by
  default) or a YouTube/Vimeo link into the file storage. Image types and
  the size cap follow `mcpMediaAllowedExtensions` and `mcpMediaMaxSizeMb` of `ai_suite_mcp`
  (`0` there means the PDF cap of 50 MB applies); SVG is never accepted. **No chat model has
  vision**: an attached image is a file ChEddi can place or move, but no model can describe,
  check or compare what it shows; alternative text and title come from `generateFileMetadata`
  (see *Limitations*). An attached image that a confirmed write places on a page (`writeRecords`,
  `replaceMediaReference`) is first taken out of the chat upload folder into the folder above it, under
  its original name and keeping its UID; the card says so, and discarding the workspace does not move
  it back. An image that is not placed stays in the chat upload folder. `moveFile` moves it (or any other file)
  into a folder of your choice; the file keeps its UID, so the image on the page stays, and a file
  moved out of the chat folder is never touched by the chat cleanup again.
- 🚧 **Research the web** *(experimental)*: two separate capabilities behind the
  `tx_cheddi_features:enable_web_research` backend-group flag. `searchWeb` runs through the AI Suite Server and costs
  credits; models with a search of their own use it instead, outside GDPR mode. `readWebPage`
  fetches one URL the editor named directly from this installation, with no AI provider in the
  path and no credits. A URL that appears in none of the editor's messages (another path, an added
  query string, another host) is shown as a confirmation card first, so text the model read cannot
  send data out on its own. Both feed
  their sources into the drawer so the editor can check the answer against them. See
  *Limitations* before enabling it.
- 🔗 **Jump to what was found or changed**: records a task wrote, and records a pure read found
  (search hits, records matching a filter, a page that was read), become buttons under the final
  answer; found records only when the task changed nothing. Child records such as accordion items or file references
  link the element they belong to.
- 📊 **Audits are kept**: an SEO, accessibility or other audit run through the chat is stored in the
  AI Suite Audit module for the page it audited, with buttons, shown only if the editor may open the
  Audit module, to open the result and, for SEO and accessibility, to download it as CSV. Audits cost credits
  and therefore need a click.
- 🗂️ **A way to mass runs**: mass runs stay in the Workflow Manager. When the editor asks for one,
  ChEddi says so and, if the editor may open that module, puts a button to it under the answer.
- 📄 **CSV on request**: when the editor asks for a CSV or spreadsheet file, the answer carries a
  download button (`createCsvDownload`). The file is built from the stored tool call, so it is still
  available after reopening the conversation, until the history is summarised. For records of one
  table the file reads them itself on every click, in the workspace of the conversation, so it holds
  the data as of the click; without a page, subtree or filter that is every record the editor may
  read, and above 2,000 rows the download is refused with a note to narrow the selection.
- 🧾 **Review what changed**: when writes land in a draft workspace (the default), the
  actions menu lists exactly the records this conversation touched and offers Publish or
  Discard per record or for all of them.
- 🗃️ **Choose the workspace**: an editor with access to more than one workspace picks the draft
  workspace per conversation in the header, next to the model. The choice is fixed with the first
  message and leaves the editor's own backend workspace unchanged until they click a link in the
  drawer: the link then opens in the workspace of the conversation, or in live with the `live` write
  mode. The list holds every workspace
  the editor has access to, for admins all of them, plus "New workspace (will be created)" when the
  editor has no ChEddi workspace yet. ChEddi creates that one as "ChEddi: <name>" (real name, else
  username), separate from the `AI Suite MCP [#<uid>]` workspace that MCP clients write into.
- 🗂️ **Conversation history**: sessions are stored per BE user, auto-titled from the first
  message, listed as cards in a panel with the open conversation marked as *Open*, searchable by title, and can be renamed, reopened, switched or
  deleted.
- 💰 **Credit feedback**: remaining credits show in the header, the badge turns amber once the credit
  pack drops below 50 and the plan has nothing left, a warning appears in the conversation, and the input locks for this conversation once
  credits are exhausted.
- 🔁 **Try again after a failure**: when a turn fails for a reason that can pass (timeout, server not
  reachable, rate limit, provider error), the error carries a button that repeats the failed step
  without storing the message twice or running an approved write again. A retry can cost credits
  again.
- 🧭 **Orientation while working**: the drawer states the operating context (write mode, GDPR
  mode, web research, where a confirmed write lands, retention) and that ChEddi is an AI system;
  a help modal explains the topics on cards; every message shows its time; a progress indicator
  names the tool that is running; the tool calls of a step fold into one line with the number of
  steps and failures, and each opens to its arguments.
- 📌 **Open items** (`enable_cheddi_open_items`): a button in the drawer header, with the number of open tasks, opens
  one task per check on the pages the editor may read, such as "48 pages without a description". Where the AI Suite
  solves a task, the task and each of its items lead there: missing page metadata to the Workflow Manager with the
  field, "only empty" and the page preselected, images without alternative text to *Generate File Metadata* with the
  image's folder, audit findings to the stored audit result of the page, missing translations,
  one task per language and site, to the page translation with the target language. Editors without the Workflow Manager work a task of empty fields
  through in steps of ten instead: one click creates suggestions from the page content or the image, the editor edits
  or unticks them and accepts the rest. Broken links, stale pages and empty rich text fields offer *Solve with ChEddi*,
  which puts a prepared request naming the page and what is missing into the composer, without sending it. Other tasks
  list their pages as links. Any item can be
  ignored for the whole team until its data changes. In the full-page view the tasks take the left column in place of
  the conversations. `enable_cheddi_open_item_rules` adds switching checks on and off and adding your own.
- 📖 **See the rules it follows**: the *Editorial rules* button in the composer shows the rules that
  apply to the open page, per area, and leads to their module for editors who may maintain them.
- 🧩 **Starter templates and housekeeping**: a templates dropdown offers the AI Suite prompt
  templates of scope `cheddi` or `general`; a context-fill notice offers to summarise a long
  conversation; the page tree refreshes after a write to pages; the drawer waits behind TYPO3
  modals instead of closing.
- 🔑 **Bring your own keys**: on BYOK tariffs the turn runs on the provider keys stored under
  *Bring your own keys* in the AI Suite extension configuration, and models without a stored
  key never appear in the picker. On credit tariffs every turn costs credits.
- 🔒 **Markdown rendered safely**: assistant Markdown is rendered with `marked` and
  sanitised with `DOMPurify` (both vendored locally) before it ever touches `innerHTML`.
- 🧹 **Self-cleaning**: a scheduler command soft-deletes idle sessions and hard-deletes
  them after a grace period.

## Limitations & accepted trade-offs

These are deliberate design decisions, not bugs, and worth knowing before you deploy:

- **Every turn goes through the AI Suite Server.** ChEddi never calls a model provider
  directly, so the server has to be reachable even on BYOK tariffs, where it forwards the
  request using your own keys.
- **The model never sees an image.** Messages travel to the server as plain strings, so an
  attached image is a file ChEddi can place, not a picture the model can look at; alternative text
  comes from `generateFileMetadata`. Documents (PDF, Word, spreadsheets, plain text) *are* read,
  as text, on demand.
- **Write reversibility comes from the workspace draft, not from ChEddi.** With the default
  `workspace` write mode a confirmed change lands in a draft you can publish or discard from the
  drawer; in `live` mode a confirmed change is immediate. Note that the underlying `generateImage`
  tool stores its file directly, so no workspace discard undoes it (see the `ai_suite_mcp`
  README). The same holds for `uploadMedia` and `moveFile`: an imported or moved file is stored right away, which is
  why their confirmation is destructive (two steps) and their card says so. Likewise, an attachment a
  confirmed write places on a page leaves the chat folder at once, and discarding the workspace leaves
  it where it went.
- **Web research is experimental.** 🚧 It is off by default (`enable_web_research`) and its
  behaviour and configuration may change between minor versions; treat it as a preview, not as
  a stable interface. Three consequences worth knowing before you switch it on:
  - **`searchWeb` sends the editor's query out of the installation**, to a search service by way
    of the AI Suite Server, and it costs credits. Outside GDPR mode the query may be processed
    outside the EU. In GDPR mode (`forceGdpa` in AI Suite or `chatForceGdpa`) only a GDPR-compliant
    search runs, and the tool is withheld where none is available; the drawer says which applies. Models with a search of their own search natively
    outside GDPR mode, never in it, and they are never offered `searchWeb`. `readWebPage` is
    unaffected by GDPR mode: it is an HTTP GET from your server to a page the editor already named, which is the same request they could make
    with a browser.
  - **On a bring-your-own-key plan** the search needs your own search key; without it the tool is
    withheld from the model entirely, so a turn cannot fail halfway through a search it could never
    run.
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
| `autodudes/ai-suite` | `^12.26.0 \|\| ^13.20.0 \|\| ^14.8.0` | The shared services (requests, settings, models, workspace context, localization, CSV export, prompt templates) and the backend-group permission model |
| `autodudes/ai-suite-mcp` | `^0.13` | Provides the tools, their permission checks and the settings ChEddi may tighten for the chat |
| `typo3/cms-workspaces` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required; the default `workspace` write mode routes confirmed changes through a draft |
| `typo3/cms-scheduler` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required. The session auto-delete command also runs straight from the CLI, but the dependency is hard |
| `typo3/cms-reports` | `^12.4.11 \|\| ^13.4.1 \|\| ^14.3.0` | Required; the Reports module shows the *ChEddi Environment* check |
| `smalot/pdfparser`, `phpoffice/phpword`, `phpoffice/phpspreadsheet` | `^2.12`, `^1.4`, `^3.10` | Text extraction for attached PDF / Word / spreadsheet documents; bundled for classic mode |
| `typo3/cms-linkvalidator` | suggested | Optional. Its results appear under open items; ChEddi reads them and never starts a link check |
| `symfony/clock` | `^6.4 \|\| ^7.0 \|\| ^8.0` | Shipped by the TYPO3 core |
| PHP extensions | `ctype`, `dom`, `fileinfo`, `gd`, `iconv`, `json`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib` | Needed by the document libraries. Without them the affected attachment types stay unreadable |

## Installation

```bash
composer require autodudes/cheddi
```

Then in the TYPO3 backend:

1. Activate the extension (it ships its DB tables via `ext_tables.sql`).
2. Grant the **Enable Chat Interface** permission to the relevant BE groups
   (Access → **ChEddi Features**, `tx_cheddi_features:enable_cheddi_interface`).
   Without it the drawer is not injected and every chat AJAX route returns `403`.
   The full page view needs no permission of its own. It has no entry in the module menu, and a
   user without the flag who follows a direct link sees a notice instead of the chat.
3. Make sure each BE group also holds at least one chat model under **ChEddi Models**
   (`tx_cheddi_models:<Model>`: `OpenAiLuna`, `IonosQwen35`, `IonosQwen38`, `ClaudeHaiku55`,
   `ClaudeSonnet55`; in GDPR mode only `IonosQwen35` and `IonosQwen38`); otherwise the model
   dropdown is empty and the input area shows a hint.
4. Grant the ChEddi features the chat may use for that group (see *Permissions & security model*).
   They are independent of the AI Suite features: a group may translate in the AI Suite and not in
   the chat, or the other way round.
5. Optionally grant `tx_cheddi_features:enable_web_research` to groups that may use the
   experimental web research (see *Limitations*).

Updating from a version that kept these permissions under *AI Suite Features* and *AI Suite
Models*: run the upgrade wizard `cheddiSeparatePermissions` (`vendor/bin/typo3 upgrade:run
cheddiSeparatePermissions`, or *Admin Tools → Upgrade*). It moves the ChEddi flags and chat models
into their own lists and grants every group the ChEddi counterpart of the AI Suite features it holds,
so nobody loses an ability in the chat. Until it has run, no group has access to ChEddi.

### Classic mode (no Composer)

ChEddi also runs in TYPO3 12, 13 and 14 installations without Composer. The document libraries
and their dependencies are bundled in `Resources/Private/PHP`. TYPO3 v14 loads them through
`providesPackages` in `composer.json`, v12 and v13 through the `autoload` section of
`ext_emconf.php` or, when that section is missing as after a download from TER in the Extension
Manager, through the bundle's own autoloader.

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

ChEddi has its own two permission lists in the backend group (Access tab, next to the AI Suite
lists), so what an editor may do in the chat is set independently of the AI Suite modules:

| ChEddi feature | Gates in the chat |
|---|---|
| `enable_cheddi_interface` | the drawer, the backend module (the full page view shows a notice without it) and every AJAX route |
| `enable_web_research` | the experimental web research tools; in GDPR mode `searchWeb` only runs as a GDPR-compliant search, where one is available |
| `enable_cheddi_generation` | `generateFileMetadata`, file metadata generated by an AI Suite model |
| `enable_cheddi_translation` | the translation tools; `translatePage` and `translateFileMetadata` also need the flag of their own |
| `enable_cheddi_page_translation` | `translatePage`, a whole page with its content elements |
| `enable_cheddi_file_metadata_translation` | `translateFileMetadata` |
| `enable_cheddi_image_generation` | `generateImage` |
| `enable_cheddi_audit` | `auditSeo`, `auditAccessibility`, `auditContent` |
| `enable_cheddi_rendered_page_read` | `readRenderedPage` |
| `enable_cheddi_media_upload` | `uploadMedia`, `moveFile` and image attachments |
| `enable_cheddi_open_items` | the open items of the pages the editor may read |
| `enable_cheddi_open_item_rules` | create, change and delete checks, limited to the tables, fields and page trees of the group |

Reading and writing records need no flag beyond the chat itself, as over MCP.

1. **Chat models**: the model dropdown only lists models the group holds
   `tx_cheddi_models:<Model>` for.
2. **Tools**: the MCP tool catalogue is filtered with ChEddi's own permission policy, so the
   features above decide, not the AI Suite features or the MCP flags. The same policy is checked
   again when a tool runs. The chat-only tools are gated by the web research flag and file-mount
   checks instead.
3. **Models inside a tool** stay with the AI Suite: a translation, image or metadata tool may use
   only the models the group holds under **AI Suite Models**.

Tool calls run with the scopes this policy grants; admins hold every permission, as everywhere in
the backend.

### Tool risk classification

Each tool gets a severity, which decides whether confirmation is required before it runs:

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

Two exceptions, both checked before the hints. The first: a tool of a
credit-spending scope (`mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:workflow`) whose schema
has a `model` parameter, called *without* one (or with an empty value), is only listing the models
it could use, so it counts as `ReadOnly` and does not spend credits, even when the tool itself is
destructive. `translatePage` and `translateRecord` are excluded from that shortcut: without a model
they do not list anything, they create the translation records and hand the fields back, so they are
confirmed like any other write. The second: a safe-edit tool (`patchText`,
`bulkReplaceText`) called with `dryRun: true` writes nothing and counts as `ReadOnly`.

## How a turn works

The drawer sends the editor's message, the turn is answered by the AI Suite Server, and ChEddi
inspects the tool calls of the answer: read-only calls run at once and the turn continues on its own,
write and destructive calls stop the turn with a confirmation card (reads in the same answer still
run), and an answer without tool calls ends the turn. Besides `final`, `continuing` and
`needsConfirm`, a turn can end as `error`, `creditsExhausted` or `aborted` (for example when the
tool-call cap is hit). While a turn runs, the drawer shows which tool is executing.

### Tool-call cap

To stop runaway tool loops, ChEddi counts tool calls back to the last message the
editor typed. A confirmation does not reset the count, because an approved write is not a new
instruction:

- **soft warning** at 20 calls: attaches a notice the drawer surfaces;
- **hard abort** when a turn would take the count past 40: returns
  `aborted` / `toolCapReached`, and the requested calls do not run.

The drawer carries its own higher ceiling of 50 automatic continuations
purely as a backstop in case the server ever forgets to terminate a loop.

### History summarisation

The history is summarised only when the editor asks for it, never on the way through a turn.
Once `TurnResult.contextFill` reports that the conversation fills the model's context window
(`notice` from 80 %, `warning` from 95 %), the drawer offers a summarize button. It calls
`/cheddi/summarize`, which makes a second model call for the summary and costs credits. The session's
stored messages are then replaced with a single `role=summary` row inside a transaction. The drawer shows a collapsible
summary block together with the number of messages it replaced.

## AJAX routes

All routes are backend AJAX routes (`Configuration/Backend/AjaxRoutes.php`). Every one requires
`enable_cheddi_interface`, the routes of a feature also its own flag, and a session or download is
only ever served to the backend user it belongs to.

Validation errors and permission denials use `4xx` with `{ "error": { "message": ... } }`. A
`chatErrorCode`, which the drawer maps to a translated message, comes with a malformed request
(`400`, `badRequest`), a missing feature flag (`403`, `featureDisabled`) and a rejected model
(`403`, `modelNotChat`, `modelNotPermitted`, `gdprModelBlocked` or `missingAiModelApiKey`). Without
a code: the upload and workspace review routes answer `400` on a malformed request, `403` for a
missing upload folder, a missing feature flag or a refused publish/discard, `404` for an unknown
session, download or workspace review session, `409` on publish/discard without workspaces,
`413` / `415` for an oversized or unsupported attachment, `429` when the upload limit of the
session is hit (`rateLimited`), and `500` when an upload cannot be stored.

The turn routes answer with a `TurnResult`. A turn that ends in `status=error` comes back as
`422` (an unknown session on continue, confirm or summarize included, and never `5xx`, it is
user-correctable, and carrying `chatErrorCode: noOpenTurn` when a continue finds nothing to
resume, `nothingToSummarize` on an empty conversation and `chatServerTimeout` when the server
does not answer within the request timeout, which is never retried automatically); a hit rate
limit comes back as `429` with `chatErrorCode: rateLimited`
(separate buckets per backend user for `turn`, `continue` and `confirm`, each fixed at 100
requests per 60 seconds with a 60-second lockout and independent of `mcpRateLimitRequests`;
summarize shares the `turn` bucket); `creditsExhausted` and `aborted` come back as `200`.

## Architecture

`Classes/` holds the backend controllers of the drawer and the full page view, the chat services
(turn orchestration, the request to the AI Suite Server, attachments, web research, workspace review,
open items, retention), the chat-only tools (`readAttachmentText`, `searchWeb`, `readWebPage`,
`createCsvDownload`, `offerWorkflowManager`), the Scheduler commands and the domain models and
repositories. Every other tool is an `ai_suite_mcp`
tool run in-process, with the same permission checks as over MCP.

### Frontend

The drawer is a set of ES modules registered in `Configuration/JavaScriptModules.php`, without
third-party dependencies apart from `marked` and `DOMPurify`. Both are **vendored locally** (not
pulled from a CDN); the import map in `JavaScriptModules.php` records the audited versions and the
CVEs they patch, so re-run the audit and update that comment when bumping them.

Other backend modules can open the drawer with a prepared message: dispatch
`new CustomEvent('cheddi:prefill', {detail: {text}})` on `top.document`. The drawer opens and fills
the composer; nothing is sent until the editor sends it. *Fix with ChEddi* on a finding in the
AI Suite Audit module does this, for findings without a fix of their own.

The full page view is not a second chat: it takes over the one drawer instance, so a running turn,
a draft and an open confirmation survive switching between it and the rest of the backend.

## Database tables

| Table | Purpose |
|---|---|
| `tx_cheddi_session` | One row per conversation of a backend user, with its model and workspace |
| `tx_cheddi_message` | One row per message, including tool calls, attachment references (never bytes) and web research sources |
| `tx_cheddi_change` | Audit trail of the records a session changed. Only filled in workspace write mode; rows are removed once the record is published, or discarded from the drawer |
| `tx_cheddi_open_item` | One row per open item. Rebuilt by the collector, not user data |
| `tx_cheddi_open_item_resolution` | Items ignored or fixed by an accepted suggestion. Hides the item for everyone until its data changes |
| `tx_cheddi_open_item_rule` | The checks behind the open items |
| `tx_cheddi_open_item_rule_condition` | The conditions of a check |
| `tx_cheddi_upload` | One row per chat upload. The auto-deleter sweeps uploads that were never sent from this registry |

There are no FK constraints between them; the auto-deleter cascades message, change and
attachment deletion in application code.

ChEddi also adds the column `sys_workspace.tx_cheddi_owner`: the backend user a ChEddi draft workspace was
created for, `0` on every other workspace. The workspace is found by this column, so renaming it in
the workspace module keeps it in use.

## Session retention

Two-stage, handled by `ChatSessionAutoDeleter` and invoked by the
`cheddi:auto-delete-sessions` command:

1. **Soft-delete** sessions whose `last_activity` is older than `chatSessionLifetimeDays`
   (ext-conf, default **20** days). The row stays (`deleted=1`) so a future restore window
   is possible.
2. **Hard-delete** sessions soft-deleted for at least `HARD_DELETE_GRACE_DAYS` (**7** days),
   together with their messages, their `tx_cheddi_change` rows and the attachments still in
   ChEddi's upload folder that nothing else references any more, including another conversation.
3. **Sweep** uploads that were never sent: every upload is registered in `tx_cheddi_upload`, and
   one that is older than `chatSessionLifetimeDays`, still sits in a `cheddi` folder and is
   referenced by no conversation and no record is removed. A file someone put into such a folder by
   hand was never registered and is never touched; one moved out of the folder is forgotten.

Run it from the CLI:

```bash
vendor/bin/typo3 cheddi:auto-delete-sessions
```

…or register it as a recurring task in the TYPO3 scheduler module (requires
`typo3/cms-scheduler`). A daily cron is recommended. Without an active task none of this happens,
although the drawer still names the retention period; the Reports module warns about it.

## Open items

`cheddi:collect-open-items` runs the active checks and records what they find. Register it as a
recurring task in the scheduler module, daily is enough; without an active task the list stays empty
or outdated, and the Reports module warns about it.

```bash
vendor/bin/typo3 cheddi:collect-open-items
```

The command calls no model and costs no credits; only suggestions do. It looks at published standard pages in the default
language, so folders, hidden pages, custom page types and translations are not listed. An item
disappears by itself once its page was edited or its audit is older than 14 days. Grant
`enable_cheddi_open_items` to the groups that should see it; admins see it without that.

The predefined checks ship with ChEddi: pages without a description or SEO title, images without alternative text,
SEO and accessibility errors from audits, stale pages, broken links, missing translations and FrEddi questions without
an answer. A check this installation does not need is shown greyed out with the reason and cannot be switched on:
broken links need EXT:linkvalidator, missing translations a site with more than one enabled language, FrEddi questions
FrEddi, the SEO title EXT:seo. Once the requirement is met the check comes back as it was left. The recommended ones are
on without any setup; admins and groups
with `enable_cheddi_open_item_rules` switch the others on or off under *Manage checks*, grouped by area. *Suggest
checks* opens a short wizard: first the standard checks that would find something on this website, then checks
tailored to it: plain text fields most records of a table fill and some leave empty. This is computed on the installation, no
model is involved and nothing leaves it. Ticked ones are created for the whole
website the user may read. Deleted standard checks are offered again, unticked. *New check* is a short wizard for everyone who may manage checks: what should stand out (something is missing, not changed for a long time, a date, a word), for which content and which field, then the live number of hits before it is created. Admins can open a form with several conditions from its first step. A check selects records by data conditions and never calls a
model. Blocked tables are never offered. A check with more
than 1000 matches keeps its earlier items and reports the problem in the list and in the Reports module.

A task leads to the AI Suite where it can solve it, and only for groups that may open the target: missing page or image
metadata (an empty check on a field the Workflow Manager writes) opens the Workflow Manager or, for images, *Generate
File Metadata* in the Media module (`enable_massaction_generation`, write access to the file),
audit findings open the stored audit result or the audit overview (`enable_audit`), a missing translation opens the
page translation (`enable_translation_whole_page` or `enable_massaction_generation`). The link only preselects the form
(`aiSuitePreselect[...]`); nothing starts before the editor does, and the AI Suite checks every preselected value
against the user's field and language rights.

Suggestions are offered to groups without that link where a check reports an empty plain text field or the alternative text, title or
description of an image. Text is written by the model selected for the chat, images by the GDPR vision model in GDPR
mode and by the other vision model otherwise, each only with the group's model permission. The suggestion comes from
the page as visitors see it, in the record's language, or from the image, and follows the page's editorial rules.
Each suggestion costs credits by tokens; the button names the model and an estimate. *Accept selected* writes like
ChEddi does in the chat, into the draft workspace unless the write mode is live, marks the value as AI-generated, and
refuses when someone changed the field meanwhile. In live mode it asks for a second click.

A group is limited to the tables in its `tables_select`, the excluded fields in its
`non_exclude_fields` and a scope inside its page trees; the whole website is for admins only. A check
outside these limits is listed by name and cannot be changed by that group, and the preview and the
match count leave out pages the user may not read.

Predefined checks can also read data that has no TCA: findings of every audit type by severity, FrEddi
questions without an answer, pages unchanged for a number of days, links EXT:linkvalidator found broken and pages
without a translation in an enabled language of their site. Each of these items is only listed for groups that may use the matching
AI Suite module (`enable_audit`, the FrEddi module), on top of
read access to the page. FrEddi questions are attached to the start page of their site.

Another extension can add a check by implementing `AutoDudes\Cheddi\Service\Chat\OpenItem\OpenItemSource`.
It is picked up through the interface; it must not call a model or write to the site.

## Configuration

Extension configuration (`ext_conf_template.txt`):

| Key | Default | Description |
|---|---|---|
| `chatSessionLifetimeDays` | `20` | Days of inactivity before a session is soft-deleted, and the age at which an upload that was never sent is removed. |
| `chatUploadFolder` | *(empty)* | Combined identifier of the folder chat attachments are stored below, e.g. `2:/chat/`. Empty uses the editor's default upload folder. Point it at a non-public storage to keep attachments private; editors need write access to it. |
| `chatUploadRandomNames` | `1` | Store attachments under a random name prefix so a URL in a public storage cannot be guessed. Switch it off only for a non-public storage where the original names are wanted in the file list. |
| `chatForceGdpa` | `inherit` | Restrict ChEddi to GDPR-compliant models; `searchWeb` then runs only as a GDPR-compliant search and is withheld where none is available (experimental; `readWebPage` stays available, it contacts no AI provider). |
| `chatWriteMode` | `inherit` | Where writes land: draft `workspace` or `live`. |
| `chatAllowRawHtmlWrite` | `inherit` | Whether ChEddi may store raw markup in code editor fields. |
| `chatExcludedTables` | *(empty)* | Tables ChEddi must never read or write. |
| `chatExcludedTools` | *(empty)* | MCP tool names ChEddi must never offer, on top of the built-in list below. |
| `chatLogVerbose` | `inherit` | Whether the INFO trace goes to `var/log/cheddi.log`. Warnings and errors always go to `var/log/cheddi_warnings.log`, which no setting turns off. |
| `chatLogRedactionPatterns` | *(empty)* | Extra regex patterns redacted in the ChEddi log, on top of `mcpLogRedactionPatterns`. |
| `chatWebResearchAllowedDomains` | *(empty)* | Domains web research is restricted to, at most 10. A hard filter, not a ranking hint: a question whose answer lives on none of them returns no sources. Takes precedence over the block list. |
| `chatWebResearchBlockedDomains` | *(empty)* | Domains web research never returns, at most 10. Ignored while an allow list is set. |
| `chatWebResearchCountry` | *(empty)* | Two-letter ISO country the results are localised for. Empty takes the region of the default language of the first site that has one. |
| `chatWebResearchMaxSearches` | `0` | Searches one turn may run. `0` leaves it to the server. |

### Per backend group

The *ChEddi* tab of a backend group overrides part of this configuration for its members. Admins
always use the extension configuration. A group can only tighten what is security-relevant, so
the order of an editor's groups never decides how much they may do:

| Field | Combination across groups |
|---|---|
| `chatUploadFolder` | The first group with a value wins, as with `mediaStorageFolder` of the AI Suite. |
| `chatUploadRandomNames` | Can only be switched on. |
| `chatForceGdpa` | Can only be forced on. |
| `chatWriteMode` | Can only force a draft workspace, never live. |
| `chatAllowRawHtmlWrite` | Can only forbid. |
| `chatExcludedTools`, `chatExcludedTables` | The lists of all groups add up. |

The Reports module checks every group folder: one that does not exist is an error, one in a public
storage a warning.

ChEddi never offers these tools, whatever `chatExcludedTools` says, because each is reachable
through a backend module instead: `readServerInfo`,
`batchGenerateMetadata`, `batchGenerateFileMetadata`, `batchTranslatePage`,
`batchTranslateFileMetadata`, `readTaskStatus`, `readTaskResults` and `applyTaskResults`. `chatExcludedTools` adds to that list.

Every key with a counterpart in `ai_suite_mcp` (`mcpWriteMode`, `mcpAllowRawHtmlWrite`,
`mcpExcludedTables`, `mcpLogVerbose`, `mcpLogRedactionPatterns`) or in `ai_suite` (`forceGdpa`)
defaults to **inherit**, the list settings `chatExcludedTables` and `chatLogRedactionPatterns` to
empty: left empty or on `inherit`, ChEddi behaves exactly like the surface it inherits from. For most keys a configured value may only **tighten**: the table list and redaction
patterns are added to the MCP ones and never shorten them, `chatAllowRawHtmlWrite = Yes` cannot
re-enable what MCP forbids, and `chatForceGdpa = No` cannot take ChEddi out of an
installation-wide GDPR requirement. Two keys are ChEddi's own decision: `chatWriteMode` replaces
`mcpWriteMode` for ChEddi, so `live` writes live even while MCP writes to a workspace, and
`chatLogVerbose` switches `var/log/cheddi.log` independently of `mcpLogVerbose`.
The settings that tighten the shared MCP tools apply to the chat only; the MCP transport keeps its
own values.

The server endpoint and API key are **not** configured here; they are read from the parent
AI Suite ext-conf (`aiSuiteServer`, `aiSuiteApiKey`), so per-BE-group overrides apply
identically to the rest of AI Suite. The `chat*` keys above are read from this extension's own
configuration; the ones listed under *Per backend group* can be overridden there, the others cannot.

Without `aiSuiteApiKey` ChEddi does not start a turn at all: the drawer shows the same hint the
AI Suite module shows ("Please enter your AI Suite API key …") as a system message and keeps the
input locked, and a turn requested anyway comes back as `422` with `chatErrorCode: apiKeyMissing`
instead of a raw HTTP 401 from the server.

## Product and security information

User information under the EU Cyber Resilience Act (Regulation (EU) 2024/2847, Annex II).

### Manufacturer and product

- **Manufacturer:** AutoDudes GmbH & Co. KG, Ringstr. 24, 95191 Leupoldsgrün, Germany,
  [service@autodudes.de](mailto:service@autodudes.de), <https://www.autodudes.de>
- **Product:** ChEddi, Composer package `autodudes/cheddi`, extension key `cheddi`. The installed
  version is shown in the Extension Manager and in `composer.lock`.
- **Vulnerabilities:** report them to [security@autodudes.de](mailto:security@autodudes.de), see
  [SECURITY.md](SECURITY.md).
- **Support period:** while ChEddi is in beta (0.x), only the latest release receives security
  fixes. The five-year support period starts with the first stable major, see
  [SECURITY.md](SECURITY.md#supported-versions).
- **Software bill of materials:** available on request at security@autodudes.de.

### Intended purpose and security properties

ChEddi lets backend users work on TYPO3 content in plain language. It runs only for backend users
whose group holds `enable_cheddi_interface`, acts with exactly their permissions and asks for a
click before every write, two for a destructive one. Every turn goes over HTTPS to the AI Suite
Server operated by AutoDudes. The checks are listed under
[Permissions & security model](#permissions--security-model).

### Risks to know before use

- **The model can be steered by what it reads** (prompt injection): page content, an attached
  document or a web page can ask it to change other records. The confirmation shows every write;
  read it before you confirm, and keep `chatWriteMode` on `workspace` so a change stays a draft.
- **`chatWriteMode` replaces the MCP write mode, it does not tighten it.** With `live`, ChEddi
  publishes directly even where `mcpWriteMode` keeps MCP clients in a workspace; only a backend
  group can force a draft for its members.
- **Some writes cannot be undone by a workspace:** `generateImage`, `uploadMedia` and `moveFile`
  store or move files right away, see [Limitations](#limitations--accepted-trade-offs).
- **Attachments may be public.** With an empty `chatUploadFolder` they land in the editor's default
  upload folder, usually a public storage. Point it at a non-public storage. Random file name
  prefixes (`chatUploadRandomNames`, on by default) only make the URLs hard to guess, and the
  Reports module warns while attachments land in a public storage.
- **Web research sends queries out of the installation**; it is off by default
  (`enable_web_research`).
- **Conversations are stored.** They stay in the database until the retention removes them, see
  [Session retention](#session-retention).

### Secure use

- Grant `enable_cheddi_interface`, the other ChEddi flags and the chat models only to the groups that
  need them.
- Schedule `cheddi:auto-delete-sessions` daily; without it conversations are never deleted.
- Keep `chatLogVerbose` off in production; `var/log/cheddi.log` holds conversation content.
- Check the ChEddi entries in **System → Reports** after every configuration change.

### Updates

Security fixes are released as new versions. Install them with `composer update autodudes/cheddi`
or through the Extension Manager, then run **Admin Tools → Maintenance → Analyze Database
Structure** and flush the caches. ChEddi never installs updates by itself, so there is no automatic
update to switch off.

A known vulnerability in the installed version shows up for admins in **System → Reports**
(*AI Suite security updates*) and in the system information menu of the toolbar. AI Suite
provides that report; see its README.

ChEddi depends on the TYPO3 Core and on packages that Composer installs alongside it. Their security
fixes come from the TYPO3 Security Team and the package maintainers, not from AutoDudes: follow the
[TYPO3 security advisories](https://typo3.org/help/security-advisories) and keep TYPO3 on the latest
patch level of your major (`composer update "typo3/*"`). For TYPO3 12, Core security fixes from
12.4.46 on require TYPO3 Extended Long Term Support (ELTS). The minimum versions in `composer.json`
are technical requirements, not a statement that older patch levels are safe.

### Decommissioning

1. Remove the backend-group flags so the drawer disappears, and the Scheduler task that runs
   `cheddi:auto-delete-sessions`.
2. Uninstall the extension (`composer remove autodudes/cheddi`, or deactivate and delete it in the
   Extension Manager).
3. Run **Analyze Database Structure** twice: the first run renames the
   [ChEddi tables](#database-tables) and the added columns to `zzz_deleted_…`, the second drops them
   with all conversations, change logs and open items.
4. Delete the `cheddi` folders below the upload folder, which hold the chat attachments, the log
   files `var/log/cheddi*.log` and the `cheddi` entry under `EXTENSIONS` in `settings.php`.
5. ChEddi draft workspaces (`sys_workspace` with an owner set) stay; publish or delete their drafts
   in the workspace module first.

## Development

Static analysis uses the extension-local `phpstan.neon`. Code style follows the repo-wide
PHP-CS-Fixer setup.

## License

GPL-2.0-or-later. © AutoDudes, <https://www.autodudes.de/>
