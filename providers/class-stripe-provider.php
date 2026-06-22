<?php
/**
 * Stripe Payment Provider
 *
 * Wraps the existing Stripe checkout and webhook logic into the
 * LEM_Payment_Provider_Interface contract so other providers can replace it.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LEM_PLUGIN_DIR')) {
    require_once LEM_PLUGIN_DIR . 'services/payments/class-payment-provider-interface.php';
}

class LEM_Stripe_Provider implements LEM_Payment_Provider_Interface {

    public function get_name(): string {
        return 'Stripe';
    }

    public function get_id(): string {
        return 'stripe';
    }

    public function is_configured(): bool {
        $settings = get_option('lem_settings', array());
        $mode     = $settings['stripe_mode'] ?? 'test';
        $key      = $mode === 'live'
            ? ($settings['stripe_live_secret_key'] ?? '')
            : ($settings['stripe_test_secret_key'] ?? '');
        return !empty($key) && class_exists('\Stripe\Stripe');
    }

    public function create_checkout_session(array $args): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'Stripe is not configured.');
        }

        $settings = get_option('lem_settings', array());
        $mode     = $settings['stripe_mode'] ?? 'test';
        $key      = $mode === 'live'
            ? ($settings['stripe_live_secret_key'] ?? '')
            : ($settings['stripe_test_secret_key'] ?? '');

        try {
            \Stripe\Stripe::setApiKey($key);

            $session_args = array(
                'payment_method_types' => array('card'),
                'line_items'           => array(
                    array(
                        'price'    => $args['price_id'],
                        'quantity' => 1,
                    ),
                ),
                'mode'        => 'payment',
                'success_url' => $args['success_url'] ?? home_url('/confirmation?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url'  => $args['cancel_url']  ?? home_url('/'),
                'metadata'    => array(
                    'event_id'    => $args['event_id']    ?? '',
                    'event_title' => $args['event_title'] ?? '',
                    'email'       => $args['email']       ?? '',
                ),
            );

            if (!empty($args['email'])) {
                $session_args['customer_email'] = $args['email'];
            }

            // Allow companion plugins to modify the session arguments.
            $session_args = apply_filters('lem_stripe_session_args', $session_args, $args);

            $session = \Stripe\Checkout\Session::create($session_args);

            return array(
                'checkout_url' => $session->url,
                'session_id'   => $session->id,
            );
        } catch (\Exception $e) {
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    public function verify_webhook(): array|\WP_Error {
        $settings       = get_option('lem_settings', array());
        $mode           = $settings['stripe_mode'] ?? 'test';
        $webhook_secret = $mode === 'live'
            ? ($settings['stripe_live_webhook_secret']  ?? '')
            : ($settings['stripe_test_webhook_secret'] ?? '');

        if (empty($webhook_secret)) {
            return new \WP_Error('no_secret', 'Stripe webhook secret not configured.');
        }

        $payload    = (string) @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($sig_header)) {
            return new \WP_Error('missing_signature', 'Missing Stripe-Signature header.');
        }

        if (!class_exists('\Stripe\Webhook')) {
            return new \WP_Error('no_library', 'Stripe library not available.');
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\UnexpectedValueException $e) {
            return new \WP_Error('invalid_payload', $e->getMessage());
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new \WP_Error('invalid_signature', $e->getMessage());
        }

        if ($event->type !== 'checkout.session.completed') {
            // Return a typed event that callers can inspect; no LEM action needed.
            return array(
                'type'       => $event->type,
                'payment_id' => null,
                'event_id'   => null,
                'email'      => null,
                'raw'        => $event,
            );
        }

        $session  = $event->data->object;
        $event_id = $session->metadata->event_id ?? null;
        $email    = $session->metadata->email ?? $session->customer_details->email ?? null;

        return array(
            'type'       => 'checkout.completed',
            'payment_id' => $session->id,
            'event_id'   => $event_id,
            'email'      => $email,
            'raw'        => $event,
        );
    }

    public function finalize_checkout(string $reference_id, array $context = array()) {
        return new \WP_Error('not_applicable', 'Stripe checkout does not require finalize_checkout.');
    }

    public function get_payment_status(string $session_id): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'Stripe is not configured.');
        }

        $settings = get_option('lem_settings', array());
        $mode     = $settings['stripe_mode'] ?? 'test';
        $key      = $mode === 'live'
            ? ($settings['stripe_live_secret_key'] ?? '')
            : ($settings['stripe_test_secret_key'] ?? '');

        try {
            \Stripe\Stripe::setApiKey($key);
            $session = \Stripe\Checkout\Session::retrieve($session_id);
            return array(
                'paid'        => $session->payment_status === 'paid',
                'email'       => $session->customer_details->email ?? ($session->metadata->email ?? null),
                'event_id'    => $session->metadata->event_id ?? null,
                'payment_id'  => $session->id,
            );
        } catch (\Exception $e) {
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    public function get_settings_fields(): array {
        return array(
            array('key' => 'stripe_mode',               'label' => 'Mode',                'type' => 'select',   'description' => 'test or live'),
            array('key' => 'stripe_test_secret_key',    'label' => 'Test Secret Key',     'type' => 'password', 'description' => ''),
            array('key' => 'stripe_test_webhook_secret','label' => 'Test Webhook Secret', 'type' => 'password', 'description' => ''),
            array('key' => 'stripe_live_secret_key',    'label' => 'Live Secret Key',     'type' => 'password', 'description' => ''),
            array('key' => 'stripe_live_webhook_secret','label' => 'Live Webhook Secret', 'type' => 'password', 'description' => ''),
        );
    }

    public function validate_settings(array $settings): array {
        $out = array();
        foreach ($this->get_settings_fields() as $field) {
            $k = $field['key'];
            if (isset($settings[$k])) {
                $out[$k] = sanitize_text_field($settings[$k]);
            }
        }
        if (isset($out['stripe_mode']) && !in_array($out['stripe_mode'], array('test', 'live'), true)) {
            $out['stripe_mode'] = 'test';
        }
        return $out;
    }
}
