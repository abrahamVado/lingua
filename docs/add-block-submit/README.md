# Layout builder "Add block" fix summary

## Completed adjustments

1. Updated the admin JavaScript behavior so the module remembers which submitter triggered the save and replays that exact control via `requestSubmit()` after committing pending edits. This keeps Drupal's AJAX workflow intact.
2. Added resilient fallbacks that retry the submission with a synthetic click and, as a final option, `form.submit()` for legacy browsers.
3. Ensured the new logic follows the existing super-comment conventions and documents each remediation step inline for future maintainers.
4. Captured pointer and keyboard activation to reliably remember Layout Builder's **Add block** button even when editors press Enter/Space instead of clicking, and added a final selector fallback that targets `data-drupal-selector="edit-actions-submit"` so the re-submission always finds Drupal's primary action.
5. Normalized the cached submitter by walking up from inner spans/icons to the actual submit button before calling `requestSubmit()`, preventing Layout Builder from silently ignoring the retry when decorators wrap the primary control.
6. Switched the block form container to `#tree` mode so the modal's hidden JSON (`cards_state`) stays nested under `pds_template_admin`, letting `blockSubmit()` read the saved rows when Layout Builder's **Add block** action fires.
7. Added a resilient form state extractor that checks both direct and `settings`-prefixed parent paths so `blockSubmit()` always receives the `cards_state` and `group_id` values regardless of how Layout Builder nests the subform.

