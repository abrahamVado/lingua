# Lingua Drupal Toolkit
> Opinionated module + recipe pack for investor-focused Drupal builds

<p align="center">
  <img src="docs/hero.png" width="880" alt="Lingua Toolkit hero">
  <br><sub>Enable <code>pds_suite</code>, apply recipes, ship consistent investor experiences.</sub>
</p>

[![CI](https://github.com/ORG/REPO/actions/workflows/ci.yml/badge.svg)](https://github.com/ORG/REPO/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/ORG/REPO)](https://app.codecov.io/gh/ORG/REPO)
![Drupal](https://img.shields.io/badge/Drupal-10.3%2B-0678BE)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4)
![Status](https://img.shields.io/badge/status-active-brightgreen)

**Jump to:** [TL;DR](#tldr) · [What’s inside](#whats-inside) · [Requirements](#requirements) · [Install](#install) · [Recipes](#available-recipes) · [Architecture](#architecture) · [Troubleshooting](#troubleshooting) · [Contributing](#contributing) · [License](#license)

---

## TL;DR
~~~bash
# 1) Enable base module
drush en pds_suite -y

# 2) Apply a recipe (example)
drush recipes:apply PDS_financial_education -y
~~~

> 🚧 **Heads-up:** Recipes are additive. Apply only what you need.

---

## What’s inside
- **Custom module** `modules/custom/pds_suite`  
  Menu links, routes, exportable config (`.info.yml`, `.routing.yml`, `.links.menu.yml`) used by recipes.
- **Recipe collection** `recipes/*/recipe.yml`  
  Packaged content models, views, and config for specific investor experiences. Each recipe depends on `pds_suite`.
- **Drupal core integration**  
  Uses Drupal Configuration Management. Applying a recipe syncs referenced config into your site.

---

## Requirements
- Drupal **10.3+**
- PHP **8.1+**
- Drush **11+**
- Drupal **Recipes** (ships with Drupal 10.3+)

---

## Install
1) **Place the module**
~~~
web/modules/custom/pds_suite/
~~~

2) **Enable it**
~~~bash
drush en pds_suite -y && drush cr
~~~

3) **Add desired recipes**
- Copy any recipe folder into your project’s `recipes/` directory, e.g.:
  ~~~
  recipes/PDS_financial_education
  recipes/PDS_market_perspective
  recipes/PDS_timeline
  ~~~
- Apply:
  ~~~bash
  drush recipes:apply PDS_financial_education -y
  ~~~

4) **Verify**
- In the admin UI, confirm new content types, menus, routes, and views appear.

---

## Available recipes
| Recipe key                 | Purpose                               | Notable pieces                          |
|---------------------------|----------------------------------------|-----------------------------------------|
| `PDS_financial_education` | Education hub for investors            | Content types, taxonomy, views          |
| `PDS_market_perspective`  | Updates and insights feed              | Listing view, teaser display modes      |
| `PDS_timeline`            | Milestones / chronology storytelling   | Timeline type, view, blocks             |

<details>
  <summary><strong>Quick apply (copy-ready)</strong></summary>

~~~bash
set -euo pipefail
drush en pds_suite -y
drush recipes:apply PDS_market_perspective -y
drush cr
~~~
</details>

---

## Architecture

flowchart TD
    A[Drupal Core] --> B[pds_suite Module]
    B --> C{Site Recipes}
    C --> C1[PDS Financial Education]
    C --> C2[PDS Market Perspective]
    C --> C3[PDS Timeline]
    C --> C4[Other Investor Experiences]
    B --> D[Menu & Routing Definitions]
    B --> E[Content Type Config]



---

## Common tasks
**Re-apply a recipe after tweaks**
~~~bash
drush recipes:apply PDS_market_perspective -y
~~~

**Remove config introduced by a recipe**
~~~bash
# Identify exact config names first, then delete:
drush config:delete <config_name>
~~~

**Full config export (for CI)**
~~~bash
drush cex -y
~~~

**Cache clear**
~~~bash
drush cr
~~~

---

## Local development tips
- Keep most custom config in `pds_suite/config/install` so recipes stay minimal and reusable.
- Reuse machine names across recipes to avoid collisions.
- Prefer view modes and display modes over per-node theming.
- Document breaking config changes in a `CHANGELOG.md`.

---

## Troubleshooting
- **“Command not found: recipes:apply”**  
  Ensure Drupal **10.3+** and Drush **11+**. Verify the Recipes feature is available in your build.
- **Config import conflicts**  
  Export a clean baseline (`drush cex -y`) or import pending config (`drush cim -y`) before applying recipes.
- **Missing menu/route after enable**  
  Clear caches: `drush cr`. Confirm `pds_suite` is enabled: `drush pm:list | grep pds_suite`.
- **Permissions**  
  Ensure your deploy user can write to `web/modules/custom/` and `recipes/`.

---

## Contributing
- Put new recipes under `recipes/<RecipeName>/recipe.yml`.
- Use PascalCase for recipe folder names; use the exact key when applying.
- Include a short `README.md` in each recipe folder describing content types and dependencies.
- Add or update badges (CI, coverage) as pipelines evolve.

---

## License
MIT. See `LICENSE`.

---

## Changelog
- **v0.1.0** Initial toolkit: `pds_suite` + three recipes.
