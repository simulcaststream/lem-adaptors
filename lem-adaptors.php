<?php
/**
 * Plugin Name: LEM Adaptors
 * Description: Official adaptors for Live Event Manager: Mux, OME, Stripe, PayPal, and Ably chat.
 * Version: 1.0.0
 * Author: Simulcast
 * License: GPL v2 or later
 * Text Domain: lem-adaptors
 * Requires Plugins: live-event-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load Composer dependencies (Stripe SDK). Falls back to core vendor when present.
 */
function lem_adaptors_load_dependencies(): void {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
        return;
    }
    if (defined('LEM_PLUGIN_DIR') && file_exists(LEM_PLUGIN_DIR . 'vendor/autoload.php')) {
        require_once LEM_PLUGIN_DIR . 'vendor/autoload.php';
    }
}

lem_adaptors_load_dependencies();

add_action('plugins_loaded', function () {
    if (! class_exists('LEM_Streaming_Provider_Factory')
        || ! class_exists('LEM_Payment_Provider_Factory')
        || ! class_exists('LEM_Chat_Provider_Factory')) {
        return;
    }

    add_filter('lem_streaming_provider_class_file', function ($path, $id) {
        $map = array(
            'mux' => __DIR__ . '/providers/class-mux-provider.php',
            'ome' => __DIR__ . '/providers/class-ome-provider.php',
        );
        return $map[ $id ] ?? $path;
    }, 10, 2);

    add_filter('lem_payment_provider_class_file', function ($path, $id) {
        $map = array(
            'stripe' => __DIR__ . '/providers/class-stripe-provider.php',
            'paypal' => __DIR__ . '/providers/class-paypal-provider.php',
        );
        return $map[ $id ] ?? $path;
    }, 10, 2);

    $streaming = LEM_Streaming_Provider_Factory::get_instance();
    $streaming->register_provider('mux', 'LEM_Mux_Provider');
    $streaming->register_provider('ome', 'LEM_OME_Provider');

    $payments = LEM_Payment_Provider_Factory::get_instance();
    $payments->register_provider('stripe', 'LEM_Stripe_Provider');
    $payments->register_provider('paypal', 'LEM_PayPal_Provider');

    add_filter('lem_chat_provider_class_file', function ($path, $id) {
        if ($id === 'ably') {
            return __DIR__ . '/providers/class-ably-chat-provider.php';
        }
        return $path;
    }, 10, 2);

    LEM_Chat_Provider_Factory::get_instance()->register_provider('ably', 'LEM_Ably_Chat_Provider');

    require_once __DIR__ . '/includes/class-paypal-capture.php';
    add_action('wp_ajax_nopriv_lem_paypal_capture', array('LEM_PayPal_Capture', 'handle'));
    add_action('wp_ajax_lem_paypal_capture', array('LEM_PayPal_Capture', 'handle'));
}, 20);

register_activation_hook(__FILE__, function () {
    $settings = get_option('lem_settings', array());
    if (!is_array($settings)) {
        $settings = array();
    }

    $settings += array(
        // Mux
        'mux_key_id'         => '',
        'mux_private_key'    => '',
        'mux_token_id'       => '',
        'mux_token_secret'   => '',
        'mux_webhook_secret' => '',

        // OME
        'ome_server_url'   => '',
        'ome_api_url'      => '',
        'ome_api_token'    => '',
        'ome_app_name'     => 'app',
        'ome_stream_name'  => '',
        'ome_signing_key'  => '',
        'ome_webrtc_port'  => 3333,
        'ome_llhls_port'   => 3334,
        'ome_token_ttl'    => 60,

        // Stripe
        'stripe_mode'                 => 'test',
        'stripe_test_publishable_key' => '',
        'stripe_test_secret_key'      => '',
        'stripe_test_webhook_secret'  => '',
        'stripe_live_publishable_key' => '',
        'stripe_live_secret_key'      => '',
        'stripe_live_webhook_secret'  => '',

        // PayPal
        'paypal_mode'              => 'sandbox',
        'paypal_sandbox_client_id' => '',
        'paypal_sandbox_secret'    => '',
        'paypal_live_client_id'    => '',
        'paypal_live_secret'       => '',
        'paypal_webhook_id'        => '',
        'paypal_currency'          => 'USD',

        // Ably chat
        'chat_provider' => 'ably',
        'ably_api_key'  => '',
    );

    update_option('lem_settings', $settings);
});
