<?php

/**
 * Model catalog helpers.
 *
 * Pure string logic, no WordPress and no SDK: this is the part of the plugin worth unit testing, and
 * `scripts/selfcheck.php` does exactly that.
 *
 * @package StepFun\AiProvider
 */

declare(strict_types=1);

namespace StepFun\AiProvider\Util;

/**
 * Classification rules for StepFun model IDs.
 *
 * StepFun's `/models` endpoint reports only `id`, `object`, `created` and `owned_by` — no capability
 * data at all — so every capability and every request parameter this plugin declares is derived from
 * the model ID here.
 */
final class StepfunModelCatalog
{
    /**
     * Model IDs that accept image input.
     *
     * Sources (checked 2026-09-18):
     *  - `step-3.7-flash`: "旗舰多模态推理模型，原生支持图片和视频理解" —
     *    https://platform.stepfun.com/docs/zh/guides/models/overview
     *  - `step-1o-turbo-vision`: listed under 视觉理解模型 / 图像 · 视频理解, same page.
     *  - `step-1v-*`: the earlier vision family; `step-1v-32k` is used as a vision model by
     *    `weagent/apps/eval/providers.yaml` in this workspace.
     *  - `stepfun/step-3.7-flash` declares `input: ["text","image"]` in the OpenRouter model data
     *    shipped with `@earendil-works/pi-ai` (`dist/providers/data/openrouter.json`).
     *
     * StepFun only *adds* vision support over time, and a model wrongly declared here 400s rather than
     * silently degrading, so the list is kept as narrow as the evidence. Escape hatch for deployments
     * ahead of this list: `STEPFUN_MODEL_INPUT_MODALITIES=text,image`.
     *
     * @var list<string>
     */
    private const VISION_MODEL_PATTERNS = [
        '#^step-3\.7-flash#',
        '#^step-1v-#',
        '#-vision$#',
    ];

    /**
     * Model ID patterns that are not text-generation models at all.
     *
     * Checked *before* the catch-all chat rule, so a new audio or video family cannot be mistaken for
     * a chat model.
     *
     * @var list<string>
     */
    private const NON_TEXT_MODEL_PATTERNS = [
        // Text-to-image and image editing.
        '#^step-(?:1x|2x|image)-#',
        // Asynchronous video generation: no synchronous entry point in the SDK, so not supported.
        '#^step-video-#',
        // Speech synthesis and recognition.
        '#^step-(?:tts|asr)#',
        '#^stepaudio#',
        '#^step-1o-audio#',
        '#^step-audio#',
        // The Step Plan router is only reachable through a different base URL and would 404 here.
        '#^step-router-#',
    ];

    /**
     * Text-to-image families served by `POST /images/generations`.
     *
     * @var list<string>
     */
    private const IMAGE_MODEL_PATTERNS = [
        '#^step-(?:1x|2x|image)-#',
    ];

    /**
     * Sizes each image family accepts, per orientation.
     *
     * Taken from the API reference (checked 2026-09-18),
     * https://platform.stepfun.com/docs/zh/api-reference/images/image:
     *  - `step-image-edit-2`: 正方形 `1024x1024`; 长方形 `768x1360`, `896x1184`, `1360x768`, `1184x896`.
     *  - `step-2x-large`: 正方形 `256x256`…`1024x1024`; 长方形（16:9）`1280x800`, `800x1280`.
     *  - `step-1x-*`: only square resolutions are documented for text-to-image.
     *
     * The documentation states `step-image-edit-2` reads `size` as `height x width`, which makes it
     * ambiguous whether `1360x768` is wide or tall. The numeric reading (`1360x768` = 1360 wide) is
     * used here because that is what every OpenAI-compatible client assumes; if StepFun turns out to
     * mean it literally, override with `STEPFUN_SIZE_LANDSCAPE` / `STEPFUN_SIZE_PORTRAIT`. Do not
     * "fix" this from documentation alone — one live call settles it.
     *
     * @var array<string, array<string, string>>
     */
    private const SIZES_BY_FAMILY = [
        '#^step-(?:image|2x)-#' => [
            'square' => '1024x1024',
            'landscape' => '1360x768',
            'portrait' => '768x1360',
        ],
        '#^step-1x-#' => [
            'square' => '1024x1024',
            'landscape' => '1024x1024',
            'portrait' => '1024x1024',
        ],
    ];

    /**
     * The size used when nothing better is known.
     *
     * @var string
     */
    public const DEFAULT_SIZE = '1024x1024';

