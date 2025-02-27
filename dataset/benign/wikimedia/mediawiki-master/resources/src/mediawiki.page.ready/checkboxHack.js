/*!
 * The checkbox hack works without JavaScript for graphical user-interface users, but relies on
 * enhancements to work well for screen reader users. This module provides required a11y
 * interactivity for updating the `aria-expanded` accessibility state, and optional enhancements
 * for avoiding the distracting focus ring when using a pointing device, and target dismissal on
 * focus loss or external click.
 *
 * The checkbox hack is a prevalent pattern in MediaWiki similar to disclosure widgets[0]. Although
 * dated and out-of-fashion, it's surprisingly flexible allowing for both `details` / `summary`-like
 * patterns and more complex (to be used sparingly), less component-like structures where the toggle
 * button and target are in different parts of the Document without an enclosing element, so long as
 * they can be described as a sibling to the input. It's complicated and frequent enough to warrant
 * single implementation.
 *
 * In time, proper disclosure widgets should replace checkbox hacks. However, the second pattern has
 * no equivalent so the checkbox hack may have a continued use case for some time to come.
 *
 * When the abstraction is leaky, the underlying implementation is simpler than anything built to
 * hide it. Attempts to abstract the functionality for the second pattern failed so all related code
 * celebrates the implementation as directly as possible.
 *
 * All the code assumes that when the input is checked, the target is in an expanded state.
 *
 * Consider the disclosure widget pattern first mentioned:
 *
 * ```html
 * <details>                                              <!-- Container -->
 *     <summary>Click to expand navigation menu</summary> <!-- Button -->
 *     <ul>                                               <!-- Target -->
 *         <li>Main page</li>
 *         <li>Random article</li>
 *         <li>Donate to Wikipedia</li>
 *     </ul>
 * </details>
 * ```
 *
 * Which is represented verbosely by a checkbox hack as such:
 *
 * ```html
 * <div>                                                 <!-- Container -->
 *     <input                                            <!-- Hidden checkbox -->
 *         type="checkbox"
 *         id="sidebar-checkbox"
 *         class="mw-checkbox-hack-checkbox"
 *         role="button"
 *         {{#visible}}checked{{/visible}}
 *         aria-labelledby="sidebar-button"
 *         aria-controls="sidebar">
 *     <label                                            <!-- Button -->
 *         id="sidebar-button"
 *         class="mw-checkbox-hack-button"
 *         for="sidebar-checkbox">
 *         Click to expand navigation menu
 *     </label>
 *     <ul id="sidebar" class="mw-checkbox-hack-target"> <!-- Target -->
 *         <li>Main page</li>
 *         <li>Random article</li>
 *         <li>Donate to Wikipedia</li>
 *     </ul>
 * </div>
 * ```
 *
 * Where the checkbox is the input, the label is the button, and the target is the unordered list.
 * Note the wrapping div container too.
 *
 * Consider the disparate pattern:
 *
 * ```html
 * <!-- ... -->
 * <!-- The only requirement is that the button and target can be described as a sibling to the
 *      checkbox. -->
 * <input
 *     type="checkbox"
 *     id="sidebar-checkbox"
 *     class="mw-checkbox-hack-checkbox"
 *     role="button"
 *     {{#visible}}checked{{/visible}}
 *     aria-labelledby="sidebar-button"
 *     aria-controls="sidebar">
 * <!-- ... -->
 * <label
 *     id="sidebar-button"
 *     class="mw-checkbox-hack-button"
 *     for="sidebar-checkbox">
 *     Click to expand navigation menu
 * </label>
 * <!-- ... -->
 * <ul id="sidebar" class="mw-checkbox-hack-target">
 *     <li>Main page</li>
 *     <li>Random article</li>
 *     <li>Donate to Wikipedia</li>
 * </ul>
 * <!-- ... -->
 * ```
 *
 * Which is the same as the disclosure widget but without the enclosing container and the input only
 * needs to be a preceding sibling of the button and target. It's possible to bend the checkbox hack
 * further to allow the button and target to be at an arbitrary depth so long as a parent can be
 * described as a succeeding sibling of the input, but this requires a mixin implementation that
 * duplicates the rules for each relation selector.
 *
 * Exposed APIs should be considered stable. @ignore is used for JSDoc compatibility (see T138401).
 *
 * Accompanying checkbox hack styles are tracked in T252774.
 *
 * [0]: https://developer.mozilla.org/docs/Web/HTML/Element/details
 */

