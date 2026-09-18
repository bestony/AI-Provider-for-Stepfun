<?php

/**
 * Shared request creation for StepFun models.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Models;

use StepFun\AiProvider\Provider\StepfunProvider;
use StepFun\AiProvider\Util\StepfunConfig;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Builds requests against the StepFun API.
 *
 * Every StepFun endpoint authenticates with `Authorization: Bearer <key>`, which the SDK's default
 * API key authentication already applies, so no custom authentication class is needed.
 */
trait StepfunRequestTrait
{
    /**
     * Creates a request object for the StepFun API.
     *
     * Satisfies the abstract `createRequest()` of the OpenAI-compatible base class.
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path, relative to the base URL.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        $headers['User-Agent'] = StepfunConfig::getUserAgent();

        return new Request(
            $method,
            StepfunProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}
