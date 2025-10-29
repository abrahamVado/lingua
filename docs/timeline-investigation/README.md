# Timeline Save Troubleshooting Notes

## Context
- Documenting the investigative steps taken while diagnosing why the **Save timeline** button was unresponsive within the `pds_recipe_timeline` recipe.

## Actions Already Attempted
- Reviewed the timeline admin JavaScript (`pds_recipe_timeline/assets/js/pds-timeline.admin.js`) to confirm the modal click handlers and fetch workflow were registered correctly.
- Compared the shared template admin logic (`pds_recipe_template/assets/js/pds-template.admin.js`) to validate the expected request payload and endpoint behaviour.
- Inspected the timeline controller (`pds_recipe_timeline/src/Controller/TimelineRowController.php`) to verify UUID resolution and numeric identifier fallbacks on the backend.
- Traced the block form attributes emitted by `PdsTemplateBlock` to ensure the `data-pds-template-update-row-url` placeholder reached the admin UI unchanged.

## Outcome
- Implemented identifier normalisation on the client-side so rows without a valid UUID automatically fall back to their numeric identifier during save operations.
- Ensured the shared dataset cache stays in sync by reusing the normalised identifiers, preventing stale keys from blocking subsequent edits.
