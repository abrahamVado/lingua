# Timeline Entry Save Worklog

The following remediation paths were exercised while restoring the **Save timeline**
action for the `pds_recipe_timeline` recipe:

1. Reviewed the admin modal helper (`pds_recipe_timeline/assets/js/pds-timeline.admin.js`)
   to confirm the click handler triggers `submitModal()` when **Save timeline** is
   pressed.
2. Traced the `persistTimeline()` flow and identified that legacy datasets still
   populate `row.id` with the `id:123` prefix, producing malformed update URLs and
   payload identifiers whenever the UUID is missing.
3. Added client-side normalization so the persistence helper strips the `id:` prefix
   before building the request URL, the payload `row_id`, and the fallback `row.id`
   value dispatched to the backend.
4. Extended the decorated controller (`pds_recipe_timeline/src/Controller/TimelineRowController.php`)
   with a shared normalizer that converts mixed identifier formats (including the `id:`
   prefix) into canonical integers before delegating to the template update routine.
5. Re-tested the modal save to verify the request now succeeds with both UUID-backed
   rows and legacy entries that only expose numeric identifiers.
