<?php
namespace Arraytics\PluginNotice;

class Rating
{
    /**
     * Singleton instance of the Rating class.
     *
     * @var self|null
     */
    private static $instance;

    /**
     * Plugin slug or name.
     *
     * @var string
     */
    private $plugin_name;

    /**
     * Priority for hooking into WordPress actions.
     *
     * @var int
     */
    private $priority = 10;

    /**
     * Days after installation to show rating.
     *
     * @var int
     */
    private $days;

    /**
     * URL for plugin rating page.
     *
     * @var string
     */
    private $rating_url;

    /**
     * URL for plugin support page.
     *
     * @var string
     */
    private $support_url;

    /**
     * Plugin version, dynamically fetched.
     *
     * @var string
     */
    private $version;

    /**
     * Whether conditions to show rating are met.
     *
     * @var bool
     */
    private $condition_status = true;

    /**
     * Text domain for the plugin.
     *
     * @var string
     */
    private $text_domain;

    /**
     * URL of the plugin logo.
     *
     * @var string
     */
    private $plugin_logo;

    /**
     * Allowed admin screens to show rating.
     *
     * @var array
     */
    private $plugin_screens = [];

    /**
     * Whether duplicate rating notice is allowed.
     *
     * @var bool
     */
    private $duplication = false;

    /**
     * Flag to mark never-show triggered state.
     *
     * @var bool
     */
    private $never_show_triggered = false;

    /**
     * Interval (in days) to show rating again after "ask me later".
     *
     * @var int
     */
    private $rating_show_interval = 30;

    /**
     * API URL for fetching rating data.
     *
     * @var string
     */
    private $api_url;

    /**
     * Get singleton instance.
     *
     * @param string|null $text_domain
     * @param string|null $unique_id
     * @return self|false
     */
    public static function instance($text_domain = null, $unique_id = null)
    {
        if ($text_domain === null) {
            return false;
        }
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        self::$instance->config($text_domain, $unique_id ?? uniqid());

        return self::$instance;
    }

    /**
     * Configure plugin-specific settings.
     *
     * @param string $text_domain
     * @param string $unique_id
     * @return self
     */
    public function config($text_domain, $unique_id)
    {
        $this->text_domain = $text_domain;
        return $this;
    }

    public function update_settings()
    {
        $settings = get_transient($this->text_domain . '_rating_settings');
        if ($settings) {
            return;
        }

        $response = wp_remote_get($this->api_url . '/wp-json/plugin-banner/v1/rating');

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        $rating_settings = isset($data[$this->text_domain]) ? $data[$this->text_domain] : '';

        set_transient($this->text_domain . '_rating_settings', $rating_settings, 12 * HOUR_IN_SECONDS);
    }

    public function set_plugin($plugin_name, $plugin_url)
    {
        $this->plugin_name = $plugin_name;
        $this->rating_url = $plugin_url;
        return $this;
    }

    public function set_api_url($url)
    {
        $this->api_url = rtrim($url, '/') . '/';
        return $this;
    }

    public function set_priority($priority)
    {
        $this->priority = $priority;
        return $this;
    }

    public function set_first_appear_day($days = 7)
    {
        $this->days = $days;
        return $this;
    }

    public function set_rating_url($url)
    {
        $this->rating_url = $url;
        return $this;
    }

    public function set_support_url($url)
    {
        $this->support_url = $url;
        return $this;
    }

    public function set_plugin_logo($logo_url)
    {
        $this->plugin_logo = $logo_url;
        return $this;
    }

    public function set_allowed_screens($screen)
    {
        $this->plugin_screens[] = $screen;
        return $this;
    }

    public function set_condition($result)
    {
        if (is_bool($result)) {
            $this->condition_status = $result;
        } elseif (is_callable($result)) {
            $this->condition_status = (bool) call_user_func($result);
        } else {
            $this->condition_status = false;
        }
        return $this;
    }

    public static function init()
    {
        add_action('wp_ajax_wpmet_rating_never_show_message', [__CLASS__, 'never_show_message']);
        add_action('wp_ajax_wpmet_rating_ask_me_later_message', [__CLASS__, 'ask_me_later_message']);

        if (self::$instance instanceof self) {
            self::$instance->update_settings();
        }
    }

    protected function is_current_screen_allowed($current_screen_id)
    {
        $allowed = array_merge($this->plugin_screens, ['dashboard', 'plugins']);
        return in_array($current_screen_id, $allowed, true);
    }

    public function call()
    {
        self::init();
        add_action('admin_head', [$this, 'fire'], $this->priority);
    }

    public function fire()
    {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $current_screen = get_current_screen();

        if (!$this->is_current_screen_allowed($current_screen->id)) {
            return;
        }

        if ($this->condition_status === false) {
            return;
        }

        add_action('admin_footer', [$this, 'scripts'], 9999);

        if (!$this->action_on_fire()) {
            return;
        }

        if (!$this->is_installation_date_exists()) {
            $this->set_installation_date();
        }

        if (get_option($this->text_domain . '_never_show') === 'yes') {
            return;
        }

        if (get_option($this->text_domain . '_ask_me_later') === 'yes') {
            $this->days = $this->rating_show_interval;
            $this->duplication = true;
            $this->never_show_triggered = true;
            if ($this->get_remaining_days() >= $this->days) {
                $this->duplication = false;
            }
        }

        $this->display_message_box();
    }

    protected function action_on_fire()
    {
        return true;
    }

    public function set_installation_date()
    {
        add_option($this->text_domain . '_install_date', current_time('mysql'));
    }

    public function is_installation_date_exists()
    {
        return (bool) get_option($this->text_domain . '_install_date');
    }

    public function get_installation_date()
    {
        return get_option($this->text_domain . '_install_date');
    }

