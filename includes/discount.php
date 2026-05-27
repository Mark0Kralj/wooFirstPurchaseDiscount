<?php

namespace FPD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discount {

	const SESSION_AUTO_COUPON_CODE = 'fpd_auto_coupon_code';

	private static $syncing_coupon = false;

	public static function init() {

		add_action(
			'woocommerce_before_calculate_totals',
			array( __CLASS__, 'maybe_apply_coupon_discount' ),
			20,
			1
		);

		add_action(
			'woocommerce_cart_calculate_fees',
			array( __CLASS__, 'apply_discount' ),
			20,
			1
		);
	}

	public static function apply_discount( $cart ) {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		if ( 'coupon' === Settings::get_discount_method() ) {
			self::maybe_apply_coupon_discount( $cart );
			return;
		}

		self::maybe_apply_coupon_discount( $cart );

		$discount_percent = Settings::get_discount_amount();

		if ( $discount_percent <= 0 ) {
			return;
		}

		if ( ! self::is_eligible_for_first_purchase() ) {
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

	public static function maybe_apply_coupon_discount( $cart = null ) {

		if ( self::$syncing_coupon ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart && function_exists( 'WC' ) && WC()->cart ) {
			$cart = WC()->cart;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		self::$syncing_coupon = true;

		try {
			$method          = Settings::get_discount_method();
			$selected_coupon = Settings::get_coupon_code();
			$stored_coupon   = self::get_stored_auto_coupon_code();

			if ( 'coupon' !== $method || empty( $selected_coupon ) || $cart->is_empty() ) {
				self::remove_coupon_if_needed( $cart, $stored_coupon );
				self::remove_coupon_if_needed( $cart, $selected_coupon );
				self::clear_stored_auto_coupon_code();

				return;
			}

			if (
				$stored_coupon
				&& ! self::is_same_coupon_code( $stored_coupon, $selected_coupon )
			) {
				self::remove_coupon_if_needed( $cart, $stored_coupon );
			}

			if (
				! self::is_coupon_active( $selected_coupon )
				|| ! self::is_eligible_for_first_purchase()
			) {
				self::remove_coupon_if_needed( $cart, $selected_coupon );
				self::clear_stored_auto_coupon_code();

				return;
			}

			if ( ! $cart->has_discount( $selected_coupon ) ) {
				$applied = $cart->apply_coupon( $selected_coupon );

				if ( $applied ) {
					self::set_stored_auto_coupon_code( $selected_coupon );
				}
			} else {
				self::set_stored_auto_coupon_code( $selected_coupon );
			}
		} finally {
			self::$syncing_coupon = false;
		}
	}

	private static function is_eligible_for_first_purchase() {

		$user_id       = get_current_user_id();
		$billing_email = self::get_customer_email();

		if ( ! $user_id && ! $billing_email ) {
			return false;
		}

		if ( self::customer_has_previous_orders( $user_id, $billing_email ) ) {
			return false;
		}

		return true;
	}

	private static function is_coupon_active( $coupon_code ) {

		if ( empty( $coupon_code ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return false;
		}

		$coupon_id = wc_get_coupon_id_by_code( $coupon_code );

		if ( ! $coupon_id ) {
			return false;
		}

		$coupon = new \WC_Coupon( $coupon_id );

		if ( ! $coupon->get_id() ) {
			return false;
		}

		$expires = $coupon->get_date_expires();

		if ( $expires && $expires->getTimestamp() < current_time( 'timestamp', true ) ) {
			return false;
		}

		$usage_limit = (int) $coupon->get_usage_limit();
		$usage_count = (int) $coupon->get_usage_count();

		if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
			return false;
		}

		return true;
	}

	private static function remove_coupon_if_needed( $cart, $coupon_code ) {

		if ( empty( $coupon_code ) || ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		if ( $cart->has_discount( $coupon_code ) ) {
			$cart->remove_coupon( $coupon_code );
		}
	}

	private static function get_stored_auto_coupon_code() {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$coupon_code = WC()->session->get( self::SESSION_AUTO_COUPON_CODE, '' );

		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$coupon_code = wc_format_coupon_code( $coupon_code );
		}

		return $coupon_code;
	}

	private static function set_stored_auto_coupon_code( $coupon_code ) {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set(
			self::SESSION_AUTO_COUPON_CODE,
			$coupon_code
		);
	}

	private static function clear_stored_auto_coupon_code() {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->__unset( self::SESSION_AUTO_COUPON_CODE );
	}

	private static function is_same_coupon_code( $coupon_a, $coupon_b ) {

		if ( function_exists( 'wc_is_same_coupon' ) ) {
			return wc_is_same_coupon( $coupon_a, $coupon_b );
		}

		return strtolower( (string) $coupon_a ) === strtolower( (string) $coupon_b );
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

		if ( empty( $billing_email ) ) {
			$billing_email = self::get_customer_email_from_json_request();
		}

		if ( empty( $billing_email ) && is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $user && ! empty( $user->user_email ) ) {
				$billing_email = sanitize_email( $user->user_email );
			}
		}

		return sanitize_email( $billing_email );
	}

	private static function get_customer_email_from_json_request() {

		static $email = null;

		if ( null !== $email ) {
			return $email;
		}

		$email = '';

		$content_type = '';

		if ( ! empty( $_SERVER['CONTENT_TYPE'] ) ) {
			$content_type = strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) );
		}

		if ( false === strpos( $content_type, 'application/json' ) ) {
			return '';
		}

		$raw_body = file_get_contents( 'php://input' );

		if ( empty( $raw_body ) ) {
			return '';
		}

		$data = json_decode( $raw_body, true );

		if ( ! is_array( $data ) ) {
			return '';
		}

		$possible_paths = array(
			array( 'billing_address', 'email' ),
			array( 'billingAddress', 'email' ),
			array( 'customer', 'billing_address', 'email' ),
			array( 'customer', 'billingAddress', 'email' ),
			array( 'billing_email' ),
		);

		foreach ( $possible_paths as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					$value = null;
					break;
				}

				$value = $value[ $key ];
			}

			if ( ! empty( $value ) && is_email( $value ) ) {
				$email = sanitize_email( $value );
				return $email;
			}
		}

		return '';
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
					'type'        => 'shop_order',
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
					'type'          => 'shop_order',
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