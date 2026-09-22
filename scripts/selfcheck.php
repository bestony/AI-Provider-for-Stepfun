<?php

// phpcs:ignoreFile -- dev-only CLI harness; .gitattributes export-ignores it from releases.

/**
 * Runnable self-check for the StepFun provider plugin.
 *
 * Covers the plugin's own logic — model classification, sort order, capability declarations, and
 * request building — without needing WordPress, composer or a live API key. One file, no test
 * framework, because this is the smallest thing that fails when the logic breaks.
 *
 * Usage:
 *   php scripts/selfcheck.php
 *   php scripts/selfcheck.php --sdk=/path/to/wordpress-php-ai-client/src   # extra live-SDK checks
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/*
 * The plugin's autoloader refuses to run outside WordPress (Plugin Check requires a direct-access
 * guard on it), so this harness defines ABSPATH the way a WordPress test bootstrap does.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

/*
 * WordPress keys `plugin_action_links_*` and resolves `load_plugin_textdomain()`'s path against the
 * plugins directory, so the harness has to know where that is: the plugin root's parent.
 */
$GLOBALS['stepfun_plugins_dir'] = dirname($root);
$GLOBALS['stepfun_plugin_dir'] = $root;

require $root . '/src/autoload.php';

use StepFun\AiProvider\Admin\StepfunSettings;
use StepFun\AiProvider\Util\StepfunConfig;
use StepFun\AiProvider\Util\StepfunModelCatalog;

$failures = 0;
$checks = 0;

/**
 * Asserts a condition and records the outcome.
 *
 * @param bool $condition The condition to check.
 * @param string $description What is being checked.
 * @return void
 */
function check(bool $condition, string $description): void
{
    global $failures, $checks;
    $checks++;

    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL  {$description}\n");
        return;
    }

    fwrite(STDOUT, "ok    {$description}\n");
}

// --- Vision classification. -------------------------------------------------------------------
foreach (['step-3.7-flash', 'step-3.7-flash-2603', 'step-1v-32k', 'step-1o-turbo-vision'] as $modelId) {
    check(StepfunModelCatalog::supportsImageInput($modelId), "{$modelId} accepts image input");
}
foreach (['step-3.5-flash', 'step-2-mini', 'step-1-32k', 'step-image-edit-2'] as $modelId) {
    check(!StepfunModelCatalog::supportsImageInput($modelId), "{$modelId} is text-only");
}

// --- Image classification. --------------------------------------------------------------------
foreach (['step-1x-medium', 'step-2x-large', 'step-image-edit-2'] as $modelId) {
    check(StepfunModelCatalog::isImageModel($modelId), "{$modelId} generates images");
}
foreach (['step-3.7-flash', 'step-1v-32k', 'step-video-t2v'] as $modelId) {
    check(!StepfunModelCatalog::isImageModel($modelId), "{$modelId} is not an image model");
}

// --- Text classification: video, speech and the router must never look like chat models. -------
foreach (['step-3.7-flash', 'step-3.5-flash', 'step-1v-32k', 'step-1-32k'] as $modelId) {
    check(StepfunModelCatalog::isTextModel($modelId), "{$modelId} generates text");
}
foreach (
    [
    'step-video-t2v',
    'step-tts-mini',
    'step-asr',
    'step-audio-2',
    'step-1o-audio',
    'step-router-v1',
    'step-image-edit-2',
    'step-2x-large',
    'gpt-4o',
    ] as $modelId
) {
    check(!StepfunModelCatalog::isTextModel($modelId), "{$modelId} is not a chat model");
}
check(
    StepfunModelCatalog::isUnsupported('step-video-t2v'),
    'video models are declared with no capability at all'
);
check(
    StepfunModelCatalog::isUnsupported('step-tts-mini'),
    'TTS models are declared with no capability at all'
);

// --- Sampling parameters: documented as supported, so never stripped. -------------------------
check(
    !StepfunModelCatalog::rejectsSamplingParameters('step-3.7-flash'),
    'step-3.7-flash accepts temperature, so nothing is stripped'
);

// --- Sort order: usable before unusable, chat before image, preview last. ---------------------
check(
    StepfunModelCatalog::compareModelIds('step-3.7-flash', 'step-video-t2v') < 0,
    'a usable model sorts before a capability-less one'
);
check(
    StepfunModelCatalog::compareModelIds('step-3.7-flash', 'step-image-edit-2') < 0,
    'text models sort before image models'
);
check(
    StepfunModelCatalog::compareModelIds('step-3.7-flash', 'step-3.7-flash-preview') < 0,
    'a stable model sorts before its preview variant'
);
check(
    StepfunModelCatalog::compareModelIds('step-3.10-flash', 'step-3.7-flash') < 0,
    'versions compare numerically, not lexically'
);
check(
    StepfunModelCatalog::compareModelIds('step-3.7-flash', 'step-new-flash') < 0,
    'a numbered generation sorts before an unnumbered one'
);
check(
    StepfunModelCatalog::compareModelIds('step-3.7-flash', 'step-3.7-flash') === 0,
    'comparing a model with itself is neutral'
);