/**
 * Checkbox hack listener state.
 *
 * TODO: Change to @-typedef when we switch to JSDoc
 *
 * @class {Object} CheckboxHackListeners
 * @property {Function} [onUpdateAriaExpandedOnInput]
 * @property {Function} [onToggleOnClick]
 * @property {Function} [onDismissOnClickOutside]
 * @property {Function} [onDismissOnFocusLoss]
 * @ignore
 */

/**
 * Revise the aria-expanded state to match the checked state.
 *
 * @param {HTMLInputElement} checkbox
 * @return {void}
 * @ignore
 */
function updateAriaExpanded( checkbox ) {
	checkbox.setAttribute( 'aria-expanded', checkbox.checked.toString() );
}

/**
 * setCheckedState() is called when a user event on some element other than the checkbox
 * should result in changing the checkbox state.
 *
 * @param {HTMLInputElement} checkbox
 * @param {boolean} checked
 * @return {void}
 * @ignore
 */
function setCheckedState( checkbox, checked ) {
	checkbox.checked = checked;
	updateAriaExpanded( checkbox );
}

/**
 * Returns true if the Event's target is an inclusive descendant of any the checkbox hack's
 * constituents (checkbox, button, or target), and false otherwise.
 *
 * @param {HTMLInputElement} checkbox
 * @param {HTMLElement} button
 * @param {Node} target
 * @param {Event} event
 * @return {boolean}
 * @ignore
 */
function containsEventTarget( checkbox, button, target, event ) {
	return event.target instanceof Node && (
		checkbox.contains( event.target ) ||
		button.contains( event.target ) ||
		target.contains( event.target )
	);
}

/**
 * Dismiss the target when event is outside the checkbox, button, and target.
 * In simple terms this closes the target (menu, typically) when clicking somewhere else.
 *
 * @param {HTMLInputElement} checkbox
 * @param {HTMLElement} button
 * @param {Node} target
 * @param {Event} event
 * @return {void}
 * @ignore
 */
function dismissIfExternalEventTarget( checkbox, button, target, event ) {
	if ( checkbox.checked && !containsEventTarget( checkbox, button, target, event ) ) {
		setCheckedState( checkbox, false );
	}
}

