# Timeline UUID Retry Notes

The following options were recorded while restoring the **Save timeline** action when
`pds_recipe_timeline` rows expose invalid UUIDs:

1. Reproduced the failure by observing `/pds-template/update-row` returning `404 Row not
   found.` whenever the modal dispatched the stale UUID without a fallback identifier.
2. Audited the admin helper (`pds_recipe_timeline/assets/js/pds-timeline.admin.js`) and
   confirmed `persistTimeline()` stopped immediately after the first failure instead of
   reusing the numeric identifier already available in the preview dataset.
3. Implemented a sequential retry so the modal now rebuilds the request URL and payload
   with the row id whenever the UUID attempt responds with 400/404 or an "invalid UUID"
   message.
4. Validated that `TimelineRowController::resolveRowUuid()` leverages numeric ids to
   regenerate canonical UUIDs, allowing the fallback request to persist and echo the
   repaired identifier back to the UI.
