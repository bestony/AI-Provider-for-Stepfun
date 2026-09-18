<?php

/**
 * StepFun provider class file.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Provider;

use StepFun\AiProvider\Metadata\StepfunModelMetadataDirectory;
use StepFun\AiProvider\Models\StepfunImageGenerationModel;
use StepFun\AiProvider\Models\StepfunTextGenerationModel;
use StepFun\AiProvider\Util\StepfunConfig;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the StepFun provider.
 *
 * StepFun serves an OpenAI-compatible API: `POST /chat/completions` for text and vision,
 * `POST /images/generations` for text-to-image, and `GET /models` to list models. All three accept
 * `Authorization: Bearer <key>`, which is the SDK's default API key authentication, so no custom
 * authentication class is needed.
 */
class StepfunProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @return string The base URL for the StepFun API.
     */
    protected static function baseUrl(): string
    {
        return StepfunConfig::getBaseUrl();
    }

    /**
     * {@inheritDoc}
     *
     * @param ModelMetadata $modelMetadata The model metadata.
     * @param ProviderMetadata $providerMetadata The provider metadata.
     * @return ModelInterface The model instance.
     * @throws RuntimeException If the model has no supported capability for this provider.
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                $model = new StepfunTextGenerationModel($modelMetadata, $providerMetadata);
            } elseif ($capability->isImageGeneration()) {
                $model = new StepfunImageGenerationModel($modelMetadata, $providerMetadata);
            } else {
                continue;
            }

            /*
             * StepFun requests routinely run for tens of seconds. Without this the request is sent
             * with WordPress' 5 second default and times out.
             */
            $model->setRequestOptions(StepfunConfig::createRequestOptions());

            return $model;
        }

        throw new RuntimeException(
            sprintf(
                /* translators: %s: model ID. */
                esc_html__('The model "%s" has no supported capability for StepFun.', 'ai-provider-for-stepfun'),
                esc_html($modelMetadata->getId())
            )
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return ProviderMetadata The provider metadata.
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            StepfunConfig::PROVIDER_ID,
            'StepFun',
            ProviderTypeEnum::cloud(),
            'https://platform.stepfun.com/interface-key',
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $description = 'Text, vision and image generation with StepFun (阶跃星辰) models.';
            $args[] = function_exists('__')
                ? __('Text, vision and image generation with StepFun (阶跃星辰) models.', 'ai-provider-for-stepfun')
                : $description;
        }

        // Provider logoPath support was added in SDK 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $args[] = dirname(__DIR__, 2) . '/assets/images/stepfun.svg';
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     *
     * @return ProviderAvailabilityInterface The provider availability check.
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Valid credentials are confirmed by listing models, which requires the API key.
        return new ListModelsApiBasedProviderAvailability(static::modelMetadataDirectory());
    }

    /**
     * {@inheritDoc}
     *
     * @return ModelMetadataDirectoryInterface The model metadata directory.
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new StepfunModelMetadataDirectory();
    }
}
