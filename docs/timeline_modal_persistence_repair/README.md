# Timeline Modal Persistence Repair

The following remediation steps were implemented while restoring the **Save timeline**
interaction inside `pds_recipe_timeline`:

1. Replaced the `Promise.finally` usage in
   `pds_recipe_timeline/assets/js/pds-timeline.admin.js` with a guarded
   `.then(...).catch(...).then(...)` chain and introduced
   `setModalSavingState()` so legacy browsers without native `finally` support stop
   throwing errors when editors click **Save timeline**.
2. Added a POST retry path inside `persistTimeline()` so instances that reject PATCH
   requests still persist the modal payload by reusing the numeric identifier when
   necessary.
3. Extended `TimelineRowController::requestTargetsTimeline()` to detect mixed recipe
   type keys (such as `recipeType` or `recipe-type`) ensuring the decorated controller
   handles AJAX submissions even when integrators provide non-standard casing.

Together these changes unblock the button interaction, guarantee a transport fallback,
and keep timeline saves routed through the timeline-aware controller.
