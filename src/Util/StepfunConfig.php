<?php

/**
 * Plugin configuration reader.
 *
 * Loads outside WordPress (the self-check exercises it that way), so every WordPress call is guarded.
 * Values are resolved as: environment variable > PHP constant > stored option > built-in default.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Util;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * Reads the plugin's optional configuration.
 */
final class StepfunConfig
{
    /**
     * The plugin version, reported in the User-Agent header.
     *
     * @var string
     */
    public const VERSION = '1.2.2';

    /**
     * The option the Settings → Bestony AI Provider for StepFun page stores the chosen base URL in.
     *
     * @var string
     */
    public const OPTION_NAME = 'stepfun_base_url';

    /**
     * Base URL of the StepFun API (mainland-China platform).
     *
     * @var string
     */
    public const BASE_URL_STEPFUN_COM = 'https://api.stepfun.com/v1';

    /**
     * Base URL of the Step Plan API on the mainland-China platform.
     *
     * @var string
     */
    public const BASE_URL_STEPFUN_COM_STEP_PLAN = 'https://api.stepfun.com/step_plan/v1';

    /**
     * Base URL of the StepFun API (international platform).
     *
     * @var string
     */
    public const BASE_URL_STEPFUN_AI = 'https://api.stepfun.ai/v1';

    /**
     * Base URL of the Step Plan API on the international platform.
     *
     * @var string
     */
    public const BASE_URL_STEPFUN_AI_STEP_PLAN = 'https://api.stepfun.ai/step_plan/v1';

    /**
     * The base URL used when nothing is configured.
     *
     * @var string
     */
    public const DEFAULT_BASE_URL = self::BASE_URL_STEPFUN_COM;

    /**
     * The provider ID used by the SDK registry, the Connectors option name and the filter tuples.
     *
     * Frozen: it decides `connectors_ai_stepfun_api_key`, `STEPFUN_API_KEY` and the value users pass
     * to model preference filters. Changing it drops every stored API key.
     *
     * @var string
     */
    public const PROVIDER_ID = 'stepfun';

    /**
     * Model pushed to the front of the list and used for the AI plugin's preference filters.
     *
     * A static default: StepFun's model list endpoint reports no capability data, so there is nothing
     * to compute a "best" model from. Override with the `STEPFUN_DEFAULT_MODEL` environment variable
     * or constant.
     *
     * @var string
     */
    public const DEFAULT_MODEL = 'step-3.7-flash';

    /**
     * Image model pushed to the front of the image feature's preference list.
     *
     * `step-image-edit-2` is the model StepFun's own image guide recommends; it is the only image
     * family still documented for new use. Override with `STEPFUN_IMAGE_MODEL`.
     *
     * @var string
     */
    public const DEFAULT_IMAGE_MODEL = 'step-image-edit-2';

    /**
     * Resolves configuration from an environment variable or a PHP constant.
     *
     * @param string $name The variable/constant name.
     * @return string The value, or an empty string when unset.
     */
    public static function env(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (defined($name)) {
            $constant = constant($name);
            if (is_scalar($constant)) {
                return (string) $constant;
            }
        }

        return '';
    }

    /**
     * Gets the base URLs the settings page offers.
     *
     * @return list<string> The allowed base URLs.
     */
    public static function getBaseUrlChoices(): array
    {
        return [
            self::BASE_URL_STEPFUN_COM,
            self::BASE_URL_STEPFUN_COM_STEP_PLAN,
            self::BASE_URL_STEPFUN_AI,
            self::BASE_URL_STEPFUN_AI_STEP_PLAN,
        ];
    }

    /**
     * Whether a URL is one of the offered base URLs.
     *
     * @param string $url The URL to check, without a trailing slash.
     * @return bool Whether the URL may be used.
     */
    public static function isAllowedBaseUrl(string $url): bool
    {
        return in_array($url, self::getBaseUrlChoices(), true);
    }

    /**
     * Gets the API base URL.
     *
     * Resolved as: environment variable/constant > the stored option > the built-in default. The
     * option is validated on read as well as on save, so a value written directly to the database
     * (WP-CLI, a migration, a stray filter) can never point requests at an unlisted host.
     *
     * @return string The base URL, without a trailing slash.
     */
    public static function getBaseUrl(): string
    {
        $url = self::env('STEPFUN_BASE_URL');
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $stored = self::getStoredBaseUrl();

        return $stored === '' ? self::DEFAULT_BASE_URL : $stored;
    }

