# tools

Verification scripts for this plugin. They exercise the code against a real
Moodle bootstrap **without PHPUnit**, which is what you need when the target site
has no test database initialised.

They are read-only with respect to Moodle: they construct objects and inspect what
would be sent, and never write to the database or the file storage. The only files
they create are temporary ones, in the system temp directory.

> **On a production site, run them as the user that owns moodledata**, normally
> `www-data`. Any script that bootstraps Moodle can populate its caches, and a cache
> file created by root is one the web server may not be able to rewrite. This is why
> the tools that must run against an installed site are self-contained: copy the one
> file somewhere that user can read, then `runuser -u www-data -- php ...`.

`tools/` is excluded from the release archive by `.gitattributes`, so nothing here
ships to a Moodle site.

| Script | What it does |
|---|---|
| `verify_settings.php` | 42 checks on the text actions: action settings actually reaching the request, defaults, temperature clamping, response handling, headers, the live catalogue, seeded instance defaults |
| `verify_image.php` | 49 checks on image generation: parameter mapping, per-model capability filtering, response parsing, watermarking, and validation of built request bodies against the live API |
| `verify_forms.php` | 21 checks on the admin UI: the QuickForm element types the plugin relies on, the provider-instance hook, and each action form rendering to HTML with the right fields |
| `check_lang.php` | Every language string is defined, every definition is used, and the file stays alphabetical |
| `check_style.py` | Moodle coding style: line length, whitespace, GPL headers, and identifiers that must stay removed |
| `verify_installed.php` | Checks an *installed* copy through Moodle's own autoloader: registered version, cache definition, both catalogues, instances and whether their credentials survived, and every form rendering with no unresolved strings. Spends nothing |
| `smoke_test.php` | Sends real, billed requests: one text generation, and one image with `--images`. Asks first |
| `verify_untouched.sh` | Compares an installed copy against a commit, to prove a site was not modified or that a deployment matched |

## Running them

```bash
php tools/verify_settings.php --moodle=/path/to/moodle
php tools/verify_image.php    --moodle=/path/to/moodle
php tools/verify_image.php    --moodle=/path/to/moodle --offline   # no network
php tools/verify_forms.php    --moodle=/path/to/moodle
php tools/check_lang.php
python3 tools/check_style.py
tools/verify_untouched.sh /path/to/moodle/ai/provider/n3xtopenrouter HEAD

# Against an installed copy. Run as the owner of moodledata; copy them out first,
# since this directory is absent from an installed plugin.
install -m 644 tools/verify_installed.php /tmp/vi.php
runuser -u www-data -- php /tmp/vi.php --moodle=/path/to/moodle/public
```

`--moodle` may be omitted when the plugin is installed inside the Moodle tree you
want to use; it is then found automatically. `MOODLE_ROOT` works as an
environment variable equivalent.

Each script exits non-zero on failure, so they compose in a shell loop or a hook.

See [`../docs/OPERATIONS.md`](../docs/OPERATIONS.md) for what the harness cannot
cover, and why the PHPUnit suite needs changes outside the plugin to run.
