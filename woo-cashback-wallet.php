<?php
/**
 * Plugin Name: کش‌بک و کیف پول ووکامرس
 * Plugin URI: https://github.com/sahandse/woo-cashback-wallet
 * Description: کش‌بک درصدی یا مبلغ ثابت و کیف پول ووکامرس برای استفاده در خریدهای بعدی.
 * Version: 1.0.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: woo-cashback-wallet
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class WCW_Plugin {
    const VERSION = '1.0.0';
    const OPTION  = 'wcw_settings';
    const BALANCE_META = '_wcw_wallet_balance';

    public function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function declare_hpos() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('woocommerce_order_status_completed', [$this, 'grant_cashback']);
        add_action('woocommerce_account_dashboard', [$this, 'wallet_summary']);
        add_shortcode('woo_cashback_wallet', [$this, 'wallet_shortcode']);
    }

    public function woocommerce_notice() {
        echo '<div class="notice notice-error"><p>افزونه کش‌بک و کیف پول برای اجرا به WooCommerce نیاز دارد.</p></div>';
    }

    public function defaults() {
        return [
            'enabled' => 'yes',
            'mode' => 'percent',
            'value' => 5,
            'min_order' => 0,
            'max_cashback' => 0,
            'use_wallet' => 'yes',
            'accent' => '#111827',
            'sms_provider' => 'none',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('wcw_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        return [
            'enabled' => !empty($in['enabled']) ? 'yes' : 'no',
            'mode' => in_array($in['mode'] ?? '', ['percent','fixed'], true) ? $in['mode'] : $d['mode'],
            'value' => max(0, (float)($in['value'] ?? 0)),
            'min_order' => max(0, (float)($in['min_order'] ?? 0)),
            'max_cashback' => max(0, (float)($in['max_cashback'] ?? 0)),
            'use_wallet' => !empty($in['use_wallet']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
            'sms_provider' => in_array($in['sms_provider'] ?? '', ['none','melipayamak','farazsms'], true)
                ? $in['sms_provider']
                : 'none',
        ];
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'کش‌بک و کیف پول',
            'کش‌بک و کیف پول',
            'manage_woocommerce',
            'woo-cashback-wallet',
            [$this, 'settings_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'woo-cashback-wallet')) return;
        wp_enqueue_style('wcw-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        ?>
        <div class="wrap wcw-admin">
            <div class="wcw-hero">
                <div>
                    <h1>کش‌بک و کیف پول ووکامرس</h1>
                    <p>مدیریت کش‌بک و اعتبار خرید مشتریان.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wcw_group'); ?>
                <div class="wcw-grid">
                    <section class="wcw-card">
                        <h2>کش‌بک</h2>
                        <label class="wcw-switch">
                            <span>فعال بودن</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[enabled]" value="1" <?php checked($s['enabled'],'yes'); ?>>
                        </label>

                        <label>نوع کش‌بک
                            <select name="<?php echo self::OPTION; ?>[mode]">
                                <option value="percent" <?php selected($s['mode'],'percent'); ?>>درصدی</option>
                                <option value="fixed" <?php selected($s['mode'],'fixed'); ?>>مبلغ ثابت</option>
                            </select>
                        </label>

                        <label>مقدار
                            <input type="number" min="0" step="0.01" name="<?php echo self::OPTION; ?>[value]" value="<?php echo esc_attr($s['value']); ?>">
                        </label>

                        <label>حداقل مبلغ سفارش
                            <input type="number" min="0" step="1000" name="<?php echo self::OPTION; ?>[min_order]" value="<?php echo esc_attr($s['min_order']); ?>">
                        </label>

                        <label>سقف کش‌بک هر سفارش
                            <input type="number" min="0" step="1000" name="<?php echo self::OPTION; ?>[max_cashback]" value="<?php echo esc_attr($s['max_cashback']); ?>">
                            <small>۰ یعنی بدون سقف.</small>
                        </label>
                    </section>

                    <section class="wcw-card">
                        <h2>کیف پول</h2>
                        <label class="wcw-switch">
                            <span>استفاده از کیف پول برای خرید</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[use_wallet]" value="1" <?php checked($s['use_wallet'],'yes'); ?>>
                        </label>
                        <p>برداشت وجه به کارت بانکی در این افزونه غیرفعال است؛ اعتبار فقط برای پرداخت سفارش‌های بعدی استفاده می‌شود.</p>
                    </section>

                    <section class="wcw-card">
                        <h2>پیامک</h2>
                        <label>سرویس
                            <select name="<?php echo self::OPTION; ?>[sms_provider]">
                                <option value="none" <?php selected($s['sms_provider'],'none'); ?>>غیرفعال</option>
                                <option value="melipayamak" <?php selected($s['sms_provider'],'melipayamak'); ?>>ملی‌پیامک</option>
                                <option value="farazsms" <?php selected($s['sms_provider'],'farazsms'); ?>>فراز SMS</option>
                            </select>
                        </label>
                    </section>

                    <section class="wcw-card">
                        <h2>ظاهر</h2>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>

                    <section class="wcw-card">
                        <h2>شورت‌کد</h2>
                        <code>[woo_cashback_wallet]</code>
                    </section>

                    <section class="wcw-card">
                        <h2>وضعیت توسعه</h2>
                        <p>هسته اعتبار کیف پول و ثبت کش‌بک سفارش تکمیل شده است. اعمال کیف پول به‌عنوان روش پرداخت و SMS واقعی در نسخه‌های بعدی همین Repo توسعه داده می‌شود.</p>
                    </section>
                </div>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }

    private function calculate_cashback($total) {
        $s = $this->settings();

        if ((float)$total < (float)$s['min_order']) return 0;

        $amount = 'fixed' === $s['mode']
            ? (float)$s['value']
            : ((float)$total * ((float)$s['value'] / 100));

        if ((float)$s['max_cashback'] > 0) {
            $amount = min($amount, (float)$s['max_cashback']);
        }

        return max(0, $amount);
    }

    public function grant_cashback($order_id) {
        $s = $this->settings();
        if ('yes' !== $s['enabled']) return;

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_user_id()) return;

        if ('yes' === $order->get_meta('_wcw_cashback_granted')) return;

        $cashback = $this->calculate_cashback($order->get_total());
        if ($cashback <= 0) return;

        $user_id = $order->get_user_id();
        $balance = (float)get_user_meta($user_id, self::BALANCE_META, true);
        $new_balance = $balance + $cashback;

        update_user_meta($user_id, self::BALANCE_META, $new_balance);
        $order->update_meta_data('_wcw_cashback_granted', 'yes');
        $order->update_meta_data('_wcw_cashback_amount', $cashback);
        $order->save();
    }

    public function wallet_summary() {
        if (!is_user_logged_in()) return;

        $balance = (float)get_user_meta(get_current_user_id(), self::BALANCE_META, true);
        echo '<div class="wcw-wallet-summary"><p><strong>موجودی کیف پول:</strong> ' . wp_kses_post(wc_price($balance)) . '</p></div>';
    }

    public function wallet_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>برای مشاهده کیف پول وارد حساب کاربری شوید.</p>';
        }

        $balance = (float)get_user_meta(get_current_user_id(), self::BALANCE_META, true);

        return '<div class="wcw-wallet-card"><strong>موجودی کیف پول:</strong> ' .
            wp_kses_post(wc_price($balance)) .
            '<p>این اعتبار فقط برای خریدهای بعدی قابل استفاده است.</p></div>';
    }
}

new WCW_Plugin();
