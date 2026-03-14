<?php
class ControllerExtensionPaymentStripe extends Controller {

    public function index() {
        $this->load->language('extension/payment/stripe');

        if (empty($this->session->data['order_id'])) {
            return '';
        }

        $csrf_token = bin2hex(random_bytes(32));
        $this->session->data['stripe_csrf_token'] = $csrf_token;

        $intent = $this->createPaymentIntent();
        if (!$intent) {
            return '<div class="alert alert-danger">' . $this->language->get('error_generic') . '</div>';
        }

        // Map OpenCart language code to a Stripe-supported locale
        $lang_code  = $this->config->get('config_language');
        $locale_map = array(
            'tr-tr' => 'tr', 'de-de' => 'de', 'fr-fr' => 'fr',
            'nl-nl' => 'nl', 'it-it' => 'it', 'es-es' => 'es',
            'pl-pl' => 'pl', 'pt-pt' => 'pt', 'en-gb' => 'en',
        );
        $stripe_locale = isset($locale_map[$lang_code]) ? $locale_map[$lang_code] : 'auto';

        $data = array(
            'publishable_key' => $this->config->get('payment_stripe_publishable_key'),
            'client_secret'   => $intent['client_secret'],
            'csrf_token'      => $csrf_token,
            'stripe_locale'   => $stripe_locale,
            'confirm_url'     => $this->url->link('extension/payment/stripe/confirm', '', true),
            // Return URL for redirect-based methods (iDEAL, Bancontact, etc.)
            'return_url'      => $this->url->link('extension/payment/stripe/complete', '', true),
            'button_confirm'  => $this->language->get('button_confirm'),
            'text_loading'    => $this->language->get('text_loading'),
            'error_generic'   => $this->language->get('error_generic'),
            'error_payment'   => $this->language->get('error_payment'),
        );

        return $this->load->view('extension/payment/stripe', $data);
    }

    private function createPaymentIntent() {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/stripe');

        $order_id   = (int)$this->session->data['order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            return false;
        }

        $idempotency_key = 'pi-' . $order_id . '-' . md5($order_id . $order_info['total'] . $order_info['currency_code']);

        try {
            $stripe   = $this->stripeClient();
            $amount   = $this->model_extension_payment_stripe->toStripeAmount($order_info['total'], $order_info['currency_code']);
            $currency = strtolower($order_info['currency_code']);

            $intent = $stripe->paymentIntents->create(
                [
                    // Automatically enables all eligible payment methods for the buyer's location
                    'automatic_payment_methods' => ['enabled' => true],
                    'amount'                    => $amount,
                    'currency'                  => $currency,
                    'metadata'                  => [
                        'order_id'   => $order_id,
                        'store_name' => $this->config->get('config_name'),
                    ],
                    'description' => 'Order #' . $order_id . ' - ' . $order_info['email'] . ' - ' . trim($order_info['firstname'] . ' ' . $order_info['lastname']),
                ],
                ['idempotency_key' => $idempotency_key]
            );

            return ['client_secret' => $intent->client_secret, 'id' => $intent->id];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log->write('[Stripe] createPaymentIntent error: ' . $e->getMessage());
            return false;
        }
    }

