# Phase 4 — Offline: Backend Architecture Plan

Backend/database work needed for IMS.md §7.1: offline create/edit, sync on reconnect, and
user-driven conflict resolution. The client (local storage, replay queue, merge UI) is out of
scope here, but the API contract below is designed for it.

## 1. What the codebase already gives us

The offline problem is ~60% solved by existing Phase 2 infrastructure. The design below
builds on these, and none of it is replaced:

- **Full version history + optimistic locking.** Every edit creates a
  `FormSubmissionVersion` row; `update()` checks the client's `version_number` under
  `lockForUpdate()` inside a transaction and 409s on mismatch
  (`app/Http/Controllers/Api/V1/Form/FormSubmissionController.php:97-129`). A
  `UNIQUE(submission_id, version_number)` index is the hard backstop
  (`database/migrations/2026_03_16_220622_*`). This *is* the conflict-detection engine an
  offline sync needs — offline just widens the window between read and write.
- **Template-version pinning.** Submissions validate against the `json_schema` of the
  template version pinned at creation (`app/Http/Requests/Form/UpdateFormSubmissionRequest.php`,
  `app/Rules/ContentMatchesTemplateSchema.php`), so a template evolving while a user is
  offline does **not** invalidate their queued edits. Schema drift is already handled.
- **Conflict notification.** `NotificationService::notifyConflict()` writes an in-app
  notification on every 409 (`app/Services/NotificationService.php:104-117`).
- **Live re-authorization.** Policies re-read role/team/department from the DB per request
  (`app/Services/Form/FormPermissionService.php:25-55`), not from JWT claims — replayed
  offline writes are automatically authorized against *current* grants.
- **No hard deletes to sync.** Submissions and templates have no `destroy` route; only
  permission grants can be deleted. So the pull protocol needs no tombstones.

## 2. What's missing (the gaps this plan closes)

| Gap | Consequence for offline | Where |
|---|---|---|
| No idempotency anywhere | Retried `POST form-submissions` on a flaky reconnect creates duplicates; retried updates double-increment versions | confirmed absent repo-wide |
| Bare 409 body (`{"message":"Version conflict"}`) | Client must issue extra GETs to learn the winning version; can't build a merge UI from the error | `FormSubmissionController.php:128` |
| Server-only auto-increment IDs | Offline-created submissions have no identity until synced; queued edits can't reference them | all models |
| No delta/pull endpoint | Client must re-download everything on every reconnect | no `updated_at`-since queries exist |
| Rejected offline work is discarded | A 409'd payload evaporates; "allow users to resolve conflict" (deliverable 3) has nothing to resolve from later or from another device | — |
| Fixed 14-day JWT refresh window (`refresh_iat=false`), 0s blacklist grace | User offline >14 days must re-login; parallel requests during refresh can 401 | `config/jwt.php:123-124,239` |
| Notifications sent synchronously (`Queueable` but not `ShouldQueue`) | A sync burst fans out admin emails inline, slowing replay | `app/Notifications/*.php` |
| Workflow/assign are last-write-wins outside the version scheme | `submit/approve/reject/assign` don't bump `version_number` and aren't conflict-checked | `WorkflowController.php`, `AssignmentController.php` |

## 3. Architecture decision

**Server-authoritative sync over the existing version model. No CRDTs, no operational
transforms, no event-sourced change log.**

