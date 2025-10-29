# Timeline Save Investigation

The following remediation steps were performed while diagnosing why the **Save timeline**
action inside the `pds_recipe_timeline` recipe modal was unresponsive:

1. Verified the admin modal (`pds_recipe_timeline/assets/js/pds-timeline.admin.js`) binds the
   delegated click handler for the **Save timeline** button and calls `submitModal()` correctly.
2. Inspected the persistence helper (`persistTimeline()`) to confirm it serializes timeline
   entries, preserves the current row metadata, and issues a `PATCH` request towards the
   update endpoint advertised by the admin form container.
3. Compared the generated endpoint attribute from the block builder
   (`pds_recipe_template/src/Plugin/Block/PdsTemplateBlock.php`) to ensure the query string
   still carries the `type=pds_recipe_timeline` marker required by the controller decorator.
4. Traced previous fixes captured in `docs/timeline_troubleshooting/README.md` to rule out
   regressions related to DOM rehydration, dataset synchronization, or automatic table
   provisioning.
5. Reproduced the failure path where the timeline modal receives an update URL without the
   UUID placeholder and confirmed the helper appended the row UUID **after** the query
   string, yielding malformed requests.
6. Updated the persistence helper so it now injects the UUID before the query parameters
   when the placeholder is absent, ensuring the `PATCH` request reaches the decorated
   timeline controller successfully.
