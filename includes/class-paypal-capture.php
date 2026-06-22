<?php
/**
 * PayPal capture return handler.
 *
 * PayPal redirects the buyer back with ?token=ORDER_ID&event_id=POST_ID.
 * Access is granted via the payment provider interface (finalize_checkout + reconciliation in core).
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_PayPal_Capture {
    public static function handle(): void {
        $order_id = sanitize_text_field($_GET['token'] ?? '');
        $event_id = sanitize_text_field($_GET['event_id'] ?? '');

        if (empty($order_id)) {
            wp_die('Invalid PayPal return URL — missing order token.', 'Payment Error', array('response' => 400));
        }

        if (! class_exists('LEM_Payment_Provider_Factory')) {
            wp_die('LEM core is not active.', 'Payment Error', array('response' => 500));
        }

        $provider = LEM_Payment_Provider_Factory::get_instance()->get_provider('paypal');
        if (! $provider instanceof LEM_Payment_Provider_Interface || ! $provider->is_configured()) {
            wp_die('PayPal is not configured.', 'Payment Error', array('response' => 500));
        }

        $event_url = $event_id ? get_permalink((int) $event_id) : home_url('/');

        global $live_event_manager;
        if ($live_event_manager && method_exists($live_event_manager, 'reconcile_payment_session')) {
            $reconciled = $live_event_manager->reconcile_payment_session($order_id, 'paypal', $event_id);
            if ($reconciled && is_array($reconciled) && ! empty($reconciled['jwt']) && ! empty($reconciled['event_id'])) {
                $watch = method_exists($live_event_manager, 'get_event_url')
                    ? $live_event_manager->get_event_url((int) $reconciled['event_id'])
                    : $event_url;
                wp_safe_redirect($watch);
                exit;
            }
            if (is_wp_error($reconciled)) {
                wp_safe_redirect(add_query_arg('lem_payment_error', '1', $event_url));
                exit;
            }
        }

        wp_safe_redirect(add_query_arg(
            array(
                'lem_payment'  => 'processing',
                'paypal_order' => $order_id,
            ),
            $event_url
        ));
        exit;
    }
}
