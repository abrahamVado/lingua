# Block Edit Saved Items Bug

//1.- Issue Summary
- When editing an existing block, the tab labeled "B" does not display the saved items associated with that block.
- The issue prevents verification or modification of previously stored data within the tab during the edit flow.

//2.- Reproduction Steps
1. Create or locate a block that already has saved items assigned to tab B.
2. Initiate the edit action for that block from the interface.
3. Observe the contents of tab B once the edit view loads.

//3.- Observed Behavior
- Tab B appears empty or fails to render the list of saved items, even though the data exists in persistence.

//4.- Expected Behavior
- Tab B should immediately load and display all saved items tied to the block upon entering the edit flow.

//5.- Initial Hypotheses
- The edit initialization request might omit tab B data due to a missing include/filter when fetching the block payload.
- A state management regression could reset the tab B collection because of an unintended store mutation when switching tabs.
- Rendering logic might be gating tab B visibility on a flag that is not re-evaluated during edits.

//6.- Planned Investigation Steps (to discard options)
- Inspect the API response when entering edit mode to confirm whether tab B items are returned and rule out backend omissions.
- Trace the client-side state initialization for the edit form to verify tab B collections are populated before render.
- Review tab component lifecycle hooks to ensure they reload items when the edit view is opened.
- Add temporary logging around the reducer or store responsible for tab data to detect unintended resets.
- Compare the behavior with a previous known-good commit to determine if a recent change introduced the regression.
- Validate whether legacy blocks rely solely on the stored `group_id` and, if so, hydrate the missing `instance_uuid` from that identifier to keep tab B connected to historical rows.

//7.- Immediate Fix Attempt
- Adjust the PHP block loader so legacy records that only retained `group_id` still repopulate the hidden JSON state and refresh the stored `instance_uuid`, ensuring tab B receives rows during edits.
- Extend the row listing controller to honor an explicit `group_id` fallback and rewrite the related group record with the active UUID so legacy datasets render again without manual intervention.
- Verify that the controller listing call now reuses the repaired UUID by opening tab B immediately after launching the modal.
- Teach the admin behavior to auto-request the preview listing whenever the modal opens with an empty JSON payload so tab B hydrates legacy blocks without manual refreshes.
- Thread the stored `group_id` through the listing URL as an explicit fallback and let the controller repair the legacy record when the active UUID misses, keeping historical rows visible during edits.

//8.- Additional Notes
- Capture screenshots or network logs during reproduction to share findings with the team.
- Prioritize fixing any data loss risks if tab B modifications overwrite existing items.

//9.- Next Fix Experiments
- Confirm that the row listing endpoint returns the resolved `group_id` even when it relied on legacy fallbacks so the modal can rehydrate its hidden state automatically.
- Update the admin behavior to store the repaired `group_id` from the listing response back into the DOM attributes and hidden input, ensuring subsequent AJAX calls reuse the recovered identifier without staying stuck on `0`.
