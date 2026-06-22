<?php
/**
 * Plugin Name: Speaklar AI Order Confirmation for WooCommerce
 * Description: Sends WooCommerce orders to a Speaklar AI voice agent and updates the order after Speaklar posts the call result webhook.
 * Version: 0.1.0
 * Author: Speaklar
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: speaklar-ai-order-confirmation
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Speaklar_AI_Order_Confirmation
{
    private const OPTION_KEY = 'speaklar_ai_order_confirmation_settings';
    private const VERSION = '0.1.0';

    public static function boot(): void
    {
        $plugin = new self();

        register_activation_hook(__FILE__, [$plugin, 'activate']);
        add_action('admin_menu', [$plugin, 'admin_menu']);
        add_action('admin_post_speaklar_aioc_save_settings', [$plugin, 'save_settings']);
        add_action('admin_post_speaklar_aioc_refresh_agents', [$plugin, 'refresh_agents']);
        add_action('rest_api_init', [$plugin, 'register_rest_routes']);
        add_action('init', [$plugin, 'register_order_statuses']);
        add_action('woocommerce_new_order', [$plugin, 'handle_new_order'], 20, 2);
        add_action('woocommerce_order_status_changed', [$plugin, 'handle_status_change'], 20, 4);
        add_action('add_meta_boxes', [$plugin, 'add_order_meta_box']);
        add_filter('wc_order_statuses', [$plugin, 'add_order_status_labels']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$plugin, 'plugin_action_links']);
    }

    public function activate(): void
    {
        $settings = get_option(self::OPTION_KEY);
        if (!is_array($settings)) {
            update_option(self::OPTION_KEY, $this->defaults());
            return;
        }

        update_option(self::OPTION_KEY, array_merge($this->defaults(), $settings));
    }

    public function admin_menu(): void
    {
        $parent = class_exists('WooCommerce') ? 'woocommerce' : 'options-general.php';
        add_submenu_page(
            $parent,
            __('Speaklar AI Confirmation', 'speaklar-ai-order-confirmation'),
            __('Speaklar AI', 'speaklar-ai-order-confirmation'),
            class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options',
            'speaklar-ai-order-confirmation',
            [$this, 'render_settings_page']
        );
    }

    public function plugin_action_links(array $links): array
    {
        $url = admin_url('admin.php?page=speaklar-ai-order-confirmation');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'speaklar-ai-order-confirmation') . '</a>');

        return $links;
    }

    public function render_settings_page(): void
    {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to manage Speaklar settings.', 'speaklar-ai-order-confirmation'));
        }

        $settings = $this->settings();
        $agents = is_array($settings['last_agents'] ?? null) ? $settings['last_agents'] : [];
        $voices = $this->voice_options($settings);
        $has_credentials = (string) $settings['speaklar_url'] !== '' && (string) $settings['api_key'] !== '';
        $message = isset($_GET['speaklar_message']) ? sanitize_text_field(wp_unslash($_GET['speaklar_message'])) : '';
        $error = isset($_GET['speaklar_error']) ? sanitize_text_field(wp_unslash($_GET['speaklar_error'])) : '';
        $webhook_url = $this->webhook_url($settings);
        $order_statuses = $this->order_statuses();

        ?>
        <div class="wrap speaklar-aioc-wrap">
            <style>
                .speaklar-aioc-wrap{max-width:1120px}
                .speaklar-aioc-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:24px 0 18px;padding:24px 28px;background:#111827;color:#fff;border-radius:8px}
                .speaklar-aioc-brand{display:flex;align-items:center;gap:14px}
                .speaklar-aioc-logo{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:8px;background:#fff;overflow:hidden}
                .speaklar-aioc-logo img{display:block;width:36px;height:36px;object-fit:contain}
                .speaklar-aioc-hero h1{margin:0;color:#fff;font-size:24px;line-height:1.2}
                .speaklar-aioc-hero p{margin:5px 0 0;color:#cbd5e1}
                .speaklar-aioc-badge{display:inline-flex;align-items:center;min-height:30px;padding:0 11px;border-radius:999px;background:#ecfdf5;color:#047857;font-weight:700}
                .speaklar-aioc-badge.is-muted{background:#fef3c7;color:#92400e}
                .speaklar-aioc-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}
                .speaklar-aioc-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:22px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
                .speaklar-aioc-panel + .speaklar-aioc-panel{margin-top:16px}
                .speaklar-aioc-panel h2{margin:0 0 14px;font-size:18px;line-height:1.3}
                .speaklar-aioc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
                .speaklar-aioc-field label{display:block;margin:0 0 7px;color:#1d2327;font-weight:650}
                .speaklar-aioc-field input[type="url"],
                .speaklar-aioc-field input[type="password"],
                .speaklar-aioc-field input[type="text"],
                .speaklar-aioc-field input[type="number"],
                .speaklar-aioc-field select{width:100%;max-width:100%;min-height:40px;border-color:#c3c4c7;border-radius:6px}
                .speaklar-aioc-field .description{margin:7px 0 0;color:#646970}
                .speaklar-aioc-copy{display:flex;gap:8px;align-items:center}
                .speaklar-aioc-copy input{flex:1}
                .speaklar-aioc-checks{display:flex;flex-wrap:wrap;gap:10px 18px;margin-top:10px}
                .speaklar-aioc-checks label{display:inline-flex;align-items:center;gap:7px;margin:0}
                .speaklar-aioc-status-map{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px}
                .speaklar-aioc-status-map label{margin:0!important}
                .speaklar-aioc-status-map span{display:block!important;min-width:0!important;margin-bottom:6px;font-weight:650}
                .speaklar-aioc-actions{display:flex;gap:10px;align-items:center;margin-top:18px}
                .speaklar-aioc-actions .submit{margin:0;padding:0}
                .speaklar-aioc-actions .button-primary{min-height:38px}
                .speaklar-aioc-side-list{margin:0}
                .speaklar-aioc-side-list li{display:flex;justify-content:space-between;gap:14px;margin:0;padding:11px 0;border-top:1px solid #f0f0f1}
                .speaklar-aioc-side-list li:first-child{border-top:0}
                .speaklar-aioc-side-list span{color:#646970}
                .speaklar-aioc-side-list strong{text-align:right}
                @media (max-width:960px){.speaklar-aioc-layout{grid-template-columns:1fr}.speaklar-aioc-grid,.speaklar-aioc-status-map{grid-template-columns:1fr}.speaklar-aioc-hero{display:block}.speaklar-aioc-badge{margin-top:16px}}
            </style>

            <div class="speaklar-aioc-hero">
                <div class="speaklar-aioc-brand">
                    <div class="speaklar-aioc-logo">
                        <img src="<?php echo esc_url(plugins_url('assets/logos.png', __FILE__)); ?>" alt="<?php echo esc_attr__('Speaklar', 'speaklar-ai-order-confirmation'); ?>">
                    </div>
                    <div>
                        <h1><?php echo esc_html__('Speaklar AI Order Confirmation', 'speaklar-ai-order-confirmation'); ?></h1>
                        <p><?php echo esc_html__('Connect WooCommerce orders to your Speaklar voice agent.', 'speaklar-ai-order-confirmation'); ?></p>
                    </div>
                </div>
                <span class="speaklar-aioc-badge <?php echo $has_credentials ? '' : 'is-muted'; ?>">
                    <?php echo esc_html($has_credentials ? __('Connected', 'speaklar-ai-order-confirmation') : __('Login required', 'speaklar-ai-order-confirmation')); ?>
                </span>
            </div>

            <?php if ($message !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <?php if ($error !== '') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!class_exists('WooCommerce')) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('WooCommerce is not active. Settings can be saved, but order calling will only work after WooCommerce is installed and active.', 'speaklar-ai-order-confirmation'); ?></p>
                </div>
            <?php endif; ?>

            <div class="speaklar-aioc-layout">
                <div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('speaklar_aioc_save_settings'); ?>
                        <input type="hidden" name="action" value="speaklar_aioc_save_settings">

                        <div class="speaklar-aioc-panel">
                            <h2><?php echo esc_html__('Speaklar Login', 'speaklar-ai-order-confirmation'); ?></h2>
                            <div class="speaklar-aioc-grid">
                                <div class="speaklar-aioc-field">
                                    <label for="speaklar_url"><?php echo esc_html__('Speaklar URL', 'speaklar-ai-order-confirmation'); ?></label>
                                    <input name="speaklar_url" id="speaklar_url" type="url" required placeholder="https://app.speaklar.com" value="<?php echo esc_attr($settings['speaklar_url']); ?>">
                                    <p class="description"><?php echo esc_html__('Base URL of the Speaklar app or API server.', 'speaklar-ai-order-confirmation'); ?></p>
                                </div>
                                <div class="speaklar-aioc-field">
                                    <label for="api_key"><?php echo esc_html__('API Key', 'speaklar-ai-order-confirmation'); ?></label>
                                    <input name="api_key" id="api_key" type="password" required autocomplete="new-password" value="<?php echo esc_attr($settings['api_key']); ?>">
                                    <p class="description"><?php echo esc_html__('Used to load agents, voices, and request confirmation calls.', 'speaklar-ai-order-confirmation'); ?></p>
                                </div>
                            </div>
                        </div>

                        <?php if ($has_credentials) : ?>
                        <div class="speaklar-aioc-panel">
                            <h2><?php echo esc_html__('Voice Setup', 'speaklar-ai-order-confirmation'); ?></h2>
                            <div class="speaklar-aioc-grid">
                                <div class="speaklar-aioc-field">
                                    <label for="agent_id"><?php echo esc_html__('AI Voice Agent', 'speaklar-ai-order-confirmation'); ?></label>
                                    <?php if ($agents) : ?>
                                        <select name="agent_id" id="agent_id">
                                            <option value=""><?php echo esc_html__('Select agent', 'speaklar-ai-order-confirmation'); ?></option>
                                            <?php foreach ($agents as $agent) : ?>
                                                <?php
                                                $agent_id = (string) ($agent['id'] ?? '');
                                                $agent_name = (string) ($agent['name'] ?? $agent_id);
                                                ?>
                                                <option value="<?php echo esc_attr($agent_id); ?>" <?php selected((string) $settings['agent_id'], $agent_id); ?>>
                                                    <?php echo esc_html($agent_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <p class="description"><?php echo esc_html__('No agents loaded yet. Save your credentials, then refresh agents.', 'speaklar-ai-order-confirmation'); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="speaklar-aioc-field">
                                    <label for="text_to_speech_name"><?php echo esc_html__('Voice', 'speaklar-ai-order-confirmation'); ?></label>
                                    <select name="text_to_speech_name" id="text_to_speech_name">
                                        <?php foreach ($voices as $voice) : ?>
                                            <?php
                                            $voice_value = (string) ($voice['value'] ?? '');
                                            $voice_name = $this->voice_display_name((string) ($voice['name'] ?? $voice_value));
                                            ?>
                                            <option value="<?php echo esc_attr($voice_value); ?>" <?php selected((string) $settings['text_to_speech_name'], $voice_value); ?>>
                                                <?php echo esc_html($voice_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="speaklar-aioc-field">
                                    <label for="max_duration_minutes"><?php echo esc_html__('Max Duration Minutes', 'speaklar-ai-order-confirmation'); ?></label>
                                    <input name="max_duration_minutes" id="max_duration_minutes" type="number" min="1" max="30" step="1" value="<?php echo esc_attr((int) $settings['max_duration_minutes']); ?>">
                                </div>
                                <div class="speaklar-aioc-field">
                                    <label><?php echo esc_html__('Webhook URL', 'speaklar-ai-order-confirmation'); ?></label>
                                    <div class="speaklar-aioc-copy">
                                        <input type="text" class="code" readonly value="<?php echo esc_attr($webhook_url); ?>" onclick="this.select();">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="speaklar-aioc-panel">
                            <h2><?php echo esc_html__('Order Automation', 'speaklar-ai-order-confirmation'); ?></h2>
                            <label>
                                <input type="checkbox" name="trigger_on_new_order" value="1" <?php checked((int) $settings['trigger_on_new_order'], 1); ?>>
                                <?php echo esc_html__('Request an AI confirmation call when a new order is created.', 'speaklar-ai-order-confirmation'); ?>
                            </label>
                            <div class="speaklar-aioc-checks">
                                <?php foreach ($order_statuses as $status_key => $status_label) : ?>
                                    <?php $clean_status = $this->clean_order_status($status_key); ?>
                                    <label>
                                        <input type="checkbox" name="trigger_statuses[]" value="<?php echo esc_attr($clean_status); ?>" <?php checked(in_array($clean_status, (array) $settings['trigger_statuses'], true)); ?>>
                                        <?php echo esc_html($status_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p>
                                <label>
                                    <input type="checkbox" name="cod_only" value="1" <?php checked((int) $settings['cod_only'], 1); ?>>
                                    <?php echo esc_html__('Only call Cash on Delivery orders.', 'speaklar-ai-order-confirmation'); ?>
                                </label>
                            </p>
                        </div>

                        <div class="speaklar-aioc-panel">
                            <h2><?php echo esc_html__('Result Status Mapping', 'speaklar-ai-order-confirmation'); ?></h2>
                            <div class="speaklar-aioc-status-map">
                                <?php $this->render_status_select('confirmed_status', __('Customer confirmed', 'speaklar-ai-order-confirmation'), $settings, $order_statuses); ?>
                                <?php $this->render_status_select('cancelled_status', __('Customer cancelled', 'speaklar-ai-order-confirmation'), $settings, $order_statuses); ?>
                                <?php $this->render_status_select('later_status', __('Customer asked for callback/change', 'speaklar-ai-order-confirmation'), $settings, $order_statuses); ?>
                                <?php $this->render_status_select('no_answer_status', __('No answer / unreachable', 'speaklar-ai-order-confirmation'), $settings, $order_statuses); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="speaklar-aioc-actions">
                            <?php submit_button($has_credentials ? __('Save Settings', 'speaklar-ai-order-confirmation') : __('Save and Load Agents', 'speaklar-ai-order-confirmation')); ?>
                        </div>
                    </form>

                    <?php if ($has_credentials) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="speaklar-aioc-actions">
                        <?php wp_nonce_field('speaklar_aioc_refresh_agents'); ?>
                        <input type="hidden" name="action" value="speaklar_aioc_refresh_agents">
                        <?php submit_button(__('Refresh Agents and Voices', 'speaklar-ai-order-confirmation'), 'secondary', 'submit', false); ?>
                    </form>
                    <?php endif; ?>
                </div>

                <aside class="speaklar-aioc-panel">
                    <h2><?php echo esc_html__('Connection Summary', 'speaklar-ai-order-confirmation'); ?></h2>
                    <ul class="speaklar-aioc-side-list">
                        <li><span><?php echo esc_html__('Agent', 'speaklar-ai-order-confirmation'); ?></span><strong><?php echo esc_html($settings['agent_name'] ?: __('Not selected', 'speaklar-ai-order-confirmation')); ?></strong></li>
                        <li><span><?php echo esc_html__('Voice', 'speaklar-ai-order-confirmation'); ?></span><strong><?php echo esc_html($settings['text_to_speech_name']); ?></strong></li>
                        <li><span><?php echo esc_html__('Max duration', 'speaklar-ai-order-confirmation'); ?></span><strong><?php echo esc_html((int) $settings['max_duration_minutes']); ?> min</strong></li>
                        <li><span><?php echo esc_html__('Agents refreshed', 'speaklar-ai-order-confirmation'); ?></span><strong><?php echo esc_html($settings['last_agents_fetched_at'] ?: __('Never', 'speaklar-ai-order-confirmation')); ?></strong></li>
                        <li><span><?php echo esc_html__('Voices refreshed', 'speaklar-ai-order-confirmation'); ?></span><strong><?php echo esc_html($settings['last_voices_fetched_at'] ?: __('Never', 'speaklar-ai-order-confirmation')); ?></strong></li>
                    </ul>
                </aside>
            </div>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Unauthorized.', 'speaklar-ai-order-confirmation'));
        }
        check_admin_referer('speaklar_aioc_save_settings');

        $settings = $this->settings();
        $settings['speaklar_url'] = esc_url_raw((string) wp_unslash($_POST['speaklar_url'] ?? ''));
        $settings['api_key'] = sanitize_text_field((string) wp_unslash($_POST['api_key'] ?? ''));
        $settings['agent_list_path'] = sanitize_text_field((string) wp_unslash($_POST['agent_list_path'] ?? $settings['agent_list_path']));
        $settings['voice_list_path'] = sanitize_text_field((string) wp_unslash($_POST['voice_list_path'] ?? $settings['voice_list_path']));
        $settings['call_create_path'] = sanitize_text_field((string) wp_unslash($_POST['call_create_path'] ?? $settings['call_create_path']));
        if (isset($_POST['agent_id'])) {
            $settings['agent_id'] = sanitize_text_field((string) wp_unslash($_POST['agent_id']));
        }
        if (isset($_POST['text_to_speech_name'])) {
            $settings['text_to_speech_name'] = sanitize_text_field((string) wp_unslash($_POST['text_to_speech_name']));
        }
        if (isset($_POST['max_duration_minutes'])) {
            $settings['max_duration_minutes'] = max(1, absint($_POST['max_duration_minutes']));
        }
        if (isset($_POST['trigger_on_new_order'])) {
            $settings['trigger_on_new_order'] = 1;
        } elseif (isset($_POST['agent_id'])) {
            $settings['trigger_on_new_order'] = 0;
        }
        if (isset($_POST['cod_only'])) {
            $settings['cod_only'] = 1;
        } elseif (isset($_POST['agent_id'])) {
            $settings['cod_only'] = 0;
        }
        if (isset($_POST['trigger_statuses'])) {
            $settings['trigger_statuses'] = $this->sanitize_status_list((array) $_POST['trigger_statuses']);
        }
        if (isset($_POST['confirmed_status'])) {
            $settings['confirmed_status'] = $this->clean_order_status((string) wp_unslash($_POST['confirmed_status']));
        }
        if (isset($_POST['cancelled_status'])) {
            $settings['cancelled_status'] = $this->clean_order_status((string) wp_unslash($_POST['cancelled_status']));
        }
        if (isset($_POST['later_status'])) {
            $settings['later_status'] = $this->clean_order_status((string) wp_unslash($_POST['later_status']));
        }
        if (isset($_POST['no_answer_status'])) {
            $settings['no_answer_status'] = $this->clean_order_status((string) wp_unslash($_POST['no_answer_status']));
        }
        $settings['webhook_secret'] = $settings['webhook_secret'] ?: wp_generate_password(40, false, false);

        $agent_result = $this->fetch_agents($settings);
        if (!is_wp_error($agent_result)) {
            $settings['last_agents'] = $agent_result;
            $settings['last_agents_fetched_at'] = current_time('mysql');
        }
        $voice_result = $this->fetch_voices($settings);
        if (!is_wp_error($voice_result)) {
            $settings['last_voices'] = $voice_result;
            $settings['last_voices_fetched_at'] = current_time('mysql');
        }
        $settings['agent_name'] = $this->agent_name_for_id($settings['agent_id'], (array) $settings['last_agents']);

        update_option(self::OPTION_KEY, $settings);

        if (is_wp_error($agent_result)) {
            $this->redirect_with_message('', $agent_result->get_error_message());
        }

        $this->redirect_with_message(__('Settings saved. Agent and voice lists refreshed.', 'speaklar-ai-order-confirmation'));
    }

    public function refresh_agents(): void
    {
        if (!$this->current_user_can_manage()) {
            wp_die(esc_html__('Unauthorized.', 'speaklar-ai-order-confirmation'));
        }
        check_admin_referer('speaklar_aioc_refresh_agents');

        $settings = $this->settings();
        $agents = $this->fetch_agents($settings);
        if (is_wp_error($agents)) {
            $this->redirect_with_message('', $agents->get_error_message());
        }
        $voices = $this->fetch_voices($settings);

        $settings['last_agents'] = $agents;
        $settings['last_agents_fetched_at'] = current_time('mysql');
        if (!is_wp_error($voices)) {
            $settings['last_voices'] = $voices;
            $settings['last_voices_fetched_at'] = current_time('mysql');
        }
        $settings['agent_name'] = $this->agent_name_for_id($settings['agent_id'], $agents);
        update_option(self::OPTION_KEY, $settings);

        $this->redirect_with_message(__('Agent and voice lists refreshed.', 'speaklar-ai-order-confirmation'));
    }

    public function register_rest_routes(): void
    {
        register_rest_route('speaklar/v1', '/order-confirmation', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_call_result'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function register_order_statuses(): void
    {
        register_post_status('wc-customer-confirmed', [
            'label' => _x('Customer confirmed', 'Order status', 'speaklar-ai-order-confirmation'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Customer confirmed <span class="count">(%s)</span>',
                'Customer confirmed <span class="count">(%s)</span>',
                'speaklar-ai-order-confirmation'
            ),
        ]);
    }

    public function add_order_status_labels(array $statuses): array
    {
        $updated = [];
        foreach ($statuses as $key => $label) {
            $updated[$key] = $label;
            if ($key === 'wc-processing') {
                $updated['wc-customer-confirmed'] = _x('Customer confirmed', 'Order status', 'speaklar-ai-order-confirmation');
            }
        }

        return $updated ?: $statuses + [
            'wc-customer-confirmed' => _x('Customer confirmed', 'Order status', 'speaklar-ai-order-confirmation'),
        ];
    }

    public function receive_call_result(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $this->settings();
        if (!$this->valid_webhook_request($request, $settings)) {
            return new WP_REST_Response(['message' => 'Invalid webhook secret.'], 401);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = [];
        }

        $call_id = sanitize_text_field((string) ($payload['call_id'] ?? $payload['conversation_id'] ?? $payload['id'] ?? ''));
        $order_id = absint($payload['order_id'] ?? $payload['woo_order_id'] ?? $payload['external_order_id'] ?? 0);
        if ($order_id <= 0 && isset($payload['order']) && is_array($payload['order'])) {
            $order_id = absint($payload['order']['id'] ?? 0);
        }
        if ($order_id <= 0 && $call_id !== '') {
            $order_id = $this->order_id_for_call_id($call_id);
        }
        if ($order_id <= 0) {
            return new WP_REST_Response(['message' => 'order_id or known call_id is required.'], 422);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return new WP_REST_Response(['message' => 'WooCommerce order not found.'], 404);
        }

        $raw_result = (string) ($payload['result'] ?? $payload['intent'] ?? $payload['confirmation_status'] ?? $payload['status'] ?? '');
        $result = $this->normalize_result($raw_result);
        $target_status = $this->status_for_result($result, $settings);
        $summary = sanitize_textarea_field((string) ($payload['summary'] ?? $payload['message'] ?? ''));
        $transcript = sanitize_textarea_field((string) ($payload['transcript'] ?? ''));

        $note_parts = [
            'Speaklar AI call result: ' . ($result ?: 'unknown'),
        ];
        if ($call_id !== '') {
            $note_parts[] = 'Call ID: ' . $call_id;
        }
        if ($summary !== '') {
            $note_parts[] = 'Summary: ' . $summary;
        }
        if ($transcript !== '') {
            $note_parts[] = 'Transcript: ' . $this->truncate($transcript, 1800);
        }

        $order->add_order_note(implode("\n", $note_parts));
        $order->update_meta_data('_speaklar_aioc_last_result', $result);
        $order->update_meta_data('_speaklar_aioc_last_callback_at', current_time('mysql'));
        if ($call_id !== '') {
            $order->update_meta_data('_speaklar_aioc_last_call_id', $call_id);
        }
        $order->update_meta_data('_speaklar_aioc_last_payload', wp_json_encode($payload));

        if ($target_status !== '') {
            $order->update_status($target_status, 'Speaklar AI order confirmation updated status.');
        } else {
            $order->save();
        }

        return new WP_REST_Response([
            'ok' => true,
            'order_id' => $order_id,
            'result' => $result,
            'status' => $target_status,
        ]);
    }

    public function handle_new_order($order_id, $order = null): void
    {
        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        $this->maybe_send_order_call($order, 'new_order');
    }

    public function handle_status_change($order_id, $old_status, $new_status, $order): void
    {
        $this->maybe_send_order_call($order, 'status_changed', $new_status);
    }

    public function add_order_meta_box(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_meta_box(
            'speaklar-aioc-order',
            __('Speaklar AI Confirmation', 'speaklar-ai-order-confirmation'),
            [$this, 'render_order_meta_box'],
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_order_meta_box($post): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($post->ID) : null;
        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'speaklar-ai-order-confirmation') . '</p>';
            return;
        }

        $requested_at = (string) $order->get_meta('_speaklar_aioc_requested_at');
        $call_id = (string) $order->get_meta('_speaklar_aioc_last_call_id');
        $result = (string) $order->get_meta('_speaklar_aioc_last_result');
        $callback_at = (string) $order->get_meta('_speaklar_aioc_last_callback_at');

        echo '<p><strong>' . esc_html__('Call requested:', 'speaklar-ai-order-confirmation') . '</strong><br>' . esc_html($requested_at ?: __('Not yet', 'speaklar-ai-order-confirmation')) . '</p>';
        echo '<p><strong>' . esc_html__('Call ID:', 'speaklar-ai-order-confirmation') . '</strong><br>' . esc_html($call_id ?: '-') . '</p>';
        echo '<p><strong>' . esc_html__('Last result:', 'speaklar-ai-order-confirmation') . '</strong><br>' . esc_html($result ?: '-') . '</p>';
        echo '<p><strong>' . esc_html__('Callback received:', 'speaklar-ai-order-confirmation') . '</strong><br>' . esc_html($callback_at ?: '-') . '</p>';
    }

    private function maybe_send_order_call($order, string $event, string $status = ''): void
    {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }

        $settings = $this->settings();
        if (!$this->connection_ready($settings)) {
            return;
        }

        if ((string) $order->get_meta('_speaklar_aioc_requested_at') !== '') {
            return;
        }

        $order_status = $status !== '' ? $this->clean_order_status($status) : $this->clean_order_status($order->get_status());
        if ($event === 'new_order' && (int) $settings['trigger_on_new_order'] !== 1) {
            return;
        }
        $trigger_statuses = (array) ($settings['trigger_statuses'] ?? []);
        if (!$trigger_statuses && (int) $settings['trigger_on_new_order'] === 1) {
            $trigger_statuses = (array) $this->defaults()['trigger_statuses'];
        }
        if (!in_array($order_status, $trigger_statuses, true)) {
            return;
        }
        if ((int) $settings['cod_only'] === 1 && method_exists($order, 'get_payment_method') && $order->get_payment_method() !== 'cod') {
            return;
        }

        $payload = $this->order_payload($order, $settings, $event);
        $url = $this->api_url($settings, 'call_create_path');
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => $this->api_headers($settings),
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $order->add_order_note('Speaklar AI call request failed: ' . $response->get_error_message());
            $order->update_meta_data('_speaklar_aioc_last_error', $response->get_error_message());
            $order->save();
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $body) : $body;
            $order->add_order_note('Speaklar AI call request failed: HTTP ' . $code . ' ' . $this->truncate($message, 800));
            $order->update_meta_data('_speaklar_aioc_last_error', 'HTTP ' . $code . ' ' . $message);
            $order->save();
            return;
        }

        $call_id = is_array($decoded) ? sanitize_text_field((string) ($decoded['call_id'] ?? $decoded['id'] ?? $decoded['conversation_id'] ?? '')) : '';
        $order->update_meta_data('_speaklar_aioc_requested_at', current_time('mysql'));
        $order->update_meta_data('_speaklar_aioc_request_payload', wp_json_encode($payload));
        if ($call_id !== '') {
            $order->update_meta_data('_speaklar_aioc_last_call_id', $call_id);
        }
        $order->add_order_note('Speaklar AI confirmation call requested' . ($call_id !== '' ? '. Call ID: ' . $call_id : '.'));
        $order->save();
    }

    private function order_payload($order, array $settings, string $event): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $items[] = [
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'subtotal' => (string) $item->get_subtotal(),
                'total' => (string) $item->get_total(),
                'sku' => $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
            ];
        }

        return [
            'source' => 'woocommerce',
            'event' => $event,
            'text_to_speech_name' => (string) $settings['text_to_speech_name'],
            'max_duration_minutes' => (int) $settings['max_duration_minutes'],
            'store' => [
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
                'timezone' => wp_timezone_string(),
            ],
            'agent' => [
                'id' => (string) $settings['agent_id'],
                'name' => (string) $settings['agent_name'],
            ],
            'callback' => [
                'webhook_url' => $this->webhook_url($settings),
                'webhook_secret' => (string) $settings['webhook_secret'],
            ],
            'customer' => [
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'phone' => (string) $order->get_billing_phone(),
                'email' => (string) $order->get_billing_email(),
            ],
            'order' => [
                'id' => (int) $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'status' => (string) $order->get_status(),
                'currency' => (string) $order->get_currency(),
                'total' => (string) $order->get_total(),
                'subtotal' => (string) $order->get_subtotal(),
                'shipping_total' => (string) $order->get_shipping_total(),
                'payment_method' => (string) $order->get_payment_method(),
                'payment_method_title' => (string) $order->get_payment_method_title(),
                'billing_address' => $this->address_payload($order, 'billing'),
                'shipping_address' => $this->address_payload($order, 'shipping'),
                'items' => $items,
                'admin_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
            ],
        ];
    }

    private function address_payload($order, string $type): array
    {
        $prefix = $type === 'shipping' ? 'get_shipping_' : 'get_billing_';

        return [
            'first_name' => (string) $order->{$prefix . 'first_name'}(),
            'last_name' => (string) $order->{$prefix . 'last_name'}(),
            'company' => (string) $order->{$prefix . 'company'}(),
            'address_1' => (string) $order->{$prefix . 'address_1'}(),
            'address_2' => (string) $order->{$prefix . 'address_2'}(),
            'city' => (string) $order->{$prefix . 'city'}(),
            'state' => (string) $order->{$prefix . 'state'}(),
            'postcode' => (string) $order->{$prefix . 'postcode'}(),
            'country' => (string) $order->{$prefix . 'country'}(),
        ];
    }

    private function fetch_agents(array $settings)
    {
        if (!$this->connection_ready($settings, false)) {
            return new WP_Error('speaklar_missing_settings', __('Speaklar URL and API key are required before fetching agents.', 'speaklar-ai-order-confirmation'));
        }

        $response = wp_remote_get($this->api_url($settings, 'agent_list_path'), [
            'timeout' => 20,
            'headers' => $this->api_headers($settings),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new WP_Error('speaklar_agent_fetch_failed', sprintf(__('Could not fetch agents from Speaklar. HTTP %d', 'speaklar-ai-order-confirmation'), $code));
        }

        $rows = $this->extract_agent_rows($decoded);
        $agents = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['agent_id'] ?? $row['port'] ?? $row['value'] ?? '');
            $name = (string) ($row['agent_name'] ?? $row['name'] ?? $row['label'] ?? $row['title'] ?? $id);
            if ($id === '' || $name === '') {
                continue;
            }
            $agents[] = [
                'id' => sanitize_text_field($id),
                'name' => sanitize_text_field($name),
                'port' => sanitize_text_field((string) ($row['port'] ?? '')),
                'status' => sanitize_text_field((string) ($row['status'] ?? '')),
                'company_name' => sanitize_text_field((string) ($row['company_name'] ?? '')),
            ];
        }

        if (!$agents) {
            return new WP_Error('speaklar_no_agents', __('Speaklar returned no AI voice agents.', 'speaklar-ai-order-confirmation'));
        }

        return $agents;
    }

    private function fetch_voices(array $settings)
    {
        if (!$this->connection_ready($settings, false)) {
            return new WP_Error('speaklar_missing_settings', __('Speaklar URL and API key are required before fetching voices.', 'speaklar-ai-order-confirmation'));
        }

        $response = wp_remote_get($this->api_url($settings, 'voice_list_path'), [
            'timeout' => 20,
            'headers' => $this->api_headers($settings),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new WP_Error('speaklar_voice_fetch_failed', sprintf(__('Could not fetch voices from Speaklar. HTTP %d', 'speaklar-ai-order-confirmation'), $code));
        }

        $rows = $this->extract_voice_rows($decoded);
        $voices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = (string) ($row['value'] ?? $row['text_to_speech_name'] ?? $row['name_key'] ?? $row['id'] ?? $row['voice_id'] ?? '');
            $name = (string) ($row['name'] ?? $row['label'] ?? $row['title'] ?? $row['voice_name'] ?? $value);
            if ($value === '' || $name === '') {
                continue;
            }

            $voices[] = [
                'value' => sanitize_text_field($value),
                'name' => sanitize_text_field($name),
            ];
        }

        if (!$voices) {
            return new WP_Error('speaklar_no_voices', __('Speaklar returned no voices.', 'speaklar-ai-order-confirmation'));
        }

        return $voices;
    }

    private function extract_agent_rows(array $decoded): array
    {
        foreach (['agents', 'items', 'results'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['agents', 'items', 'results'] as $key) {
                if (isset($decoded['data'][$key]) && is_array($decoded['data'][$key])) {
                    return $decoded['data'][$key];
                }
            }

            if ($this->is_list_array($decoded['data'])) {
                return $decoded['data'];
            }
        }

        if ($this->is_list_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function extract_voice_rows(array $decoded): array
    {
        foreach (['voices', 'items', 'results'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['voices', 'items', 'results'] as $key) {
                if (isset($decoded['data'][$key]) && is_array($decoded['data'][$key])) {
                    return $decoded['data'][$key];
                }
            }

            if ($this->is_list_array($decoded['data'])) {
                return $decoded['data'];
            }
        }

        if ($this->is_list_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function valid_webhook_request(WP_REST_Request $request, array $settings): bool
    {
        $secret = (string) ($settings['webhook_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        $provided = (string) ($request->get_param('secret') ?: $request->get_header('x-speaklar-webhook-secret') ?: '');
        $json = $request->get_json_params();
        if ($provided === '' && is_array($json)) {
            $provided = (string) ($json['webhook_secret'] ?? '');
        }
        if ($provided !== '' && hash_equals($secret, $provided)) {
            return true;
        }

        $signature = (string) $request->get_header('x-speaklar-signature');
        if ($signature === '') {
            return false;
        }
        $signature = preg_replace('/^sha256=/i', '', trim($signature));
        $expected = hash_hmac('sha256', (string) $request->get_body(), $secret);

        return hash_equals($expected, $signature);
    }

    private function api_headers(array $settings): array
    {
        return [
            'Authorization' => 'Bearer ' . (string) $settings['api_key'],
            'X-Speaklar-Api-Key' => (string) $settings['api_key'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Speaklar-Plugin-Version' => self::VERSION,
        ];
    }

    private function api_url(array $settings, string $path_key): string
    {
        $path = trim((string) ($settings[$path_key] ?? ''));
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim((string) $settings['speaklar_url'], '/') . '/' . ltrim($path, '/');
    }

    private function webhook_url(array $settings): string
    {
        $secret = (string) ($settings['webhook_secret'] ?? '');

        return add_query_arg('secret', rawurlencode($secret), rest_url('speaklar/v1/order-confirmation'));
    }

    private function settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge($this->defaults(), $stored);
        if ((string) $settings['text_to_speech_name'] === '' && !empty($stored['voice_id'])) {
            $settings['text_to_speech_name'] = (string) $stored['voice_id'];
        }
        if ((string) $settings['text_to_speech_name'] === 'default') {
            $settings['text_to_speech_name'] = 'ria';
        }
        if ((string) $settings['webhook_secret'] === '') {
            $settings['webhook_secret'] = wp_generate_password(40, false, false);
            update_option(self::OPTION_KEY, $settings);
        }

        return $settings;
    }

    private function defaults(): array
    {
        return [
            'speaklar_url' => '',
            'api_key' => '',
            'agent_id' => '',
            'agent_name' => '',
            'text_to_speech_name' => 'ria',
            'max_duration_minutes' => 3,
            'webhook_secret' => wp_generate_password(40, false, false),
            'agent_list_path' => '/api/wordpress/agents',
            'voice_list_path' => '/api/wordpress/voices',
            'call_create_path' => '/api/wordpress/order-confirmation/calls',
            'trigger_on_new_order' => 1,
            'trigger_statuses' => ['pending', 'processing', 'on-hold'],
            'cod_only' => 0,
            'confirmed_status' => 'processing',
            'cancelled_status' => 'cancelled',
            'later_status' => 'on-hold',
            'no_answer_status' => 'on-hold',
            'last_agents' => [],
            'last_voices' => [],
            'last_agents_fetched_at' => '',
            'last_voices_fetched_at' => '',
        ];
    }

    private function connection_ready(array $settings, bool $require_agent = true): bool
    {
        if ((string) $settings['speaklar_url'] === '' || (string) $settings['api_key'] === '') {
            return false;
        }

        return !$require_agent || (string) $settings['agent_id'] !== '';
    }

    private function current_user_can_manage(): bool
    {
        return current_user_can(class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options');
    }

    private function redirect_with_message(string $message = '', string $error = ''): void
    {
        $args = ['page' => 'speaklar-ai-order-confirmation'];
        if ($message !== '') {
            $args['speaklar_message'] = rawurlencode($message);
        }
        if ($error !== '') {
            $args['speaklar_error'] = rawurlencode($error);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function order_statuses(): array
    {
        if (function_exists('wc_get_order_statuses')) {
            return wc_get_order_statuses();
        }

        return [
            'wc-pending' => __('Pending payment', 'speaklar-ai-order-confirmation'),
            'wc-processing' => __('Processing', 'speaklar-ai-order-confirmation'),
            'wc-on-hold' => __('On hold', 'speaklar-ai-order-confirmation'),
            'wc-completed' => __('Completed', 'speaklar-ai-order-confirmation'),
            'wc-cancelled' => __('Cancelled', 'speaklar-ai-order-confirmation'),
        ];
    }

    private function clean_order_status(string $status): string
    {
        $status = sanitize_key($status);

        return preg_replace('/^wc-/', '', $status);
    }

    private function sanitize_status_list(array $statuses): array
    {
        $clean = [];
        foreach ($statuses as $status) {
            $status = $this->clean_order_status((string) wp_unslash($status));
            if ($status !== '') {
                $clean[] = $status;
            }
        }

        return array_values(array_unique($clean));
    }

    private function render_status_select(string $name, string $label, array $settings, array $statuses): void
    {
        echo '<label style="display:block;margin:0 0 10px;"><span style="display:inline-block;min-width:220px;">' . esc_html($label) . '</span>';
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($statuses as $status_key => $status_label) {
            $clean = $this->clean_order_status($status_key);
            echo '<option value="' . esc_attr($clean) . '" ' . selected((string) $settings[$name], $clean, false) . '>' . esc_html($status_label) . '</option>';
        }
        echo '</select></label>';
    }

    private function normalize_result(string $result): string
    {
        $result = strtolower(trim(str_replace([' ', '-'], '_', $result)));
        $confirmed = ['yes', 'confirm', 'confirmed', 'order_confirmed', 'customer_confirmed', 'success'];
        $cancelled = ['no', 'cancel', 'cancelled', 'canceled', 'reject', 'rejected', 'customer_cancelled', 'customer_canceled'];
        $later = ['later', 'callback', 'call_back', 'reschedule', 'change', 'changed', 'address_change', 'needs_change'];
        $no_answer = ['no_answer', 'unanswered', 'busy', 'failed', 'unreachable', 'wrong_number', 'voicemail'];

        if (in_array($result, $confirmed, true)) {
            return 'confirmed';
        }
        if (in_array($result, $cancelled, true)) {
            return 'cancelled';
        }
        if (in_array($result, $later, true)) {
            return 'later';
        }
        if (in_array($result, $no_answer, true)) {
            return 'no_answer';
        }

        return $result;
    }

    private function status_for_result(string $result, array $settings): string
    {
        $map = [
            'confirmed' => (string) $settings['confirmed_status'],
            'cancelled' => (string) $settings['cancelled_status'],
            'later' => (string) $settings['later_status'],
            'no_answer' => (string) $settings['no_answer_status'],
        ];

        return $map[$result] ?? '';
    }

    private function order_id_for_call_id(string $call_id): int
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $order_ids = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => '_speaklar_aioc_last_call_id',
            'meta_value' => $call_id,
        ]);

        return isset($order_ids[0]) ? absint($order_ids[0]) : 0;
    }

    private function voice_options(array $settings): array
    {
        $clean = [];
        foreach ((array) ($settings['last_voices'] ?? []) as $voice) {
            if (!is_array($voice)) {
                continue;
            }

            $value = (string) ($voice['value'] ?? $voice['text_to_speech_name'] ?? $voice['name_key'] ?? $voice['id'] ?? '');
            $name = (string) ($voice['name'] ?? $voice['label'] ?? $voice['title'] ?? $voice['voice_name'] ?? $value);
            if ($value === '' || $name === '') {
                continue;
            }

            $clean[$value] = [
                'value' => sanitize_text_field($value),
                'name' => sanitize_text_field($name),
            ];
        }

        if (!$clean) {
            $clean['ria'] = [
                'value' => 'ria',
                'name' => 'ria',
            ];
        }

        return array_values($clean);
    }

    private function voice_display_name(string $name): string
    {
        return trim((string) preg_replace('/\s+-\s+[^-]+$/', '', $name));
    }

    private function agent_name_for_id(string $agent_id, array $agents): string
    {
        foreach ($agents as $agent) {
            if ((string) ($agent['id'] ?? '') === $agent_id) {
                return (string) ($agent['name'] ?? '');
            }
        }

        return '';
    }

    private function is_list_array(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    private function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }
}

Speaklar_AI_Order_Confirmation::boot();
