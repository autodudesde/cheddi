# Changelog

All notable changes to ChEddi are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Security fixes are listed under **Security** and published as
[GitHub security advisories](https://github.com/autodudesde/cheddi/security/advisories); see
[SECURITY.md](SECURITY.md) for supported versions and how to report a vulnerability.

## [0.6.1] - 2026-11-27

### Added

- Added security advisories for ChEddi in the Reports module and the toolbar
- Added a security policy, the CRA user information and secure configuration notes to the documentation and manufacturer contact details in composer.json and ext_emconf.php

### Changed

- Improved unreadable attachments: ChEddi names the file types this installation can read, offers pasting the content as text, and tells a missing library apart from a missing PHP extension
- Bundled JavaScript libraries (marked, DOMPurify) are now built from a lock file and audited for known vulnerabilities before every build

### Fixed

- Fixed CSV and Markdown attachments, which the drawer refused although they were meant to be supported
- Fixed classic mode installations where another extension already ships smalot/pdfparser: Word and spreadsheet attachments are read again

### Security

- Updated the bundled PhpSpreadsheet to 3.10.10 and DOMPurify to 3.4.18

### Upgrade notes

- Please clear all caches after updating.
- Requires AI Suite MCP 0.13.1 or later.

## [0.6.0] - 2026-11-15

### Added

- Added open items: a scheduler command collects stale pages, findings of stored audits, missing translations, broken links and empty rich text fields, and the drawer shows them as one task per check behind a header button with their count. Each task leads to the feature that solves it (Workflow Manager, Generate File Metadata, stored audit, page translation) or hands a prepared request to the chat
- Added own open item checks: admins create them through a goal wizard (something is missing, unchanged for a long time, a date has passed or is coming up, a text contains a word) with a live hit count, and can edit, delete and restore the standard checks
- Added a full page view of the chat with the conversation list beside it
- Added search and rename for conversations, shown as cards with the open conversation marked
- Added a button that adds the open page to a message, and the drawer now stays open on clicks in the page or file tree
- Added drag and drop and pasting for attachments
- Added a retry for a failed turn without typing it again
- Show the editorial rules that apply to the open page
- Added "Fix with ChEddi" to audit findings the Audit module cannot fix itself
- Added the comparison of a draft with live to the chat
- Confirmation cards now preview bulk replace, image copies and replacements, page and record translations and audits
- Attachments placed on the site leave the chat folder under their own file name

### Changed

- Links from the chat open in the workspace of the conversation
- A CSV export now reads the records itself, so the file holds every matching record
- Tool calls of one step are folded into one line, and long found lists can be expanded
- Clearer messages for editors on timeouts, server errors and the step limit, and newly created records are named by their title
- Folders in media cards are named by storage and path
- Updated the bundled PhpSpreadsheet to 3.10.9 for its security patches

### Fixed

- Fixed PDF, Word and spreadsheet attachments being unreadable after installing the extension from TER on v12 and v13
- Fixed excluded tools still running when they are chat-only tools
- Fixed dry runs of the text edit tools asking for a confirmation
- Fixed the chat creating an element a second time that was already on the page
- Fixed the drawer covering TYPO3 modals and the missing message backgrounds on v12, and the module width on narrow v12 viewports
- Fixed the download button of a records export missing in a reopened conversation

### Upgrade notes

- Please clear all caches after updating and compare database.
- Open items need a scheduler task for the command cheddi:collect-open-items.
- Requires AI Suite 12.26.0, 13.20.0 or 14.8.0 and AI Suite MCP 0.13.0.

## [0.5.0] - 2026-10-28

### Added

- Added ChEddi's own backend group permissions (ChEddi Features and ChEddi Models), independent of the AI Suite and MCP permissions
- Added a ChEddi tab to backend groups to tighten settings per group (upload folder, GDPR mode, write mode, raw HTML, excluded tools and tables)
- Added media to the chat behind its own permission: uploading media, image attachments and moving files, each with a confirmation card
- Added a draft workspace of its own per user and a workspace choice per conversation
- Added separate permissions for whole-page and file metadata translation
- Added IONOS Qwen 3.8 as a second GDPR model, used by default in GDPR mode with Qwen 3.5 as fallback
- Updated the Claude chat models to Claude Haiku 5.5 and Claude Sonnet 5.5 and removed Claude Opus 5
- Showed the language of a navigation target as a badge
- Let other backend modules open the drawer with a prepared message

### Changed

- Stored chat attachments under random names, in a configurable folder (chatUploadFolder), and cleaned up uploads that were never sent
- Asked for confirmation before a web page is fetched whose URL the editor did not name
- Kept supplied text in its wording when the editor picks a generation model
- Explained a chat model the server withdrew and a missing session with localized messages
- Showed one low-balance notice before the exhaustion notice and took the low-balance decision from the server
- Kept the chosen model shown when the model select is rebuilt
- Described web research in GDPR mode as running on an EU provider

### Upgrade notes

- Please clear all caches after updating and compare database.
- ChEddi no longer reads the AI Suite permissions for the chat, so no group has ChEddi access after the update. Run the upgrade wizard cheddiSeparatePermissions (typo3 upgrade:run cheddiSeparatePermissions) to migrate them.
- The media permission (enable_cheddi_media_upload) starts off and is not migrated.
- Requires ai-suite-mcp v0.12.0.

## [0.4.0] - 2026-10-02

### Added

- Added a before/after preview of text edits to the confirmation card
- Added a language badge to the conversation changes list, so a page and its translation no longer appear as two identical rows on multilingual sites

### Changed

- Optimized the extension settings tabs
- Aligned the tool bridge with the slimmed-down AI Suite MCP context (no session key and token id handed over anymore)

### Upgrade notes

- This release requires AI Suite MCP v0.11.0 or later. Please clear all caches after updating.

## [0.3.1] - 2026-09-26

### Changed

- Revised the German backend translations

### Upgrade notes

- Please clear all caches after updating.
- Supports AI Suite MCP 0.10.

## [0.3.0] - 2026-09-22

### Breaking changes

- The settings `chatSearchAdditionalTables` and `chatExcludeAdditionalTablesFromSearch` are removed and existing values are ignored, because AI Suite MCP now resolves the searchable tables from the TCA. A table you kept out of the search through the second setting is searched again; move it to `chatExcludedTables`, which also blocks reads and writes.

### Added

- The drawer can be docked to the right edge: the backend shell gives up exactly that width, so the record and the conversation are visible at once instead of the drawer covering what it just opened. Undocked, closed or on a narrow viewport the width is handed back automatically.
- Records that a read found are now offered as links under the answer, stored audits come with buttons to open the result and download its CSV, and tabular answers can be downloaded as a CSV file that survives reopening the conversation.
- Added "execute all" beside "decline all" on the confirmation card, which decides every call whose own approve button is enabled; destructive calls keep their two-step arming. "Decline all" now only decides what is still open.
- The help modal opens on topic cards with a detail view and previous/next navigation instead of eight tabs, and messages show the time they were written, the conversation list the date and time of last use.
- The operating context now opens by telling the editor they are talking to an AI system, as Art. 50(1) of the EU AI Act requires, ahead of write mode and data protection.
- Added Claude Opus 5 to the model picker, including the backend group permission needed to grant it.
- Added support for installations without Composer: the document libraries ship bundled, so ChEddi runs in TYPO3 12, 13 and 14 classic mode, with the environment status reporting attachment types that cannot be read.

### Changed

- The drawer keeps its size after a resize instead of snapping back on the next page load.
- The drawer closes when a target button is clicked, so the record it opens is not hidden behind it; a download leaves it open.
- A conversation left while a write was waiting for approval offers its confirmation card again after reopening instead of being stuck for good.
- Download buttons now render after the answer and the tool call that built the file, in the same order as the restored history.
- `translatePage` and `translateRecord` called without a model are confirmed like any other write instead of running unconfirmed.
- Followed the collapsed tool catalogue of AI Suite MCP in the tool labels and the never-offered list, so a call no longer shows a stale name.
- Removed the now empty "Content search" subcategory from the extension configuration and shortened the prompt template scope label to ChEddi.

### Fixed

- Fixed timeout detection on Guzzle 8, where every connection failure ended as a generic error answer and a timeout lost its own error code.

### Upgrade notes

- Please clear all caches after updating.

## [0.2.1] - 2026-09-03

### Changed

- Raised the dependency constraints to the current releases: AI Suite v12.23.0 / v13.17.0 / v14.5.0 and AI Suite MCP v0.8.0.

## [0.2.0] - 2026-09-02

### Added

- The drawer now shows which tool a turn is actually running, and reports progress from the first turn on.
- Added an on-demand summary for long conversations.
- Reworked the drawer header, composer and status refresh, and kept the drawer usable next to modals, workspaces and prompt templates.
- Optimize the handling of the drawer's decisions and vocabularies (attachment caps, credit thresholds, operating-context sentences, labels, model gating)
- Extended and optimized Web research

### Changed

- A missing draft workspace is now named as such, workspace records are resolved through the shared service
- Each kind of chat request now has its own rate-limit bucket, and the turn request is bounded by timeouts.

### Fixed

- Fixed English backends rendering German label fallbacks
- Fixed the system domain being taken from the HTTP host instead of the site configuration.

### Upgrade notes

- Please clear all caches after updating and compare database.

## [0.1.0] - 2026-08-11

### Added

- First release of ChEddi, a conversational assistant that opens as a resizable drawer on top of any TYPO3 backend module and drives the AI Suite tools from natural language.
- Editors reach the same MCP tools an external client would, page tree, content generation, translation, metadata, image generation, record CRUD, limited to what their backend permissions already allow.
- Read-only tools run on their own, every write asks for a click and destructive calls ask twice. The decision stays in the conversation as an audit trail.
- Confirmed writes land in a draft workspace by default. The actions menu lists the records a conversation touched and publishes or discards them, per record or all at once.
- The drawer reports the current page and module with every turn, so answers relate to what the editor is looking at.
- Documents can be attached and are read as text on demand (PDF, Word, spreadsheets, plain text); a preflight says up front when a file cannot be read. Images can be attached but are not analysed.
- Web research sits behind the `enable_web_research` backend group flag and is experimental: `searchWeb` runs through the AI Suite Server and costs credits, `readWebPage` fetches one editor-named URL directly from the installation with no AI provider in the path.
- Sessions are stored per backend user, auto-titled from the first message, and can be reopened, switched or deleted. A scheduler command soft-deletes idle sessions and removes them after a grace period.
- Remaining credits show in the header, turn amber below a threshold and lock the input when they run out.
- Write mode, GDPR mode, raw-HTML writes, excluded tables and tools, searchable tables and logging are configurable per installation. Each of these either inherits from AI Suite / MCP or tightens it, never widens it.
- Assistant Markdown is rendered with `marked` and sanitised with `DOMPurify`, both vendored locally, before it reaches the DOM.

### Upgrade notes

- Please clear all caches after updating and compare database.
- ChEddi is beta. The feature set and the HTTP contract can still change between minor versions, so pin a version and read the release notes before upgrading.
- It requires EXT:ai_suite (12.22.0 or newer), EXT:ai_suite_mcp (0.7.0 or newer) and the TYPO3 system extensions workspaces and scheduler. The chat never talks to AI providers directly: every turn goes to the AI Suite Server, which holds the credentials, so an AI Suite account with credits is required. There is no bring-your-own-key path for the chat.
- Web research is off by default and experimental.