    /**
     * Whether a model accepts image input.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model accepts images.
     */
    public static function supportsImageInput(string $modelId): bool
    {
        foreach (self::VISION_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a model generates images rather than text.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model generates images.
     */
    public static function isImageModel(string $modelId): bool
    {
        foreach (self::IMAGE_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a model can generate text at all.
     *
     * Video, speech and image models are excluded. Everything else in the `step-` namespace is
     * treated as a chat model: StepFun's public surface is entirely `step-*`, and falling back to
     * "text" keeps a newly released chat model usable instead of silently absent from every picker.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model generates text.
     */
    public static function isTextModel(string $modelId): bool
    {
        foreach (self::NON_TEXT_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId) === 1) {
                return false;
            }
        }

        return strpos($modelId, 'step-') === 0;
    }

    /**
     * Whether a model rejects non-default sampling parameters.
     *
     * Empty on purpose. The API reference documents `temperature`, `top_p` and `frequency_penalty`
     * for `step-3.7-flash` with explicit ranges, so the reasoning family does *not* reject them the
     * way OpenAI's does. Add a pattern here only after an upstream 400 has been observed; the
     * corresponding options must then also be removed in the metadata directory.
     *
     * @param string $modelId The model ID.
     * @return bool Whether sampling parameters must not be sent.
     */
    public static function rejectsSamplingParameters(string $modelId): bool
    {
        return false;
    }

    /**
     * Resolves the `size` parameter for an image request.
     *
     * @param string $modelId The model ID.
     * @param string|null $orientation `square`, `landscape`, `portrait` or null.
     * @return string The size value to send.
     */
    public static function sizeForOrientation(string $modelId, ?string $orientation): string
    {
        $orientation = $orientation === null ? 'square' : strtolower($orientation);

        $override = StepfunConfig::env('STEPFUN_SIZE_' . strtoupper($orientation));
        if ($override !== '') {
            return $override;
        }

        foreach (self::SIZES_BY_FAMILY as $pattern => $sizes) {
            if (preg_match($pattern, $modelId) === 1) {
                return $sizes[$orientation] ?? $sizes['square'];
            }
        }

        return self::DEFAULT_SIZE;
    }

    /**
     * Orders model IDs so the models a user is most likely to want appear first.
     *
     * Not a judgement about which model is best: the goal is that the first entry in the picker is a
     * current flagship rather than something alphabetically lucky.
     *
     * @param string $modelIdA The first model ID.
     * @param string $modelIdB The second model ID.
     * @return int Negative if the first model should sort first, positive otherwise.
     */
    public static function compareModelIds(string $modelIdA, string $modelIdB): int
    {
        // Preview and experimental variants are rate-limited and short-lived.
        $previewDelta = (int) self::isPreview($modelIdA) <=> (int) self::isPreview($modelIdB);
        if ($previewDelta !== 0) {
            return $previewDelta;
        }

        // Models that can actually do something come before ones the plugin declares no capability for.
        $unsupportedDelta = (int) self::isUnsupported($modelIdA) <=> (int) self::isUnsupported($modelIdB);
        if ($unsupportedDelta !== 0) {
            return $unsupportedDelta;
        }

        // Text generation is the common case; image models are picked explicitly.
        $imageDelta = (int) self::isImageModel($modelIdA) <=> (int) self::isImageModel($modelIdB);
        if ($imageDelta !== 0) {
            return $imageDelta;
        }

        /*
         * Newer generation first. StepFun names generations with a leading number (`step-3.7-flash`,
         * `step-1v-32k`), so compare that numerically — and put numbered families ahead of a
         * hypothetical unnumbered one, which a plain string sort gets backwards because digits sort
         * before letters.
         */
        $versionA = self::generationVersion($modelIdA);
        $versionB = self::generationVersion($modelIdB);
        if ($versionA === null || $versionB === null) {
            $numberedDelta = (int) ($versionA === null) <=> (int) ($versionB === null);
            if ($numberedDelta !== 0) {
                return $numberedDelta;
            }
        } elseif (version_compare($versionA, $versionB, '!=')) {
            return version_compare($versionA, $versionB, '>') ? -1 : 1;
        }

        // Same generation: a plainer name first, then reverse-natural for a stable order.
        return -strnatcasecmp($modelIdA, $modelIdB);
    }

    /**
     * Extracts the generation number from a model ID.
     *
     * @param string $modelId The model ID.
     * @return string|null The version, or null when the ID carries none.
     */
    private static function generationVersion(string $modelId): ?string
    {
        if (preg_match('/^step-(\d+(?:\.\d+)*)/', $modelId, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Whether a model is a preview or experimental variant.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model is a preview.
     */
    public static function isPreview(string $modelId): bool
    {
        return stripos($modelId, 'preview') !== false
            || stripos($modelId, '-exp') !== false
            || stripos($modelId, 'beta') !== false;
    }

    /**
     * Whether this plugin declares no capability for a model.
     *
     * @param string $modelId The model ID.
     * @return bool Whether the model is unsupported.
     */
    public static function isUnsupported(string $modelId): bool
    {
        return !self::isTextModel($modelId) && !self::isImageModel($modelId);
    }
}
