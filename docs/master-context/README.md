# Consuming the PDS Template master context

//1.- Use the rendered data attributes as the entry point for master metadata.
Frontend widgets should locate the `.pds-template-display-wrapper` element and read the
`data-pds-template-master-uuid` and `data-pds-template-master-id` attributes. The UUID is
stable across deployments and is the recommended key for AJAX requests, while the numeric
ID is convenient for logging or analytics.

//2.- Bootstrap scripts through `drupalSettings` when multiple blocks are present.
The block build process injects a `drupalSettings.pdsRecipeTemplate.masters` object keyed by
the instance UUID. Each entry contains:

- `metadata`: group id, instance uuid, recipe type, and item count snapshot.
- `datasets`: derivative lists for desktop images, mobile images, and geo coordinates.

This allows per-instance initialization without querying the DOM repeatedly.

//3.- Fetch or display related records using the UUID.
Public-facing scripts can call bespoke endpoints (for example, `/api/recipes/{uuid}`) using
the instance UUID. When an endpoint returns enriched rows, merge them with the datasets
exposed in the template. The desktop and mobile lists should be treated as lookup tables
for galleries, while the `geo` list can seed map markers or store locators.

//4.- Handle the absence of extended datasets gracefully.
When a dataset array is empty, avoid firing extra network requests. The counts exposed via
`metadata.items_count` let you short-circuit fetch calls for empty masters and show fallback
UI messaging immediately.