// --- Size mapping. ----------------------------------------------------------------------------
check(
    StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'square') === '1024x1024',
    'square maps to 1024x1024'
);
check(
    StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'landscape') === '1360x768',
    'landscape maps to a documented step-image-edit-2 size'
);
check(
    StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'portrait') === '768x1360',
    'portrait maps to a documented step-image-edit-2 size'
);
check(
    StepfunModelCatalog::sizeForOrientation('step-image-edit-2', null) === '1024x1024',
    'no orientation falls back to square'
);
check(
    StepfunModelCatalog::sizeForOrientation('step-2x-large', 'landscape') === '1360x768',
    'step-2x family shares the documented size table'
);
check(
    StepfunModelCatalog::sizeForOrientation('step-1x-medium', 'landscape') === '1024x1024',
    'step-1x only documents square text-to-image sizes'
);
check(
    in_array(
        StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'landscape'),
        ['1024x1024', '768x1360', '896x1184', '1360x768', '1184x896'],
        true
    ),
    'every mapped size is one the API documents'
);
check(
    in_array(
        StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'portrait'),
        ['1024x1024', '768x1360', '896x1184', '1360x768', '1184x896'],
        true
    ),
    'the portrait size is documented too'
);

// --- Configuration defaults. ------------------------------------------------------------------
check(StepfunConfig::getBaseUrl() === 'https://api.stepfun.com/v1', 'default base URL');
check(StepfunConfig::getRequestTimeout() >= 60.0, 'request timeout is long enough for an LLM call');
check(
    StepfunConfig::getUserAgent() === 'ai-provider-for-stepfun/' . StepfunConfig::VERSION,
    'user agent identifies the plugin and its version'
);
check(StepfunConfig::getStructuredOutputMode() === 'json_schema', 'structured output defaults to json_schema');
check(StepfunConfig::getDefaultModelId() === 'step-3.7-flash', 'default chat model');
check(StepfunConfig::getImageModelId() === 'step-image-edit-2', 'default image model');
check(!StepfunConfig::declaresImageInput(), 'image input is not force-declared by default');
check(!StepfunConfig::hasCredentials(), 'no credentials unless one is configured');

// The base URL and the size table are the two values a deployment is most likely to override.
putenv('STEPFUN_BASE_URL=https://api.stepfun.ai/v1/');
check(StepfunConfig::getBaseUrl() === 'https://api.stepfun.ai/v1', 'STEPFUN_BASE_URL overrides the base URL');
putenv('STEPFUN_SIZE_LANDSCAPE=1184x896');
check(
    StepfunModelCatalog::sizeForOrientation('step-image-edit-2', 'landscape') === '1184x896',
    'STEPFUN_SIZE_LANDSCAPE overrides the landscape size'
);
putenv('STEPFUN_BASE_URL');
putenv('STEPFUN_SIZE_LANDSCAPE');

// --- Request building, against the real SDK when one is available. ----------------------------
$sdkPath = null;
foreach ($argv as $index => $argument) {
    if (strpos($argument, '--sdk=') === 0) {
        $sdkPath = substr($argument, 6);
    }
}

