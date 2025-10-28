# Updating the PDS Recipe Template module without Drush

//1.- Trigger Drupal's update.php script through the browser.
Open `https://your-site.example.com/update.php` while logged in as an administrator. When the
update UI loads, follow the wizard to apply any pending database updates. This executes the
same schema changes that `drush updb` would run, ensuring tables like
`pds_template_group` and `pds_template_item` are created when missing.

//2.- Run the update script from the command line when the web UI is unavailable.
If shell access is available but Drush is not installed, change into your Drupal webroot and
run `php core/scripts/update.php --module=pds_recipe_template`. This leverages Drupal's
built-in CLI script to process the module's schema updates without requiring Drush.

//3.- Clear caches after applying updates.
Use the site's performance admin page (`/admin/config/development/performance`) to clear caches,
or execute `php core/scripts/drupal quick-start --no-server` followed by the cache rebuild menu
option. Clearing caches ensures the freshly created tables are picked up by any cached schema
metadata.

//4.- Re-test the row creation workflow.
After the updates run and caches are cleared, retry saving a recipe template row. If the error
persists, inspect the `admin/reports/dblog` page for the exact exception message so the
underlying issue can be addressed directly.

//5.- Apply the latest schema recovery patch when update.php previously showed no work.
If you were already on Drupal 10 and update.php initially reported "no pending updates," pull the
latest code and rerun the update script. Update `9002` now rebuilds `pds_template_item` when legacy
columns such as `link` or `image_url` are still present, preventing the "Unable to create row." error.