    // AJAX endpoint for card and wallet payments (non-redirect flow)
    public function confirm() {
        $this->load->language('extension/payment/stripe');
        $json = array();

        $post_token    = isset($this->request->post['csrf_token']) ? $this->request->post['csrf_token'] : '';
        $session_token = isset($this->session->data['stripe_csrf_token']) ? $this->session->data['stripe_csrf_token'] : '';

        if (empty($post_token) || empty($session_token) || !hash_equals($session_token, $post_token)) {
            $json['error'] = $this->language->get('error_generic');
            $this->sendJson($json);
            return;
        }

        // Do not consume the token yet — the customer may need to retry after a card decline.
        // The token is invalidated only after a successful payment below.

        if (empty($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_generic');
            $this->sendJson($json);
            return;
        }

        $payment_intent_id = isset($this->request->post['payment_intent_id'])
            ? trim($this->request->post['payment_intent_id'])
            : '';

        if (!preg_match('/^pi_[a-zA-Z0-9]+$/', $payment_intent_id)) {
            $json['error'] = $this->language->get('error_generic');
            $this->sendJson($json);
            return;
        }

        $json = $this->processPaymentIntent($payment_intent_id, (int)$this->session->data['order_id']);

        if (isset($json['redirect'])) {
            unset($this->session->data['stripe_csrf_token']);
        }

        $this->sendJson($json);
    }

    // Return URL for redirect-based methods: iDEAL, Bancontact, SEPA, etc.
    // Stripe appends redirect_status: 'succeeded' | 'processing' | 'failed'
    public function complete() {
        $this->load->language('extension/payment/stripe');

        $redirect_status   = isset($this->request->get['redirect_status']) ? $this->request->get['redirect_status'] : '';
        $payment_intent_id = isset($this->request->get['payment_intent'])  ? $this->request->get['payment_intent']  : '';

        if (!preg_match('/^pi_[a-zA-Z0-9]+$/', $payment_intent_id) || $redirect_status === 'failed') {
            $this->session->data['error'] = $this->language->get('error_payment');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        // 'processing' is valid for SEPA/Sofort — the webhook will confirm later.
        if (!in_array($redirect_status, array('succeeded', 'processing'))) {
            $this->session->data['error'] = $this->language->get('error_payment');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        if (!empty($this->session->data['order_id'])) {
            $order_id = (int)$this->session->data['order_id'];
        } else {
            // Session may be lost after a bank redirect — fall back to intent metadata
            $order_id = $this->getOrderIdFromIntent($payment_intent_id);
        }

        if (!$order_id) {
            $this->session->data['error'] = $this->language->get('error_generic');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $result = $this->processPaymentIntent($payment_intent_id, $order_id);

        if (isset($result['redirect'])) {
            $this->response->redirect($result['redirect']);
        } else {
            $this->session->data['error'] = isset($result['error']) ? $result['error'] : $this->language->get('error_payment');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    private function processPaymentIntent($payment_intent_id, $order_id) {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/stripe');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            return array('error' => $this->language->get('error_generic'));
        }

        if ($this->model_extension_payment_stripe->isOrderProcessed($order_id)) {
            return array('redirect' => $this->url->link('checkout/success', '', true));
        }

        // Always verify intent server-side — never trust client-supplied status
        try {
            $intent = $this->stripeClient()->paymentIntents->retrieve($payment_intent_id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log->write('[Stripe] processPaymentIntent retrieve error: ' . $e->getMessage());
            return array('error' => $this->language->get('error_payment'));
        }

        if ((int)($intent->metadata['order_id'] ?? 0) !== $order_id) {
            $this->log->write('[Stripe] SECURITY: order_id mismatch. session=' . $order_id . ' intent_metadata=' . ($intent->metadata['order_id'] ?? 'none'));
            return array('error' => $this->language->get('error_generic'));
        }

        $expected_amount = $this->model_extension_payment_stripe->toStripeAmount($order_info['total'], $order_info['currency_code']);
        if ($intent->amount !== $expected_amount || strtolower($intent->currency) !== strtolower($order_info['currency_code'])) {
            $this->log->write('[Stripe] SECURITY: amount/currency mismatch for order_id=' . $order_id);
            return array('error' => $this->language->get('error_generic'));
        }

        if ($intent->status === 'succeeded') {
            // Instant payment (card, Apple Pay, Google Pay, etc.)
            // saveTransaction runs first; if a webhook arrives concurrently,
            // isOrderProcessed() returns true and the duplicate is skipped.
            $this->model_extension_payment_stripe->saveTransaction(
                $order_id,
                $intent->id,
                (string)($intent->latest_charge ?? ''),
                $order_info['total'],
                $order_info['currency_code'],
                'succeeded'
            );
            $order_status_id = (int)$this->config->get('payment_stripe_order_status_id') ?: 2;
            $this->model_checkout_order->addOrderHistory(
                $order_id,
                $order_status_id,
                'Payment confirmed. [Stripe Intent: ' . $intent->id . ']',
                false
            );

        } elseif ($intent->status === 'processing') {
            // Delayed payment method (SEPA Direct Debit, Sofort, etc.)
            // Bank confirmation is pending; the webhook will complete the order.
            if (!$this->model_extension_payment_stripe->hasProcessingTransaction($order_id)) {
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    1,
                    'Stripe: Payment is processing (SEPA/Bank Transfer). Awaiting bank confirmation. Intent: ' . $intent->id,
                    false
                );
                $this->model_extension_payment_stripe->saveTransaction(
                    $order_id,
                    $intent->id,
                    '',
                    $order_info['total'],
                    $order_info['currency_code'],
                    'processing'
                );
            }

        } else {
            return array('error' => $this->language->get('error_payment'));
        }

        return array('redirect' => $this->url->link('checkout/success', '', true));
    }

    private function getOrderIdFromIntent($payment_intent_id) {
        try {
            $intent = $this->stripeClient()->paymentIntents->retrieve($payment_intent_id);
            return (int)($intent->metadata['order_id'] ?? 0);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return 0;
        }
    }

    private function stripeClient() {
        require_once DIR_SYSTEM . 'library/stripe/vendor/autoload.php';
        return new \Stripe\StripeClient($this->config->get('payment_stripe_secret_key'));
    }

    private function sendJson($data) {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }
}