if ($sdkPath !== null && is_file($sdkPath . '/polyfills.php')) {
    require $sdkPath . '/polyfills.php';
    spl_autoload_register(static function (string $class) use ($sdkPath): void {
        $prefix = 'WordPress\\AiClient\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = $sdkPath . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    use_stepfun_sdk_checks();
} else {
    check(!StepfunConfig::hasCredentials(), 'without the AI Client there are no credentials to report');
    fwrite(STDOUT, "skip  SDK-dependent checks (pass --sdk=<path to php-ai-client/src> to run them)\n");
}

/**
 * Checks that need the real SDK classes: declared options and the built request.
 *
 * @return void
 */
function use_stepfun_sdk_checks(): void
{
    $response = new \WordPress\AiClient\Providers\Http\DTO\Response(
        200,
        [],
        json_encode([
            'object' => 'list',
            'data' => [
                ['id' => 'step-3.7-flash', 'object' => 'model', 'created' => 1713196800, 'owned_by' => 'stepai'],
                ['id' => 'step-3.5-flash', 'object' => 'model', 'created' => 1713974400, 'owned_by' => 'stepai'],
                ['id' => 'step-1o-turbo-vision', 'object' => 'model', 'created' => 1711015200, 'owned_by' => 'stepai'],
                ['id' => 'step-1v-32k', 'object' => 'model', 'created' => 1711015200, 'owned_by' => 'stepai'],
                ['id' => 'step-image-edit-2', 'object' => 'model', 'created' => 1711015200, 'owned_by' => 'stepai'],
                ['id' => 'step-video-t2v', 'object' => 'model', 'created' => 1711015200, 'owned_by' => 'stepai'],
            ],
        ])
    );

    // parseResponseToModelMetadataList() is protected: reach it through a subclass.
    $parser = new class extends \StepFun\AiProvider\Metadata\StepfunModelMetadataDirectory {
        /**
         * Exposes the protected parser.
         *
         * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The model list response.
         * @return list<\WordPress\AiClient\Providers\Models\DTO\ModelMetadata> The parsed models.
         */
        public function parse(\WordPress\AiClient\Providers\Http\DTO\Response $response): array
        {
            return $this->parseResponseToModelMetadataList($response);
        }
    };

    $models = $parser->parse($response);
    check(count($models) === 6, 'every model in the list response is parsed');

    $byId = [];
    $orderedIds = [];
    foreach ($models as $model) {
        $byId[$model->getId()] = $model;
        $orderedIds[] = $model->getId();
    }

    check($orderedIds[0] === 'step-3.7-flash', 'the configured model is sorted first');
    check(
        array_search('step-image-edit-2', $orderedIds, true) > array_search('step-3.5-flash', $orderedIds, true),
        'image models sort after chat models'
    );
    check(
        array_search('step-video-t2v', $orderedIds, true) === count($orderedIds) - 1,
        'the capability-less video model sorts last'
    );

    // Option names come back as camelCase for ModelConfig-derived options (e.g. `inputModalities`).
    $optionNames = static function (\WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model): array {
        return array_map(
            static fn($option): string => $option->getName()->value,
            $model->getSupportedOptions()
        );
    };
    $optionValues = static function (
        \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model,
        string $optionName
    ): ?array {
        foreach ($model->getSupportedOptions() as $option) {
            if ($option->getName()->value === $optionName) {
                return $option->getSupportedValues();
            }
        }
        return null;
    };

    // Capabilities.
    $capabilityValues = static function (
        \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model
    ): array {
        return array_map(static fn($capability): string => $capability->value, $model->getSupportedCapabilities());
    };
    check($capabilityValues($byId['step-video-t2v']) === [], 'the video model declares no capability');

    // Input modalities: vision models offer two combinations, text-only models one.
    check(
        count((array) $optionValues($byId['step-3.7-flash'], 'inputModalities')) === 2,
        'a vision model declares two input modality combinations'
    );
    check(
        count((array) $optionValues($byId['step-3.5-flash'], 'inputModalities')) === 1,
        'a text-only model declares one input modality combination'
    );
    check(
        count((array) $optionValues($byId['step-1v-32k'], 'inputModalities')) === 2,
        'step-1v-32k is recognised as a vision model'
    );

    // Image model: image output, and only a candidate count of one.
    $imageOutputModalities = $optionValues($byId['step-image-edit-2'], 'outputModalities');
    check(
        is_array($imageOutputModalities)
            && count($imageOutputModalities) === 1
            && count($imageOutputModalities[0]) === 1
            && $imageOutputModalities[0][0]->isImage(),
        'the image model declares image output'
    );
    check(
        $optionValues($byId['step-image-edit-2'], 'candidateCount') === [1],
        'the image model only supports one image per request'
    );
    check(
        !in_array('temperature', $optionNames($byId['step-image-edit-2']), true),
        'the image model declares no sampling options'
    );

    // Undocumented chat parameters must not be declared.
    $chatOptions = $optionNames($byId['step-3.7-flash']);
    check(in_array('temperature', $chatOptions, true), 'chat models declare temperature');
    check(in_array('frequencyPenalty', $chatOptions, true), 'chat models declare frequency penalty');
    check(!in_array('presencePenalty', $chatOptions, true), 'undocumented presence penalty is not declared');
    check(!in_array('logprobs', $chatOptions, true), 'undocumented logprobs is not declared');
    check(!in_array('webSearch', $chatOptions, true), 'undeclared web search is not offered');
    check(!in_array('outputMediaOrientation', $chatOptions, true), 'chat models declare no media options');

    // Aspect ratio stays undeclared: the size table is not verified against a live endpoint.
    check(
        !in_array('outputMediaAspectRatio', $optionNames($byId['step-image-edit-2']), true),
        'aspect ratio is not declared while the upstream size semantics are unverified'
    );

    // --- Requests, end to end through a fake HTTP transporter. ---------------------------------
    $providerMetadata = \StepFun\AiProvider\Provider\StepfunProvider::metadata();

    /**
     * Records the request it is handed and replays a canned response.
     */
    $transporter = new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
        /** @var \WordPress\AiClient\Providers\Http\DTO\Request|null */
        public $request = null;

        /** @var array<string, mixed> */
        public $body = [];

        /** @var array<string, mixed> */
        public $queue = [];

        /**
         * Sends a request and records it.
         *
         * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
         * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Transport options.
         * @return \WordPress\AiClient\Providers\Http\DTO\Response The canned response.
         */
        public function send(
            \WordPress\AiClient\Providers\Http\DTO\Request $request,
            ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null
        ): \WordPress\AiClient\Providers\Http\DTO\Response {
            $this->request = $request;
            $this->body = (array) $request->getData();

            return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], json_encode($this->queue));
        }
    };

    /**
     * Wires a model to the fake transporter with a test credential.
     *
     * @param object $model The model instance.
     * @param object $transporter The fake transporter.
     * @return void
     */
    $bind = static function ($model, $transporter): void {
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(
            new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('test-key')
        );
        $model->setRequestOptions(StepfunConfig::createRequestOptions());
    };

    // Chat: request shape, structured output wrapper, vision input.
    $chatModel = new \StepFun\AiProvider\Models\StepfunTextGenerationModel(
        $byId['step-3.7-flash'],
        $providerMetadata
    );
    $bind($chatModel, $transporter);
    $chatModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'systemInstruction' => 'Be terse.',
        'maxTokens' => 256,
        'temperature' => 0.2,
        // What the AI plugin's Editorial Notes feature sends via as_json_response().
        'outputMimeType' => 'application/json',
        'outputSchema' => [
            'type' => 'object',
            'properties' => ['suggestions' => ['type' => 'array']],
        ],
    ]));

    $transporter->queue = [
        'id' => 'chatcmpl-1',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'Hi there'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
    ];
    $chatResult = $chatModel->generateTextResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('hi')]
    )]);

    check(
        $transporter->request->getUri() === 'https://api.stepfun.com/v1/chat/completions',
        'the chat request targets /v1/chat/completions'
    );
    check($transporter->body['model'] === 'step-3.7-flash', 'the chat request carries the model ID');
    check($transporter->body['max_tokens'] === 256, 'max_tokens is forwarded');
    check($transporter->body['temperature'] === 0.2, 'temperature is forwarded');
    check($transporter->body['messages'][0]['role'] === 'system', 'the system instruction is prepended');
    check(
        $transporter->request->getHeaders()['User-Agent'][0] === StepfunConfig::getUserAgent(),
        'the chat request identifies the plugin'
    );
    check(
        $transporter->request->getHeaders()['Authorization'][0] === 'Bearer test-key',
        'the chat request carries the Bearer token'
    );

    /*
     * Regression: the SDK base class emitted {"type":"json_schema","json_schema":<schema>}, which
     * StepFun rejects with 400 invalid_request_error, param: response_format.
     */
    $responseFormat = $transporter->body['response_format'] ?? null;
    check(
        is_array($responseFormat) && ($responseFormat['type'] ?? null) === 'json_schema',
        'structured output requests use the json_schema response format'
    );
    check(
        isset($responseFormat['json_schema']['name'], $responseFormat['json_schema']['schema']),
        'the JSON schema is wrapped in a named json_schema object'
    );
    check(
        ($responseFormat['json_schema']['schema']['type'] ?? null) === 'object',
        'the schema wrapper carries the caller schema unchanged'
    );
    check(
        ($responseFormat['json_schema']['schema']['properties']['suggestions']['type'] ?? null) === 'array',
        'nested schema properties survive the wrapping'
    );
    check(
        $chatResult->getCandidates()[0]->getMessage()->getParts()[0]->getText() === 'Hi there',
        'the chat response text is parsed'
    );
    check($chatResult->getTokenUsage()->getPromptTokens() === 7, 'prompt tokens are counted');

    // Vision input: an inline image must become an image_url content part.
    $transporter->queue = [
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'A cat'],
            'finish_reason' => 'stop',
        ]],
    ];
    $visionModel = new \StepFun\AiProvider\Models\StepfunTextGenerationModel(
        $byId['step-3.7-flash'],
        $providerMetadata
    );
    $bind($visionModel, $transporter);
    $visionModel->generateTextResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [
            new \WordPress\AiClient\Messages\DTO\MessagePart('what is this'),
            new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Files\DTO\File(
                    'data:image/png;base64,iVBORw0KGgo=',
                    'image/png'
                )
            ),
        ]
    )]);
    $contentTypes = array_column($transporter->body['messages'][0]['content'], 'type');
    check(
        in_array('image_url', $contentTypes, true),
        'an inline image becomes an image_url content part'
    );

    // Reasoning content is parsed into a thought part by the base class.
    $transporter->queue = [
        'choices' => [[
            'message' => ['role' => 'assistant', 'reasoning_content' => 'pondering', 'content' => 'Answer'],
            'finish_reason' => 'stop',
        ]],
    ];
    $reasoned = $chatModel->generateTextResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('hi')]
    )]);
    $channels = [];
    foreach ($reasoned->getCandidates()[0]->getMessage()->getParts() as $part) {
        if ($part->getType()->isText()) {
            $channels[$part->getChannel()->value] = $part->getText();
        }
    }
    check(($channels['thought'] ?? null) === 'pondering', 'reasoning_content becomes a thought part');
    check(($channels['content'] ?? null) === 'Answer', 'the visible answer is a separate content part');

    // Image generation: request shape and b64_json parsing.
    $imageModel = new \StepFun\AiProvider\Models\StepfunImageGenerationModel(
        $byId['step-image-edit-2'],
        $providerMetadata
    );
    $bind($imageModel, $transporter);
    $imageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'candidateCount' => 1,
        'outputFileType' => 'inline',
        'outputMediaOrientation' => 'landscape',
    ]));

    // 1x1 transparent PNG.
    $pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/AF/'
        . 'yRXuAAAAAElFTkSuQmCC';
    $transporter->queue = [
        'created' => 1710146871,
        'data' => [['b64_json' => $pixel, 'seed' => 42, 'finish_reason' => 'success']],
    ];
    $imageResult = $imageModel->generateImageResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('a cat')]
    )]);

    check(
        $transporter->request->getUri() === 'https://api.stepfun.com/v1/images/generations',
        'the image request targets /v1/images/generations'
    );
    check(!isset($transporter->body['output_format']), 'output_format is stripped from the image request');
    check(
        ($transporter->body['response_format'] ?? null) === 'b64_json',
        'an inline request asks for b64_json'
    );
    check(
        ($transporter->body['size'] ?? null) === '1360x768',
        'the landscape size comes from the catalog'
    );
    check($transporter->body['model'] === 'step-image-edit-2', 'the image request carries the model ID');
    check($transporter->body['n'] === 1, 'the image request asks for one image');

    $imageFile = $imageResult->toImageFile();
    check($imageFile->getBase64Data() === $pixel, 'the b64_json response is parsed into an inline image');
    check($imageResult->getId() === 'img-1710146871', 'the created timestamp becomes the result ID');

    // A URL response must parse too, for a remote output file type.
    $imageModel->setConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputFileType' => 'remote',
    ]));
    $transporter->queue = [
        'created' => 1710146871,
        'data' => [['url' => 'https://res.stepfun.com/image_gen/example.png', 'seed' => 7]],
    ];
    $remoteResult = $imageModel->generateImageResult([new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart('a cat')]
    )]);
    check(
        ($transporter->body['response_format'] ?? null) === 'url',
        'a remote request asks for a url'
    );
    check(
        $remoteResult->toImageFile()->isRemote(),
        'the url response is parsed into a remote image'
    );

    use_stepfun_requirements_checks($byId);
    use_stepfun_plugin_checks();
}

