<?php
/**
 * Plugin Name: Woo First Purchase Discount
 * Description: Adds an automatic discount for customers making their first WooCommerce purchase.
 * Version: 1.0.0
 * Author: Lavit Solutions - MRK
 * Text Domain: first-purchase-discount
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FPD_FILE', __FILE__ );
define( 'FPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'FPD_URL', plugin_dir_url( __FILE__ ) );
define( 'FPD_VERSION', '1.0.0' );

add_action( 'plugins_loaded', function () {

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><strong>First Purchase Discount</strong> requires WooCommerce to be active.</p>
            </div>
            <?php
        } );

        return;
    }

    require_once FPD_PATH . 'includes/settings.php';
    require_once FPD_PATH . 'includes/discount.php';
    require_once FPD_PATH . 'includes/plugin.php';

    \FPD\Plugin::instance();
} );