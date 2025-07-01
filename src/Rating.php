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
    private string $plugin_name;

    /**
     * Priority for hooking into WordPress actions.
     *
     * @var int
     */
    private int $priority = 10;

    /**
     * Days after installation to show rating.
     *
     * @var int
     */
    private int $days;

    /**
     * URL for plugin rating page.
     *
     * @var string
     */
    private string $rating_url;

    /**
     * URL for plugin support page.
     *
     * @var string
     */
    private string $support_url;

    /**
     * Plugin version, dynamically fetched.
     *
     * @var string
     */
    private string $version;

    /**
     * Whether conditions to show rating are met.
     *
     * @var bool
     */
    private bool $condition_status = true;

    /**
     * Text domain for the plugin.
     *
     * @var string
     */
    private string $text_domain;

    /**
     * URL of the plugin logo.
     *
     * @var string
     */
    private string $plugin_logo;

    /**
     * Allowed admin screens to show rating.
     *
     * @var array
     */
    private array $plugin_screens = [];

    /**
     * Whether duplicate rating notice is allowed.
     *
     * @var bool
     */
    private bool $duplication = false;

    /**
     * Flag to mark never-show triggered state.
     *
     * @var bool
     */
    private bool $never_show_triggered = false;

    /**
     * Interval (in days) to show rating again after "ask me later".
     *
     * @var int
     */
    private int $rating_show_interval = 30;

    /**
     * API URL for fetching rating data.
     *
     * @var string
     */
    private string $api_url;

    /**
     * Get singleton instance.
     *
     * @param string|null $text_domain
     * @param string|null $unique_id
     * @return self|false
     */
    public static function instance(string $text_domain = null, string $unique_id = null)
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
    public function config(string $text_domain, string $unique_id): self
    {
        $this->text_domain = $text_domain;
        // You can use $unique_id if needed.
        return $this;
    }

    /**
     * Fetch and cache rating settings from API.
     *
     * @return void
     */
    public function update_settings(): void
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

        $rating_settings = $data[$this->text_domain] ?? '';

        set_transient($this->text_domain . '_rating_settings', $rating_settings, 12 * HOUR_IN_SECONDS);
    }

    /**
     * Set the plugin name and rating URL.
     *
     * @param string $plugin_name
     * @param string $plugin_url
     * @return self
     */
    public function set_plugin(string $plugin_name, string $plugin_url): self
    {
        $this->plugin_name = $plugin_name;
        $this->rating_url = $plugin_url;
        return $this;
    }

    /**
     * Set API URL for fetching stories
     */
    public function set_api_url(string $url): self
    {
        $this->api_url = rtrim($url, '/') . '/';
        return $this;
    }

    /**
     * Set the priority for action hooks.
     *
     * @param int $priority
     * @return self
     */
    public function set_priority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Set the number of days after which rating appears.
     *
     * @param int $days
     * @return self
     */
    public function set_first_appear_day(int $days = 7): self
    {
        $this->days = $days;
        return $this;
    }

    /**
     * Set plugin rating URL.
     *
     * @param string $url
     * @return self
     */
    public function set_rating_url(string $url): self
    {
        $this->rating_url = $url;
        return $this;
    }

    /**
     * Set plugin support URL.
     *
     * @param string $url
     * @return self
     */
    public function set_support_url(string $url): self
    {
        $this->support_url = $url;
        return $this;
    }

    /**
     * Set plugin logo URL.
     *
     * @param string $logo_url
     * @return self
     */
    public function set_plugin_logo(string $logo_url): self
    {
        $this->plugin_logo = $logo_url;
        return $this;
    }

    /**
     * Add allowed admin screen id to show rating notice.
     *
     * @param string $screen
     * @return self
     */
    public function set_allowed_screens(string $screen): self
    {
        $this->plugin_screens[] = $screen;
        return $this;
    }

    /**
     * Set condition to show rating notice.
     * Accepts boolean or callable returning boolean.
     *
     * @param bool|callable $result
     * @return self
     */
    public function set_condition($result): self
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

    /**
     * Initialize ajax hooks.
     */
    public static function init(): void
    {
        add_action('wp_ajax_wpmet_rating_never_show_message', [__CLASS__, 'never_show_message']);
        add_action('wp_ajax_wpmet_rating_ask_me_later_message', [__CLASS__, 'ask_me_later_message']);

        self::$instance->update_settings();
    }

    /**
     * Check if current admin screen is allowed for rating notice.
     *
     * @param string $current_screen_id
     * @return bool
     */
    protected function is_current_screen_allowed(string $current_screen_id): bool
    {
        $allowed = array_merge($this->plugin_screens, ['dashboard', 'plugins']);
        return in_array($current_screen_id, $allowed, true);
    }

    /**
     * Entry point to start rating notice logic.
     *
     * @return void
     */
    public function call(): void
    {
        self::init();
        add_action('admin_head', [$this, 'fire'], $this->priority);
    }

    /**
     * Main logic to display rating notice when conditions are met.
     *
     * @return void
     */
    public function fire(): void
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

    /**
     * Overrideable method to control if the rating notice should be fired.
     *
     * @return bool
     */
    protected function action_on_fire(): bool
    {
        return true;
    }

    /**
     * Set installation date option.
     */
    public function set_installation_date(): void
    {
        add_option($this->text_domain . '_install_date', current_time('mysql'));
    }

    /**
     * Check if installation date option exists.
     *
     * @return bool
     */
    public function is_installation_date_exists(): bool
    {
        return (bool) get_option($this->text_domain . '_install_date');
    }

    /**
     * Get installation date.
     *
     * @return string|false
     */
    public function get_installation_date()
    {
        return get_option($this->text_domain . '_install_date');
    }

    /**
     * Set first action date and flag.
     */
    public function set_first_action_date(): void
    {
        add_option($this->text_domain . '_first_action_Date', current_time('mysql'));
        add_option($this->text_domain . '_first_action', 'yes');
    }

    /**
     * Calculate number of days between two DateTime objects.
     *
     * @param \DateTime $from_date
     * @param \DateTime $to_date
     * @return int
     */
    public function get_days(\DateTime $from_date, \DateTime $to_date): int
    {
        return (int) round(($to_date->format('U') - $from_date->format('U')) / (60 * 60 * 24));
    }

    /**
     * Get remaining days since installation.
     *
     * @return int
     */
    public function get_remaining_days(): int
    {
        $install_date = $this->get_installation_date();
        if (!$install_date) {
            return 0;
        }
        $datetime1 = new \DateTime($install_date);
        $datetime2 = new \DateTime(current_time('mysql'));
        return abs($this->get_days($datetime1, $datetime2));
    }

    /**
     * Never show rating message AJAX handler.
     */
    public static function never_show_message(): void
    {
        if (
            empty($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wpmet_rating')
        ) {
            wp_send_json_error();
            return;
        }

        $plugin_name = sanitize_key($_POST['plugin_name'] ?? '');
        if (!empty($plugin_name)) {
            add_option($plugin_name . '_never_show', 'yes');
        }
        wp_send_json_success();
    }

    /**
     * Ask me later AJAX handler.
     */
    public static function ask_me_later_message(): void
    {
        if (
            empty($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wpmet_rating')
        ) {
            wp_send_json_error();
            return;
        }

        $plugin_name = sanitize_key($_POST['plugin_name'] ?? '');
        if (!empty($plugin_name)) {
            if (get_option($plugin_name . '_ask_me_later') === false) {
                add_option($plugin_name . '_ask_me_later', 'yes');
            } else {
                add_option($plugin_name . '_never_show', 'yes');
            }
        }
        wp_send_json_success();
    }

    /**
     * Display the rating message box.
     *
     * @return void
     */
    public function display_message_box(): void
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

    /**
     * Output the necessary JS for AJAX handling of rating buttons.
     *
     * @return void
     */
    public function scripts(): void
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