/**
 * Checks the plugin's entry file: registration guards and the model preference filters.
 *
 * The plugin file is not part of the autoloader, so it is loaded here with the few WordPress
 * functions it touches stubbed out. The preference filters are the reason this exists: they rewrite a
 * list owned by other plugins, and an off-by-one there silently drops another provider's model.
 *
 * @return void
 */
function use_stepfun_plugin_checks(): void
{
    // WordPress stubs, defined only when WordPress is not actually present.
    if (!function_exists('add_action')) {
        /**
         * Records an action registration.
         *
         * @param string $hook The hook name.
         * @param callable $callback The callback.
         * @param int $priority The priority.
         * @return void
         */
        function add_action(string $hook, $callback, int $priority = 10): void
        {
            $GLOBALS['stepfun_actions'][$hook][$priority][] = $callback;
        }

        /**
         * Records a filter registration.
         *
         * @param string $hook The hook name.
         * @param callable $callback The callback.
         * @param int $priority The priority.
         * @return void
         */
        function add_filter(string $hook, $callback, int $priority = 10): void
        {
            $GLOBALS['stepfun_filters'][$hook][$priority][] = $callback;
        }

        /**
         * Returns the string unchanged; translation is a WordPress concern.
         *
         * @param string $text The text.
         * @param string|null $domain The text domain.
         * @return string The text.
         */
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }

        /**
         * Returns the escaped string unchanged.
         *
         * @param string $text The text.
         * @return string The text.
         */
        function esc_html(string $text): string
        {
            return $text;
        }

        /**
         * Returns the escaped string unchanged.
         *
         * @param string $text The text.
         * @param string|null $domain The text domain.
         * @return string The text.
         */
        function esc_html__(string $text, ?string $domain = null): string
        {
            return $text;
        }

        /**
         * Returns the URL-escaped string unchanged.
         *
         * @param string $url The URL.
         * @return string The URL.
         */
        function esc_url(string $url): string
        {
            return $url;
        }

        /**
         * Returns the attribute-escaped string unchanged.
         *
         * @param string $text The text.
         * @return string The text.
         */
        function esc_attr(string $text): string
        {
            return $text;
        }

        /**
         * Builds an admin URL the way WordPress does, without needing WordPress.
         *
         * @param string $path The path, relative to wp-admin.
         * @return string The absolute admin URL.
         */
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }

        /**
         * Returns the plugin file's path relative to the plugins directory, as WordPress does.
         *
         * @param string $file The plugin file path.
         * @return string The path relative to the plugins directory.
         */
        function plugin_basename(string $file): string
        {
            $dir = $GLOBALS['stepfun_plugins_dir'] ?? null;
            if (is_string($dir) && strpos($file, $dir) === 0) {
                return ltrim(substr($file, strlen($dir)), '/');
            }

            return basename($file);
        }

        /**
         * Reads from an in-memory option table, so option resolution can be exercised.
         *
         * @param string $name The option name.
         * @param mixed $default The default when the option is not set.
         * @return mixed The option value.
         */
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['stepfun_options'][$name] ?? $default;
        }

        /**
         * Records that translations were loaded; the harness has no .mo files to load.
         *
         * @param string $domain The text domain.
         * @param bool $deprecated Unused.
         * @param string|null $path The languages directory.
         * @return bool Always true.
         */
        function load_plugin_textdomain(string $domain, bool $deprecated = false, ?string $path = null): bool
        {
            $GLOBALS['stepfun_textdomain'] = [$domain, $path];

            return true;
        }
    }

    require dirname(__DIR__) . '/ai-provider-for-stepfun.php';

    /*
     * The SDK resolves a PSR-18 client through HTTPlug discovery when a provider is registered. That
     * works in WordPress (the AI plugin ships the discovery package) but not against a bare SDK
     * checkout, so inject a no-op transporter first and the registration path never reaches discovery.
     */
    \WordPress\AiClient\AiClient::defaultRegistry()->setHttpTransporter(
        new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
            /**
             * Never called: this harness only exercises registration and filters.
             *
             * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
             * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Transport options.
             * @return \WordPress\AiClient\Providers\Http\DTO\Response The response.
             */
            public function send(
                \WordPress\AiClient\Providers\Http\DTO\Request $request,
                ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null
            ): \WordPress\AiClient\Providers\Http\DTO\Response {
                return new \WordPress\AiClient\Providers\Http\DTO\Response(200, [], '{}');
            }
        }
    );

    // Credential detection: asks the AI Client, never reads the Connectors option.
    check(!StepfunConfig::hasCredentials(), 'no credentials before the AI Client is given one');

    // Registration must be on init priority 5, or the Connectors card never appears.
    $callbacks = $GLOBALS['stepfun_actions']['init'][5] ?? [];
    check($callbacks !== [], 'the provider registers on init priority 5');
    foreach ($callbacks as $callback) {
        $callback();
    }
    check(
        \WordPress\AiClient\AiClient::defaultRegistry()->hasProvider(StepfunConfig::PROVIDER_ID),
        'the registered provider is present in the SDK registry'
    );
    // Registering again must be a no-op rather than an error.
    foreach ($callbacks as $callback) {
        $callback();
    }
    check(true, 'registering the provider twice does not throw');
    check(!StepfunConfig::hasCredentials(), 'a registered provider is not yet a credentialed one');

    // The filters go through WordPress' filter machinery in production; apply them by hand here.
    $apply = static function (string $hook, array $value): array {
        $priorities = $GLOBALS['stepfun_filters'][$hook] ?? [];
        ksort($priorities);
        foreach ($priorities as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value);
            }
        }
        return $value;
    };

    // Without a credential the provider yields no candidates, so the lists must be untouched.
    $untouched = [['anthropic', 'claude-sonnet-5']];
    check(
        $apply('wpai_preferred_text_models', $untouched) === $untouched,
        'the text preference filter changes nothing without credentials'
    );
    check(
        $apply('wpai_preferred_image_models', $untouched) === $untouched,
        'the image preference filter changes nothing without credentials'
    );

    \WordPress\AiClient\AiClient::defaultRegistry()->setProviderRequestAuthentication(
        StepfunConfig::PROVIDER_ID,
        new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('test-key')
    );
    check(StepfunConfig::hasCredentials(), 'a key handed to the AI Client counts as credentials');

    // Preferred first, other providers preserved, and a different StepFun model kept.
    check(
        $apply('wpai_preferred_text_models', [
            ['anthropic', 'claude-sonnet-5'],
            ['stepfun', 'step-3.5-flash'],
            ['stepfun', 'step-3.7-flash'],
        ]) === [
            ['stepfun', 'step-3.7-flash'],
            ['anthropic', 'claude-sonnet-5'],
            ['stepfun', 'step-3.5-flash'],
        ],
        'the text filter prefers the default model, keeps other providers and other stepfun models'
    );

    // The image feature needs an image model, not the chat flagship.
    check(
        $apply('wpai_preferred_image_models', [['google', 'gemini-3-pro-image-preview']]) === [
            ['stepfun', 'step-image-edit-2'],
            ['google', 'gemini-3-pro-image-preview'],
        ],
        'the image filter prefers the image model and keeps other providers'
    );

    // Vision features need a chat model that accepts images.
    check(
        $apply('wpai_preferred_vision_models', []) === [['stepfun', 'step-3.7-flash']],
        'the vision filter prefers the vision-capable chat model'
    );

    // Entries from other plugins can be any shape; they must be skipped, not propagated.
    $malformed = $apply('wpai_preferred_text_models', ['nonsense', ['only-one'], ['openai', 'gpt-5.6-luna']]);
    check(
        $malformed === [['stepfun', 'step-3.7-flash'], ['openai', 'gpt-5.6-luna']],
        'malformed preference entries are skipped rather than propagated'
    );

    // The filter must reflect the configured model, not a hardcoded one.
    putenv('STEPFUN_DEFAULT_MODEL=step-3.5-flash');
    check(
        $apply('wpai_preferred_text_models', []) === [['stepfun', 'step-3.5-flash']],
        'STEPFUN_DEFAULT_MODEL is honoured by the preference filter'
    );
    putenv('STEPFUN_DEFAULT_MODEL');

    use_stepfun_settings_checks($apply);
}

