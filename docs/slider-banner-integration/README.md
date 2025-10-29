# Slider Banner Integration

## Overview
This document summarizes how the Principal-style hero slider is rendered by the `pds_recipe_slider_banner` recipe. It also highlights the key assets that were introduced for the integration.

## Twig structure
The `templates/pds-slider-banner.html.twig` template now outputs a `<section>` element that mirrors the supplied markup snippet. Each stored row becomes an individual `<article>` slide that renders:

- An adaptive `<picture>` element using the `desktop_img` and `mobile_img` data when available.
- Headline, subtitle, and description fields with support for inline HTML emphasis.
- A configurable call-to-action button whenever a link is configured.
- Navigation controls (`Prev`, `Next`, and dot pagination) that are enhanced by the new JavaScript behavior.

## Styling
The `assets/css/pds-slider-banner.public.css` stylesheet defines the hero layout, gradients, buttons, arrow controls, and pagination dots. It also introduces CSS custom properties to simplify future brand adjustments.

## JavaScript behavior
The `assets/js/pds-slider-banner.public.js` behavior powers the slider without third-party dependencies. It handles:

1. Creating pagination dots that mirror the number of slides.
2. Managing autoplay with pause-on-hover and keyboard navigation.
3. Keeping slide visibility and ARIA attributes synchronized for accessibility.

The behavior is registered through `Drupal.behaviors.pdsSliderBanner` and is attached via the existing public library.

## Library updates
`pds_recipe_slider_banner.libraries.yml` now declares dependencies on `core/drupal` and `core/once` so Drupal behaviors and the `once()` utility are available when the slider initializes.

## Usage
Attach the “Slider banner” block to a layout and provide one or more rows with titles, descriptions, media, and links. The rendered block will automatically load the styles and scripts required for the slider experience.
