# Offline Sync — Backend Guide

Phase 4 (Offline) proof-of-concept: offline create/edit, sync on reconnect, and
user-driven conflict resolution. Design rationale lives in
[`.claude/OFFLINE-PLAN.md`](../.claude/OFFLINE-PLAN.md); this document is the
usage reference for the implemented API.

All endpoints are under `/api/v1`, require a JWT bearer token (`auth:api`), and
the sync endpoints are rate-limited (`throttle:api`, 120 req/min per user).

## The client workflow at a glance

```
online                     offline                     reconnect
──────                     ───────                     ─────────
GET /sync (bootstrap) ──►  work locally:          ──►  1. POST /sync/push   (replay queue)
store cursor,              - create with               2. GET /sync?cursor=…(pull deltas)
templates, schemas,          client_uuid               3. GET /sync/conflicts
submissions,               - edit against known        4. user merges →
permissions                  version_number               POST /sync/conflicts/{id}/resolve
```

The client generates a UUID (`client_uuid`) for every submission it creates
offline and for every version (edit) it produces offline. These UUIDs make all
writes replay-safe: pushing the same queue twice never duplicates data.

---

## 1. Bootstrap / delta pull — `GET /sync`

Query params: `cursor` (optional ISO-8601 timestamp — the value returned by the
previous pull). No cursor = full bootstrap.

```
GET /api/v1/sync?cursor=2026-07-09T22:05:43+00:00
```

Response `200`:

```json
{
  "form_templates":   [ { "id": 1, "…": "…", "current_version": { "json_schema": {…}, "ui_schema": {…} } } ],
  "form_submissions": [ { "id": 42, "client_uuid": null, "status": "draft",
                          "current_version": { "version_number": 3, "content": {…}, "…": "…" } } ],
  "permissions":      [ { "form_template_id": 1, "actions": { "view": true, "create": true, "edit": false } } ],
  "cursor": "2026-07-09T22:11:02+00:00"
}
```

Semantics:

- **Templates**: admins see all; non-admins see active templates they may View.
  `current_version` carries the JSON Schema so the client can validate content
  offline.
- **Submissions**: rows whose `updated_at` moved since the cursor (matching the
  visibility stance of `GET /form-submissions`).
- **Permissions**: always the *complete* snapshot, never a delta (grant rows are
  hard-deleted, so a delta would miss revocations). If the snapshot differs from
  the client's cached copy for some template, do a full pull for that template.
- **Cursor**: server-issued; store it and send it back next time. Queries use a
  5-second overlap window, so a row can be re-received — upsert by `id`.
- Invalid cursor → `422`.

## 2. Replay the offline queue — `POST /sync/push`

Body: `{"operations": [...]}` — 1 to 100 ops, processed strictly in order, each
in its own transaction (one failing op never poisons the rest). Returns `200`
whenever the envelope is valid; per-op failures are data, not HTTP errors.

Op shapes:

```jsonc
// create — client_uuid required (the offline identity of the new submission)
{ "op": "create", "client_uuid": "…", "version_client_uuid": "…",
  "form_template_id": 1, "form_template_version_id": 3,
  "form_name": "Site Visit", "content": { … }, "priority": "high" }

// update — ref by server id OR by the client_uuid of a create (even one earlier in this batch)
{ "op": "update", "ref": { "client_uuid": "…" },
  "version_client_uuid": "…",            // required: uuid for the new version
  "base_version_number": 3,              // the version the offline edit was based on
  "form_name": "Site Visit", "content": { … } }

// submit — draft → pending_approval
{ "op": "submit", "ref": { "id": 42 } }
```

Response:

```json
{
  "results": [
    { "index": 0, "op": "create", "status": "applied", "id": 57, "client_uuid": "…",
      "version_number": 1, "submission_status": "draft" },
    { "index": 1, "op": "update", "status": "conflict", "id": 42, "conflict_id": 9,
      "current_version": { "id": 88, "version_number": 5, "form_name": "…", "content": { … } } },
    { "index": 2, "op": "update", "status": "forbidden", "error": "submission_locked" },
    { "index": 3, "op": "submit", "status": "invalid", "errors": { "op": ["…"] } }
  ],
  "mappings": { "<client_uuid>": 57 }
}
```

Per-op statuses:

| status | meaning | client action |
|---|---|---|
| `applied` | written; result carries `id` + current `version_number` | update local ids via `mappings` |
| `duplicate` | already applied earlier (replay) — success | same as applied |
| `conflict` | stale `base_version_number`; a `sync_conflicts` row was persisted and the user notified in-app | resolve via the conflict endpoints below |
| `forbidden` | policy denial, or `error: "submission_locked"` (submission approved while offline) | inform the user; not retryable |
| `invalid` | schema violation, unresolvable `ref`, malformed op, `approve`/`reject`/`assign` (online-only), or submit on a rejected submission | fix or drop the op |

Notes:

- Content is validated against the **pinned** template version's schema, so
  template changes while offline don't invalidate queued edits.
- `submit` on an already-pending/approved submission is `duplicate` (idempotent).
- Replaying a conflicting op reuses the existing open conflict — no duplicate
  conflict rows or notifications.
- Authorization is evaluated live per op (role/team/department read from the DB,
  not from the JWT), so grants revoked while offline reject the replayed write.