    /**
     * Reads the base URL from the option, if WordPress and a valid value are available.
     *
     * Guarded so this class keeps working outside WordPress (see the file docblock).
     *
     * @return string The stored base URL, or an empty string when there is none.
     */
    private static function getStoredBaseUrl(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }

        $value = get_option(self::OPTION_NAME, '');
        if (!is_string($value)) {
            return '';
        }

        $value = rtrim($value, '/');

        return self::isAllowedBaseUrl($value) ? $value : '';
    }

    /**
     * Gets how structured output (JSON response) requests are shaped.
     *
     * `json_schema` is the shape StepFun documents for `response_format`. `json_object` only asks for
     * valid JSON and is the fallback when a model rejects schemas; `none` sends no `response_format`
     * at all, which is the escape hatch when a model rejects every form of it.
     *
     * @return string One of `json_schema`, `json_object` or `none`.
     */
    public static function getStructuredOutputMode(): string
    {
        $mode = strtolower(self::env('STEPFUN_STRUCTURED_OUTPUT'));

        return in_array($mode, ['json_schema', 'json_object', 'none'], true) ? $mode : 'json_schema';
    }

    /**
     * Gets the model ID to prefer.
     *
     * @return string The model ID, or an empty string to leave the AI plugin's own defaults alone.
     */
    public static function getDefaultModelId(): string
    {
        $configured = self::env('STEPFUN_DEFAULT_MODEL');

        return $configured === '' ? self::DEFAULT_MODEL : $configured;
    }

    /**
     * Gets the image model ID to prefer for the image generation feature.
     *
     * @return string The model ID, or an empty string to leave the AI plugin's own defaults alone.
     */
    public static function getImageModelId(): string
    {
        $configured = self::env('STEPFUN_IMAGE_MODEL');

        return $configured === '' ? self::DEFAULT_IMAGE_MODEL : $configured;
    }

    /**
     * Whether this deployment claims its models accept image input.
     *
     * The StepFun model list reports no modalities, so this plugin declares vision from a maintained
     * list in the catalog. Set `STEPFUN_MODEL_INPUT_MODALITIES` to a comma-separated list containing
     * `image` (e.g. `text,image`) to declare it for every chat model instead — for deployments ahead
     * of the catalog.
     *
     * @return bool Whether image input is declared for all chat models.
     */
    public static function declaresImageInput(): bool
    {
        $modalities = self::env('STEPFUN_MODEL_INPUT_MODALITIES');
        if ($modalities === '') {
            return false;
        }

        $modalities = array_map('trim', explode(',', strtolower($modalities)));

        return in_array('image', $modalities, true);
    }

    /**
     * Gets the request timeout in seconds.
     *
     * WordPress' HTTP default is 5 seconds, which no LLM request survives.
     *
     * @return float The timeout in seconds.
     */
    public static function getRequestTimeout(): float
    {
        $timeout = self::env('STEPFUN_REQUEST_TIMEOUT');

        return $timeout === '' ? 120.0 : (float) $timeout;
    }

    /**
     * Gets the connection timeout in seconds.
     *
     * @return float The timeout in seconds.
     */
    public static function getConnectTimeout(): float
    {
        $connectTimeout = self::env('STEPFUN_CONNECT_TIMEOUT');

        return $connectTimeout === '' ? 10.0 : (float) $connectTimeout;
    }

    /**
     * Whether a StepFun credential is available to the AI Client.
     *
     * Asks the AI Client instead of reading the credential itself: the key was given to WordPress by
     * the user, so the option is not this plugin's to read. The registry carries an authentication
     * instance once the user saved a key in Settings → Connectors (core hands it over on `init`) or
     * set `STEPFUN_API_KEY`, which the SDK resolves when the provider is registered.
     *
     * Still a purely local check — no network request — so it can be called from filters.
     *
     * @return bool Whether credentials are configured.
     */
    public static function hasCredentials(): bool
    {
        if (!class_exists(AiClient::class)) {
            return false;
        }

        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider(self::PROVIDER_ID)) {
            return false;
        }

        return $registry->getProviderRequestAuthentication(self::PROVIDER_ID) !== null;
    }

    /**
     * Creates the request options used for every StepFun request, including the model list.
     *
     * @return RequestOptions The request options.
     */
    public static function createRequestOptions(): RequestOptions
    {
        $options = new RequestOptions();
        $options->setTimeout(self::getRequestTimeout());
        $options->setConnectTimeout(self::getConnectTimeout());

        return $options;
    }

    /**
     * Gets the User-Agent header value.
     *
     * @return string The User-Agent value.
     */
    public static function getUserAgent(): string
    {
        return 'bestony-ai-provider-for-stepfun/' . self::VERSION;
    }
}
