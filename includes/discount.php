<?php

namespace FPD;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Discount {

    public static function init() {
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_discount' ), 20 );
    }

    public static function apply_discount( $cart ) {

        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( ! $cart || $cart->is_empty() ) {
            return;
        }

        $discount_percent = Settings::get_discount_amount();

        if ( $discount_percent <= 0 ) {
            return;
        }

        $user_id       = get_current_user_id();
        $billing_email = self::get_customer_email();

        if ( ! $user_id && ! $billing_email ) {
            return;
        }

        if ( self::customer_has_previous_orders( $user_id, $billing_email ) ) {
            return;
        }

        $subtotal = (float) $cart->get_subtotal();

        if ( $subtotal <= 0 ) {
            return;
        }

        $discount = round(
            $subtotal * ( $discount_percent / 100 ),
            wc_get_price_decimals()
        );

        if ( $discount <= 0 ) {
            return;
        }

        $label = Settings::get_discount_text();

        $cart->add_fee(
            $label,
            -$discount,
            false
        );
    }

    private static function get_customer_email() {

        $billing_email = '';

        if ( function_exists( 'WC' ) && WC()->customer ) {
            $billing_email = WC()->customer->get_billing_email();
        }

        if ( empty( $billing_email ) && ! empty( $_POST['billing_email'] ) ) {
            $billing_email = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
        }

        if ( empty( $billing_email ) && ! empty( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $post_data );

            if ( ! empty( $post_data['billing_email'] ) ) {
                $billing_email = sanitize_email( $post_data['billing_email'] );
            }
        }

        if ( empty( $billing_email ) && is_user_logged_in() ) {
            $user = wp_get_current_user();

            if ( $user && ! empty( $user->user_email ) ) {
                $billing_email = sanitize_email( $user->user_email );
            }
        }

        return sanitize_email( $billing_email );
    }

    private static function customer_has_previous_orders( $user_id = 0, $billing_email = '' ) {

        $statuses = apply_filters(
            'fpd_previous_order_statuses',
            array(
                'wc-processing',
                'wc-completed',
                'wc-on-hold',
            )
        );

        if ( $user_id ) {
            $orders_by_user = wc_get_orders(
                array(
                    'customer_id' => $user_id,
                    'status'      => $statuses,
                    'limit'       => 1,
                    'return'      => 'ids',
                )
            );

            if ( ! empty( $orders_by_user ) ) {
                return true;
            }
        }

        if ( $billing_email ) {
            $orders_by_email = wc_get_orders(
                array(
                    'billing_email' => $billing_email,
                    'status'        => $statuses,
                    'limit'         => 1,
                    'return'        => 'ids',
                )
            );

            if ( ! empty( $orders_by_email ) ) {
                return true;
            }
        }

        return false;
    }
}