## 3. Conflict resolution

A `conflict` result means someone else advanced the submission while the user
was offline. The losing payload is preserved server-side, so it survives app
restarts and can be resolved from any device.

- `GET /sync/conflicts` — the caller's conflicts (admins: all), `?status=`
  filter (`open` default, also `resolved` / `discarded`), paginated. Each row
  carries the submitted (losing) payload plus the submission with its current
  server version — everything needed to render a side-by-side diff.
- `GET /sync/conflicts/{id}` — single conflict (owner or admin).
- `POST /sync/conflicts/{id}/resolve` — either:
  - `{"action": "discard"}` → keep the server version, mark `discarded`; or
  - `{"form_name": "…", "content": { … }}` → the user's merged result, applied
    as a **normal new version** against the current `version_number` (audit
    trail intact: server version, losing payload, and merge all preserved),
    marks the conflict `resolved` and links `resolved_version_id`.

Resolution errors: `422 conflict_not_open` (already handled),
`422` on schema-invalid merge content, `403 submission_locked` if the
submission was approved meanwhile (discard remains the only exit),
`409 version_conflict` if the merge races another writer twice.

## 4. Changes to existing endpoints

`POST /form-submissions` and `PUT /form-submissions/{id}` gained the same
primitives (usable directly if you prefer per-request replay over batching):

- Both accept an optional `client_uuid` (on update it names the **new version**).
  Replaying the same request returns current state instead of duplicating or
  falsely conflicting. Resources now expose `client_uuid` and
  `base_version_number`.
- The update 409 is now structured:

  ```json
  { "message": "Version conflict", "error": "version_conflict",
    "current_version": { "id": 88, "version_number": 5, "content": { … }, "…": "…" },
    "your_base_version_number": 3 }
  ```

- Updating an approved submission returns
  `403 {"error": "submission_locked"}` instead of a bare 403.

Behavior without `client_uuid` is unchanged, so existing online clients are
unaffected.

## 5. Auth & operational notes

- `.env`: `JWT_REFRESH_IAT=true` (the 14-day refresh window now *rolls* on each
  refresh — a device that reconnects at least fortnightly never re-logs-in;
  longer than that requires a fresh login, after which the local queue can
  still be pushed) and `JWT_BLACKLIST_GRACE_PERIOD=30` (in-flight requests
  during a token refresh don't 401).
- Rate limits: `throttle:auth` = 10/min per IP on login; `throttle:api` =
  120/min per user on authenticated auth routes and all sync routes.
- Notification emails now implement `ShouldQueue` — run a queue worker
  (`composer run dev` already starts one; standalone: `php artisan queue:work`).

## 6. Data model additions

- `form_submissions.client_uuid` (uuid, nullable, unique) + index on `updated_at`.
- `form_submission_versions.client_uuid` (uuid, nullable, unique) and
  `base_version_number` (nullable) — which version an edit was based on.
- `sync_conflicts`: `form_submission_id`, `user_id`, `base_version_number`,
  `submitted_form_name`, `submitted_content` (json), `status`
  (`open`/`resolved`/`discarded`), `resolved_version_id`.

New code: `App\Services\Form\SubmissionSyncService` (shared transactional write
path — all submission writes go through it), `App\Services\Sync\SyncPushHandler`
(per-op orchestration), controllers under `App\Http\Controllers\Api\V1\Sync`,
`SyncConflictPolicy` (owner-or-admin).

## 7. Running it

```bash
php artisan migrate                # two new migrations
php artisan test --compact         # full suite (388 tests)
php artisan test --compact tests/Feature/Api/V1/Sync   # sync suite only (45 tests)
```

Quick smoke test with the seeded admin (`admin@example.com` / `password`):

```bash
TOKEN=$(curl -s -X POST localhost/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r .access_token)

# bootstrap
curl -s localhost/api/v1/sync -H "Authorization: Bearer $TOKEN" | jq .cursor

# replay an offline create + edit
curl -s -X POST localhost/api/v1/sync/push -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{
    "operations": [
      { "op": "create", "client_uuid": "0b9f0f4e-7c1a-4baf-9d4e-111111111111",
        "version_client_uuid": "0b9f0f4e-7c1a-4baf-9d4e-222222222222",
        "form_template_id": 1, "form_template_version_id": 1,
        "form_name": "Offline demo", "content": {} },
      { "op": "update", "ref": {"client_uuid": "0b9f0f4e-7c1a-4baf-9d4e-111111111111"},
        "version_client_uuid": "0b9f0f4e-7c1a-4baf-9d4e-333333333333",
        "base_version_number": 1, "form_name": "Offline demo", "content": {} }
    ]}' | jq
# run it twice: every op comes back "duplicate", nothing duplicates
```

## 8. Known limitations (PoC scope)

- Workflow transitions and assignment are last-write-wins outside the
  version-conflict scheme (pre-existing design; only `content`/`form_name`
  edits are conflict-checked).
- `GET /sync` returns all matches unpaginated — fine for PoC data volumes.
- Submissions in the pull are not permission-filtered, matching the current
  stance of `GET /form-submissions` (no visibility filtering anywhere today);
  if the index ever gains filtering, mirror it in the pull.
- If delete routes are ever added to templates/submissions, the pull protocol
  needs tombstones (see plan §5).
