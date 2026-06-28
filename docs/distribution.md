# Distribution — namespace isolation, release workflow & ZIP

## Namespace isolation

If two WordPress plugins both bundle this library, PHP will fatal-error on duplicate class declarations the moment the second plugin loads. You must prefix the library's namespace before shipping.

The library ships a script that copies the source into your plugin with the namespace rewritten. Your release workflow runs it automatically on every tag push — no manual step, no committed generated files.

### Setup (once)

**1. Install as a dev dependency**

```bash
composer require --dev onstage2426/wp-hub-updater:^1.0
```

**2. Generate the prefixed source**

```bash
vendor/bin/wp-hub-updater MyPlugin [output-dir]
```

Writes `src/WpHubUpdater/` by default. Pass a second argument to change the output path:

```bash
vendor/bin/wp-hub-updater MyPlugin lib/WpHubUpdater
```

Every namespace occurrence is rewritten from `WpHubUpdater` to `MyPlugin\WpHubUpdater`.

If your project already autoloads `"MyPlugin\\": "src/"` nothing else is needed — `src/WpHubUpdater/` falls under that mapping automatically. If not, add an explicit entry and dump:

```json
"autoload": {
    "psr-4": {
        "MyPlugin\\WpHubUpdater\\": "src/WpHubUpdater/"
    }
}
```
```bash
composer dump-autoload
```

**3. Add to `.gitignore`**

```
src/WpHubUpdater/
```

**4. Your LSP picks it up immediately** — `src/WpHubUpdater/` is inside your source tree so your editor gets full type info, autocomplete, and go-to-definition under your prefix.

**5. Add the release workflow** — see [Complete workflow](#complete-workflow). The workflow re-runs the script on every tag push; you never do it manually.

```php
use MyPlugin\WpHubUpdater\Plugin\PluginUpdater;

PluginUpdater::build('https://github.com/your-org/your-plugin', __FILE__)
    ->setAccessToken('ghp_xxxx');
```

---

## Release workflow & ZIP

**Option A — Commit built files** (simplest): commit `vendor/` after `composer install --no-dev`. GitHub's auto-generated source ZIP includes everything. No workflow needed.

**Option B — GitHub Actions pipeline** (recommended): keep `vendor/` and build output out of git. The workflow installs dependencies, builds, stamps the version, creates a clean ZIP, and uploads it as a release asset. The updater automatically prefers the release asset over the source ZIP.

PHP-only with no Composer dependencies and no JS build? Option A is fine.

---

## Why the release must be created as a draft first

The asset ZIP is uploaded *after* a published release becomes visible. During that gap, the updater falls back to GitHub's auto-generated source ZIP — no `vendor/`, no build output. Publishing as a draft first ensures the asset is attached before the release goes public. GitHub's `/releases/latest` endpoint skips drafts entirely.

---

## Version stamping

The tag name is the authoritative version. The workflow stamps the `Version:` header before zipping so the installed version always matches the GitHub release.

- **Plugin** — stamp the docblock header in your main PHP file:
  ```sh
  version=$(echo "$GITHUB_REF_NAME" | sed 's/^v//')
  sed -i "s/^ \* Version:.*/ * Version: $version/" your-plugin.php
  ```
- **Theme** — stamp `style.css`:
  ```sh
  version=$(echo "$GITHUB_REF_NAME" | sed 's/^v//')
  sed -i "s/^Version:.*/Version: $version/" style.css
  ```

---

## Complete workflow

Save as `.github/workflows/release.yml`. Push a tag to trigger it:

```bash
git tag v1.2.0 && git push --tags
```

```yaml
on:
  push:
    tags: ["v*"]

jobs:
  release:
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      # Check out the tagged commit so all source files are available.
      - uses: actions/checkout@v4

      # Write the tag version into the Version header before anything is zipped,
      # so the installed plugin/theme always reports the correct version.
      # Plugin: the version lives in the PHP docblock of the main file.
      # Theme:  the version lives in style.css — swap the two sed lines below.
      - name: Stamp version from tag
        run: |
          version=$(echo "$GITHUB_REF_NAME" | sed 's/^v//')
          sed -i "s/^ \* Version:.*/ * Version: $version/" your-plugin.php
          # sed -i "s/^Version:.*/Version: $version/" style.css

      # Set up the PHP version the project requires.
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"

      # Install all dependencies including dev so vendor/bin/wp-hub-updater is available.
      - run: composer install --prefer-dist --no-progress

      # Copy the library source into src/WpHubUpdater/ with the namespace rewritten
      # to your prefix. This is what gets shipped — not the vendor package.
      - name: Prefix namespace
        run: vendor/bin/wp-hub-updater MyPlugin

      # Re-install without dev dependencies. This removes the library from vendor
      # and regenerates the autoloader cleanly — src/WpHubUpdater/ now carries
      # everything that was previously in vendor/onstage2426/wp-hub-updater/.
      - run: composer install --no-dev --optimize-autoloader

      # If you have no JS build, remove these two steps.
      - uses: actions/setup-node@v4
        with:
          node-version: 24
      - run: npm ci && npm run build

      # Bundle everything into a ZIP, excluding files that have no place in a
      # production install: git history, CI config, and JS tooling.
      - name: Create ZIP
        run: |
          zip -r your-project.zip . \
            --exclude=".git/*" --exclude=".github/*" \
            --exclude="node_modules/*" --exclude="*.config.js" \
            --exclude="package*.json"

      # Publish the release as a draft first so the ZIP is attached before the
      # release goes public. Without this, sites that update in the upload window
      # would receive GitHub's auto-generated source ZIP, which has no vendor/ or
      # build output.
      - name: Create draft release with asset
        run: |
          gh release create "$GITHUB_REF_NAME" your-project.zip \
            --draft \
            --generate-notes \
            --title "$GITHUB_REF_NAME"
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}

      # Asset is fully uploaded — remove the draft flag to make the release visible.
      # The updater only sees non-draft releases, so nothing was served during upload.
      - name: Publish release
        run: gh release edit "$GITHUB_REF_NAME" --draft=false
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Replace `your-plugin.php` / `style.css` and `your-project.zip` with your actual filenames.
