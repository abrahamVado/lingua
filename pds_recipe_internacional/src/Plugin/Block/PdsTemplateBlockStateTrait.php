<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_internacional\Plugin\Block;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;

/**
 * Shared state helpers extracted from the main block plugin.
 */
trait PdsTemplateBlockStateTrait {

  /**
   * Locate a nested value in the form state across multiple parent paths.
   */
  private function extractNestedFormValue(FormStateInterface $form_state, array $candidate_parents, $default = NULL) {
    //1.- Evaluate every provided parent combination so Layout Builder subforms resolve the payload.
    foreach ($candidate_parents as $parents) {
      $normalized_parents = is_array($parents) ? $parents : [$parents];
      $value = $form_state->getValue($normalized_parents);
      if ($value !== NULL) {
        //2.- Normalize the value because Layout Builder sometimes wraps scalars in nested arrays.
        $coerced = $this->coerceFormStateScalar($value);
        if ($coerced !== NULL) {
          return $coerced;
        }

        //3.- Fall back to the original payload when the coercion did not find a scalar match.
        return $value;
      }
    }

    //3.- Gather every unique key name so deep searches target only relevant branches.
    $keys_to_scan = [];
    foreach ($candidate_parents as $parents) {
      $normalized_parents = is_array($parents) ? $parents : [$parents];
      $tail = end($normalized_parents);
      if (is_string($tail) && $tail !== '') {
        $keys_to_scan[$tail] = TRUE;
      }
    }

    if ($keys_to_scan !== []) {
      //4.- Inspect the entire values tree once to cover newly introduced Layout Builder wrappers.
      $values = $form_state->getValues();
      if (is_array($values)) {
        foreach (array_keys($keys_to_scan) as $key) {
          [$found, $resolved] = $this->searchFormValuesForKey($values, $key);
          if ($found) {
            //5.- Attempt to flatten the resolved value so callers receive the same scalar they expect.
            $coerced = $this->coerceFormStateScalar($resolved);
            if ($coerced !== NULL) {
              return $coerced;
            }

            return $resolved;
          }
        }
      }
    }

    //6.- Fall back to the supplied default when none of the candidate paths produced a value.
    return $default;
  }

  /**
   * Recursively search form values for the first match of the provided key.
   */
  private function searchFormValuesForKey(array $values, string $target): array {
    //1.- Honor direct matches immediately so shallow parents remain the fastest path.
    if (array_key_exists($target, $values)) {
      return [TRUE, $values[$target]];
    }

    //2.- Traverse nested arrays to support additional Layout Builder wrappers.
    foreach ($values as $value) {
      if (is_array($value)) {
        [$found, $resolved] = $this->searchFormValuesForKey($value, $target);
        if ($found) {
          return [TRUE, $resolved];
        }
      }
    }

    //3.- Return a miss marker so callers can continue scanning other branches.
    return [FALSE, NULL];
  }

  /**
   * Collapse Form API values into direct scalars when possible.
   */
  private function coerceFormStateScalar($value) {
    //1.- Accept primitive scalars immediately so the caller receives the exact submitted value.
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
      return $value;
    }

    if (is_array($value)) {
      //2.- Respect the Drupal pattern of storing values under a 'value' key before scanning children.
      if (array_key_exists('value', $value)) {
        $direct = $this->coerceFormStateScalar($value['value']);
        if ($direct !== NULL) {
          return $direct;
        }
      }

      //3.- Search the remaining children recursively so deeply nested wrappers still resolve.
      foreach ($value as $child) {
        $resolved = $this->coerceFormStateScalar($child);
        if ($resolved !== NULL) {
          return $resolved;
        }
      }
    }

    //4.- Signal that no scalar was discovered so callers can fall back to their defaults.
    return NULL;
  }

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
    //1.- Prefer temp state inside this LB dialog request so AJAX rebuilds keep the latest edits.
    if ($form_state->has('working_items')) {
      $tmp = $form_state->get('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    //2.- Inspect the temporary value bag used by nested subforms during AJAX operations.
    if ($form_state->hasTemporaryValue('working_items')) {
      $tmp = $form_state->getTemporaryValue('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    //3.- Reuse the snapshot stored in block configuration when available.
    $saved = $this->configuration['items'] ?? [];
    if (is_array($saved) && $saved !== []) {
      return array_values($saved);
    }

    //4.- Pull the canonical dataset from storage when configuration is empty but persisted rows exist.
    if (method_exists($this, 'loadItemsForBlock')) {
      $db_rows = $this->loadItemsForBlock();
      if (is_array($db_rows) && $db_rows !== []) {
        return array_values($db_rows);
      }
    }

    //5.- Return a normalized empty array when no cached or persisted rows were found.
    return [];
  }

}