    public function set_first_action_date()
    {
        add_option($this->text_domain . '_first_action_Date', current_time('mysql'));
        add_option($this->text_domain . '_first_action', 'yes');
    }

    public function get_days(\DateTime $from_date, \DateTime $to_date)
    {
        return (int) round(($to_date->format('U') - $from_date->format('U')) / (60 * 60 * 24));
    }

    public function get_remaining_days()
    {
        $install_date = $this->get_installation_date();
        if (!$install_date) {
            return 0;
        }
        $datetime1 = new \DateTime($install_date);
        $datetime2 = new \DateTime(current_time('mysql'));
        return abs($this->get_days($datetime1, $datetime2));
    }

    public static function never_show_message()
    {
        if (
            empty($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wpmet_rating')
        ) {
            wp_send_json_error();
            return;
        }

        $plugin_name = sanitize_key(isset($_POST['plugin_name']) ? $_POST['plugin_name'] : '');
        if (!empty($plugin_name)) {
            add_option($plugin_name . '_never_show', 'yes');
        }
        wp_send_json_success();
    }

    public static function ask_me_later_message()
    {
        if (
            empty($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wpmet_rating')
        ) {
            wp_send_json_error();
            return;
        }

        $plugin_name = sanitize_key(isset($_POST['plugin_name']) ? $_POST['plugin_name'] : '');
        if (!empty($plugin_name)) {
            if (get_option($plugin_name . '_ask_me_later') === false) {
                add_option($plugin_name . '_ask_me_later', 'yes');
            } else {
                add_option($plugin_name . '_never_show', 'yes');
            }
        }
        wp_send_json_success();
    }

    public function display_message_box()
    {
        $settings = get_transient($this->text_domain . '_rating_settings');
        if ($settings !== 'yes') {
            return;
        }

        global $wpmet_libs_execution_container;

        if (!$this->duplication) {
            if (isset($wpmet_libs_execution_container['rating'])) {
                return;
            }
        }
        $wpmet_libs_execution_container['rating'] = __FILE__;

        if ($this->get_remaining_days() >= $this->days) {
            $not_good_enough_btn_id = $this->never_show_triggered ? '_btn_never_show' : '_btn_not_good';
            $message = "Hello! Seems like you have used {$this->plugin_name} to build this website — Thanks a lot! <br>
            Could you please do us a <b>big favor</b> and give it a <b>5-star</b> rating on WordPress? 
            This would boost our motivation and help other users make a comfortable decision while choosing the {$this->plugin_name}";

            Notice::instance($this->text_domain, '_plugin_rating_msg_used_in_day')
                ->set_message($message)
                ->set_button([
                    'url' => $this->rating_url,
                    'text' => 'Ok, you deserved it',
                    'class' => 'button-primary',
                    'id' => $this->text_domain . '_btn_deserved',
                ])
                ->set_button([
                    'url' => get_current_screen()->id === 'toplevel_page_getgenie' ? '#write-for-me' : '#',
                    'text' => 'I already did',
                    'class' => 'button-default',
                    'id' => $this->text_domain . '_btn_already_did',
                    'icon' => 'dashicons-before dashicons-smiley',
                ])
                ->set_button([
                    'url' => $this->support_url,
                    'text' => 'I need support',
                    'class' => 'button-default',
                    'id' => '#',
                    'icon' => 'dashicons-before dashicons-sos',
                ])
                ->set_button([
                    'url' => '#',
                    'text' => 'Never ask again',
                    'class' => 'button-default',
                    'id' => $this->text_domain . '_btn_never_show',
                    'icon' => 'dashicons-before dashicons-welcome-comments',
                ])
                ->set_button([
                    'url' => get_current_screen()->id === 'toplevel_page_getgenie' ? '#write-for-me' : '#',
                    'text' => 'No, not good enough',
                    'class' => 'button-default',
                    'id' => $this->text_domain . $not_good_enough_btn_id,
                    'icon' => 'dashicons-before dashicons-thumbs-down',
                ])
                ->call();
        }
    }

    public function scripts()
    {
        $domain_js = esc_js($this->text_domain);
        $nonce_js = esc_js(wp_create_nonce('wpmet_rating'));

        echo <<<EOT
        <script>
        jQuery(document).ready(function (\$) {
            \$('#{$domain_js}_btn_already_did').on('click', function() {
                \$.post(ajaxurl, {
                    action: 'wpmet_rating_never_show_message',
                    plugin_name: '{$domain_js}',
                    nonce: '{$nonce_js}'
                }, function() {
                    \$('#{$domain_js}-_plugin_rating_msg_used_in_day').remove();
                });
            });

            \$('#{$domain_js}_btn_deserved').click(function() {
                \$.post(ajaxurl, {
                    action: 'wpmet_rating_never_show_message',
                    plugin_name: '{$domain_js}',
                    nonce: '{$nonce_js}'
                }, function() {
                    \$('#{$domain_js}-_plugin_rating_msg_used_in_day').remove();
                });
            });

            \$('#{$domain_js}_btn_not_good').click(function() {
                \$.post(ajaxurl, {
                    action: 'wpmet_rating_ask_me_later_message',
                    plugin_name: '{$domain_js}',
                    nonce: '{$nonce_js}'
                }, function() {
                    \$('#{$domain_js}-_plugin_rating_msg_used_in_day').remove();
                });
            });

            \$('#{$domain_js}_btn_never_show').click(function() {
                \$.post(ajaxurl, {
                    action: 'wpmet_rating_never_show_message',
                    plugin_name: '{$domain_js}',
                    nonce: '{$nonce_js}'
                }, function() {
                    \$('#{$domain_js}-_plugin_rating_msg_used_in_day').remove();
                });
            });
        });
        </script>
        EOT;
    }
}
