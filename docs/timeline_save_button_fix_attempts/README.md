# Timeline Save Button Fix Attempts

The timeline modal in `pds_recipe_timeline` was updated with three resilience fixes after
reproducing the "Save timeline" action hanging without feedback:

1. Added a collection iterator shim inside
   `pds_recipe_timeline/assets/js/pds-timeline.admin.js` so NodeList instances are traversed
   in browsers that lack native `forEach` support. This prevents runtime errors that aborted
   the save handler before issuing the network request.
2. Introduced a transport fallback in the same file that reuses `XMLHttpRequest` whenever
   `window.fetch` is unavailable. The retry guard now also switches to the numeric identifier
   when the UUID attempt is rejected with HTTP 422 responses.
3. Normalized timeline labels to Drupal's 512 character limit while collecting modal input so
   overlong descriptions no longer cause backend validation failures.

Together these changes restore the visual feedback on browsers without `fetch`, unblock legacy
administrative environments, and keep the save endpoint reachable when UUID lookups fail.