/**
 * Update the `aria-expanded` attribute based on checkbox state (target visibility) changes.
 *
 * @param {HTMLInputElement} checkbox
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindUpdateAriaExpandedOnInput( checkbox ) {
	var listener = updateAriaExpanded.bind( undefined, checkbox );
	// Whenever the checkbox state changes, update the `aria-expanded` state.
	checkbox.addEventListener( 'input', listener );
	return { onUpdateAriaExpandedOnInput: listener };
}

/**
 * Manually change the checkbox state to avoid a focus change when using a pointing device.
 *
 * @param {HTMLInputElement} checkbox
 * @param {HTMLElement} button
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindToggleOnClick( checkbox, button ) {
	function listener( event ) {
		// Do not allow the browser to handle the checkbox. Instead, manually toggle it which does
		// not alter focus.
		event.preventDefault();
		setCheckedState( checkbox, !checkbox.checked );
	}
	button.addEventListener( 'click', listener, true );
	return { onToggleOnClick: listener };
}

/**
 * Dismiss the target when clicking elsewhere and update the `aria-expanded` attribute based on
 * checkbox state (target visibility).
 *
 * @param {Window} window
 * @param {HTMLInputElement} checkbox
 * @param {HTMLElement} button
 * @param {Node} target
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindDismissOnClickOutside( window, checkbox, button, target ) {
	var listener = dismissIfExternalEventTarget.bind( undefined, checkbox, button, target );
	window.addEventListener( 'click', listener, true );
	return { onDismissOnClickOutside: listener };
}

/**
 * Dismiss the target when focusing elsewhere and update the `aria-expanded` attribute based on
 * checkbox state (target visibility).
 *
 * @param {Window} window
 * @param {HTMLInputElement} checkbox
 * @param {HTMLElement} button
 * @param {Node} target
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindDismissOnFocusLoss( window, checkbox, button, target ) {
	// If focus is given to any element outside the target, dismiss the target. Setting a focusout
	// listener on the target would be preferable, but this interferes with the click listener.
	var listener = dismissIfExternalEventTarget.bind( undefined, checkbox, button, target );
	window.addEventListener( 'focusin', listener, true );
	return { onDismissOnFocusLoss: listener };
}

/**
 * Dismiss the target when clicking or focusing elsewhere and update the `aria-expanded` attribute
 * based on checkbox state (target visibility) changes made by **the user.** When tapping the button
 * itself, clear the focus outline.
 *
 * This function calls the other bind* functions and is the only expected interaction for most use
 * cases. It's constituents are provided distinctly for the other use cases.
 *
 * @param {Window} window
 * @param {HTMLInputElement} checkbox The underlying hidden checkbox that controls target
 *   visibility.
 * @param {HTMLElement} button The visible label icon associated with the checkbox. This button
 *   toggles the state of the underlying checkbox.
 * @param {Node} target The Node to toggle visibility of based on checkbox state.
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bind( window, checkbox, button, target ) {
	return {
		onUpdateAriaExpandedOnInput: bindUpdateAriaExpandedOnInput( checkbox ).onUpdateAriaExpandedOnInput,
		onToggleOnClick: bindToggleOnClick( checkbox, button ).onToggleOnClick,
		onDismissOnClickOutside: bindDismissOnClickOutside( window, checkbox, button, target ).onDismissOnClickOutside,
		onDismissOnFocusLoss: bindDismissOnFocusLoss( window, checkbox, button, target ).onDismissOnFocusLoss
	};
}

/**
 * Free all set listeners.
 *
 * @param {Window} window
 * @param {HTMLInputElement} checkbox The underlying hidden checkbox that controls target
 *   visibility.
 * @param {HTMLElement} button The visible label icon associated with the checkbox. This button
 *   toggles the state of the underlying checkbox.
 * @param {CheckboxHackListeners} listeners
 * @return {void}
 * @ignore
 */
function unbind( window, checkbox, button, listeners ) {
	if ( listeners.onDismissOnFocusLoss ) {
		window.removeEventListener( 'focusin', listeners.onDismissOnFocusLoss );
	}
	if ( listeners.onDismissOnClickOutside ) {
		window.removeEventListener( 'click', listeners.onDismissOnClickOutside );
	}
	if ( listeners.onToggleOnClick ) {
		button.removeEventListener( 'click', listeners.onToggleOnClick );
	}
	if ( listeners.onUpdateAriaExpandedOnInput ) {
		checkbox.removeEventListener( 'input', listeners.onUpdateAriaExpandedOnInput );
	}
}

module.exports = {
	updateAriaExpanded: updateAriaExpanded,
	bindUpdateAriaExpandedOnInput: bindUpdateAriaExpandedOnInput,
	bindToggleOnClick: bindToggleOnClick,
	bindDismissOnClickOutside: bindDismissOnClickOutside,
	bindDismissOnFocusLoss: bindDismissOnFocusLoss,
	bind: bind,
	unbind: unbind
};
