<?php

/**
 * Plugin settings page class file.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Admin;

use StepFun\AiProvider\Util\StepfunConfig;

/**
 * The Settings → Bestony AI Provider for StepFun page and the Plugins-screen shortcut to it.
 *
 * Everything here goes through the WordPress Settings API: `register_setting()` owns the nonce,
 * capability check and persistence, so no option is written by hand. The page only ever offers the
 * base URLs listed in {@see StepfunConfig::getBaseUrlChoices()}.
 */
final class StepfunSettings
{
    /**
     * The Settings API option group, used for the nonce fields and the form target.
     *
     * @var string
     */
    public const OPTION_GROUP = 'stepfun_settings';

    /**
     * The admin page slug, also used as the Settings API page id.
     *
     * @var string
     */
    public const PAGE_SLUG = 'stepfun-settings';

    /**
     * Registers the admin hooks.
     *
     * @param string $pluginFile Absolute path to the main plugin file.
     * @return void
     */
    public static function register(string $pluginFile): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_menu', [self::class, 'addPage']);
        add_filter(
            'plugin_action_links_' . plugin_basename($pluginFile),
            [self::class, 'addActionLink']
        );
    }

    /**
     * Registers the setting, its section and its field.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            StepfunConfig::OPTION_NAME,
            [
                'type' => 'string',
                'default' => StepfunConfig::DEFAULT_BASE_URL,
                'sanitize_callback' => [self::class, 'sanitize'],
            ]
        );

        add_settings_section(
            'stepfun_base_url_section',
            __('StepFun API endpoint', 'bestony-ai-provider-for-stepfun'),
            [self::class, 'renderSection'],
            self::PAGE_SLUG
        );

        add_settings_field(
            StepfunConfig::OPTION_NAME,
            __('API base URL', 'bestony-ai-provider-for-stepfun'),
            [self::class, 'renderField'],
            self::PAGE_SLUG,
            'stepfun_base_url_section'
        );
    }

    /**
     * Adds the page under the Settings menu.
     *
     * @return void
     */
    public static function addPage(): void
    {
        add_options_page(
            __('Bestony AI Provider for StepFun', 'bestony-ai-provider-for-stepfun'),
            __('Bestony AI Provider for StepFun', 'bestony-ai-provider-for-stepfun'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Adds a Setup Step Plan shortcut to the plugin's row on the Plugins screen.
     *
     * @param array<string, string> $links The existing action links.
     * @return array<string, string> The action links, with the shortcut appended.
     */
    public static function addActionLink(array $links): array
    {
        $url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Setup Step Plan', 'bestony-ai-provider-for-stepfun')
        );

        return $links;
    }

    /**
     * Validates a submitted base URL against the allowed list.
     *
     * @param mixed $value The submitted value.
     * @return string An allowed base URL, or the default when the value is not one.
     */
    public static function sanitize($value): string
    {
        $value = is_string($value) ? rtrim(trim($value), '/') : '';

        return StepfunConfig::isAllowedBaseUrl($value) ? $value : StepfunConfig::DEFAULT_BASE_URL;
    }

    /**
     * Renders the settings page.
     *
     * @return void
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the section description.
     *
     * @return void
     */
    public static function renderSection(): void
    {
        echo '<p>' . esc_html__(
            'Choose which StepFun API host the provider talks to. Pick a Step Plan host if your key was provisioned for Step Plan.',
            'bestony-ai-provider-for-stepfun'
        ) . '</p>';
    }

    /**
     * Renders the base URL dropdown.
     *
     * @return void
     */
    public static function renderField(): void
    {
        $name = StepfunConfig::OPTION_NAME;
        $current = self::getStoredBaseUrl();

        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        foreach (self::getChoices() as $url => $label) {
            echo '<option value="' . esc_attr($url) . '"' . selected($url, $current, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__(
            'The model list and every generation request use this base URL. The STEPFUN_BASE_URL environment variable or PHP constant, when set, overrides it.',
            'bestony-ai-provider-for-stepfun'
        ) . '</p>';
    }

    /**
     * Gets the labels for the offered base URLs, keyed by URL.
     *
     * The URL list itself is owned by {@see StepfunConfig}; a URL without a label falls back to the
     * URL, so adding one there can never render a blank option.
     *
     * @return array<string, string> Base URL to translated label.
     */
    private static function getChoices(): array
    {
        $labels = [
            StepfunConfig::DEFAULT_BASE_URL => __('Stepfun.com', 'bestony-ai-provider-for-stepfun'),
            'https://api.stepfun.com/step_plan/v1' => __('StepPlan at Stepfun.com', 'bestony-ai-provider-for-stepfun'),
            'https://api.stepfun.ai/v1' => __('Stepfun.ai', 'bestony-ai-provider-for-stepfun'),
            'https://api.stepfun.ai/step_plan/v1' => __('Step Plan at Stepfun.ai', 'bestony-ai-provider-for-stepfun'),
        ];

        $choices = [];
        foreach (StepfunConfig::getBaseUrlChoices() as $url) {
            $choices[$url] = $labels[$url] ?? $url;
        }

        return $choices;
    }

    /**
     * Gets the base URL to preselect in the dropdown.
     *
     * The stored option is shown rather than the effective base URL, so the control always has a
     * valid selection even when the environment variable overrides the setting.
     *
     * @return string An allowed base URL.
     */
    private static function getStoredBaseUrl(): string
    {
        $value = get_option(StepfunConfig::OPTION_NAME, StepfunConfig::DEFAULT_BASE_URL);
        $value = is_string($value) ? rtrim($value, '/') : '';

        return StepfunConfig::isAllowedBaseUrl($value) ? $value : StepfunConfig::DEFAULT_BASE_URL;
    }
}
