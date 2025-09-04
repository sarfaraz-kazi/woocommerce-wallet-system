<?php
/**
 * Wallet Payment Gateway Class
 */
class WC_Wallet_Payment_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id = 'wallet';
		$this->icon = '';
		$this->has_fields = false;
		$this->method_title = __('Wallet Payment', 'wc-simple-wallet');
		$this->method_description = __('Allow customers to pay using their wallet balance.', 'wc-simple-wallet');

		$this->supports = array('products');

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = $this->get_option('enabled');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

	}

	/**
	 * Initialize gateway form fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'wc-simple-wallet'),
				'type'    => 'checkbox',
				'label'   => __('Enable Wallet Payment', 'wc-simple-wallet'),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __('Title', 'wc-simple-wallet'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'wc-simple-wallet'),
				'default'     => __('Wallet Payment', 'wc-simple-wallet'),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __('Description', 'wc-simple-wallet'),
				'type'        => 'textarea',
				'description' => __('Payment method description that the customer will see on your checkout.', 'wc-simple-wallet'),
				'default'     => __('Pay using your wallet balance.', 'wc-simple-wallet'),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Check if gateway is available
	 */
	public function is_available() {
		if ($this->enabled !== 'yes') {
			return false;
		}

		// Only available for logged in users
		if (!is_user_logged_in()) {
			return false;
		}

		// Check if user has sufficient wallet balance
		$user_id = get_current_user_id();
		$wallet_system = new WC_Simple_Wallet_System();
		$wallet_balance = $wallet_system->get_wallet_balance($user_id);
		$cart_total = WC()->cart? WC()->cart->get_total('raw'): 0;
		if ($wallet_balance < $cart_total) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Process payment
	 */
	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		$user_id = $order->get_user_id();

		if (!$user_id) {
			wc_add_notice(__('User not found.', 'wc-simple-wallet'), 'error');
			return array('result' => 'fail');
		}

		$wallet_system = new WC_Simple_Wallet_System();
		$wallet_balance = $wallet_system->get_wallet_balance($user_id);
		$order_total = $order->get_total();

		// Verify sufficient balance
		if ($wallet_balance < $order_total) {
			wc_add_notice(__('Insufficient wallet balance.', 'wc-simple-wallet'), 'error');
			return array('result' => 'fail');
		}

		// Deduct amount from wallet
		if (!$wallet_system->deduct_from_wallet($user_id, $order_total)) {
			wc_add_notice(__('Failed to process wallet payment.', 'wc-simple-wallet'), 'error');
			return array('result' => 'fail');
		}

		// Mark order as paid
		$order->payment_complete();

		// Add order note
		$order->add_order_note(
			sprintf(__('Payment completed via wallet. Amount deducted: %s', 'wc-simple-wallet'),
				wc_price($order_total))
		);

		// Reduce stock levels
		wc_reduce_stock_levels($order_id);

		// Empty cart
		WC()->cart->empty_cart();

		// Return success
		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url($order)
		);
	}

	/**
	 * Display payment fields
	 */
	public function payment_fields() {
		if ($this->description) {
			echo wpautop(wp_kses_post($this->description));
		}

		if (is_user_logged_in()) {
			$user_id = get_current_user_id();
			$wallet_system = new WC_Simple_Wallet_System();
			$wallet_balance = $wallet_system->get_wallet_balance($user_id);
			$currency_symbol = get_woocommerce_currency_symbol();

			echo '<div class="wallet-balance-info" style="background: #f9f9f9; padding: 10px; margin: 10px 0; border-radius: 3px;">';
			echo '<p><strong>' . sprintf(__('Your Wallet Balance: %s%s', 'wc-simple-wallet'), $currency_symbol, number_format($wallet_balance, 2)) . '</strong></p>';
			echo '</div>';
		}
	}
}