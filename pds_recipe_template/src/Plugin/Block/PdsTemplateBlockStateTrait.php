<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_template\Plugin\Block;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;

/**
 * Trait: PdsTemplateBlockStateTrait
 *
 * PURPOSE
 * =======
 * Small, focused helpers to:
 *  - Pull values out of notoriously nested Layout Builder form states.
 *  - Cache/retrieve ephemeral data ("working_items") across AJAX rebuilds.
 *  - Cache/retrieve the computed group id across AJAX rebuilds.
 *
 * CONTEXT
 * =======
 * - Layout Builder uses subforms + AJAX dialogs. During rebuilds, values can be
 *   wrapped in different containers (e.g. "settings" → "pds_template_admin" → …).
 * - Keys may slide around; sometimes only the final key name is stable.
 *
 * DESIGN NOTES
 * ============
 * - extractNestedFormValue() first tries exact parent paths (fast path), then
 *   falls back to a depth-first scan by *key name* (resilient path).
 * - coerceFormStateScalar() normalizes FAPI "value" wrappers (common in LB).
 * - All writes are mirrored into the temporary bag *and* into the parent
 *   FormState (when present) so both current and ancestor forms see the state.
 *
 * SAFETY
 * ======
 * - Read helpers never fatal on absent keys.
 * - Write helpers do not assume LB presence; they fallback gracefully.
 */
trait PdsTemplateBlockStateTrait {

  /**
   * Extract a nested value from FormState across *multiple* parent paths.
   *
   * WHY:
   *   Layout Builder modals can nest values under different parents depending on
   *   where the AJAX submit originates (block form, section form, override form).
   *
   * STRATEGY:
   *   1) Try each candidate parent array exactly as given (fast, precise).
   *   2) If not found, collect just the *last* key of each candidate path and
   *      scan the entire values array for the first occurrence of that key
   *      (robust when LB adds another wrapper).
   *   3) When we find an array-ish wrapper, use coerceFormStateScalar() to
   *      collapse typical FAPI shapes into a scalar (e.g., ['value' => 'foo']).
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state (may be a SubformState).
   * @param array $candidate_parents
   *   List of parent arrays (e.g., [['settings','pds_template_admin','group_id'], ['group_id']]).
   *   Strings are allowed and will be coerced to a single-element parent path.
   * @param mixed $default
   *   Returned when nothing is found or when only non-scalar wrappers exist.
   *
   * @return mixed
   *   The resolved scalar/array value, or $default when not found.
   */
  private function extractNestedFormValue(FormStateInterface $form_state, array $candidate_parents, $default = NULL) {
    // 1) Exact parent paths (fast path).
    foreach ($candidate_parents as $parents) {
      $normalized_parents = is_array($parents) ? $parents : [$parents];
      $value = $form_state->getValue($normalized_parents);
      if ($value !== NULL) {
        // 1.1) Normalize common FAPI shapes into a scalar if possible.
        $coerced = $this->coerceFormStateScalar($value);
        if ($coerced !== NULL) {
          return $coerced;
        }
        // 1.2) Fall back to the original raw value (could be an array).
        return $value;
      }
    }

    // 2) KEY-ONLY SCAN (robust path): search the entire values tree by last-key.
    $keys_to_scan = [];
    foreach ($candidate_parents as $parents) {
      $normalized_parents = is_array($parents) ? $parents : [$parents];
      $tail = end($normalized_parents);
      if (is_string($tail) && $tail !== '') {
        $keys_to_scan[$tail] = TRUE;
      }
    }

    if ($keys_to_scan !== []) {
      $values = $form_state->getValues();
      if (is_array($values)) {
        foreach (array_keys($keys_to_scan) as $key) {
          [$found, $resolved] = $this->searchFormValuesForKey($values, $key);
          if ($found) {
            $coerced = $this->coerceFormStateScalar($resolved);
            if ($coerced !== NULL) {
              return $coerced;
            }
            return $resolved;
          }
        }
      }
    }

    // 3) Nothing found → default.
    return $default;
  }

  /**
   * Depth-first search for the first occurrence of $target key.
   *
   * PERFORMANCE:
   * - O(n) in the number of nodes for the first match; we bail early once found.
   * - Called only after exact parent lookups failed.
   *
   * @param array $values
   *   Arbitrary tree of form values.
   * @param string $target
   *   Key to search for (e.g., 'group_id').
   *
   * @return array{0:bool,1:mixed}
   *   [TRUE, $value] if found; [FALSE, NULL] otherwise.
   */
  private function searchFormValuesForKey(array $values, string $target): array {
    // 1) Direct hit first.
    if (array_key_exists($target, $values)) {
      return [TRUE, $values[$target]];
    }

    // 2) DFS into children.
    foreach ($values as $value) {
      if (is_array($value)) {
        [$found, $resolved] = $this->searchFormValuesForKey($value, $target);
        if ($found) {
          return [TRUE, $resolved];
        }
      }
    }

    // 3) Miss.
    return [FALSE, NULL];
  }

