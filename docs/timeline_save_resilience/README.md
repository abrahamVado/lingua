# Timeline Save Resilience Notes

The following remediations were exercised while restoring the **Save timeline** button inside
`pds_recipe_timeline`:

1. Verified the admin JS resends the recipe type query parameter even when AJAX rebuilds strip
   it from `data-pds-template-update-row-url`, preventing the timeline controller from falling
   back to the generic recipe handler.
2. Reset timeline UUIDs cached as the literal strings `"undefined"` or `"null"` before
   dispatching persistence attempts so the retry logic can promote the numeric identifier.
3. Extended the fallback heuristics that swap the UUID for the database id when the backend
   reports ownership or lookup errors, guaranteeing the modal always retries with a working
   identifier.