The client keeps a local copy plus an ordered queue of pending operations. On reconnect it
(a) *pushes* the queue, (b) *pulls* deltas since its last cursor. The server stays the single
source of truth; conflict *detection* stays exactly where it is today (integer
`version_number` compare under row lock); conflict *resolution* is explicitly human — the
server never auto-merges JSON content. This matches the IMS.md deliverable ("allow users to
resolve conflict") and the existing 409 semantics.

Rejected alternatives (stress-tested):

- **CRDT / field-level server merge** — massive complexity for a form-filling app where
  concurrent editing of the *same submission* is the rare case, and the audit-trail
  invariant ("never update a version in place") already forbids in-place merging.
  Human-resolved 3-way merge on the client is sufficient and simpler.
- **Event-sourced change-log table for pull sync** — unnecessary because nothing relevant is
  hard-deleted; `updated_at` cursors + full permission snapshots cover it. Revisit only if
  delete routes are ever added.
- **Replaying against existing endpoints with no new sync endpoints at all** — tempting
  (least code), but it leaves the duplicate-create problem, the bare 409, and an N-round-trip
  reconnect with token-refresh races mid-loop. The primitives in Step A below fix the
  endpoints anyway; the batch push in Step C is a thin orchestration over them.

## 4. The plan

### Step A — Sync-ready write primitives (~2 days)

Make the *existing* create/update endpoints safe to retry and informative on conflict.
Everything later builds on this; it also benefits online clients immediately.

1. **Migration:** add `client_uuid` (uuid, nullable, unique) to `form_submissions` **and**
   `form_submission_versions`; add `base_version_number` (unsigned int, nullable) to
   `form_submission_versions`; add index on `form_submissions.updated_at`.
2. **Idempotent create:** `StoreFormSubmissionRequest` accepts optional `client_uuid`. In
   `store()`, if a submission with that uuid already exists, return it (200) instead of
   creating — a replayed create is a no-op. The unique index is the race backstop.
3. **Idempotent update:** `UpdateFormSubmissionRequest` accepts optional `client_uuid` (for
   the new *version* row) and records `base_version_number` (the `version_number` the edit
   was based on — same value as the lock check, persisted for audit/3-way merge). If a
   version with that uuid already exists, return current state (200) — a replayed update
   neither double-increments nor false-409s.
4. **Structured 409:** replace `abort(409, 'Version conflict')` with a JSON body carrying
   the winning state:
   ```json
   {
     "message": "Version conflict",
     "error": "version_conflict",
     "current_version": { "id": ..., "version_number": ..., "form_name": ...,
                           "content": {...}, "user": {...}, "created_at": ... },
     "your_base_version_number": 4
   }
   ```
   The client can render a merge UI from the error alone — no follow-up GET. Also give the
   approved-lock 403 a machine-readable `"error": "submission_locked"` so the client can
   distinguish "merge and retry" from "cannot retry".
5. **Extract a service:** move the transactional version-creation block (lock → compare →
   create version → advance `current_version_id`) from `FormSubmissionController::update`
   into `app/Services/Form/SubmissionSyncService` so Step C reuses it verbatim rather than
   duplicating the locking invariants.

Preserved invariants: versions are never mutated; `current_version_id` advances atomically
in the same transaction; optimistic check + `lockForUpdate()` + unique index all stay.

### Step B — Pull (delta) sync endpoint (~2-3 days)

`GET /api/v1/sync?cursor=<opaque>` (new `routes/api/v1/sync.php`, `auth:api`), returning
everything the client needs to work offline:

- **form_templates** changed since cursor (active only for non-admins, matching
  `FormTemplatePolicy::view`), each with its *current* template version (`json_schema` /
  `ui_schema` — this is what lets the client validate offline).
- **form_submissions** visible to the user changed since cursor, each with
  `currentVersion` embedded (reuse `FormSubmissionResource`).
- **permissions:** the user's *complete* resolved action set per template via the existing
  `FormPermissionService::resolvedPermissions()` — always full, never a delta, because
  grant rows are hard-deleted and a delta would miss revocations. It's small (3 booleans ×
  templates), so this costs nothing.
- **cursor:** server-issued timestamp (`now()` captured at query start, returned opaque),
  never the client's clock. Query with `updated_at >= cursor - 5s` overlap; the client
  upserts by id so re-receiving a row is harmless. First sync = no cursor = full bootstrap.

One real limitation to accept and document: gaining a *new* permission grant while offline
doesn't touch any submission's `updated_at`, so newly-visible old submissions won't appear
in a delta. Mitigation: the client compares the returned permission set with its cached one
and triggers a full (cursor-less) pull for templates whose permissions changed. Cheap,
correct, no server machinery.

### Step C — Push replay + persistent conflicts (~4-5 days)

1. **`POST /api/v1/sync/push`** — ordered array of operations:
   ```json
   { "operations": [
     { "op": "create", "client_uuid": "…", "form_template_id": 1,
       "form_template_version_id": 3, "form_name": "…", "content": {...}, "priority": "high" },
     { "op": "update", "ref": {"client_uuid": "…"} | {"id": 42},
       "version_client_uuid": "…", "base_version_number": 4,
       "form_name": "…", "content": {...} },
     { "op": "submit", "ref": {...} }
   ]}
   ```
   Semantics:
   - Each operation runs in **its own transaction** through `SubmissionSyncService` /
     `WorkflowController` logic — one bad op must not roll back the rest of the queue.
   - Ops may reference offline-created submissions by `client_uuid`; the handler resolves
     refs as it goes (a create earlier in the batch satisfies an update later in it).
   - Per-op authorization via the same policies (`create`/`update`/`submit`) — no bypass.
   - Per-op result: `applied | duplicate | conflict | forbidden | invalid`, each with the
     structured payload from Step A, plus a `mappings: {client_uuid: server_id}` block.
     HTTP 200 even with failed ops — the *envelope* succeeded; failures are data.
   - `submit` is the only workflow op accepted offline (creator-driven, Draft→Pending).
     `approve`/`reject`/`assign` are admin actions that make no sense queued offline —
     explicitly rejected as `invalid`. Re-submitting an already-pending submission maps to
     `duplicate`, not an error.