  /**
   * Attempt to collapse FAPI shapes into direct scalars.
   *
   * Handles:
   *   - Plain scalars: string / number / bool → returned as-is.
   *   - Arrays like ['value' => 'foo'] → returns 'foo'.
   *   - Nested wrappers → recursively returns the first scalar it finds.
   *
   * Returns NULL when no scalar can be derived.
   *
   * @param mixed $value
   *   Any FAPI-ish structure.
   *
   * @return string|int|float|bool|null
   *   A scalar if found; NULL otherwise.
   */
  private function coerceFormStateScalar($value) {
    // 1) Primitive scalars: take them verbatim.
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
      return $value;
    }

    // 2) Common FAPI arrays.
    if (is_array($value)) {
      // 2.1) The canonical 'value' wrapper.
      if (array_key_exists('value', $value)) {
        $direct = $this->coerceFormStateScalar($value['value']);
        if ($direct !== NULL) {
          return $direct;
        }
      }
      // 2.2) Fallback: try each child until a scalar emerges.
      foreach ($value as $child) {
        $resolved = $this->coerceFormStateScalar($child);
        if ($resolved !== NULL) {
          return $resolved;
        }
      }
    }

    // 3) No scalar found.
    return NULL;
  }

  /**
   * Cache a working items array in all relevant state bags.
   *
   * WHY:
   *   - LB modals perform multiple AJAX rebuilds before final submit.
   *   - We want the latest "preview list" persisted across those rebuilds.
   *
   * SIDE EFFECTS:
   *   - Writes both to current state AND to its parent (when available).
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current (sub)form state.
   * @param array $items
   *   Normalized rows to be reused during the session.
   */
  private function setWorkingItems(FormStateInterface $form_state, array $items): void {
    // 1) Current scope.
    $form_state->set('working_items', $items);
    $form_state->setTemporaryValue('working_items', $items);

    // 2) Parent scope (if SubformState).
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        $parent->set('working_items', $items);
        $parent->setTemporaryValue('working_items', $items);
      }
    }
  }

  /**
   * Persist a computed group id for reuse during AJAX rebuilds.
   *
   * WRITES:
   *   - Persistent bag ('set') and temporary bag ('setTemporaryValue') in
   *     both current and parent states (when present).
   */
  private function setGroupIdOnFormState(FormStateInterface $form_state, int $group_id): void {
    // 1) Current scope.
    $form_state->set('pds_template_group_id', $group_id);
    $form_state->setTemporaryValue('pds_template_group_id', $group_id);

    // 2) Parent scope.
    if ($form_state instanceof SubformStateInterface && method_exists($form_state, 'getCompleteFormState')) {
      $parent = $form_state->getCompleteFormState();
      if ($parent instanceof FormStateInterface) {
        $parent->set('pds_template_group_id', $group_id);
        $parent->setTemporaryValue('pds_template_group_id', $group_id);
      }
    }
  }

  /**
   * Retrieve a previously cached group id from FormState.
   *
   * ORDER:
   *   1) Persistent value in current scope.
   *   2) Temporary value in current scope.
   *   (Parent mirroring is only for write-side convenience; reads from the
   *    current scope are sufficient for typical LB flows.)
   *
   * @return int|null
   *   The group id if present and numeric; NULL otherwise.
   */
  private function getGroupIdFromFormState(FormStateInterface $form_state): ?int {
    // 1) Persistent store (fast).
    if ($form_state->has('pds_template_group_id')) {
      $value = $form_state->get('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    // 2) Temporary store (AJAX-friendly).
    if ($form_state->hasTemporaryValue('pds_template_group_id')) {
      $value = $form_state->getTemporaryValue('pds_template_group_id');
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    return NULL;
  }

  /**
   * Resolve the "working_items" array to prefill the modal when editing.
   *
   * PRIORITY:
   *   1) 'working_items' from current/temporary state (freshest edits).
   *   2) Configuration snapshot on the block (fast and cacheable).
   *   3) Canonical DB rows via loadItemsForBlock() (when config is empty).
   *
   * NORMALIZATION:
   *   - Always returns a flat, re-indexed array (array_values()).
   *
   * @return array
   *   Normalized list of items for the UI.
   */
  private function getWorkingItems(FormStateInterface $form_state): array {
    // 1) Current scope cache (freshest).
    if ($form_state->has('working_items')) {
      $tmp = $form_state->get('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // 2) Temporary bag (AJAX rebuilds).
    if ($form_state->hasTemporaryValue('working_items')) {
      $tmp = $form_state->getTemporaryValue('working_items');
      if (is_array($tmp)) {
        return array_values($tmp);
      }
    }

    // 3) Block config snapshot.
    $saved = $this->configuration['items'] ?? [];
    if (is_array($saved) && $saved !== []) {
      return array_values($saved);
    }

    // 4) Canonical DB (if available in the host class).
    if (method_exists($this, 'loadItemsForBlock')) {
      $db_rows = $this->loadItemsForBlock();
      if (is_array($db_rows) && $db_rows !== []) {
        return array_values($db_rows);
      }
    }

    // 5) No rows anywhere → empty.
    return [];
  }

}
