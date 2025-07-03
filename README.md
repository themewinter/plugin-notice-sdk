---

## 🛠 Installation

1. **Require this package via Composer**

Add the following configuration to your `composer.json`:

```json
{
    "require": {
        "arraytics/plugin-notice-sdk": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/themewinter/plugin-notice-sdk"
        }
    ]
}
```
If you do not have, composer installed in your plugin, please install using 

```bash
composer init
```

2. **Update Dependencies**

```bash
composer update
```

## Configuration

1. ****

Update all properties following your projects and execute.

```php
    use Arraytics\PluginNotice\PluginNotice;

    $notifyer = new PluginNotice([
        'plugin_slug'      => 'wp-cafe',
        'plugin_name'      => 'WpCafe',
        'plugin_url'       => 'https://themewinter.com/wp-cafe/',
        'support_url'      => 'https://themewinter.com/support/',
        'review_url'       => 'https://wordpress.org/support/plugin/wp-event-solution/reviews/#new-post',
        'allowed_screens'  => 'toplevel_page_wpcafe',
        'stories_api_url'  => 'https://product.themewinter.com/auth/public/stories/',
        'banners_api_url'  => 'https://product.themewinter.com/auth/public/jhanda',
        'api_url'          => 'https://product.themewinter.com/auth',
        // 💡 Dynamic filter string components
        'base_plugins'     => ['wp-cafe', 'wpcafe-free-only'],
        'pro_plugin_slug'  => 'wpcafe-pro',
        'pro_class'        => 'WpCafe_Pro',
    ]);

    $notifyer->boot();
```
