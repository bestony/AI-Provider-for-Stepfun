# Bestony AI Provider for StepFun

[StepFun](https://platform.stepfun.com/) (阶跃星辰) as a provider for the WordPress AI Client: text,
vision and image generation with Step models.

## What it does

* Fetches the model list live from `GET /v1/models`, so newly released models show up on their own.
* Text generation and chat history, including tool calling, structured output (JSON schema) and stop
  sequences.
* Vision input with `step-3.7-flash` and the `step-1v-*` / `*-vision` families, so Alt Text
  Generation works.
* Text-to-image generation with the `step-image-*` / `step-2x-*` models, returned inline or as a URL.
* Reasoning output surfaces as thought parts instead of being mixed into the answer.
* A **Settings → Bestony AI Provider for StepFun** page that picks which StepFun host to talk to, including
  the Step Plan endpoints.

## Requirements

* WordPress 7.0 or newer, with the PHP AI Client SDK (bundled in WordPress 7.0, or provided by the AI plugin)
* PHP 7.4 or newer
* A StepFun account with API access

## Install

Download the zip from [Releases](../../releases) and upload it through **Plugins → Add New → Upload
Plugin**, or copy the plugin folder to `wp-content/plugins/bestony-ai-provider-for-stepfun/`. Activate it,
then open **Settings → Connectors**, open the StepFun card and paste your API key. If your key is for
Step Plan, also pick the matching host on **Settings → Bestony AI Provider for StepFun**.

## Settings

**Settings → Bestony AI Provider for StepFun** chooses the API host:

| Option | Base URL |
| --- | --- |
| Stepfun.com (default) | `https://api.stepfun.com/v1` |
| StepPlan at Stepfun.com | `https://api.stepfun.com/step_plan/v1` |
| Stepfun.ai | `https://api.stepfun.ai/v1` |
| Step Plan at Stepfun.ai | `https://api.stepfun.ai/step_plan/v1` |

The choice is stored per site and is used for the model list and every generation request. A value
outside this list falls back to the default. The plugin's row on the Plugins screen also has a
**Setup Step Plan** shortcut to the page.

The admin screens are available in English, Simplified Chinese (`zh_CN`) and Traditional Chinese
(`zh_TW`).

## Configuration

The API key is read in order of precedence from:

1. the `STEPFUN_API_KEY` environment variable
2. the `STEPFUN_API_KEY` PHP constant
3. the `connectors_ai_stepfun_api_key` option (Settings → Connectors)

Optional settings are environment variables or PHP constants:

| Setting | Default | Purpose |
| --- | --- | --- |
| `STEPFUN_DEFAULT_MODEL` | `step-3.7-flash` | Chat model to prefer in pickers and feature filters |
| `STEPFUN_IMAGE_MODEL` | `step-image-edit-2` | Image model to prefer in the image generation feature |
| `STEPFUN_BASE_URL` | the host chosen in Settings | API base URL; overrides the settings page |
| `STEPFUN_MODEL_INPUT_MODALITIES` | from the built-in list | Comma-separated input modalities, e.g. `text,image`, to declare vision for every chat model |
| `STEPFUN_STRUCTURED_OUTPUT` | `json_schema` | `json_schema`, `json_object`, or `none` — use `json_object` if a JSON feature fails with `400 ... "param":"response_format"` |
| `STEPFUN_SIZE_LANDSCAPE` / `STEPFUN_SIZE_PORTRAIT` / `STEPFUN_SIZE_SQUARE` | `1360x768` / `768x1360` / `1024x1024` | Override the `size` sent per orientation |
| `STEPFUN_REQUEST_TIMEOUT` | `120` | Request timeout, seconds |
| `STEPFUN_CONNECT_TIMEOUT` | `10` | Connection timeout, seconds |

Changing an environment variable does not invalidate the AI Client's cached model list; run
`wp cache flush` if a new value does not take effect.

To change which model the AI plugin picks for its features, use the standard filter — this plugin
already puts its preferred models first:

```php
add_filter( 'wpai_preferred_text_models', function ( $models ) {
    array_unshift( $models, array( 'stepfun', 'step-3.5-flash' ) );
    return $models;
} );
```

## Not implemented

* **Video generation.** StepFun's video models are asynchronous jobs, but the SDK's only video entry
  point is synchronous. `step-video-*` models appear in the list with no capability rather than
  shipping code the SDK never calls.
* **Image editing.** Only `POST /v1/images/generations` is implemented.

## Data and privacy

Prompts you send to the AI, plus whatever the calling plugin or theme adds to them (system
instructions, conversation history, tool definitions, a JSON schema, attached files), are sent to
StepFun. Your API key is stored on your own site and is only ever sent to the StepFun host configured
above. Nothing is sent until your site actually asks the AI Client for a generation.

* Platform and docs: <https://platform.stepfun.com/>
* Terms of Service: <https://platform.stepfun.com/docs/zh/agreement/userservice>
* Privacy Policy: <https://platform.stepfun.com/docs/zh/agreement/userprivacy>
* International platform: <https://platform.stepfun.ai/>

## Development

```
php scripts/selfcheck.php                                              # logic checks, no WordPress needed
php scripts/selfcheck.php --sdk=/path/to/php-ai-client/src             # plus live-SDK checks
```

The self-check asserts model classification, sort order, the declared capabilities and options, the
request shapes for chat and images, and that the declared metadata satisfies the requirements the AI
plugin's features actually send. It needs neither WordPress nor an API key.

Release: push a tag matching the plugin version (`git tag v<version> && git push origin v<version>`).
The [release workflow](.github/workflows/release.yml) verifies the tag against `Version:`/`Stable tag:`,
verifies a matching changelog entry exists, builds the plugin zip and publishes it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

The `assets/images/stepfun.svg` mark comes from [Lobe Icons](https://github.com/lobehub/lobe-icons)
(MIT) and is a trademark of StepFun, used only to identify the service this plugin connects to.

The full WordPress plugin readme, including the changelog, lives in [readme.txt](readme.txt).
