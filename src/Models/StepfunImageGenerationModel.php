<?php

/**
 * StepFun image generation model class file.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Models;

use StepFun\AiProvider\Util\StepfunModelCatalog;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;

/**
 * Image generation model for StepFun text-to-image models.
 *
 * StepFun's image endpoint follows the OpenAI Images spec closely:
 * `POST /images/generations` with `{model, prompt, n, size, response_format}` and a response of
 * `{created, data: [{b64_json|url, seed, finish_reason}]}`. The base class already builds that
 * request and parses both response shapes, so only the two fields StepFun does not accept are
 * overridden here.
 *
 * Image editing (`/images/edits`, `/images/image2image`) is deliberately not implemented.
 */
class StepfunImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    use StepfunRequestTrait;

    /**
     * {@inheritDoc}
     *
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt The prompt to generate an image for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $params = parent::prepareGenerateImageParams($prompt);

        /*
         * `output_format` is an OpenAI gpt-image-* field. StepFun selects the output shape with
         * `response_format` instead, and sending an unknown parameter risks a 400.
         */
        unset($params['output_format']);

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null $aspectRatio The desired media aspect ratio.
     * @return string The prepared size parameter.
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $modelId = $this->metadata()->getId();

        /*
         * Aspect ratios are not declared as a supported option, so the framework never routes a ratio
         * here. Translate one anyway rather than throwing, so a caller that sets a ratio through a
         * custom option still gets a sensible request.
         */
        if ($aspectRatio !== null) {
            $parts = explode(':', $aspectRatio);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $orientation = $parts[0] > $parts[1]
                    ? MediaOrientationEnum::landscape()
                    : ($parts[0] < $parts[1] ? MediaOrientationEnum::portrait() : MediaOrientationEnum::square());
            }
        }

        return StepfunModelCatalog::sizeForOrientation(
            $modelId,
            $orientation === null ? null : $orientation->value
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $responseData The response data from the API.
     * @return string The result ID.
     */
    protected function getResultId(array $responseData): string
    {
        // The Images API returns a `created` timestamp instead of an `id`.
        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'img-' . $responseData['created']
            : '';
    }
}
