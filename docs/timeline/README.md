# Timeline Debug Log

## Context
Timeline entries inside the `pds_recipe_timeline` recipe were not persisting after clicking **Save timeline**.

## Attempts
1. Reviewed the administrative JavaScript (`assets/js/pds-timeline.admin.js`) to confirm the Save button invokes `submitModal()` and dispatches the persistence routine for timeline rows.
2. Compared the AJAX update flow against the shared `pds_recipe_template` controller to ensure timeline payloads mirror the expected structure and headers required by the backend endpoint.
3. Added a recovery path in `TimelineRowController::resolveRowUuid()` so rows with missing or corrupted UUIDs regenerate a fresh identifier based on their numeric database id before syncing timeline milestones.
