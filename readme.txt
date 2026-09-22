=== AI Provider for StepFun ===
Contributors:      bestony
Tags:              ai, connector, stepfun, artificial-intelligence, vision
Requires at least: 7.0
Tested up to:      7.1
Stable tag:        1.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

StepFun (阶跃星辰) provider for the PHP AI Client: text, vision and image generation with Step models.

== Description ==

Adds [StepFun](https://platform.stepfun.com/) (阶跃星辰) as a provider for the WordPress AI Client.

* The model list is fetched live from `GET /v1/models`, so newly released models appear on their own.
* Text generation and chat history with the Step chat models, including tool calling, structured
  output (JSON schema) and stop sequences.
* Vision: `step-3.7-flash` and the `step-1v-*` / `*-vision` families accept images, so features like
  Alt Text Generation work.
* Image generation with the `step-image-*` / `step-2x-*` text-to-image models, returning either an
  inline image or a URL.
* Reasoning output is surfaced as thought parts rather than mixed into the answer text.
* A **Settings → AI Provider for StepFun** page to pick which StepFun host to talk to, including the
  Step Plan endpoints.

== Screenshots ==

1. The StepFun connector card on Settings → Connectors, with the API key field.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-stepfun/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings → Connectors, open the StepFun card and paste your API key — or define it outside
   the database (see Configuration).

A StepFun account with API access is required. Create a key at
[https://platform.stepfun.com/interface-key](https://platform.stepfun.com/interface-key).

== Settings ==

**Settings → AI Provider for StepFun** picks which StepFun host the provider talks to. Four hosts are
offered:

* Stepfun.com — `https://api.stepfun.com/v1` (default)
* StepPlan at Stepfun.com — `https://api.stepfun.com/step_plan/v1`
* Stepfun.ai — `https://api.stepfun.ai/v1`
* Step Plan at Stepfun.ai — `https://api.stepfun.ai/step_plan/v1`

The chosen host is used for the model list and for every generation request. It is stored per site, so
each site on a multisite network configures its own. There is also a **Setup Step Plan** link in the
plugin's row on the Plugins screen that jumps straight to this page.

Only these four hosts can be selected: a value that is not one of them (written to the database
directly, for example) falls back to the default. Setting `STEPFUN_BASE_URL` overrides the choice.

The page is available in English, Simplified Chinese (`zh_CN`) and Traditional Chinese (`zh_TW`).

== Configuration ==

The API key is read, in order of precedence, from:

1. The `STEPFUN_API_KEY` environment variable
2. The `STEPFUN_API_KEY` PHP constant
3. The `connectors_ai_stepfun_api_key` option (Settings → Connectors)

All optional settings are environment variables or PHP constants:

* `STEPFUN_DEFAULT_MODEL` — chat model to prefer in pickers and feature filters.
  Default: `step-3.7-flash`.
* `STEPFUN_IMAGE_MODEL` — image model to prefer in the image generation feature.
  Default: `step-image-edit-2`.
* `STEPFUN_BASE_URL` — API base URL. Overrides the value chosen on Settings → AI Provider for StepFun
  (see below).
* `STEPFUN_MODEL_INPUT_MODALITIES` — comma-separated list of modalities your models accept. Include
  `image` (e.g. `text,image`) to declare vision for **every** chat model, for deployments ahead of
  the built-in list.
* `STEPFUN_STRUCTURED_OUTPUT` — how JSON response requests are shaped. One of:
  * `json_schema` (default) — sends the JSON schema, wrapped the way StepFun documents it.
  * `json_object` — asks only for valid JSON, without the schema. Fallback for a model that rejects
    schemas.
  * `none` — sends no `response_format` at all.
* `STEPFUN_SIZE_LANDSCAPE` / `STEPFUN_SIZE_PORTRAIT` / `STEPFUN_SIZE_SQUARE` — override the `size`
  value sent for each orientation. Defaults: `1360x768` / `768x1360` / `1024x1024`. See the FAQ.
* `STEPFUN_REQUEST_TIMEOUT` — request timeout in seconds. Default: `120`.
* `STEPFUN_CONNECT_TIMEOUT` — connection timeout in seconds. Default: `10`.

Because the plugin has no database-backed settings of its own, changing an environment variable after
the AI Client has cached its model list does not invalidate that cache. If a new value does not take
effect immediately, clear the cache — in WP-CLI:

    wp cache flush
    wp transient delete --all

To influence which models the AI plugin picks, use the standard filters in your own plugin or theme —
this plugin already puts its preferred models first:

    add_filter( 'wpai_preferred_text_models', function ( $models ) {
        array_unshift( $models, array( 'stepfun', 'step-3.5-flash' ) );
        return $models;
    } );

== Frequently Asked Questions ==

= Does this plugin work without the PHP AI Client? =

No. It requires the PHP AI Client SDK, which is provided by WordPress 7.0 or by the AI plugin. The
provider stays silent when the SDK is missing.

= Do I need to configure anything in the database? =

The API key (on Settings → Connectors) and, if you do not use the default host, the base URL chosen on
Settings → AI Provider for StepFun. Both can instead be set with an environment variable or constant.

= Why does the plugin require WordPress 7.0? =

Because the Connectors screen and the `connectors_ai_stepfun_api_key` option are WordPress 7.0
features. The SDK's own credential lookup reads only environment variables and constants, so on 6.9
you could paste a key into a UI that does not exist yet. WordPress 7.0 wires the stored key to the
provider for you.

= Which models can be used with vision features? =

`step-3.7-flash`, the `step-1v-*` family and any model whose ID ends in `-vision`. The model list
endpoint reports no capabilities, so this list lives in the plugin source. If your model supports
images but is not on the list, set `STEPFUN_MODEL_INPUT_MODALITIES=text,image`.

= Why is there no video generation? =

StepFun's video models are asynchronous jobs, and the PHP AI Client only exposes a synchronous entry
point for video generation. The plugin therefore declares no capability for `step-video-*` models:
they still appear in the model list, but they cannot be selected. Implementing it would mean writing
code the SDK never calls.

= Why is there no image editing? =

Only text-to-image (`POST /v1/images/generations`) is implemented. StepFun's edit endpoints
(`/v1/images/edits`, `/v1/images/image2image`) take a different request shape per model family, and
the AI Client's reference-image flow is not a reliable fit for them.

= Image generation fails with an invalid size =

The plugin sends `1360x768` for landscape and `768x1360` for portrait. StepFun documents
`step-image-edit-2` sizes as `height x width`, which is ambiguous — those two values are the
reasonable numeric reading, but if the API rejects them, swap them:

    STEPFUN_SIZE_LANDSCAPE=768x1360
    STEPFUN_SIZE_PORTRAIT=1360x768

= A JSON feature fails with `400 ... "param":"response_format"` =

That feature asks for a JSON schema response. Try `STEPFUN_STRUCTURED_OUTPUT=json_object` to ask for
plain JSON instead, or `none` to send no `response_format` at all — the prompt still asks for JSON.

= Where is my API key stored? =

In the WordPress options table on your own site, under the AI Client's connector option
(`connectors_ai_stepfun_api_key`), or in an environment variable or PHP constant if you set one. It is
never transmitted anywhere except to the StepFun API when fulfilling a request.

= What data leaves my site? =

Only what you send to the AI: your prompts, and whatever the calling plugin or theme adds to them
(system instructions, conversation history, tool definitions, a JSON schema, or attached files). See
External services below for the exact endpoints.

= Is there a settings page? =

Yes, one: **Settings → AI Provider for StepFun**, which chooses the API host (see Settings above). The
API key still lives on Settings → Connectors, and the remaining optional behaviour is controlled by
environment variables or constants.

== External services ==

This plugin connects to the StepFun API, an external service operated by 上海阶跃星辰智能科技有限公司
(StepFun). It is required so the WordPress AI Client can send requests to StepFun models from your
site. StepFun is a paid service: requests are billed to your StepFun account, and an account with API
access is required.

The plugin contacts the following endpoints under the selected base URL — `https://api.stepfun.com/v1`
by default, or whichever host is chosen on Settings → AI Provider for StepFun:

* `GET /models` — called when the AI Client refreshes its list of available models, and when it checks
  whether your credentials work. No user content is sent; only your API key, so StepFun can return the
  models available to your account.
* `POST /chat/completions` — called whenever any plugin or theme on your site uses the WordPress AI
  Client to generate text or to analyze an image. The request carries your API key and the prompt:
  messages, system instruction, tool definitions, and any other parameters the calling code supplied
  (conversation history, a JSON schema for structured output, or images attached to the prompt).
* `POST /images/generations` — called when something on your site asks the AI Client to generate an
  image. The request carries your API key and the image prompt.

No request is made until something on your site asks the AI Client for a generation, or the AI Client
refreshes its model list. Your API key is stored on your own site and is only ever sent to the host
configured above.

This service is provided by StepFun:

* Platform and documentation: [https://platform.stepfun.com/](https://platform.stepfun.com/)
* Terms of Service: [https://platform.stepfun.com/docs/zh/terms/terms-of-service](https://platform.stepfun.com/docs/zh/terms/terms-of-service)
* Privacy Policy: [https://platform.stepfun.com/docs/zh/terms/privacy-policy](https://platform.stepfun.com/docs/zh/terms/privacy-policy)
* International platform: [https://platform.stepfun.ai/](https://platform.stepfun.ai/)

== Changelog ==

= 1.1.0 =
* New: Settings → AI Provider for StepFun, to choose between the StepFun and Step Plan API hosts on
  the mainland-China and international platforms. The choice applies to the model list and every
  generation request, and is stored per site.
* New: a **Setup Step Plan** link in the plugin's row on the Plugins screen.
* Added English, Simplified Chinese (`zh_CN`) and Traditional Chinese (`zh_TW`) translations for the
  admin screens.

= 1.0.1 =
* Stop reading the `connectors_ai_stepfun_api_key` option directly. Whether a StepFun credential is
  configured is now asked of the AI Client, so the plugin never handles the key the user saved in
  Settings → Connectors. Behaviour is unchanged: the `STEPFUN_API_KEY` constant and environment
  variable still work.

= 1.0.0 =
* Initial release: text generation, chat history, tool calling, structured output, vision input and
  text-to-image generation with StepFun (阶跃星辰) models.

== Upgrade Notice ==

= 1.1.0 =
Adds a settings page for choosing the StepFun API host (including the Step Plan endpoints). The
default behaviour is unchanged.

= 1.0.1 =
The stored StepFun API key is no longer read by the plugin; the AI Client reports whether a credential is configured.

= 1.0.0 =
Initial release.
