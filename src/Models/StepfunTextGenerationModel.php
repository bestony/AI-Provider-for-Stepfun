<?php

/**
 * StepFun text generation model class file.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Models;

use StepFun\AiProvider\Util\StepfunConfig;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Text generation model for StepFun chat models.
 *
 * Everything below the request body — message mapping, vision input, tool calls, response parsing,
 * reasoning content, token usage — is handled by the SDK base class. Only StepFun's request shape
 * quirks need overriding.
 */
class StepfunTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    use StepfunRequestTrait;

    /**
     * {@inheritDoc}
     *
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt The prompt to generate text for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        /*
         * An empty array signals "send no response_format" (see prepareResponseFormatParam()); leaving
         * the key in place would send `"response_format": []`, which is a 400.
         */
        if (isset($params['response_format']) && $params['response_format'] === []) {
            unset($params['response_format']);
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Fixes the request shape for structured output, which the SDK base class gets wrong.
     *
     * The base class sends `{"type":"json_schema","json_schema":<schema>}`, but StepFun documents the
     * OpenAI shape, where the schema is wrapped in a named object:
     * `{"type":"json_schema","json_schema":{"name":...,"schema":{...}}}`. Without the wrapper the
     * request is rejected with `400 invalid_request_error, param: response_format` — which is what the
     * AI plugin's JSON features (Editorial Notes, Slug Generation, …) were hitting.
     *
     * @see https://platform.stepfun.com/docs/zh/api-reference/chat/chat-completion-create
     *
     * @param array<string, mixed>|null $outputSchema The output schema, or null for plain JSON mode.
     * @return array<string, mixed> The response format parameter, or an empty array to send none.
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        $mode = StepfunConfig::getStructuredOutputMode();

        if ($mode === 'none') {
            return [];
        }

        if ($mode === 'json_object' || !is_array($outputSchema)) {
            return ['type' => 'json_object'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'stepfun_response',
                'schema' => $outputSchema,
            ],
        ];
    }
}
