# Operations: everything outside the plugin

This document covers what lives *outside* `ai/provider/n3xtopenrouter`:
environment prerequisites, the deployment runbook, how to reproduce every check
that was run, and the upstream defects this plugin runs into. The plugin's own
behaviour is documented in [`README.md`](../README.md).

## 1. Patches outside the plugin: none

**No Moodle core file is patched, and none needs to be.** The plugin uses only
public core APIs: `\core_ai\provider`, `\core_ai\process_base`, the
`after_ai_provider_form_hook` hook, `\core_ai\ai_image`, the Cache API and the
File API. There is no `db/upgrade.php`, no core override, no autoloader trick, and
nothing written outside the plugin directory at install time beyond what Moodle
does for any plugin (its `config` rows and its cache definition).

If you need to prove a site was not modified, compare the installed copy against
any commit in this repository:

```bash
tools/verify_untouched.sh /path/to/moodle/ai/provider/n3xtopenrouter <git-ref>
```

It prints `IDENTICAL` or lists the differing files. Use the baseline import commit
to prove nothing changed, or `HEAD` to prove a deployment landed exactly what was
intended. Development-only paths (`tools/`, `.github/`, dotfiles) are excluded,
since the release archive does not contain them either.

## 2. Environment prerequisites

| Requirement | Why | How to check |
|---|---|---|
| Moodle **5.0+** | The AI subsystem and its per-instance provider config | `php admin/cli/cfg.php --name=release` |
| PHP **8.2+** | Matches Moodle 5.0's own floor | `php -v` |
| **GD** extension | Core watermarks AI-generated images | `php -r 'var_dump(extension_loaded("gd"));'` |
| Outbound **HTTPS to `openrouter.ai`** | API calls, plus the model catalogues | `curl -sI https://openrouter.ai/api/v1/models \| head -1` |

Two Moodle settings can block outbound calls even when the network allows them.
Check both if requests fail with a connection error rather than an HTTP status:

- **Site administration → General → HTTP security → `curlsecurityblockedhosts`** —
  must not match `openrouter.ai`.
- **Site administration → General → HTTP proxy** — if the site is behind a proxy,
  Moodle's HTTP client uses these settings, and `openrouter.ai` must be reachable
  through it.

The model catalogues (`/api/v1/models` and `/api/v1/images/models`) are public and
need no API key, but they do need the same outbound access. If they are
unreachable the settings forms still render from a short built-in list and say so.

## 3. Deployment runbook

Use the script. It does the whole sequence in the right order, with the details
that are easy to get wrong by hand.

```bash
# Look first: prints every step, changes nothing.
tools/deploy.sh --moodle=/path/to/moodle --dry-run

# Then do it. It asks for confirmation.
sudo tools/deploy.sh --moodle=/path/to/moodle
```

`--moodle` accepts either the project root or the code root; the layout is worked
out from there. `--ref=` deploys something other than `HEAD`.

### Why it needs to be a script

- **Moodle 5 can serve from a `public/` subdirectory.** When it does, the code and
  this plugin live in `public/`, while `admin/cli/` and the real `config.php` stay
  at the project root, and `public/config.php` is only a shim that includes it. A
  runbook that says "run `$MOODLE/admin/cli/upgrade.php`" is wrong on exactly half
  of Moodle 5 installations. The script detects which layout it is looking at.
- **Moodle's CLI must not run as root.** Doing so leaves files in `moodledata` that
  the web server cannot rewrite. The script runs it as the owner of `moodledata`
  (override with `--as-user=`), while doing the file replacement as root, because
  that is what writing into the Moodle tree and restoring ownership requires.
- **A failed deploy must not reopen the site.** On failure the script leaves
  maintenance mode ON deliberately, and prints the rollback command.

### What it does, in order

1. Maintenance mode on.
2. Back up the installed plugin to a tarball, and print the path.
3. `git archive` the chosen ref and replace the plugin directory.
4. Restore ownership (`root:<moodledata group>`) and modes (755/644).
5. `admin/cli/upgrade.php --non-interactive`, as the CLI user.
6. `admin/cli/purge_caches.php`, as the CLI user.
7. `verify_untouched.sh` against the deployed ref, to confirm what landed.
8. `verify_installed.php`, which exercises the installed code: registered version,
   cache definition, both catalogues, the instances and their credentials, and
   every settings form rendering with no unresolved strings. Still in maintenance,
   so a failure here does not reopen a broken site.
9. Maintenance mode off.
10. One HTTP request to the site, to confirm it answers.

### Rollback

```bash
sudo tools/deploy.sh --moodle=/path/to/moodle --rollback=/tmp/n3xtopenrouter-backup-<timestamp>.tgz
```

Moodle refuses to downgrade a plugin whose `$plugin->version` is lower than the
one recorded in the database. If the upgrade step had already completed before you
rolled back, restore the database from backup as well, or raise the old
`version.php` past the newer number first.

This plugin adds no tables and has no `db/upgrade.php`, so a successful upgrade
writes only its version into `config_plugins`. A full database dump before
deploying is cheap insurance but not strictly required for the schema.

### After deploying

Review the model on **each action**. A site that configured a model under 1.0.x
was never actually using it, so the effective model changes on upgrade — see the
1.1.0 entry in [`CHANGELOG.md`](../CHANGELOG.md).

