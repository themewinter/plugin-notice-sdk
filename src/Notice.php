<?php

namespace Arraytics\PluginNotice;

class Notice
{
    /**
     * Singleton instance of the Notice class.
     *
     * @var self|null
     */
    protected static $instance;

    /**
     * Plugin version (set from main plugin).
     *
     * @var string
     */
    protected string $version = '1.0.0'; // default fallback

    /**
     * Unique identifier for the notice.
     *
     * @var string
     */
    protected string $notice_id;

    /**
     * Text domain of the plugin.
     *
     * @var string
     */
    protected string $text_domain;

    /**
     * Unique ID used internally.
     *
     * @var string
     */
    protected string $unique_id;

    /**
     * Additional CSS classes for the notice container.
     *
     * @var string
     */
    protected string $class = '';

    /**
     * Default button configuration.
     *
     * @var array
     */
    protected array $button;

    /**
     * Size-specific settings (currently unused).
     *
     * @var array
     */
    protected array $size = [];

    /**
     * List of buttons added to the notice.
     *
     * @var array
     */
    protected array $buttons = [];

    /**
     * Title of the notice.
     *
     * @var string
     */
    protected string $title = '';

    /**
     * Message body of the notice.
     *
     * @var string
     */
    protected string $message = '';

    /**
     * URL of the left-side logo image.
     *
     * @var string
     */
    protected string $logo = '';

    /**
     * Whether the notice container should have gutter (padding).
     *
     * @var string
     */
    protected string $gutter = '';

    /**
     * Inline style for the logo image.
     *
     * @var string
     */
    protected string $logo_style = '';

    /**
     * Dismissible type: false, 'user', or 'global'.
     *
     * @var string|bool
     */
    protected string|bool $dismissible = false;

    /**
     * Expiration time in seconds for the dismissible notice.
     *
     * @var int
     */
    protected int $expired_time = 1;

    /**
     * Raw HTML content to override default markup.
     *
     * @var string
     */
    protected string $html = '';

    /**
     * Get an instance of the Notice class.
     *
     * @param string|null $text_domain
     * @param string|null $unique_id
     * @return self|false
     */
    public static function instance(?string $text_domain = null, ?string $unique_id = null): self|false
    {
        if (!$text_domain) {
            return false;
        }
        self::$instance = new self();
        return self::$instance->config($text_domain, $unique_id ?? uniqid());
    }

    /**
     * Initialize the dismiss AJAX and enqueue scripts.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_ajax_wpmet-notices', [__CLASS__, 'dismiss_ajax_call']);
    }

    /**
     * Configure basic properties of the notice.
     *
     * @param string $text_domain
     * @param string $unique_id
     * @return $this
     */
    public function config(string $text_domain, string $unique_id): self
    {
        $this->text_domain  = $text_domain;
        $this->unique_id    = $unique_id;
        $this->notice_id    = "$text_domain-$unique_id";
        $this->dismissible  = false;
        $this->expired_time = 1;

        $this->button = [
            'default_class' => 'button',
            'class'         => 'button-secondary',
            'text'          => 'Button',
            'url'           => '#',
            'icon'          => '',
        ];

        return $this;
    }

    /**
     * Set additional CSS classes.
     *
     * @param string $classname
     * @return $this
     */
    public function set_class(string $classname = ''): self
    {
        $this->class .= $classname;
        return $this;
    }

    /**
     * Set the notice type.
     *
     * @param string $type
     * @return $this
     */
    public function set_type(string $type = ''): self
    {
        $this->class .= " notice-$type";
        return $this;
    }

    /**
     * Add a button to the notice.
     *
     * @param array $button
     * @return $this
     */
    public function set_button(array $button = []): self
    {
        $this->buttons[] = array_merge($this->button, $button);
        return $this;
    }

    /**
     * Set a custom ID for the notice.
     *
     * @param string $id
     * @return $this
     */
    public function set_id(string $id): self
    {
        $this->notice_id = $id;
        return $this;
    }

    /**
     * Set the title of the notice.
     *
     * @param string $title
     * @return $this
     */
    public function set_title(string $title = ''): self
    {
        $this->title .= $title;
        return $this;
    }

    /**
     * Set the message of the notice.
     *
     * @param string $message
     * @return $this
     */
    public function set_message(string $message = ''): self
    {
        $this->message .= $message;
        return $this;
    }

    /**
     * Enable or disable gutter spacing.
     *
     * @param bool $gutter
     * @return $this
     */
    public function set_gutter(bool $gutter = true): self
    {
        $this->gutter = $gutter;
        $this->class .= $gutter ? '' : ' no-gutter';
        return $this;
    }

    /**
     * Set logo and style.
     *
     * @param string $logo
     * @param string $logo_style
     * @return $this
     */
    public function set_logo(string $logo = '', string $logo_style = ''): self
    {
        $this->logo = $logo;
        $this->logo_style = $logo_style;
        return $this;
    }

    /**
     * Set custom HTML content.
     *
     * @param string $html
     * @return $this
     */
    public function set_html(string $html = ''): self
    {
        $this->html .= $html;
        return $this;
    }

