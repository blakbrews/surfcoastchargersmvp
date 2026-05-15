<?php
/**
 * Plugin Name: Blak Brews Wholesale Pricing Rules
 * Description: Custom wholesale pricing logic for Blak Brews via WooCommerce Wholesale Suite.
 * Version:     1.0.0
 * Author:      Blak Brews
 */

/**
 * Blak Brews – Wholesale Pricing Rules
 *
 * - Standard products: wholesale price at qty >= 5 only
 * - 1kg variations (Weight attribute = 1kg): wholesale from qty 1
 * - Fallback: no product discounts + cart retail subtotal >= $500 = 25% off all items
 * - Never stacks both rules simultaneously
 *
 * Runs at priority 999 — after Wholesale Suite (priority ~10).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_calculate_totals', 'blakbrews_wholesale_pricing', 999 );

function blakbrews_wholesale_pricing( $cart ) {

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! function_exists( 'wwp_get_wholesale_role_of_current_user' ) ) return;

    $wholesale_role = wwp_get_wholesale_role_of_current_user();
    if ( empty( $wholesale_role ) || ! is_array( $wholesale_role ) ) return;

    $role_key = $wholesale_role[0] ?? '';
    if ( empty( $role_key ) ) return;

    // ── Config ────────────────────────────────────────────────────────────────
    $moq            = 5;
    $cart_threshold = 500.00;
    $cart_discount  = 0.25;
    // ─────────────────────────────────────────────────────────────────────────

    $any_product_discount = false;

    // Pass 1 — product-level pricing
    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

        $product   = $cart_item['data'];
        $qty       = (int) $cart_item['quantity'];
        $parent_id = (int) $cart_item['product_id'];
        $item_id   = ! empty( $cart_item['variation_id'] )
                        ? (int) $cart_item['variation_id']
                        : $parent_id;

        // Reset to retail — neutralises anything Wholesale Suite applied
        $regular_price = (float) $product->get_regular_price();
        $product->set_price( $regular_price );

        // Get wholesale price for this role
        $meta_key        = $role_key . '_wholesale_price';
        $wholesale_price = (float) get_post_meta( $item_id, $meta_key, true );

        if ( $wholesale_price <= 0 && $item_id !== $parent_id ) {
            $wholesale_price = (float) get_post_meta( $parent_id, $meta_key, true );
        }

        if ( $wholesale_price <= 0 ) continue;

        // Check if this is a 1kg variation by Weight attribute
        $is_1kg = false;
        if ( ! empty( $cart_item['variation'] ) ) {
            foreach ( $cart_item['variation'] as $attr_key => $attr_value ) {
                if ( strpos( strtolower( $attr_key ), 'weight' ) !== false ) {
                    $normalized = strtolower( str_replace( ' ', '', $attr_value ) );
                    if ( $normalized === '1kg' || $normalized === '1000g' ) {
                        $is_1kg = true;
                    }
                    break;
                }
            }
        }

        if ( $is_1kg || $qty >= $moq ) {
            $product->set_price( $wholesale_price );
            $any_product_discount = true;
        }
    }

    // Pass 2 — cart subtotal fallback
    if ( ! $any_product_discount ) {

        $retail_subtotal = 0.0;
        foreach ( $cart->get_cart() as $item ) {
            $retail_subtotal += (float) $item['data']->get_price() * (int) $item['quantity'];
        }

        if ( $retail_subtotal >= $cart_threshold ) {
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $product->set_price( round( (float) $product->get_price() * ( 1 - $cart_discount ), 4 ) );
            }
        }
    }
}