## 4. Reproducing the verification

The checks that were run against this plugin live in [`tools/`](../tools) and are
reproducible on any machine with a Moodle checkout. They need **no PHPUnit** and
are read-only with respect to Moodle: they construct objects and inspect what
would be sent, and never write to the database or the file storage.

```bash
# From this repository. --moodle can be omitted when the plugin is installed
# inside the Moodle tree being used.
php tools/verify_settings.php --moodle=/path/to/moodle    # 42 checks, text actions
php tools/verify_image.php    --moodle=/path/to/moodle    # 49 checks, image action
php tools/verify_forms.php    --moodle=/path/to/moodle    # 21 checks, admin forms
php tools/check_lang.php                                  # language string coverage
python3 tools/check_style.py                              # Moodle coding style
```

`verify_image.php` posts each request body it builds to OpenRouter **with no API
key**. The endpoint validates the body before it authenticates, so HTTP 401 means
the request shape was accepted and HTTP 400 means it was rejected. Nothing is
generated and nothing is billed. Pass `--offline` to skip that section.

### Two things to know about the harness

- **Language strings resolve from the *installed* plugin**, not from this
  checkout, because `get_string()` reads Moodle's own string cache. Assertions
  that would depend on a string are therefore written language-independently. If
  you see `[[somestring]]` in harness output, that is why, and it is not a defect.
- **`tools/` is excluded from the release archive** via `.gitattributes`, so these
  scripts never ship to a Moodle site.

### What the harness cannot prove

Three things remain outside its reach, and they are the only ways to get from
"verified" to "proven in production":

1. **A real generation.** No request has ever been made with a real API key, for
   either text or images. The image request *shape* is validated against the live
   endpoint, but `/api/v1/chat/completions` checks authentication before it
   validates the body, so the text request shape cannot be checked that way.
2. **The install and upgrade path.** The plugin has not been installed into a
   Moodle, so `admin/cli/upgrade.php` registering the component and its cache
   definition is untested.
3. **The PHPUnit suite**, which needs the config changes described below.

The first two are one command each, after deploying:

```bash
# A real request. Asks before spending anything; --images adds one generation.
install -m 644 tools/smoke_test.php /tmp/smoke_test.php
runuser -u www-data -- php /tmp/smoke_test.php --moodle=/path/to/moodle/public
```

The copy is because `tools/` is absent from an installed plugin, and a repository
under `/root` cannot be read by the web server user at all. The script is
self-contained so one file is enough.

There is also the browser equivalent: sign in as an admin and open
`/ai/provider/n3xtopenrouter/test_connection.php`, which asks for
confirmation and then reports the model that answered.

## 5. Running the PHPUnit suite

The suite in `tests/` is standard Moodle PHPUnit, but running it requires changes
to the Moodle checkout's **`config.php`**, which is outside this plugin. Do this
on a development copy, never on a live site.

```php
// In config.php, BEFORE require_once(__DIR__ . '/lib/setup.php'):
$CFG->phpunit_prefix = 'phpu_';
$CFG->phpunit_dataroot = '/path/to/a/separate/phpu_moodledata';
```

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter aiprovider_n3xtopenrouter
```

`phpunit_dataroot` must **not** point at the live `dataroot`, and initialisation
drops and recreates every table with the `phpunit_prefix`. This is why the suite
was not executed against the production site, and why `tools/` exists.

CI runs the suite properly on every push; see
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

## 6. Upstream defects this plugin runs into

### Core raises PHP warnings while watermarking

`\core_ai\ai_image::draw_rounded_rectangle()` passes computed float values to
`imagefilledarc()` parameters typed `int`, which PHP 8.1+ reports:

```
Implicit conversion from float 19.5 to int loses precision
  in ai/classes/ai_image.php:175, 185, 198
```

This is core code, not plugin code, and it is cosmetic — the watermark is still
drawn. It is visible with developer debugging on. Reproduce with
`php tools/verify_image.php`, section 6. No workaround is applied here: silencing
core's warnings from a plugin would hide real ones.

### Vector output cannot be watermarked

Core's watermarker is raster-only. Models such as `recraft/recraft-v4-vector`
return SVG, which is stored unwatermarked with a developer notice rather than
failing the generation. If your site policy requires every AI image to carry a
watermark, do not offer the vector models.

### The bundled-PEAR shim

`classes/compat.php` prepends Moodle's bundled PEAR to `include_path`, because
some server images put a system PEAR ahead of Moodle's, and old PEAR versions
fatal on PHP 8 when called statically — which breaks the QuickForm-based settings
forms. It is a no-op where no system PEAR exists. Check with:

```bash
php -r 'echo get_include_path(), "\n";'
ls /usr/share/php/PEAR.php /usr/local/lib/php/PEAR.php 2>/dev/null
```

The shim lives inside the plugin, but it exists because of a condition outside it,
which is why it is recorded here.

## 7. Costs and rate limits

Text actions are billed per token; **image models are billed per image**. Both
are billed to your OpenRouter account, and nothing in Moodle caps spending.

The provider instance form carries Moodle's standard per-user and site-wide rate
limits. Set them before enabling image generation. Usage is visible under
**Site administration → AI → AI usage**, and per-request cost in OpenRouter's own
dashboard.
