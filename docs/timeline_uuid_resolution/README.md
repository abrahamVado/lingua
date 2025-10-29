# Timeline UUID Resolution Attempts

The following remediation paths were documented while restoring the **Save timeline**
workflow for the `pds_recipe_timeline` recipe:

1. Confirmed the modal persistence helper still serializes the `row_id` attribute when
   the preview snapshot provides numeric identifiers instead of UUIDs.
2. Exercised the decorated controller (`pds_recipe_timeline/src/Controller/TimelineRowController.php`)
   to verify it reuses the shared template update logic after normalizing the identifiers.
3. Added a fallback branch in `TimelineRowController::resolveRowUuid()` so the route
   placeholder value is interpreted as a numeric identifier whenever the incoming UUID is
   missing, including the `id:123` legacy prefix used by historical datasets.
4. Re-ran the modal save flow to ensure timeline entries persist even when callers only
   provide numeric identifiers in the request payload or URL.