2. **`sync_conflicts` table** — deliverable 3 needs rejected work to survive:
   `id, form_submission_id FK, user_id FK, base_version_number, submitted_form_name,
   submitted_content json, status enum(open, resolved, discarded), resolved_version_id
   nullable FK, timestamps`. A `conflict` result from push (not plain online 409s — only
   the sync path) persists the losing payload here and fires the existing
   `notifyConflict()`. The user's offline work is never silently lost and is resolvable
   later, from any device.
3. **Resolution endpoints:**
   - `GET /api/v1/sync/conflicts` — my open conflicts (payload + the current server version
     side by side; client renders the diff/merge UI).
   - `POST /api/v1/sync/conflicts/{conflict}/resolve` — body: merged `form_name`/`content`
     (or `{"action": "discard"}`). Applies the merge as a **normal new version** through
     `SubmissionSyncService` against the *current* `version_number` (audit trail intact:
     server version, losing payload, and merged result are all preserved), marks the
     conflict `resolved`/`discarded`, links `resolved_version_id`. If the submission was
     approved meanwhile, resolution returns `submission_locked` — discard is the only exit.
4. **New files** follow the checklist in CLAUDE.md: `SyncController` +
   `SyncConflictController` under `Api/V1/Sync/`, `SyncPushRequest` /
   `ResolveConflictRequest`, `SyncConflictResource`, `SyncConflictPolicy` (owner-or-admin,
   registered in `AppServiceProvider`), factory + migration.

### Step D — Auth & operational hardening (~1 day)

- `JWT_REFRESH_IAT=true` (`config/jwt.php:123`) so the 14-day refresh window *rolls* on each
  refresh; a device that reconnects at least fortnightly never forces re-login. Document
  that >14 days fully offline still requires re-login (acceptable; queued work survives
  locally and pushes after login — push is authorized by policies, not token age).
- `JWT_BLACKLIST_GRACE_PERIOD=30` (`config/jwt.php:239`) so requests in flight during a
  token refresh don't 401. The batch push also minimizes concurrent-request exposure.
- Add `throttle` middleware to `auth/login`, `auth/refresh`, and the sync routes — there is
  currently **no rate limiting anywhere**, and reconnect storms are exactly when it matters.
- Make the four mail notifications implement `ShouldQueue` (queue driver is already
  `database`, jobs table exists) so a push replaying 20 creates doesn't send 20×admins
  emails inline. Note for ops: a queue worker must now actually run (`composer run dev`
  already starts one).

### Step E — Tests (woven through A–D, ~2 days of dedicated scenarios)

Pest feature tests per CLAUDE.md conventions (in-memory SQLite, factories, `actingAs($user,
'api')`), the sync-specific matrix being the point:

- Replay safety: same create/update op twice → one submission / one version, second returns
  `duplicate` with identical state.
- Conflict path: edit offline at v3, server moves to v4 → push returns `conflict` with v4
  payload, `sync_conflicts` row created, in-app notification exists; resolve → new v5,
  conflict `resolved`, versions v3/v4/v5 all present (audit intact).
- Approved-while-offline: push update → `forbidden`/`submission_locked`; resolve on a
  locked conflict → only discard succeeds.
- Permission revoked while offline: push → `forbidden`, no version created.
- Batch ordering: create + two updates + submit for one `client_uuid` in one push → final
  state v3/pending; one invalid op mid-batch doesn't poison neighbors.
- Pull: cursor returns only changed rows; permission snapshot always complete; overlap
  window doesn't duplicate-break the client contract (upsert-safe payload).
- Existing suites (`FormSubmissionTest`, workflow, notifications) still green — Step A
  touches the hot path.

## 5. Explicit non-goals

- **No server-side auto-merge** of `content` JSON — humans resolve, per the deliverable.
- **No offline `approve`/`reject`/`assign`** — admin online actions.
- **No websockets/push notifications, no background schedulers** — sync is client-initiated
  on reconnect; nothing here needs a cron.
- **No soft deletes / tombstones** — nothing syncable is deletable today. If delete routes
  are ever added (CLAUDE.md already flags this), the pull protocol must grow tombstones;
  that is the trigger to revisit, not before.
- **No changes to the ABAC model or JWT claims** — claims stay informational; live
  re-authorization on replay is a feature, not a bug.

## 6. Sequencing & estimate

A → B and A → C are hard dependencies (both reuse the Step A service and idempotency
columns); B and C are independent of each other and parallelizable. D is independent
config/middleware work, any time. Total ≈ 11-13 working days — inside the 2-3 week Phase 4
budget in IMS.md, with slack for client-contract iteration.

| Step | Deliverable (IMS.md §7.1) | Days |
|---|---|---|
| A — write primitives | foundations for 1 & 2 | 2 |
| B — pull sync | 1 (offline create/edit needs local data) & 2 | 2-3 |
| C — push + conflicts | 2 & 3 | 4-5 |
| D — auth/ops | reliability of 2 | 1 |
| E — test scenarios | — | 2 |
