<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;

/**
 * Shared state helpers extracted from the main block plugin.
 */
trait PdsTemplateBlockStateTrait {

  /**
   * Stash working_items in form_state for modal reuse.
   */
  private function setWorkingItems(FormStateInterface $form_state, array $items): void {
    //1.- Store the data on the immediate form state so simple rebuilds can reuse it.
    $form_state->set('working_items', $items);
    $form_state->setTemporaryValue('working_items', $items);

    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        //2.- Mirror the snapshot into the parent scope for AJAX callbacks triggered higher up.
        $parent->set('working_items', $items);
        $parent->setTemporaryValue('working_items', $items);
      }
    }
  }

  /**
   * Persist computed group id on the form state for AJAX rebuilds.
   */
  private function setGroupIdOnFormState(FormStateInterface $form_state, int $group_id): void {
    //1.- Save the id on the current form state so later requests keep the context.
    $form_state->set('pds_template_group_id', $group_id);
    //2.- Mirror into the temporary store for subform rebuilds triggered by AJAX.
    $form_state->setTemporaryValue('pds_template_group_id', $group_id);

    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        //3.- Bubble the id up so parent form handlers can also reach it.
        $parent->set('pds_template_group_id', $group_id);
        $parent->setTemporaryValue('pds_template_group_id', $group_id);
      }
    }
  }

  /**
   * Retrieve cached group id from the form state when available.
   */
  private function getGroupIdFromFormState(FormStateInterface $form_state): ?int {
    //1.- Look at the immediate form state bag first.
    if ($form_state->has('pds_template_group_id')) {
      $value = $form_state->get('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    //2.- Fallback to the temporary storage when inside AJAX subforms.
    if ($form_state->hasTemporaryValue('pds_template_group_id')) {
      $value = $form_state->getTemporaryValue('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    return NULL;
  }

  /**
   * Get items to prefill the modal when editing.
   */
  private function getWorkingItems(FormStateInterface $form_state): array {
    // Prefer temp state inside this LB dialog request.
    if ($form_state->has('working_items')) {
      $tmp = $form_state->get('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }
    if ($form_state->hasTemporaryValue('working_items')) {
      $tmp = $form_state->getTemporaryValue('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // Fallback to last saved snapshot in config.
    $saved = $this->configuration['items'] ?? [];
    return is_array($saved) ? array_values($saved) : [];
  }

}
