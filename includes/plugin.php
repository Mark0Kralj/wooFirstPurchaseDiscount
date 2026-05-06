<?php

namespace FPD;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        Settings::init();
        Discount::init();
    }
}