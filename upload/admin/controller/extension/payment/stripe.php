<?php
class ControllerExtensionPaymentStripe extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/payment/stripe');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_stripe', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link(
                'marketplace/extension',
                'user_token=' . $this->session->data['user_token'] . '&type=payment',
                true
            ));
        }

        $data = array();

        // Language strings
        $strings = array(
            'heading_title', 'text_edit', 'text_enabled', 'text_disabled',
            'text_yes', 'text_no', 'text_success', 'text_home', 'text_extension',
            'entry_publishable_key', 'entry_secret_key', 'entry_webhook_secret',
            'entry_order_status', 'entry_test_mode', 'entry_status', 'entry_sort_order',
            'help_publishable_key', 'help_secret_key', 'help_webhook_secret',
            'button_save', 'button_cancel',
        );
        foreach ($strings as $s) {
            $data[$s] = $this->language->get($s);
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
            ),
            array(
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true),
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/payment/stripe', 'user_token=' . $this->session->data['user_token'], true),
            ),
        );

        $data['action'] = $this->url->link('extension/payment/stripe', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/stripe_webhook';

        // Load order statuses for dropdown
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Form fields
        $fields = array('publishable_key', 'secret_key', 'webhook_secret', 'order_status_id', 'test_mode', 'status', 'sort_order');
        foreach ($fields as $field) {
            $key = 'payment_stripe_' . $field;
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $data[$key] = $this->config->get($key);
            }
        }

        // Default order status: 2 (Processing)
        if (!$data['payment_stripe_order_status_id']) {
            $data['payment_stripe_order_status_id'] = 2;
        }

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/stripe', $data));
    }

    public function install() {
        $this->load->model('extension/payment/stripe');
        $this->model_extension_payment_stripe->install();
    }

    public function uninstall() {
        $this->load->model('extension/payment/stripe');
        $this->model_extension_payment_stripe->uninstall();
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/stripe')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        $pk = trim($this->request->post['payment_stripe_publishable_key'] ?? '');
        $sk = trim($this->request->post['payment_stripe_secret_key'] ?? '');
        $wh = trim($this->request->post['payment_stripe_webhook_secret'] ?? '');

        if (empty($pk)) {
            $this->error['warning'] = $this->language->get('error_publishable_key');
        } elseif (!preg_match('/^pk_(test|live)_[a-zA-Z0-9]{10,}$/', $pk)) {
            $this->error['warning'] = $this->language->get('error_publishable_key_format');
        }

        if (empty($sk)) {
            $this->error['warning'] = $this->language->get('error_secret_key');
        } elseif (!preg_match('/^(sk|rk)_(test|live)_[a-zA-Z0-9]{10,}$/', $sk)) {
            $this->error['warning'] = $this->language->get('error_secret_key_format');
        }

        if (!empty($wh) && !preg_match('/^whsec_[a-zA-Z0-9+\/=]{10,}$/', $wh)) {
            $this->error['warning'] = $this->language->get('error_webhook_secret');
        }
        return !$this->error;
    }
}
