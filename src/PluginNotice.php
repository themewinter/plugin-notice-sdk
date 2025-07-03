<?php

namespace Arraytics\PluginNotice;

/**
 * Class PluginNotice
 *
 * Handles displaying stories, banners, and rating notices in a WordPress plugin.
 *
 * This class is intended to be used as part of a Composer package and configured dynamically
 * from the consuming project.
 *
 * @package Arraytics\PluginNotice
 */
class PluginNotice
{
    /**
     * Plugin slug (used as identifier).
     *
     * @var string
     */
    protected $plugin_slug;

    /**
     * Human-readable plugin name.
     *
     * @var string
     */
    protected $plugin_name;

    /**
     * Plugin website URL.
     *
     * @var string
     */
    protected $plugin_url;

    /**
     * Support page URL for the plugin.
     *
     * @var string
     */
    protected $support_url;

    /**
     * Review URL (typically on WordPress.org).
     *
     * @var string
     */
    protected $review_url;

    /**
     * Allowed admin screen(s) where the banner/notice can be shown.
     *
     * @var string
     */
    protected $allowed_screens;

    /**
     * API endpoint for fetching stories.
     *
     * @var string
     */
    protected $stories_api_url;

    /**
     * API endpoint for fetching banners.
     *
     * @var string
     */
    protected $banners_api_url;

    /**
     * Array of plugin slugs used to build the active plugins filter string.
     *
     * @var array
     */
    protected $base_plugins;

    /**
     * Slug of the pro version plugin, if available.
     *
     * @var string|null
     */
    protected $pro_plugin_slug = null;

    /**
     * Class name to check if the pro plugin is active.
     *
     * @var string|null
     */
    protected $pro_class = null;

    /**
     * Final computed filter string (comma-separated plugin slugs).
     *
     * @var string
     */
    protected $filter_string;
    
    /**
     * Store api url
     *
     * @var string
     */
    protected $api_url;

    /**
     * PluginNotice constructor.
     *
     * @param array $config {
     *     Configuration options.
     *
     *     @type string      $plugin_slug
     *     @type string      $plugin_name
     *     @type string      $plugin_url
     *     @type string      $support_url
     *     @type string      $review_url
     *     @type string      $allowed_screens
     *     @type string      $stories_api_url
     *     @type string      $banners_api_url
     *     @type array       $base_plugins         Optional. Default is [$plugin_slug].
     *     @type string|null $pro_plugin_slug      Optional. Slug of the pro plugin.
     *     @type string|null $pro_class            Optional. Class name to check if pro is active.
     *     @type string|null $filter_string        Optional. Overrides auto-generated filter string.
     * }
     */
    public function __construct(array $config)
    {
        $this->plugin_slug     = $config['plugin_slug'];
        $this->plugin_name     = $config['plugin_name'];
        $this->plugin_url      = $config['plugin_url'];
        $this->support_url     = $config['support_url'];
        $this->review_url      = $config['review_url'];
        $this->allowed_screens = $config['allowed_screens'];
        $this->stories_api_url = $config['stories_api_url'];
        $this->banners_api_url = $config['banners_api_url'];

        $this->base_plugins    = isset($config['base_plugins']) ? $config['base_plugins'] : [$this->plugin_slug];
        $this->pro_plugin_slug = isset($config['pro_plugin_slug']) ? $config['pro_plugin_slug'] : null;
        $this->pro_class       = isset($config['pro_class']) ? $config['pro_class'] : null;

        $this->filter_string   = isset($config['filter_string']) ? $config['filter_string'] : $this->generate_filter_string();
        $this->api_url         = $config['api_url'];
    }

    /**
     * Boot and render all available components (Notice, Stories, Banner, Rating).
     *
     * Should be called during the plugin's initialization phase.
     *
     * @return void
     */
    public function boot()
    {
        // Initialize core notices
        Notice::init();

        // Show stories from remote API
        Stories::instance($this->plugin_slug)
            ->set_filter($this->filter_string)
            ->set_plugin($this->plugin_name, $this->plugin_url)
            ->set_api_url($this->stories_api_url)
            ->call();

        // Show banners from remote API
        Banner::instance($this->plugin_slug)
            ->set_filter(ltrim($this->filter_string, ','))
            ->set_api_url($this->banners_api_url)
            ->set_plugin_screens($this->allowed_screens)
            ->call();

        // Show rating prompt
        Rating::instance($this->plugin_slug)
            ->set_plugin($this->plugin_name, $this->review_url)
            ->set_allowed_screens($this->allowed_screens)
            ->set_priority(30)
            ->set_first_appear_day(7)
            ->set_condition(true)
            ->set_api_url($this->api_url)
            ->set_support_url($this->support_url)
            ->call();
    }

    /**
     * Generate a comma-separated plugin filter string.
     *
     * Adds the pro plugin slug if the corresponding class exists,
     * and removes `-free-only` suffix if pro is active.
     *
     * @return string
     */
    protected function generate_filter_string()
    {
        $plugins = $this->base_plugins;

        if (
            $this->pro_class &&
            (class_exists($this->pro_class) || function_exists($this->pro_class)) &&
            $this->pro_plugin_slug
        ) {
            $plugins[] = $this->pro_plugin_slug;

            // Remove potential 'free-only' entry if pro is active
            $plugins = array_filter($plugins, function ($plugin) {
                return $plugin !== $this->plugin_slug . '-free-only';
            });
        }

        return implode(',', $plugins);
    }
}
