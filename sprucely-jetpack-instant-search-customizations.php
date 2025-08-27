<?php
/**
 * Plugin Name:       Sprucely — Jetpack Instant Search Wholesale Visibility
 * Description:       Hide the "wholesale" product category from Jetpack Instant Search results and filters for non-wholesale users (role: wholesale_customer).
 * Version:           1.0.1
 * Author:            Sprucely (sprucely.net)
 * Text Domain:       spr-jetpack-instant-search-wholesale-visibility
 * Requires at least: 6.3
 * Requires PHP:      7.4
 *
 * @package Spr_Jetpack_Instant_Search_Wholesale_Visibility
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * Security / Maintainability / Accessibility notes:
 * - No user-supplied input is processed; configuration is fixed server-side.
 * - We scope changes to Jetpack Instant Search via its documented filter, so normal queries, loops,
 *   and admin screens are unaffected. (Hook: jetpack_instant_search_options)
 * - The CSS/JS fallback only hides a specific facet option (product_cat=wholesale) for non-wholesale users.
 *   It sets [hidden] and aria-hidden to avoid keyboard/focus traps (WCAG 2.2.1 + 2.4.x considerations).
 */
final class Spr_Jetpack_Instant_Search_Wholesale_Visibility {

	/**
	 * Singleton instance.
	 *
	 * @var Spr_Jetpack_Instant_Search_Wholesale_Visibility|null
	 */
	private static $instance = null;

	/**
	 * Wholesale role key and category slug constants.
	 * These can be filtered if you ever need to change them.
	 */
	private const WHOLESALE_ROLE = 'wholesale_customer';
	private const WHOLESALE_SLUG = 'wholesale'; // product_cat term slug.

	/**
	 * Get singleton.
	 *
	 * @return Spr_Jetpack_Instant_Search_Wholesale_Visibility
	 */
	public static function spr_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook up behaviors.
	 */
	private function __construct() {
		add_filter( 'jetpack_instant_search_options', array( $this, 'spr_filter_instant_search_options' ), 999 );

		// Progressive enhancement: hide the wholesale facet option in the overlay sidebar for non-wholesale users.
		add_action( 'wp_enqueue_scripts', array( $this, 'spr_enqueue_overlay_fallback_assets' ) );
	}

	/**
	 * Determine if the current user should see wholesale products.
	 *
	 * @return bool True for wholesale users and admins/managers; false for retail/guests.
	 */
	private function spr_user_can_view_wholesale() {
		// Let shop managers / admins see everything for debugging & ops.
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		$role = (string) apply_filters( 'spr_wholesale_role_key', self::WHOLESALE_ROLE );
		return in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Modify Jetpack Instant Search options to exclude wholesale-only category for non-wholesale users.
	 *
	 * Jetpack hook docs show using `adminQueryFilter` for category exclusion; we extend it with role-awareness
	 * and target WooCommerce product categories via `taxonomy.product_cat.slug`. :contentReference[oaicite:1]{index=1}
	 *
	 * @param array $options Existing Instant Search options.
	 * @return array Modified options.
	 */
	public function spr_filter_instant_search_options( $options ) {
		if ( $this->spr_user_can_view_wholesale() ) {
			return $options;
		}

		$wholesale_slug = sanitize_title( (string) apply_filters( 'spr_wholesale_category_slug', self::WHOLESALE_SLUG ) );

		// Branch A: Anything that's NOT a product stays eligible.
		$not_product = array(
			'bool' => array(
				'must_not' => array(
					array( 'term' => array( 'post_type' => 'product' ) ),
				),
			),
		);

		// Branch B: It's a product, but NOT in the wholesale category.
		$product_not_wholesale = array(
			'bool' => array(
				'must'     => array(
					array( 'term' => array( 'post_type' => 'product' ) ),
				),
				'must_not' => array(
					array( 'term' => array( 'taxonomy.product_cat.slug' => $wholesale_slug ) ),
				),
			),
		);

		$options['adminQueryFilter'] = array(
			'bool' => array(
				'should'               => array( $not_product, $product_not_wholesale ),
				'minimum_should_match' => 1, // At least one branch must match.
			),
		);

		return $options;
	}

	/**
	 * Enqueue a tiny CSS+JS fallback that hides the 'wholesale' facet option in the overlay for non-wholesale users.
	 *
	 * This only runs for non-wholesale users. If Jetpack ever renders a zero-count facet value,
	 * this ensures it is visually and semantically hidden (no focusable link).
	 */
	public function spr_enqueue_overlay_fallback_assets() {
		if ( $this->spr_user_can_view_wholesale() ) {
			return;
		}

		$wholesale_slug = sanitize_title( (string) apply_filters( 'spr_wholesale_category_slug', self::WHOLESALE_SLUG ) );

		// Minimal, self-contained style—targets the facet link that Jetpack renders with data attributes.
		$css = <<<CSS
/* Accessibility: ensure the facet option is not perceivable or focusable for non-wholesale users. */
.jetpack-instant-search__overlay a.jetpack-search-filter__link[data-filter-type="taxonomy"][data-taxonomy="product_cat"][data-val="{$wholesale_slug}"] {
	display: none !important;
}
CSS;

		// We attach our own handle to guarantee printing even if theme doesn't enqueue block styles.
		wp_register_style( 'spr-jetpack-wholesale-css', false, array(), '1.0.1' );
		wp_enqueue_style( 'spr-jetpack-wholesale-css' );
		wp_add_inline_style( 'spr-jetpack-wholesale-css', $css );

		// Defensive JS: if markup differs, remove any matching facet links at runtime and mark hidden for assistive tech.
		$js = <<<JS
(function() {
	'use strict';
	var slug = '{$wholesale_slug}';

	function hideWholesaleFacet(root) {
		if (!root) { return; }
		var sel = 'a.jetpack-search-filter__link[data-filter-type="taxonomy"][data-taxonomy="product_cat"][data-val="' + slug + '"]';
		var nodes = root.querySelectorAll(sel);
		nodes.forEach(function(node) {
			node.setAttribute('aria-hidden', 'true');
			node.setAttribute('hidden', '');
			node.setAttribute('tabindex', '-1');
			node.style.display = 'none';
		});
	}

	// On load and whenever the overlay updates (Jetpack mutates DOM on search).
	document.addEventListener('DOMContentLoaded', function() {
		hideWholesaleFacet(document);
		var target = document.body;
		if (!('MutationObserver' in window) || !target) { return; }
		var mo = new MutationObserver(function(mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var m = mutations[i];
				if (m.addedNodes && m.addedNodes.length) {
					hideWholesaleFacet(document);
				}
			}
		});
		mo.observe(target, { childList: true, subtree: true });
	});
})();
JS;

		wp_register_script( 'spr-jetpack-wholesale-js', '', array(), '1.0.1', true );
		wp_enqueue_script( 'spr-jetpack-wholesale-js' );
		wp_add_inline_script( 'spr-jetpack-wholesale-js', $js, 'after' );
	}
}

Spr_Jetpack_Instant_Search_Wholesale_Visibility::spr_instance();
