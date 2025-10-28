# Layout builder "Add block" fix summary

## Completed adjustments

1. Updated the admin JavaScript behavior so the module remembers which submitter triggered the save and replays that exact control via `requestSubmit()` after committing pending edits. This keeps Drupal's AJAX workflow intact.
2. Added resilient fallbacks that retry the submission with a synthetic click and, as a final option, `form.submit()` for legacy browsers.
3. Ensured the new logic follows the existing super-comment conventions and documents each remediation step inline for future maintainers.
4. Captured pointer and keyboard activation to reliably remember Layout Builder's **Add block** button even when editors press Enter/Space instead of clicking, and added a final selector fallback that targets `data-drupal-selector="edit-actions-submit"` so the re-submission always finds Drupal's primary action.

