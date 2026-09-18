<?php

/**
 * StepFun model metadata directory class file.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Metadata;

use StepFun\AiProvider\Provider\StepfunProvider;
use StepFun\AiProvider\Util\StepfunConfig;
use StepFun\AiProvider\Util\StepfunModelCatalog;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the StepFun model metadata directory.
 *
 * StepFun's `/models` endpoint returns `{id, object, created, owned_by}` and no capability
 * information, so capabilities and options are declared here. That declaration is the single source
 * of truth: the SDK decides which model may serve a request by matching it against these values, so
 * under-declaring makes a model unusable and over-declaring turns into a 400 from the upstream.
 *
 * Only options StepFun documents are declared. `presence_penalty`, `logprobs`, `top_logprobs` and
 * `web_search` are absent from the Chat Completions reference and are therefore not declared, even
 * though the OpenAI-compatible base class would happily send `presence_penalty`.
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string, object?: string, created?: int, owned_by?: string}>
 * }
 */
class StepfunModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path, relative to the base URL.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        /*
         * The base class does not pass request options here, so without this the model list request
         * is sent with WordPress' 5 second HTTP default.
         */
        return new Request(
            $method,
            StepfunProvider::url($path),
            $headers,
            $data,
            StepfunConfig::createRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param Response $response The response from the API endpoint to list models.
     * @return list<ModelMetadata> List of model metadata objects.
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !is_array($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('StepFun', 'data');
        }

        $preferredModelId = StepfunConfig::getDefaultModelId();

        $models = [];
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }

            $modelId = $modelData['id'];

            if (StepfunModelCatalog::isImageModel($modelId)) {
                $capabilities = [CapabilityEnum::imageGeneration()];
                $options = $this->createImageOptions();
            } elseif (StepfunModelCatalog::isTextModel($modelId)) {
                $capabilities = [
                    CapabilityEnum::textGeneration(),
                    CapabilityEnum::chatHistory(),
                ];
                $options = $this->createTextOptions($modelId);
            } else {
                /*
                 * Video, speech and any future family this plugin does not implement. The model stays
                 * in the list — so it is visible and does not look like the API is hiding something —
                 * but with no capability it can never be selected for a request.
                 */
                $capabilities = [];
                $options = [];
            }

            $models[] = new ModelMetadata($modelId, $modelId, $capabilities, $options);
        }

        usort(
            $models,
            static function (ModelMetadata $a, ModelMetadata $b) use ($preferredModelId): int {
                // An explicitly configured model is pinned to the top of every picker.
                if ($preferredModelId !== '') {
                    $aPreferred = $a->getId() === $preferredModelId ? 0 : 1;
                    $bPreferred = $b->getId() === $preferredModelId ? 0 : 1;
                    if ($aPreferred !== $bPreferred) {
                        return $aPreferred <=> $bPreferred;
                    }
                }

                return StepfunModelCatalog::compareModelIds($a->getId(), $b->getId());
            }
        );

        return $models;
    }

    /**
     * Builds the supported options for a text generation model.
     *
     * @param string $modelId The model ID.
     * @return list<SupportedOption> The supported options.
     */
    private function createTextOptions(string $modelId): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        // Either the catalog knows this model takes images, or the deployment asserts it does.
        if (
            StepfunModelCatalog::supportsImageInput($modelId)
            || StepfunConfig::declaresImageInput()
        ) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        if (StepfunModelCatalog::rejectsSamplingParameters($modelId)) {
            return $options;
        }

        return array_merge($options, [
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
        ]);
    }

    /**
     * Builds the supported options for an image generation model.
     *
     * @return list<SupportedOption> The supported options.
     */
    private function createImageOptions(): array
    {
        return [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            /*
             * The API serves one image per request ("当前仅支持每次生成一张图片"), so the only
             * supported count is 1. Declaring it makes the SDK pick another model, rather than the
             * plugin sending an `n` the endpoint rejects.
             */
            new SupportedOption(OptionEnum::candidateCount(), [1]),
            // Both shapes are documented: `b64_json` and, by default, `url`.
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::customOptions()),
        ];
    }
}
