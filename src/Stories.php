<?php
namespace Arraytics\PluginNotice;

class Stories
{
    /**
     * Plugin version (set from main plugin).
     *
     * @var string
     */
    protected string $version = '1.0.0'; // default fallback

    /**
     * Holds the stories data object.
     *
     * @var object|null
     */
    protected $data = null;

    /**
     * Title for the stories widget.
     *
     * @var string|null
     */
    protected $title = null;

    /**
     * Plugin links to be added via filter.
     *
     * @var array
     */
    protected $plugin_link = [];

    /**
     * Timestamp of the last check for stories.
     *
     * @var int
     */
    protected $last_check = 0;

    /**
     * Interval (in seconds) between API fetches.
     *
     * @var int
     */
    protected $check_interval = 3600 * 6; // 6 hours

    /**
     * Allowed plugin admin screens for displaying stories.
     *
     * @var array|null
     */
    protected $plugin_screens = [];

    /**
     * Text domain for localization and option keys.
     *
     * @var string|null
     */
    protected $text_domain = null;

    /**
     * Comma-separated filter string for blacklisting stories.
     *
     * @var string|null
     */
    protected $filter_string = null;

    /**
     * Base URL of the API for fetching stories.
     *
     * @var string|null
     */
    protected $api_url = null;

    /**
     * Internal storage for filtered stories.
     *
     * @var array
     */
    private $stories = [];


    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance(string $text_domain = ''): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        if ($text_domain !== '') {
            self::$instance->set_text_domain($text_domain);
        }
        return self::$instance;
    }

    /**
     * Set the plugin version dynamically.
     *
     * @param string $version
     * @return $this
     */
    public function set_version(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Get plugin version.
     *
     * @return string
     */
    public function get_version(): string
    {
        return $this->version;
    }

    /**
     * Get current file path of this class
     */
    public function get_script_location(): string
    {
        return __FILE__;
    }

    /**
     * Set plugin link for filter hooks
     */
    public function set_plugin(string $link_title, string $weblink = ''): self
    {
        $plugin = [$link_title, $weblink];
        add_filter('eventin/stories/plugin_links', function (array $plugin_links) use ($plugin) {
            $plugin_links[] = $plugin;
            return $plugin_links;
        });
        return $this;
    }

    /**
     * Register the dashboard widget callback
     */
    public function call(): void
    {
        add_action('wp_dashboard_setup', [$this, 'show_story_widget'], 111);
    }

    /**
     * Set the dashboard widget title
     */
    public function set_title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Enable test mode (shorten interval)
     */
    public function is_test(bool $is_test = false): self
    {
        if ($is_test) {
            $this->check_interval = 1;
        }
        return $this;
    }

    /**
     * Set the text domain for options and filtering
     */
    public function set_text_domain(string $text_domain): self
    {
        $this->text_domain = $text_domain;
        return $this;
    }

    /**
     * Set the filter string for blacklist/whitelist filtering
     */
    public function set_filter(string $filter_string): self
    {
        $this->filter_string = $filter_string;
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
     * Add allowed plugin screens where the widget should appear
     */
    public function set_plugin_screens(string $screen): self
    {
        $this->plugin_screens[] = $screen;
        return $this;
    }

    /**
     * Show the dashboard widget with stories
     */
    public function show_story_widget(): void
    {
        $this->get_stories();
        
        if (empty($this->data) || !empty($this->data->error)) {
            return;
        }

        $filterList = [];
        if (!empty($this->filter_string)) {
            $filterList = array_filter(array_map('trim', explode(',', $this->filter_string)));
        }

        foreach ($this->data as $story) {
            if (!empty($filterList) && $this->in_blacklist($story, $filterList)) {
                continue;
            }
            $this->set_stories($story);
        }

        if (empty($this->stories)) {
            return;
        }

        // error_log(print_r('Story loading 5', true));
        $widget_title = $this->title ? "{$this->title} Stories" : 'Stories';

        wp_add_dashboard_widget(
            'wpmet-stories',
            $widget_title,
            [$this, 'show']
        );

        // Move the widget to the top of the normal-high priority widgets
        global $wp_meta_boxes;
        $dashboard = $wp_meta_boxes['dashboard']['normal']['high'] ?? [];
        if (isset($dashboard['wpmet-stories'])) {
            $wp_meta_boxes['dashboard']['normal']['high'] =
                array_merge(['wpmet-stories' => $dashboard['wpmet-stories']], $dashboard);
        }
    }

    /**
     * Render the stories widget output
     */
    public function show(): void
    {
        usort($this->stories, fn($a, $b) => $a['priority'] <=> $b['priority']);
        include_once 'views/utility-story-template.php';
    }

    /**
     * Check if current admin screen is allowed to show stories widget
     */
    public function is_correct_screen_to_show(string $b_screen, string $screen_id): bool
    {
        if (in_array($b_screen, [$screen_id, 'all_page'], true)) {
            return true;
        }
        if ($b_screen === 'plugin_page') {
            return in_array($screen_id, $this->plugin_screens ?? [], true);
        }
        return false;
    }

    /**
     * Check if a given configuration is whitelisted for the provided list
     */
    private function in_whitelist(object $conf, array $list): bool
    {
        $match = $conf->data->whitelist ?? '';
        if (empty($match)) {
            return true;
        }
        $match_arr = array_map('trim', explode(',', $match));
        return count(array_intersect($list, $match_arr)) > 0;
    }

    /**
     * Check if a given configuration is blacklisted for the provided list
     */
    private function in_blacklist(object $conf, array $list): bool
    {
        $match = $conf->data->blacklist ?? '';
        if (empty($match)) {
            return false;
        }
        $match_arr = array_map('trim', explode(',', $match));
        return count(array_intersect($list, $match_arr)) > 0;
    }

    /**
     * Add a story to the internal collection after checks
     */
    private function set_stories(object $story): void
    {
        if (isset($this->stories[$story->id])) {
            return; // already added
        }

        // Check time window (start and end timestamps)
        if (!empty($story->start) && !empty($story->end)) {
            $now = time();
            if (intval($story->start) > $now || intval($story->end) < $now) {
                return;
            }
        }

        // Prepare filter list for active plugins + current text domain
        $filter = [$this->text_domain];
        foreach ((array) get_option('active_plugins', []) as $plugin) {
            $plugin_name = pathinfo($plugin, PATHINFO_FILENAME);
            if (!empty($plugin_name)) {
                $filter[] = $plugin_name;
            }
        }

        if (empty(array_intersect($filter, (array) $story->plugins))) {
            return;
        }

        $this->stories[$story->id] = [
            'id'          => $story->id,
            'title'       => $story->title,
            'description' => $story->description,
            'type'        => $story->type,
            'priority'    => $story->priority,
            'story_link'  => $story->data->story_link ?? '',
            'story_image' => $story->data->story_image ?? '',
        ];
    }

    /**
     * Fetch stories from API or cache and update local storage
     */
    private function get_stories(): void
    {
        $this->data = get_option($this->text_domain . '__stories_data');
        $this->data = empty($this->data) ? [] : $this->data;

        $this->last_check = (int) get_option($this->text_domain . '__stories_last_check', 0);

        if (($this->last_check + $this->check_interval) < time()) {
            $response = wp_remote_get($this->api_url . 'cache/stories.json?nocache=' . time(), [
                'timeout'     => 10,
                'httpversion' => '1.1',
            ]);

            if (!is_wp_error($response) && !empty($response['body'])) {
                $fetched_data = json_decode($response['body']);
                if (!empty($fetched_data)) {
                    $this->data = $fetched_data;
                    update_option($this->text_domain . '__stories_last_check', time());
                    update_option($this->text_domain . '__stories_data', $this->data);
                }
            }
        }
    }
}
