# Supporting the PDS Recipe Template module without shell access

//1.- Use your hosting control panel to inspect the database tables.
Log into the provider's database UI (such as phpMyAdmin, Adminer, or the "Databases" section of
your hosting dashboard). Select the site's database and run `SHOW TABLES LIKE 'pds_template_group';`
and `SHOW TABLES LIKE 'pds_template_item';`. If either query returns no rows, the module still needs
to create the table. Because these tools run entirely in the browser, you can perform the check
without SSH or Drush access.

//2.- Re-run Drupal's update.php installer from the browser to recreate missing tables.
While logged in as an administrator, visit `https://your-site.example.com/update.php`. When the
update UI prompts for maintenance confirmation, proceed through the wizard. The module's schema
updates will automatically recreate `pds_template_group` and `pds_template_item` when they are
missing or out of date. This is the same code path Drupal executes when `drush updb` runs.

//3.- Confirm the repair by saving a recipe template block instance.
After update.php completes, edit and re-save the block at `admin/structure/block`. Saving forces
the block to ask the `LegacySchemaRepairer` service for the group ID. If the tables are present,
the save will succeed and a new row will appear the next time you open the block form. If the tables
are still absent, Drupal will log a new "Unable to load schema repairer" message in the Recent log
messages report.

//4.- Use the Recent log messages report to monitor schema repair attempts.
Navigate to `admin/reports/dblog` and filter for the "pds_recipe_template" channel. The log will
confirm when the schema repairer recreates tables or when it cannot load the service. Monitoring
these messages gives you a feedback loop entirely within the admin UI.

//5.- Schedule a host-level cache clear if autoload errors persist.
If update.php reports success but the log still complains that `LegacySchemaRepairer` cannot be
autoloaded, open a ticket with your hosting provider asking them to clear the PHP opcode cache or to
run `composer dump-autoload` on your behalf. Without console access you cannot issue the commands
directly, but providers can execute them and confirm that Drupal's service container sees the class.
