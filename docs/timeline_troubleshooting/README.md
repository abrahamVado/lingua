# Timeline Troubleshooting Notes

The following checks were performed while addressing the "Save timeline" action issue inside the `pds_recipe_timeline` recipe:

- Confirmed the admin JavaScript (`pds_recipe_timeline/assets/js/pds-timeline.admin.js`) binds the **Save timeline** handler and that the modal collects year and description inputs before issuing a request.
- Reviewed the delegated template controller (`pds_recipe_template/src/Controller/RowController.php`) to verify the `/pds-template/update-row/{uuid}/{row_uuid}` endpoint accepts PATCH payloads with the timeline data.
- Inspected the block builder (`pds_recipe_template/src/Plugin/Block/PdsTemplateBlock.php`) to ensure the admin markup exposes the `data-pds-template-update-row-url` attribute used by the timeline modal.
- Validated the shared routing definition (`pds_recipe_template/pds_recipe_template.routing.yml`) still advertises the PATCH route required by the modal.
- Checked the database installer (`pds_mxsuite.install`) for the `pds_template_item_timeline` schema to confirm timeline entries have a storage table.