    /**
     * Configure dismiss behavior.
     *
     * @param string $scope 'user' or 'global'
     * @param int $time Expiry time in seconds
     * @return $this
     */
    public function set_dismiss(string $scope = 'global', int $time = 604800): self
    {
        $this->dismissible = $scope;
        $this->expired_time = $time;
        return $this;
    }

    /**
     * Get the compiled data for rendering.
     *
     * @return array
     */
    public function get_data(): array
    {
        return [
            'message' => $this->message,
            'title'   => $this->title,
            'buttons' => $this->buttons,
            'class'   => $this->class,
            'html'    => $this->html,
        ];
    }

    /**
     * Add the notice to the WordPress admin_notices hook.
     *
     * @return void
     */
    public function call(): void
    {
        add_action('admin_notices', [$this, 'get_notice']);
    }

    /**
     * Display the notice HTML based on conditions.
     *
     * @return void
     */
    public function get_notice(): void
    {
        $expired = match ($this->dismissible) {
            'user'   => get_user_meta(get_current_user_id(), $this->notice_id, true),
            'global' => get_transient($this->notice_id),
            default  => '',
        };

        global $notice_list;
        $notice_list ??= [];

        if (!isset($notice_list[$this->notice_id]) && (false === $expired || empty($expired))) {
            $notice_list[$this->notice_id] = __FILE__;
            $this->generate_html();
        }
    }

    /**
     * Render the HTML for the notice.
     *
     * @return void
     */
    public function generate_html(): void
    {
        ?>
            <div 
                id="<?php 
                echo esc_attr($this->notice_id);
                ?>" 
                class="notice wpmet-notice notice-<?php 
                echo esc_attr($this->notice_id . ' ' . $this->class);
                ?> <?php 
                echo \false === $this->dismissible ? '' : 'is-dismissible';
                ?>"

                expired_time="<?php 
                echo esc_attr($this->expired_time);
                ?>"
                dismissible="<?php 
                echo esc_attr($this->dismissible);
                ?>"
            >
                <?php 
                if (!empty($this->logo)) {
                    ?>
                    <img class="notice-logo" style="<?php 
                    echo esc_attr($this->logo_style);
                    ?>" src="<?php 
                    echo esc_url($this->logo);
                    ?>" />
                <?php 
                }
                ?>

                <div class="notice-right-container <?php 
                echo empty($this->logo) ? 'notice-container-full-width' : '';
                ?>">

                    <?php 
                if (empty($this->html)) {
                    ?>
                        <?php 
                    echo empty($this->title) ? '' : sprintf('<div class="notice-main-title notice-vert-space">%s</div>', esc_html($this->title));
                    ?>

                        <div class="notice-message notice-vert-space">
                        <?php 
                    echo wp_kses_post( $this->message );
                    ?>
                        </div>

                        <?php 
                    if (!empty($this->buttons)) {
                        ?>
                            <div class="button-container notice-vert-space">
                                <?php 
                        foreach ($this->buttons as $button) {
                            ?>
                                    <a id="<?php 
                            echo !isset($button['id']) ? '' : esc_attr($button['id']);
                            ?>" href="<?php 
                            echo esc_url($button['url']);
                            ?>" class="wpmet-notice-button <?php 
                            echo esc_attr($button['class']);
                            ?>">
                                        <?php 
                            if (!empty($button['icon'])) {
                                ?>
                                            <i class="notice-icon <?php 
                                echo esc_attr($button['icon']);
                                ?>"></i>
                                        <?php 
                            }
                            ?>
                                        <?php 
                            echo esc_html($button['text']);
                            ?>
                                    </a>
                                    &nbsp;
                                <?php 
                        }
                        ?>
                            </div>
                        <?php 
                    }
                    ?>

                    <?php 
                } else {
                    ?>
                        <?php 
                    echo \wp_kses_post( $this->html );
                    ?>
                    <?php 
                }
                ?>

                </div>

                <?php 
                if (\false !== $this->dismissible) {
                    ?>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">x#test-console-04</span>
                    </button>
                <?php 
                }
                ?>

                <div style="clear:both"></div>

            </div>
		<?php 
    }

    /**
     * Handle AJAX dismissal request.
     *
     * @return void
     */
    public static function dismiss_ajax_call(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wpmet-notices')) {
            wp_send_json_error();
        }

        $notice_id   = sanitize_text_field(wp_unslash($_POST['notice_id'] ?? ''));
        $dismissible = sanitize_text_field(wp_unslash($_POST['dismissible'] ?? ''));
        $expired     = (int) sanitize_text_field(wp_unslash($_POST['expired_time'] ?? ''));

        if ($notice_id) {
            if ($dismissible === 'user') {
                update_user_meta(get_current_user_id(), $notice_id, true);
            } else {
                set_transient($notice_id, true, $expired);
            }
            wp_send_json_success();
        }

        wp_send_json_error();
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
     * Get file location of this script.
     *
     * @return string
     */
    public function get_script_location(): string
    {
        return __FILE__;
    }
}
