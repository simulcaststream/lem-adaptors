<?php
/**
 * Plugin Name: LEM Adaptors
 * Plugin URI: https://github.com/simulcaststream/wp-lem-adaptors
 * Description: Official adaptors for Live Event Manager: Mux, OME, Stripe, PayPal, and Ably chat.
 * Version: 1.0.0
 * Requires Plugins: live-event-manager
 * Author: Simulcast
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lem-adaptors
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether Live Event Manager is installed and active.
 *
 * WordPress "Requires Plugins" only resolves plugins hosted on WordPress.org,
 * so we check the core plugin directly (folder: live-event-manager).
 */
function lem_adaptors_is_core_active(): bool {
    if (defined('LEM_VERSION')) {
        return true;
    }

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return is_plugin_active('live-event-manager/live-event-manager.php');
}

/**
 * Load Composer dependencies (Stripe PHP SDK).
 */
function lem_adaptors_load_dependencies(): void {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
}

function lem_adaptors_has_stripe_sdk(): bool {
    return class_exists('\Stripe\Stripe');
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

add_action('admin_notices', function () {
    if (!is_admin() || !current_user_can('activate_plugins')) {
        return;
    }

    if (!lem_adaptors_is_core_active()) {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'LEM Adaptors requires Live Event Manager to be installed and active (plugin folder: live-event-manager).',
            'lem-adaptors'
        );
        echo '</p></div>';
        return;
    }

    if (!lem_adaptors_has_stripe_sdk()) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'LEM Adaptors: Stripe PHP SDK not found. Run composer install in the lem-adaptors plugin directory, or use a release ZIP that includes vendor/.',
            'lem-adaptors'
        );
        echo '</p></div>';
    }
});

register_activation_hook(__FILE__, function () {
    if (!lem_adaptors_is_core_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            wp_kses_post(
                __('LEM Adaptors requires <strong>Live Event Manager</strong> to be installed and active first.', 'lem-adaptors')
            ),
            esc_html__('Plugin Activation Error', 'lem-adaptors'),
            array('back_link' => true)
        );
    }

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
        'paypal_sandbox_client_id'     => '',
        'paypal_sandbox_client_secret' => '',
        'paypal_live_client_id'        => '',
        'paypal_live_client_secret'    => '',
        'paypal_webhook_id'        => '',
        'paypal_currency'          => 'USD',

        // Ably chat
        'chat_provider' => 'ably',
        'ably_api_key'  => '',
    );

    // Migrate legacy PayPal secret option keys (pre-1.0.0 activation defaults).
    if (empty($settings['paypal_sandbox_client_secret']) && !empty($settings['paypal_sandbox_secret'])) {
        $settings['paypal_sandbox_client_secret'] = $settings['paypal_sandbox_secret'];
    }
    if (empty($settings['paypal_live_client_secret']) && !empty($settings['paypal_live_secret'])) {
        $settings['paypal_live_client_secret'] = $settings['paypal_live_secret'];
    }

    update_option('lem_settings', $settings);
});
