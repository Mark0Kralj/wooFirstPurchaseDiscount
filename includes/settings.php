<?php

namespace FPD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_AMOUNT = 'fpd_discount_amount';
	const OPTION_TEXT   = 'fpd_discount_text';
	const OPTION_METHOD = 'fpd_discount_method';
	const OPTION_COUPON = 'fpd_coupon_code';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'First Purchase Discount',
			'First Purchase Discount',
			'manage_woocommerce',
			'first-purchase-discount',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {

		register_setting(
			'fpd_settings_group',
			self::OPTION_METHOD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_discount_method' ),
				'default'           => 'fee',
			)
		);

		register_setting(
			'fpd_settings_group',
			self::OPTION_AMOUNT,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( __CLASS__, 'sanitize_discount_amount' ),
				'default'           => 10,
			)
		);

		register_setting(
			'fpd_settings_group',
			self::OPTION_TEXT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'First purchase discount',
			)
		);

		register_setting(
			'fpd_settings_group',
			self::OPTION_COUPON,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_coupon_code' ),
				'default'           => '',
			)
		);
	}

	public static function sanitize_discount_method( $value ) {
		$value = sanitize_text_field( $value );

		$allowed = array(
			'fee',
			'coupon',
		);

		if ( ! in_array( $value, $allowed, true ) ) {
			return 'fee';
		}

		return $value;
	}

	public static function sanitize_discount_amount( $value ) {
		$value = (float) wc_format_decimal( $value );

		if ( $value < 0 ) {
			$value = 0;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	public static function sanitize_coupon_code( $value ) {
		$value = sanitize_text_field( $value );

		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$value = wc_format_coupon_code( $value );
		}

		if ( empty( $value ) ) {
			return '';
		}

		if ( function_exists( 'wc_get_coupon_id_by_code' ) && ! wc_get_coupon_id_by_code( $value ) ) {
			return '';
		}

		return $value;
	}

	public static function get_discount_method() {
		$method = get_option( self::OPTION_METHOD, 'fee' );

		if ( ! in_array( $method, array( 'fee', 'coupon' ), true ) ) {
			$method = 'fee';
		}

		return $method;
	}

	public static function get_discount_amount() {
		$amount = get_option( self::OPTION_AMOUNT, 10 );

		return (float) $amount;
	}

	public static function get_discount_text() {
		$text = get_option( self::OPTION_TEXT, 'First purchase discount' );

		if ( empty( $text ) ) {
			$text = 'First purchase discount';
		}

		return $text;
	}

	public static function get_coupon_code() {
		$coupon_code = get_option( self::OPTION_COUPON, '' );

		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$coupon_code = wc_format_coupon_code( $coupon_code );
		}

		return $coupon_code;
	}

	public static function get_active_coupons() {

		$coupon_ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $coupon_ids ) ) {
			return array();
		}

		$active_coupons = array();

		foreach ( $coupon_ids as $coupon_id ) {
			$coupon = new \WC_Coupon( $coupon_id );

			if ( ! $coupon->get_id() ) {
				continue;
			}

			if ( ! self::is_coupon_active_for_dropdown( $coupon ) ) {
				continue;
			}

			$active_coupons[] = $coupon;
		}

		return $active_coupons;
	}

	private static function is_coupon_active_for_dropdown( \WC_Coupon $coupon ) {

		if ( ! $coupon->get_id() ) {
			return false;
		}

		$expires = $coupon->get_date_expires();

		if ( $expires && $expires->getTimestamp() < time() ) {
			return false;
		}

		$usage_limit = (int) $coupon->get_usage_limit();
		$usage_count = (int) $coupon->get_usage_count();

		if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
			return false;
		}

		return true;
	}

	private static function get_coupon_select_label( \WC_Coupon $coupon ) {
		$code   = $coupon->get_code();
		$type   = $coupon->get_discount_type();
		$amount = $coupon->get_amount();

		return sprintf(
			'%s (%s: %s)',
			$code,
			$type,
			$amount
		);
	}

	public static function render_settings_page() {
		$discount_method = self::get_discount_method();
		$selected_coupon = self::get_coupon_code();
		$coupons         = self::get_active_coupons();
		$selected_seen   = false;
		?>
		<div class="wrap">
			<h1>First Purchase Discount</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'fpd_settings_group' ); ?>

				<table class="form-table">

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_METHOD ); ?>">
								Discount method
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( self::OPTION_METHOD ); ?>"
								name="<?php echo esc_attr( self::OPTION_METHOD ); ?>"
							>
								<option value="fee" <?php selected( $discount_method, 'fee' ); ?>>
									Automatic cart fee discount
								</option>

								<option value="coupon" <?php selected( $discount_method, 'coupon' ); ?>>
									Automatically apply WooCommerce coupon
								</option>
							</select>

							<p class="description">
								Fee mode adds a negative cart fee. Coupon mode applies the selected WooCommerce coupon automatically.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_AMOUNT ); ?>">
								Discount amount (%)
							</label>
						</th>
						<td>
							<input
								type="number"
								min="0"
								max="100"
								step="0.01"
								id="<?php echo esc_attr( self::OPTION_AMOUNT ); ?>"
								name="<?php echo esc_attr( self::OPTION_AMOUNT ); ?>"
								value="<?php echo esc_attr( self::get_discount_amount() ); ?>"
							>

							<p class="description">
								Used only when discount method is set to automatic cart fee discount. Example: 10 = 10% discount.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_TEXT ); ?>">
								Discount text
							</label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="<?php echo esc_attr( self::OPTION_TEXT ); ?>"
								name="<?php echo esc_attr( self::OPTION_TEXT ); ?>"
								value="<?php echo esc_attr( self::get_discount_text() ); ?>"
							>

							<p class="description">
								Used only when discount method is set to automatic cart fee discount.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_COUPON ); ?>">
								First purchase coupon
							</label>
						</th>
						<td>
							<select
								id="<?php echo esc_attr( self::OPTION_COUPON ); ?>"
								name="<?php echo esc_attr( self::OPTION_COUPON ); ?>"
							>
								<option value="">— Select coupon —</option>

								<?php foreach ( $coupons as $coupon ) : ?>
									<?php
									$code = $coupon->get_code();

									if ( $selected_coupon && wc_is_same_coupon( $selected_coupon, $code ) ) {
										$selected_seen = true;
									}
									?>

									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected_coupon, $code ); ?>>
										<?php echo esc_html( self::get_coupon_select_label( $coupon ) ); ?>
									</option>
								<?php endforeach; ?>

								<?php if ( $selected_coupon && ! $selected_seen ) : ?>
									<option value="<?php echo esc_attr( $selected_coupon ); ?>" selected>
										<?php echo esc_html( $selected_coupon . ' (currently selected, but not active or not found)' ); ?>
									</option>
								<?php endif; ?>
							</select>

							<p class="description">
								Used only when discount method is set to WooCommerce coupon. The list includes published coupons that are not expired and have not reached their global usage limit.
							</p>

							<?php if ( empty( $coupons ) ) : ?>
								<p class="description" style="color:#b32d2e;">
									No active coupons found. Create a WooCommerce coupon first.
								</p>
							<?php endif; ?>
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}