<?php
/**
 * Plugin Name: WooCommerce Simple Wallet System
 * Description: A simple wallet payment system for WooCommerce
 * Version: 1.0.0
 * Author: Sarfaraz Kazi
 * Author URI: https://sarfarajkazi7.link
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// HPOS Compatibility
add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Main Wallet System Class
 */
class WC_Simple_Wallet_System {

    const WALLET_META_KEY = '_wallet_balance';

    public function __construct() {
        add_action('init', array($this, 'init'));
	    // Initialize payment gateway
	    add_action('plugins_loaded', array($this, 'init_wallet_gateway'));
	    add_filter('woocommerce_payment_gateways',array($this,'add_wallet_gateway'));
    }


    public function init() {
        // Add wallet balance field to user profile
        add_action('show_user_profile', array($this, 'add_wallet_field_to_profile'));
        add_action('edit_user_profile', array($this, 'add_wallet_field_to_profile'));
        add_action('personal_options_update', array($this, 'save_wallet_field'));
        add_action('edit_user_profile_update', array($this, 'save_wallet_field'));



	    // Add wallet info to my account page
        add_action('woocommerce_account_dashboard', array($this, 'display_wallet_balance_in_account'));
    }

    /**
     * Add wallet balance field to user profile
     */
    public function add_wallet_field_to_profile($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $wallet_balance = $this->get_wallet_balance($user->ID);
        $currency_symbol = get_woocommerce_currency_symbol();
        ?>
        <h3><?php _e('Wallet Information', 'wc-simple-wallet'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="wallet_balance"><?php _e('Wallet Balance', 'wc-simple-wallet'); ?></label></th>
                <td>
                    <input type="number"
                           step="0.01"
                           min="0"
                           name="wallet_balance"
                           id="wallet_balance"
                           value="<?php echo esc_attr($wallet_balance); ?>"
                           class="regular-text" />
                    <span class="description"><?php printf(__('Current balance: %s%s', 'wc-simple-wallet'), $currency_symbol, number_format($wallet_balance, 2)); ?></span>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save wallet balance field
     */
    public function save_wallet_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (isset($_POST['wallet_balance'])) {
            $new_balance = sanitize_text_field($_POST['wallet_balance']);
            $new_balance = floatval($new_balance);

            // Ensure balance is not negative
            if ($new_balance < 0) {
                $new_balance = 0;
            }

            update_user_meta($user_id, self::WALLET_META_KEY, $new_balance);
        }
    }

    /**
     * Get wallet balance for a user
     */
    public function get_wallet_balance($user_id) {
        if (empty($user_id)) {
            return 0;
        }

        $balance = get_user_meta($user_id, self::WALLET_META_KEY, true);
        return floatval($balance);
    }

    /**
     * Update wallet balance for a user
     */
    public function update_wallet_balance($user_id, $amount) {
        if (empty($user_id)) {
            return false;
        }

        $current_balance = $this->get_wallet_balance($user_id);
        $new_balance = $current_balance + $amount;

        // Ensure balance doesn't go negative
        if ($new_balance < 0) {
            $new_balance = 0;
        }

        return update_user_meta($user_id, self::WALLET_META_KEY, $new_balance);
    }

    /**
     * Deduct amount from wallet
     */
    public function deduct_from_wallet($user_id, $amount) {
        if (empty($user_id) || $amount <= 0) {
            return false;
        }

        $current_balance = $this->get_wallet_balance($user_id);

        // Check if sufficient balance
        if ($current_balance < $amount) {
            return false;
        }

        return $this->update_wallet_balance($user_id, -$amount);
    }

    /**
     * Add wallet payment gateway to WooCommerce
     */
    public function add_wallet_gateway($gateways) {
        $gateways[] = WC_Wallet_Payment_Gateway::class;
	    return $gateways;
    }

    /**
     * Initialize wallet payment gateway
     */
    public function init_wallet_gateway() {
        if (class_exists('WC_Payment_Gateway')) {
	        include_once dirname(__FILE__) . '/includes/class-wc-wallet-payment-gateway.php';
        }
    }

    /**
     * Display wallet balance in customer account dashboard
     */
    public function display_wallet_balance_in_account() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $wallet_balance = $this->get_wallet_balance($user_id);
        $currency_symbol = get_woocommerce_currency_symbol();

        echo '<div class="woocommerce-wallet-balance" style="background: #f7f7f7; padding: 15px; margin: 20px 0; border-radius: 5px;">';
        echo '<h3>' . __('My Wallet', 'wc-simple-wallet') . '</h3>';
        echo '<p><strong>' . sprintf(__('Current Balance: %s%s', 'wc-simple-wallet'), $currency_symbol, number_format($wallet_balance, 2)) . '</strong></p>';
        echo '</div>';
    }
}

// Initialize the plugin
new WC_Simple_Wallet_System();

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, function() {
    // Add default wallet balance to existing users
    $users = get_users();
    foreach ($users as $user) {
        if (!get_user_meta($user->ID, WC_Simple_Wallet_System::WALLET_META_KEY, true)) {
            update_user_meta($user->ID, WC_Simple_Wallet_System::WALLET_META_KEY, 0);
        }
    }
});

/**
 * Add wallet balance to new users
 */
add_action('user_register', function($user_id) {
    update_user_meta($user_id, WC_Simple_Wallet_System::WALLET_META_KEY, 0);
});

/**
 * Add styles for better UX
 */
add_action('wp_head', function() {
    if (is_checkout() || is_account_page()) {
        ?>
        <style>
        .wallet-balance-info {
            font-size: 14px;
        }
        .wallet-balance-info p {
            margin: 0;
            color: #333;
        }
        .woocommerce-wallet-balance {
            border-left: 4px solid #0073aa;
        }
        .woocommerce-wallet-balance h3 {
            margin-top: 0;
            color: #0073aa;
        }
        </style>
        <?php
    }
});
?>