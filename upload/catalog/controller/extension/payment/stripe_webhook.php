<?php
/**
 * Stripe Webhook Endpoint
 *
 * Verifies and processes events sent by Stripe.
 * URL: index.php?route=extension/payment/stripe_webhook
 *
 * Required events (configure in Stripe Dashboard → Developers → Webhooks):
 *   - payment_intent.succeeded
 *   - payment_intent.payment_failed
 */
class ControllerExtensionPaymentStripeWebhook extends Controller {

    public function index() {
        // Raw body is required for signature verification — do not buffer through OC
        $payload = @file_get_contents('php://input');

        if (empty($payload)) {
            $this->sendResponse(400, 'No payload');
            return;
        }

        $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        if (empty($sig_header)) {
            $this->log->write('[Stripe Webhook] Missing Stripe-Signature header');
            $this->sendResponse(400, 'Missing signature');
            return;
        }

        $webhook_secret = $this->config->get('payment_stripe_webhook_secret');
        if (empty($webhook_secret)) {
            $this->log->write('[Stripe Webhook] webhook_secret not configured');
            $this->sendResponse(500, 'Webhook not configured');
            return;
        }

        // Signature verification also guards against replay attacks (built-in tolerance window)
        require_once DIR_SYSTEM . 'library/stripe/vendor/autoload.php';

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\UnexpectedValueException $e) {
            $this->log->write('[Stripe Webhook] Invalid payload: ' . $e->getMessage());
            $this->sendResponse(400, 'Invalid payload');
            return;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->log->write('[Stripe Webhook] Signature verification failed: ' . $e->getMessage());
            $this->sendResponse(400, 'Invalid signature');
            return;
        }

        $this->processEvent($event);

        $this->sendResponse(200, 'OK');
    }

    private function processEvent(\Stripe\Event $event) {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/stripe');

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;
        }
    }

    private function handlePaymentSucceeded(\Stripe\PaymentIntent $intent) {
        $order_id = (int)($intent->metadata['order_id'] ?? 0);
        if (!$order_id) {
            $this->log->write('[Stripe Webhook] payment_intent.succeeded: order_id missing in metadata. Intent=' . $intent->id);
            return;
        }

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            $this->log->write('[Stripe Webhook] payment_intent.succeeded: order not found. order_id=' . $order_id);
            return;
        }

        if ($this->model_extension_payment_stripe->isOrderProcessed($order_id)) {
            $this->log->write('[Stripe Webhook] payment_intent.succeeded: already processed. order_id=' . $order_id);
            return;
        }

        $expected_amount = $this->model_extension_payment_stripe->toStripeAmount($order_info['total'], $order_info['currency_code']);
        if ($intent->amount !== $expected_amount || strtolower($intent->currency) !== strtolower($order_info['currency_code'])) {
            $this->log->write('[Stripe Webhook] SECURITY: amount mismatch for order_id=' . $order_id
                . '. expected=' . $expected_amount . ' ' . $order_info['currency_code']
                . ', got=' . $intent->amount . ' ' . $intent->currency);
            return;
        }

        $order_status_id = (int)$this->config->get('payment_stripe_order_status_id') ?: 2;
        $this->model_checkout_order->addOrderHistory(
            $order_id,
            $order_status_id,
            'Payment confirmed via Stripe. [Intent: ' . $intent->id . ']',
            false
        );

        // Updates an existing 'processing' record to 'succeeded', or inserts a new one
        $this->model_extension_payment_stripe->saveTransaction(
            $order_id,
            $intent->id,
            (string)($intent->latest_charge ?? ''),
            $order_info['total'],
            $order_info['currency_code'],
            'succeeded'
        );

        $this->log->write('[Stripe Webhook] Order confirmed via webhook. order_id=' . $order_id);
    }

    private function handlePaymentFailed(\Stripe\PaymentIntent $intent) {
        $order_id = (int)($intent->metadata['order_id'] ?? 0);
        if (!$order_id) return;

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) return;

        // Leave already-succeeded orders untouched
        if ($this->model_extension_payment_stripe->isOrderProcessed($order_id)) return;

        // Preserve the current order status; just append a note.
        // The "Failed" status ID varies across OpenCart installations.
        $this->model_checkout_order->addOrderHistory(
            $order_id,
            (int)$order_info['order_status_id'],
            'Stripe Webhook: payment_intent.payment_failed. Intent: ' . $intent->id
                . ' | Reason: ' . ($intent->last_payment_error->message ?? 'Unknown'),
            false
        );

        $this->model_extension_payment_stripe->saveTransaction(
            $order_id,
            $intent->id,
            '',
            $order_info['total'],
            $order_info['currency_code'],
            'failed'
        );

        $this->log->write('[Stripe Webhook] Payment failed for order_id=' . $order_id . '. Intent=' . $intent->id);
    }

    private function sendResponse($code, $message) {
        $phrases = array(200 => 'OK', 400 => 'Bad Request', 500 => 'Internal Server Error');
        $phrase  = isset($phrases[$code]) ? $phrases[$code] : 'Error';
        $this->response->addHeader('HTTP/1.1 ' . $code . ' ' . $phrase);
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(array('status' => $message)));
    }
}