/**
 * Checks the settings page wiring: the base URL option, its validation, and the Plugins shortcut.
 *
 * @param callable $apply Applies a recorded filter by hook name.
 * @return void
 */
function use_stepfun_settings_checks(callable $apply): void
{
    // --- The offered base URLs. -----------------------------------------------------------------
    $choices = StepfunConfig::getBaseUrlChoices();
    check(count($choices) === 4, 'exactly four StepFun base URLs are offered');
    check($choices[0] === StepfunConfig::DEFAULT_BASE_URL, 'the default base URL is offered first');
    foreach (
        [
        'https://api.stepfun.com/v1',
        'https://api.stepfun.com/step_plan/v1',
        'https://api.stepfun.ai/v1',
        'https://api.stepfun.ai/step_plan/v1',
        ] as $url
    ) {
        check(StepfunConfig::isAllowedBaseUrl($url), "{$url} is an allowed base URL");
    }
    check(
        !StepfunConfig::isAllowedBaseUrl('https://evil.example.com/v1'),
        'an unlisted host is not an allowed base URL'
    );

    // --- Resolution: env > option > default. ----------------------------------------------------
    $GLOBALS['stepfun_options'] = [];
    check(
        StepfunConfig::getBaseUrl() === StepfunConfig::DEFAULT_BASE_URL,
        'no option and no constant yields the default base URL'
    );

    $GLOBALS['stepfun_options'][StepfunConfig::OPTION_NAME] = 'https://api.stepfun.ai/v1';
    check(
        StepfunConfig::getBaseUrl() === 'https://api.stepfun.ai/v1',
        'the stored option becomes the base URL'
    );

    // A value written straight to the database must not reach the network.
    $GLOBALS['stepfun_options'][StepfunConfig::OPTION_NAME] = 'https://evil.example.com/v1';
    check(
        StepfunConfig::getBaseUrl() === StepfunConfig::DEFAULT_BASE_URL,
        'an unlisted stored option falls back to the default base URL'
    );

    $GLOBALS['stepfun_options'][StepfunConfig::OPTION_NAME] = 'https://api.stepfun.ai/v1/';
    check(
        StepfunConfig::getBaseUrl() === 'https://api.stepfun.ai/v1',
        'a stored trailing slash is trimmed before use'
    );

    // The constant still wins, so an existing deployment is not silently repointed by the UI.
    putenv('STEPFUN_BASE_URL=https://api.stepfun.com/step_plan/v1');
    check(
        StepfunConfig::getBaseUrl() === 'https://api.stepfun.com/step_plan/v1',
        'STEPFUN_BASE_URL overrides the stored option'
    );
    putenv('STEPFUN_BASE_URL');
    $GLOBALS['stepfun_options'] = [];

    // --- Every choice must actually drive request URL building. ---------------------------------
    $provider = \StepFun\AiProvider\Provider\StepfunProvider::class;
    foreach (StepfunConfig::getBaseUrlChoices() as $choice) {
        $GLOBALS['stepfun_options'][StepfunConfig::OPTION_NAME] = $choice;
        check(
            $provider::url('chat/completions') === $choice . '/chat/completions',
            "a request for {$choice} joins to {$choice}/chat/completions"
        );
    }

    // The SDK joins with `baseUrl() . '/' . ltrim($path, '/')`, so a leading slash must not double up.
    $GLOBALS['stepfun_options'][StepfunConfig::OPTION_NAME] = 'https://api.stepfun.ai/step_plan/v1';
    check(
        $provider::url('/models') === 'https://api.stepfun.ai/step_plan/v1/models',
        'a leading slash on the path does not produce a double slash'
    );
    check(
        $provider::url() === 'https://api.stepfun.ai/step_plan/v1',
        'an empty path yields the base URL itself'
    );
    $GLOBALS['stepfun_options'] = [];

    // --- Sanitisation of a submitted value. -----------------------------------------------------
    check(
        StepfunSettings::sanitize('https://api.stepfun.ai/step_plan/v1')
            === 'https://api.stepfun.ai/step_plan/v1',
        'an allowed base URL survives sanitisation'
    );
    check(
        StepfunSettings::sanitize('https://api.stepfun.ai/step_plan/v1/')
            === 'https://api.stepfun.ai/step_plan/v1',
        'a submitted trailing slash is trimmed'
    );
    check(
        StepfunSettings::sanitize('https://evil.example.com/v1') === StepfunConfig::DEFAULT_BASE_URL,
        'an unlisted submitted URL is replaced by the default'
    );
    check(
        StepfunSettings::sanitize(['https://api.stepfun.ai/v1']) === StepfunConfig::DEFAULT_BASE_URL,
        'an array submission is replaced by the default'
    );
    check(
        StepfunSettings::sanitize(null) === StepfunConfig::DEFAULT_BASE_URL,
        'a null submission is replaced by the default'
    );

    // --- The Plugins-screen shortcut. -----------------------------------------------------------
    $hook = 'plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/ai-provider-for-stepfun.php');
    $links = $apply($hook, ['deactivate' => '<a href="#">Deactivate</a>']);
    check(count($links) === 2, 'the shortcut is appended to the existing plugin action links');
    $shortcut = (string) end($links);
    check(
        strpos($shortcut, 'Setup Step Plan') !== false,
        'the plugin row offers a Setup Step Plan link'
    );
    check(
        strpos($shortcut, 'options-general.php?page=' . StepfunSettings::PAGE_SLUG) !== false,
        'the Setup Step Plan link points at the settings page'
    );
    check(
        ($links['deactivate'] ?? null) === '<a href="#">Deactivate</a>',
        'existing plugin action links are preserved untouched'
    );

    // --- Translations. --------------------------------------------------------------------------
    $callbacks = $GLOBALS['stepfun_actions']['init'][10] ?? [];
    check($callbacks !== [], 'the text domain is loaded on init');
    foreach ($callbacks as $callback) {
        $callback();
    }
    check(
        ($GLOBALS['stepfun_textdomain'][0] ?? null) === 'ai-provider-for-stepfun',
        'the text domain matches the plugin header'
    );
    check(
        ($GLOBALS['stepfun_textdomain'][1] ?? null) === basename($GLOBALS['stepfun_plugin_dir']) . '/languages',
        'translations are loaded from the plugin languages directory'
    );
}

