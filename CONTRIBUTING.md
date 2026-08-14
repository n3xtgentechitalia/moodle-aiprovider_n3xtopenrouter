# Contributing

## Scope

This repository contains the Moodle AI provider plugin
`aiprovider_n3xtopenrouter`. The repository root *is* the plugin root; it is
installed at `<moodle-root>/ai/provider/n3xtopenrouter`.

## Local setup

The plugin has to live inside a Moodle 5.0+ checkout to run anything:

```bash
ln -s /path/to/this/repo /path/to/moodle/public/ai/provider/n3xtopenrouter
php admin/cli/upgrade.php
```

## Before opening a pull request

```bash
# Syntax
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# Moodle coding style, PHPDoc, and the test suite
moodle-plugin-ci phpcs   ai/provider/n3xtopenrouter
moodle-plugin-ci phpdoc  ai/provider/n3xtopenrouter
vendor/bin/phpunit --filter aiprovider_n3xtopenrouter
```

Without a test database, verify against a real Moodle bootstrap instead:

```bash
php tools/verify_settings.php --moodle=/path/to/moodle
php tools/verify_image.php    --moodle=/path/to/moodle
php tools/check_lang.php
python3 tools/check_style.py
```

Before tagging a release, confirm the package still installs through the web UI:

```bash
php tools/validate_zip.php --moodle=/path/to/moodle
```

CI runs the same checks; see `.github/workflows/ci.yml`. Anything that has to
happen outside the plugin directory belongs in `docs/OPERATIONS.md`, documented
so it can be reproduced — not applied silently.

## Conventions

- Follow Moodle coding style and plugin architecture. Where core bundles an
  equivalent provider (`ai/provider/openai`, `ai/provider/gemini`), match how it
  does things rather than inventing a local pattern.
- Action settings are keyed by the **fully qualified action class name**. Read
  them through `abstract_processor::get_action_setting()` and never by short
  action name — that bug shipped once already, and `tests/action_settings_test.php`
  exists to stop it shipping again.
- New defaults belong in `classes/defaults.php`, not inline in a form or processor.
- Every user-visible string goes through `get_string()`. Keep
  `lang/en/aiprovider_n3xtopenrouter.php` alphabetically sorted, and delete
  strings when their last caller goes.
- Add or update tests under `tests/` for any behaviour change.

## Versioning

- Releases use Semantic Versioning and Git tags: `vMAJOR.MINOR.PATCH`.
- `$plugin->version` in `version.php` is the numeric Moodle upgrade version and
  must increase for every release.
- `$plugin->release` holds the SemVer tag.

## Release checklist

1. Implement the change, with tests.
2. Update `CHANGELOG.md` under a new version heading.
3. Bump `$plugin->version` and `$plugin->release` in `version.php`.
4. Regenerate `screenshots/` if the admin UI changed.
5. Commit, tag `vX.Y.Z`, and push the branch and the tag.
6. The release workflow builds `n3xtopenrouter-vX.Y.Z.zip` with the
   `n3xtopenrouter/` directory at its root, which is the layout
   moodle.org requires. Attach that ZIP to the plugin release.
