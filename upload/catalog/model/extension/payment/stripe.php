<?php
class ModelExtensionPaymentStripe extends Model {

    public function getMethod($address, $total) {
        $this->load->language('extension/payment/stripe');

        if ($this->config->get('payment_stripe_geo_zone_id')) {
            $query = $this->db->query("
                SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "zone_to_geo_zone`
                WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_stripe_geo_zone_id') . "'
                  AND `country_id` = '" . (int)$address['country_id'] . "'
                  AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')
            ");
            $available = (bool)$query->row['total'];
        } else {
            $available = true;
        }

        if (!$available) {
            return array();
        }

        return array(
            'code'       => 'stripe',
            'title'      => $this->language->get('heading_title'),
            'terms'      => '',
            'sort_order' => (int)$this->config->get('payment_stripe_sort_order'),
        );
    }

    /**
     * Returns true if a 'succeeded' transaction exists for the order.
     * Guards against race conditions between the webhook and the confirm() endpoint.
     */
    public function isOrderProcessed($order_id) {
        $query = $this->db->query("
            SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "stripe_transaction`
            WHERE `order_id` = '" . (int)$order_id . "'
              AND `status` = 'succeeded'
            LIMIT 1
        ");
        return (int)$query->row['total'] > 0;
    }

    /**
     * Returns true if a 'processing' transaction exists for the order.
     * Prevents duplicate records for delayed methods like SEPA or Sofort.
     */
    public function hasProcessingTransaction($order_id) {
        $query = $this->db->query("
            SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "stripe_transaction`
            WHERE `order_id` = '" . (int)$order_id . "'
              AND `status` = 'processing'
            LIMIT 1
        ");
        return (int)$query->row['total'] > 0;
    }

    /**
     * Insert or update a transaction record.
     * For SEPA: initially saved as 'processing'; the webhook later updates it to 'succeeded'.
     */
    public function saveTransaction($order_id, $payment_intent_id, $charge_id, $amount, $currency, $status) {
        $existing = $this->db->query("
            SELECT `stripe_transaction_id` FROM `" . DB_PREFIX . "stripe_transaction`
            WHERE `payment_intent_id` = '" . $this->db->escape($payment_intent_id) . "'
            LIMIT 1
        ");

        if ($existing->num_rows) {
            $this->db->query("
                UPDATE `" . DB_PREFIX . "stripe_transaction`
                SET `status`    = '" . $this->db->escape($status) . "',
                    `charge_id` = '" . $this->db->escape($charge_id) . "',
                    `amount`    = '" . (float)$amount . "'
                WHERE `payment_intent_id` = '" . $this->db->escape($payment_intent_id) . "'
            ");
        } else {
            $this->db->query("
                INSERT INTO `" . DB_PREFIX . "stripe_transaction`
                SET `order_id`          = '" . (int)$order_id . "',
                    `payment_intent_id` = '" . $this->db->escape($payment_intent_id) . "',
                    `charge_id`         = '" . $this->db->escape($charge_id) . "',
                    `amount`            = '" . (float)$amount . "',
                    `currency`          = '" . $this->db->escape(strtoupper($currency)) . "',
                    `status`            = '" . $this->db->escape($status) . "',
                    `date_added`        = NOW()
            ");
        }
    }

    /**
     * Converts an order total to the smallest currency unit expected by Stripe.
     * Zero-decimal currencies (JPY, KRW, etc.) are passed as-is.
     */
    public function toStripeAmount($total, $currency) {
        $zero_decimal = array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF');
        if (in_array(strtoupper($currency), $zero_decimal)) {
            return (int)round($total);
        }
        return (int)round($total * 100);
    }
}
