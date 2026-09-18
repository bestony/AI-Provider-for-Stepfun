<?php

/**
 * Plugin configuration reader.
 *
 * Intentionally free of WordPress functions so it can be loaded (and exercised) outside WordPress.
 * Every value is resolved as: environment variable > PHP constant > built-in default.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Util;

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
    public const VERSION = '1.0.0';

    /**
     * Base URL of the StepFun API (mainland-China platform).
     *
     * The international platform serves the same API at `https://api.stepfun.ai/v1`; switch with
     * `STEPFUN_BASE_URL`.
     *
     * @var string
     */
    public const DEFAULT_BASE_URL = 'https://api.stepfun.com/v1';

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
     * Gets the API base URL.
     *
     * @return string The base URL, without a trailing slash.
     */
    public static function getBaseUrl(): string
    {
        $url = self::env('STEPFUN_BASE_URL');

        return $url === '' ? self::DEFAULT_BASE_URL : rtrim($url, '/');
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
     * Whether a StepFun credential is present locally.
     *
     * A purely local check (environment, constant, stored option) so it can be called from filters
     * without triggering a network request. The option is included because that is where the
     * Connectors screen stores the key, and WordPress 7.0 feeds it back to the SDK on its own.
     *
     * @return bool Whether credentials are configured.
     */
    public static function hasCredentials(): bool
    {
        if (self::env('STEPFUN_API_KEY') !== '') {
            return true;
        }

        if (function_exists('get_option')) {
            $option = get_option('connectors_ai_' . self::PROVIDER_ID . '_api_key', '');
            if (is_string($option) && $option !== '') {
                return true;
            }
        }

        return false;
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
        return 'ai-provider-for-stepfun/' . self::VERSION;
    }
}