/**
 * Checks that the declared metadata satisfies the requirements the AI plugin actually sends.
 *
 * This is the failure mode that matters most: metadata is the single source of truth, so a missing
 * capability or option makes a feature report "no available model" with no obvious cause. Each case
 * below mirrors a real call site in the AI plugin.
 *
 * @param array<string, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata> $byId Models by ID.
 * @return void
 */
function use_stepfun_requirements_checks(array $byId): void
{
    $message = static fn(string $text) => new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart($text)]
    );

    // Plain text generation (Title Generation, Summarization, Excerpt Generation, …).
    $plainConfig = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'systemInstruction' => 'Be terse.',
    ]);
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$message('generate a title')],
            $plainConfig
        )->areMetBy($byId['step-3.7-flash']),
        'plain text generation is supported by the configured chat model'
    );

    // Chat history (two or more messages) must map onto the chatHistory capability.
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$message('hi'), $message('and again')],
            $plainConfig
        )->areMetBy($byId['step-3.7-flash']),
        'a multi-message prompt is supported (chat history is declared)'
    );

    // Structured output, exactly as the AI plugin's as_json_response() call sites build it.
    $jsonConfig = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputMimeType' => 'application/json',
        'outputSchema' => [
            'type' => 'object',
            'properties' => ['suggestions' => ['type' => 'array']],
        ],
    ]);
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$message('suggest notes')],
            $jsonConfig
        )->areMetBy($byId['step-3.7-flash']),
        'a JSON schema request is supported (Editorial Notes and friends)'
    );

    // Vision: Alt Text Generation sends text plus an image file.
    $visionMessage = new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [
            new \WordPress\AiClient\Messages\DTO\MessagePart('describe this'),
            new \WordPress\AiClient\Messages\DTO\MessagePart(
                new \WordPress\AiClient\Files\DTO\File('data:image/png;base64,iVBORw0KGgo=', 'image/png')
            ),
        ]
    );
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$visionMessage],
            $plainConfig
        )->areMetBy($byId['step-3.7-flash']),
        'alt text generation is supported by the vision model'
    );
    check(
        !\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$visionMessage],
            $plainConfig
        )->areMetBy($byId['step-3.5-flash']),
        'the text-only model is correctly rejected for an image prompt'
    );

    // Tool calling: the requirement is inferred from the prompt, not configured by the caller.
    // A function *response* from the user side is what a caller sends back after a tool round trip.
    $toolMessage = new \WordPress\AiClient\Messages\DTO\Message(
        \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
        [new \WordPress\AiClient\Messages\DTO\MessagePart(new \WordPress\AiClient\Tools\DTO\FunctionResponse(
            'call_1',
            'get_weather',
            ['temperature' => 21]
        ))]
    );
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$toolMessage],
            $plainConfig
        )->areMetBy($byId['step-3.7-flash']),
        'tool calling is supported (function declarations are declared)'
    );

    // Image generation, exactly as the AI plugin's Generate Image ability builds it.
    $imageConfig = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'outputFileType' => 'inline',
    ]);
    check(
        \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::imageGeneration(),
            [$message('a cat')],
            $imageConfig
        )->areMetBy($byId['step-image-edit-2']),
        'the Generate Image ability is supported (inline output)'
    );
    check(
        !\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::imageGeneration(),
            [$message('a cat')],
            $imageConfig
        )->areMetBy($byId['step-3.7-flash']),
        'the chat model is correctly rejected for image generation'
    );

    // A candidate count of 2 must not match: the API only serves one image per request.
    $twoImagesConfig = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray([
        'candidateCount' => 2,
    ]);
    check(
        !\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::imageGeneration(),
            [$message('a cat')],
            $twoImagesConfig
        )->areMetBy($byId['step-image-edit-2']),
        'asking for two images is correctly rejected'
    );

    // A video model has no capability, so it must never be selected for anything.
    check(
        !\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
            \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            [$message('hi')],
            $plainConfig
        )->areMetBy($byId['step-video-t2v']),
        'the video model is never selected for text generation'
    );
}

// --- Result. -----------------------------------------------------------------------------------
fwrite(STDOUT, sprintf("\n%d checks, %d failure(s)\n", $checks, $failures));

exit($failures === 0 ? 0 : 1);
