<?php

namespace FPD;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {

    const OPTION_AMOUNT = 'fpd_discount_amount';
    const OPTION_TEXT   = 'fpd_discount_text';

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
    }

    public static function sanitize_discount_amount( $value ) {
        $value = wc_format_decimal( $value );

        if ( $value < 0 ) {
            $value = 0;
        }

        if ( $value > 100 ) {
            $value = 100;
        }

        return $value;
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

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>First Purchase Discount</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'fpd_settings_group' ); ?>

                <table class="form-table">
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
                                Example: 10 = 10% discount.
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
                                This text will appear in cart and checkout totals.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}