# Changelog

All notable changes to this project are documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic Versioning.

## [2.0.0] - 2026-08-14

Renamed. This is no longer the plugin it was forked from, and carrying its name was
becoming misleading: the request path, the model selection, the image action, the
tests and the tooling are all different work now.

### Changed - BREAKING

- **The component is now `aiprovider_n3xtopenrouter`**, installed at
  `ai/provider/n3xtopenrouter`. Previously `aiprovider_schooleesopenrouter` at
  `ai/provider/schooleesopenrouter`.
- Namespace, language file, string keys, cache definition and hook callback all
  follow the new component name.
- Display name is now *OpenRouter AI Provider (Next Gen Technologies)*.
- Maintainer is Alessio Giustini, Next Gen Technologies Italia (n3xtgentech.it).
  Upstream attributions are preserved in the headers of the files the code derives
  from: Marcus Green for the provider structure, and the Schoolees OpenRouter fork
  this started from.

### Added

- `tools/validate_zip.php`, which runs Moodle's own `\core\update\validator`
  against a built package. It is the same code path as uploading through Site
  administration > Plugins > Install plugins, so a clean run here means the upload
  will be accepted. Works on any ZIP, not just one built from this repository.
- README rewritten in Italian and English, with installation documented for the way
  most people actually install: uploading a ZIP through the web interface. It calls
  out the trap that catches everyone - GitHub's "Source code (zip)" has the tag in
  its root directory name, so Moodle rejects it; the release asset does not.

### Upgrading from `aiprovider_schooleesopenrouter`

Moodle has no built-in way to rename a component, and **uninstalling the old plugin
deletes its provider instances**, API key included - see
`lib/classes/plugininfo/aiprovider.php::uninstall_cleanup()`. So the order matters:

1. Install this plugin. Both can coexist.
2. Point the existing instance at the new component:
   `tools/migrate_from_schoolees.php --moodle=/path/to/moodle`
   This moves the `ai_providers` row, carrying the API key, the models and every
   action setting.
3. Verify with `tools/verify_installed.php`.
4. Only then uninstall the old plugin. It finds no rows of its own, so nothing is
   lost.

Doing step 4 first loses the API key and every action setting.

## [1.2.0] - 2026-08-14

### Added

- **Image generation.** The `generate_image` action is now supported, against
  OpenRouter's unified image endpoint (`/api/v1/images`). Moodle's aspect ratio,
  quality and style are mapped onto what that endpoint accepts, the returned
  image is watermarked as core requires, and it is stored in the requesting
  user's draft file area.
- Image model chooser built from OpenRouter's image catalogue (43 models), a
  separate catalogue and cache from the text one, with the same *Other model*
  free-text fallback.
- Per-model capability filtering. The catalogue publishes, for each image model,
  which parameters it accepts and with which values, so a request carries only
  what the chosen model supports. This matters: `aspect_ratio` is accepted by 40
  of the 43 models, `resolution` by 19, `quality` by only 7, and sending an
  unsupported parameter is rejected outright.
- Aspect ratio now degrades rather than failing. A landscape request becomes
  `3:2`, `16:9` or `4:3`, whichever the chosen model accepts first — without this,
  asking `recraft/recraft-v4` for a landscape image would simply error.
- Configurable resolution tier for image generation, sent only when the chosen
  model accepts the tier picked, so an admin who asked for 2K never silently
  gets 4K.

### Changed

- Endpoint and model defaults are resolved per action, so image generation can
  target a different endpoint from the text actions without special-casing.
- The action settings form composes its fields per action, so image generation
  has no temperature or system instruction rather than showing ones that would
  be ignored.
- Privacy metadata records the aspect ratio and quality that image requests send.

### Notes

- Only one image is requested per action, regardless of how many the caller asks
  for: `response_generate_image` carries exactly one file, and core's own
  providers read only the first, so a larger count bills for discarded output.
- `style` is appended to the prompt rather than sent as a parameter, because the
  endpoint has none and drops it silently.
- Vector output, from models such as `recraft-v4-vector`, cannot be watermarked
  and is stored unwatermarked.
- Image models are billed per image. Consider a per-user rate limit before
  enabling the action.

## [1.1.0] - 2026-08-14

### Fixed

- **Action settings were ignored entirely.** Moodle keys a provider instance's
  action config by the fully qualified action class name
  (`core_ai\aiactions\generate_text`), but the processors looked settings up by
  short action name (`generate_text`). Nothing ever matched, so the model,
  endpoint, temperature and system instruction an admin configured were silently
  discarded and every request used the built-in defaults. This is the root cause
  of "the plugin does not let me choose a model".
- New provider instances no longer start with an empty settings block.
  `get_action_setting_defaults()` is now implemented, so an instance is created
  with its model, endpoint, temperature and system instruction populated and
  visible in the UI.
- Reading `system_fingerprint` from the response raised
  `Undefined property: stdClass::$system_fingerprint` on every request served by
  an upstream provider that does not return it. Optional response fields
  (`id`, `system_fingerprint`, `usage`) are now genuinely optional.
- A response with no usable content is reported as an error instead of being
  passed on as an empty success.
- Temperature is validated on save and clamped to the 0–2 range the API accepts,
  rather than being coerced to zero by form cleaning.

### Added

- Model chooser built from OpenRouter's live catalogue, cached for 24 hours, with
  an *Other model* free-text fallback and a notice when the catalogue is
  unreachable. Batch-only variants are excluded because they cannot serve a
  synchronous chat completion.
- The model that actually answered is reported back to Moodle, so
  `openrouter/auto` is auditable instead of opaque.
- Configurable **Maximum words** for *Summarise text*, replacing the hard-coded
  500-word single-paragraph cap. `0` disables capping entirely.
- `HTTP-Referer` and `X-Title` headers, which is how OpenRouter attributes
  traffic to a site.
- Regression test coverage for the action-settings lookup, response handling,
  temperature clamping and the summary word cap.

### Changed

- Defaults live in one class (`aiprovider_n3xtopenrouter\defaults`) instead
  of being repeated as literals across forms and processors.
- Default model is now an explicit `google/gemini-3.7-flash` rather than
  `openrouter/auto`.
- *Generate text* and *Summarise text* each have their own settings form.
- The provider instance form uses the standard `passwordunmask` field for the API
  key and points admins at where the model is configured.
- Error messages come from language strings rather than hard-coded English.

### Removed

- **The OpenRouter organization ID field.** It was sent as an
  `OpenAI-Organization` header, which OpenRouter ignores. Any stored value is
  left untouched in the database but is no longer sent or shown.
- Dead image-generation code (`process_generate_image.php`,
  `action_generate_image_form.php`, their language strings and fixtures).
  `generate_image` was never in `get_action_list()`, so none of it could run.
- `settings.php`, which was a no-op on Moodle 5 wrapping ~90 lines of unreachable
  legacy configuration.
- Stale screenshots, which documented a UI this release changes. They need
  regenerating from a live install.

### Upgrade notes

- Review the model on each action after upgrading. Sites that configured a model
  under 1.0.x were never actually using it, so the model in effect will change
  from `openrouter/auto` to whatever the action settings say.
- Purge the site caches to populate the model catalogue immediately.

## [1.0.1] - 2026-02-17

### Changed

- Bumped Moodle plugin build number for re-submission and publishing validation.

## [1.0.0] - 2026-02-17

### Added

- Initial stable SemVer release for the Schoolees OpenRouter AI Provider.
- Documented contribution workflow in `CONTRIBUTING.md`.
- Established changelog-based release tracking.
