<?php
class ModelExtensionPaymentStripe extends Model {
    public function install() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "stripe_transaction` (
                `stripe_transaction_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`             INT(11) UNSIGNED NOT NULL,
                `payment_intent_id`    VARCHAR(255) NOT NULL,
                `charge_id`            VARCHAR(255) NOT NULL DEFAULT '',
                `amount`               DECIMAL(15,4) NOT NULL,
                `currency`             VARCHAR(10) NOT NULL,
                `status`               VARCHAR(50) NOT NULL,
                `date_added`           DATETIME NOT NULL,
                PRIMARY KEY (`stripe_transaction_id`),
                UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
                KEY `order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");
    }

    public function uninstall() {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "stripe_transaction`");
    }
}
