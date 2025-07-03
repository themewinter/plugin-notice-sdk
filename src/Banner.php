<?php

namespace Arraytics\PluginNotice;

/**
 * Class Banner
 *
 * Displays remote banners and notices fetched from an external API.
 *
 * @package Arraytics\PluginNotice
 */
class Banner
{
    /**
     * Plugin version (set from main plugin).
     *
     * @var string
     */
    protected $version = '1.0.0'; // default fallback

    /**
     * Singleton instance.
     *
     * @var static
     */
    private static $instance;

    /**
     * API data response.
     *
     * @var array|object
     */
    protected $data;

    /**
     * Last API check timestamp.
     *
     * @var int
     */
    protected $last_check = 0;

    /**
     * Time interval between API checks (default: 6 hours).
     *
     * @var int
     */
    protected $check_interval = 3600 * 6;

    /**
     * Allowed screen IDs for banners.
     *
     * @var array
     */
    protected $plugin_screens = [];

    /**
     * Text domain (used for caching keys).
     *
     * @var string
     */
    protected $text_domain;

    /**
     * Comma-separated filter string (e.g., active plugin slugs).
     *
     * @var string|null
     */
    protected $filter_string = null;

    /**
     * Parsed filter values as an array.
     *
     * @var array
     */
    protected $filter_array = [];

    /**
     * Remote API base URL.
     *
     * @var string
     */
    protected $api_url;

    /**
     * Set the plugin version dynamically.
     *
     * @param string $version
     * @return $this
     */
    public function set_version($version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Get plugin version.
     *
     * @return string
     */
    public function get_version()
    {
        return $this->version;
    }

    /**
     * Get file path of this script.
     *
     * @return string
     */
    public function get_script_location()
    {
        return __FILE__;
    }

    /**
     * Entry point to start the banner system.
     *
     * @return void
     */
    public function call()
    {
        add_action('admin_head', [$this, 'display_content']);
    }

    /**
     * Render banners if applicable.
     *
     * @return void
     */
    public function display_content()
    {
        $this->get_data();
        if (!empty($this->data->error) || empty($this->data)) {
            return;
        }

        foreach ($this->data as $content) {
            if (!empty($this->filter_array) && $this->in_blacklist($content, $this->filter_array)) {
                continue;
            }

            if ($content->start <= time() && time() <= $content->end) {
                $screen = get_current_screen();

                if ($this->is_correct_screen_to_show($content->screen, $screen->id)) {
                    $inline_css = !empty($content->data->style_css)
                        ? ' style="' . $content->data->style_css . '"'
                        : '';

                    $banner_id = !empty($content->data->unique_key)
                        ? $content->data->unique_key
                        : $content->id;

                    $instance = Notice::instance($this->text_domain, $banner_id)
                        ->set_dismiss('global', 3600 * 24 * 15);

                    if ($content->type === 'banner') {
                        $this->render_banner($content, $instance, $inline_css);
                    }

                    if ($content->type === 'notice') {
                        $this->render_notice($content, $instance, $inline_css);
                    }
                }
            }
        }
    }

    /**
     * Render notice type content.
     *
     * @param object $content
     * @param object $instance
     * @param string $inline_css
     * @return void
     */
    private function render_notice($content, $instance, $inline_css)
    {
        $instance->set_message($content->data->notice_body);

        if (!empty($content->data->notice_image)) {
            $instance->set_logo($content->data->notice_image);
        }

        if (!empty($content->data->button_text)) {
            $instance->set_button([
                'default_class' => 'button',
                'class'         => 'button-secondary button-small',
                'text'          => $content->data->button_text,
                'url'           => $content->data->button_link,
            ]);
        }

        $instance->call();
    }

    /**
     * Render banner type content.
     *
     * @param object $content
     * @param object $instance
     * @param string $inline_css
     * @return void
     */
    private function render_banner($content, $instance, $inline_css)
    {
        $html = sprintf(
            '<a target="_blank"%s class="plugin-notice-href" href="%s"><img style="display: block;margin: 0 auto;" src="%s" /></a>',
            $inline_css,
            esc_url($content->data->banner_link),
            esc_url($content->data->banner_image)
        );

        $instance->set_gutter(false)->set_html($html)->call();
    }

    /**
     * Get data from cache or fetch from remote if outdated.
     *
     * @return void
     */
    private function get_data()
    {
        $this->data = get_option($this->text_domain . '__banner_data') ?: [];
        $this->last_check = get_option($this->text_domain . '__banner_last_check') ?: 0;

        if (($this->last_check + $this->check_interval) < time()) {
            $response = wp_remote_get(
                $this->api_url . '/cache/' . $this->text_domain . '.json?nocache=' . time(),
                ['timeout' => 10, 'httpversion' => '1.1']
            );

            if (!is_wp_error($response) && !empty($response['body'])) {
                $decoded = json_decode($response['body']);
                if (!empty($decoded)) {
                    $this->data = $decoded;
                    update_option($this->text_domain . '__banner_data', $this->data);
                    update_option($this->text_domain . '__banner_last_check', time());
                }
            }
        }
    }

    /**
     * Check if content should not be shown due to blacklist.
     *
     * @param object $conf
     * @param array $list
     * @return bool
     */
    private function in_blacklist($conf, $list)
    {
        $match = isset($conf->data->blacklist) ? $conf->data->blacklist : '';
        if (empty($match)) {
            return false;
        }

        $match_arr = array_map('trim', explode(',', $match));
        return !empty(array_intersect($list, $match_arr));
    }

    /**
     * Determine if the screen is valid to show content.
     *
     * @param string $expected_screen
     * @param string $current_screen_id
     * @return bool
     */
    public function is_correct_screen_to_show($expected_screen, $current_screen_id)
    {
        if (in_array($expected_screen, ['all_page', $current_screen_id], true)) {
            return true;
        }

        if ($expected_screen === 'plugin_page') {
            return in_array($current_screen_id, $this->plugin_screens, true);
        }

        return false;
    }

    /**
     * Enable test mode (set check interval to 1 sec).
     *
     * @param bool $is_test
     * @return $this
     */
    public function is_test($is_test = false)
    {
        if ($is_test) {
            $this->check_interval = 1;
        }

        return $this;
    }

    /**
     * Set the text domain (used for option key).
     *
     * @param string $text_domain
     * @return $this
     */
    public function set_text_domain($text_domain)
    {
        $this->text_domain = $text_domain;
        return $this;
    }

    /**
     * Set the filter string and convert it to array.
     *
     * @param string $filter_string
     * @return $this
     */
    public function set_filter($filter_string)
    {
        $this->filter_string = $filter_string;
        $this->filter_array = array_map('trim', explode(',', $filter_string));
        return $this;
    }

    /**
     * Set the API URL to fetch banners from.
     *
     * @param string $url
     * @return $this
     */
    public function set_api_url($url)
    {
        $this->api_url = $url;
        return $this;
    }

    /**
     * Set allowed plugin screen(s) for showing the banner.
     *
     * @param string|array $screen
     * @return $this
     */
    public function set_plugin_screens($screen)
    {
        $this->plugin_screens = (array) $screen;
        return $this;
    }

    /**
     * Get singleton instance and set text domain.
     *
     * @param string $text_domain
     * @return static
     */
    public static function instance($text_domain = '')
    {
        self::$instance = new static();
        return self::$instance->set_text_domain($text_domain);
    }
}
