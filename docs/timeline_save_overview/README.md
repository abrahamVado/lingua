# Timeline Save Options Catalog

This catalog tracks the remediation attempts exercised while restoring the **Save timeline**
action inside the `pds_recipe_timeline` recipe modal.

1. Validated the delegated click handler in
   `pds_recipe_timeline/assets/js/pds-timeline.admin.js` to ensure **Save timeline**
   triggers `submitModal()` and reaches the persistence helper. (See
   `docs/timeline_save_attempts/README.md`.)
2. Normalized legacy `id:123` identifiers on both the client helper and the decorated
   controller so timeline requests succeed even when UUIDs are missing. (See
   `docs/timeline_entry_save_options/README.md` and
   `docs/timeline_uuid_resolution/README.md`.)
3. Added sequential retries inside `persistTimeline()` so the modal automatically retries
   with the numeric id whenever the UUID save attempt fails for any reason, satisfying the
   "if the uuid is failing use the id" requirement captured during QA.
4. Confirmed the backend decorator (`pds_recipe_timeline/src/Controller/TimelineRowController.php`)
   provisions the auxiliary timeline table on demand and refreshes cached UUIDs whenever a
   numeric identifier is supplied. (See `docs/timeline_troubleshooting/README.md` and
   `docs/timeline_uuid_retry/README.md`.)

Each option above was validated against the admin modal to guarantee editors can persist
milestones reliably across both UUID-backed rows and legacy numeric entries.
