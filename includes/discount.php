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

		add_filter(
			'woocommerce_order_item_product',
			array( __CLASS__, 'change_order_item_product_sku_by_coupon_discount' ),
			20,
			2
		);
	}

	public static function apply_discount( $cart ) {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$discount_method = Settings::get_discount_method();

		if ( 'disabled' === $discount_method ) {
			self::maybe_apply_coupon_discount( $cart );
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
	public static function change_order_item_product_sku_by_coupon_discount( $product, $item ) {

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $product;
		}

		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return $product;
		}

		$order = $item->get_order();

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $product;
		}

		$discount_data = self::get_order_item_discount_data_for_sku( $order, $item, $product );

		if ( empty( $discount_data['suffix'] ) ) {
			return $product;
		}

		$base_sku = self::get_base_sku_from_product_for_order_item( $product );

		if ( empty( $base_sku ) ) {
			return $product;
		}

		$discount_sku = $base_sku . $discount_data['suffix'];

		/*
		* IMPORTANT:
		* Clone product so we do NOT change the real WooCommerce product SKU.
		* This only changes the SKU when product is loaded through order item.
		*/
		$cloned_product = clone $product;

		try {
			$cloned_product->set_sku( $discount_sku );
		} catch ( \Exception $e ) {
			return $product;
		}

		return $cloned_product;
	}

	private static function get_order_coupon_discount_data_for_sku( $order ) {

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return array();
		}

		$coupon_codes = $order->get_coupon_codes();

		if ( empty( $coupon_codes ) ) {
			return array();
		}

		$selected_coupon = '';

		if ( class_exists( '\FPD\Settings' ) ) {
			$selected_coupon = Settings::get_coupon_code();
		}

		/*
		* First check the coupon selected in First Purchase Discount settings.
		*/
		if ( ! empty( $selected_coupon ) ) {
			foreach ( $coupon_codes as $coupon_code ) {
				if ( self::is_same_coupon_code( $coupon_code, $selected_coupon ) ) {
					$data = self::get_coupon_discount_data_for_sku( $coupon_code );

					if ( ! empty( $data ) ) {
						return $data;
					}
				}
			}
		}

		/*
		* Fallback: check any percentage coupon on the order.
		*/
		foreach ( $coupon_codes as $coupon_code ) {
			$data = self::get_coupon_discount_data_for_sku( $coupon_code );

			if ( ! empty( $data ) ) {
				return $data;
			}
		}

		return array();
	}

	private static function get_coupon_discount_data_for_sku( $coupon_code ) {

		$coupon = new \WC_Coupon( $coupon_code );

		if ( ! $coupon || ! $coupon->get_id() ) {
			return array();
		}

		if ( 'percent' !== $coupon->get_discount_type() ) {
			return array();
		}

		$percent = (float) $coupon->get_amount();
		$suffix  = self::get_sku_suffix_by_discount_percent( $percent );

		if ( empty( $suffix ) ) {
			return array();
		}

		return array(
			'coupon_code' => $coupon->get_code(),
			'percent'     => $percent,
			'suffix'      => $suffix,
		);
	}

	private static function get_base_sku_from_product_for_order_item( $product ) {

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return '';
		}

		$base_sku = $product->get_sku();

		/*
		* If variation has no SKU, fallback to parent product SKU.
		*/
		if ( empty( $base_sku ) && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();

			if ( $parent_id ) {
				$parent_product = wc_get_product( $parent_id );

				if ( $parent_product ) {
					$base_sku = $parent_product->get_sku();
				}
			}
		}

		return $base_sku;
	}
	private static function get_order_item_discount_data_for_sku( $order, $item, $product ) {

		if (
			! $order || ! is_a( $order, 'WC_Order' )
			|| ! $item || ! is_a( $item, 'WC_Order_Item_Product' )
			|| ! $product || ! is_a( $product, 'WC_Product' )
		) {
			return array();
		}

		$percent = self::get_order_item_effective_discount_percent( $item, $product );
		$suffix  = self::get_sku_suffix_by_discount_percent( $percent );

		if ( empty( $suffix ) ) {
			return array();
		}

		return array(
			'percent' => $percent,
			'suffix'  => $suffix,
		);
	}
	private static function get_sku_suffix_by_discount_percent( $percent ) {

		$percent = (float) $percent;

		/*
		* IMPORTANT:
		* Check from highest to lowest.
		*
		* 20% or more = SKU.4
		* 15% or more = SKU.3
		* 10% or more = SKU.2
		* 5%  or more = SKU.1
		*/
		if ( $percent >= 20 ) {
			return '.4';
		}

		if ( $percent >= 15 ) {
			return '.3';
		}

		if ( $percent >= 10 ) {
			return '.2';
		}

		if ( $percent >= 5 ) {
			return '.1';
		}

		return '';
	}
	
	private static function get_order_item_effective_discount_percent( $item, $product ) {

		$qty = (float) $item->get_quantity();

		if ( $qty <= 0 ) {
			$qty = 1;
		}

		/*
		* Final discounted line total INCLUDING tax.
		*
		* Example:
		* item total    = 1,666.67
		* item tax      = 333.33
		* full total    = 2,000.00
		*/
		$discounted_line_total_with_tax =
			(float) $item->get_total()
			+ (float) $item->get_total_tax();

		/*
		* Product regular price.
		* On your site this appears to be the full price including PDV.
		*/
		$regular_price = self::get_product_regular_price_for_discount_check( $product );

		if ( $regular_price > 0 ) {

			$regular_line_total_with_tax = $regular_price * $qty;

			if (
				$regular_line_total_with_tax > 0
				&& $discounted_line_total_with_tax < $regular_line_total_with_tax
			) {
				return round(
					(
						(
							$regular_line_total_with_tax - $discounted_line_total_with_tax
						) / $regular_line_total_with_tax
					) * 100,
					4
				);
			}
		}

		/*
		* Fallback:
		* WooCommerce order item subtotal INCLUDING tax.
		* This mainly catches coupon/order-level discounts.
		*/
		$line_subtotal_with_tax =
			(float) $item->get_subtotal()
			+ (float) $item->get_subtotal_tax();

		if (
			$line_subtotal_with_tax > 0
			&& $discounted_line_total_with_tax < $line_subtotal_with_tax
		) {
			return round(
				(
					(
						$line_subtotal_with_tax - $discounted_line_total_with_tax
					) / $line_subtotal_with_tax
				) * 100,
				4
			);
		}

		return 0;
	}

	private static function get_product_regular_price_for_discount_check( $product ) {

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return 0;
		}

		$regular_price = (float) $product->get_regular_price();

		/*
		* If variation regular price is empty, fallback to parent product.
		*/
		if ( $regular_price <= 0 && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();

			if ( $parent_id ) {
				$parent_product = wc_get_product( $parent_id );

				if ( $parent_product ) {
					$regular_price = (float) $parent_product->get_regular_price();
				}
			}
		}

		return $regular_price;
	}
}