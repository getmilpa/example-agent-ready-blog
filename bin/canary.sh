#!/usr/bin/env bash
# Canary run: consume the milpa family from the LOCAL monorepo (path repos) and
# prove the loop still runs end to end. Used during wave development upstream.
set -euo pipefail
cd "$(dirname "$0")/.."
export COMPOSER=composer-dev.json
composer update --no-interaction --quiet
rm -f var/posts.json
php bin/blog.php --auto-approve | grep -q "PUBLISHED" && echo "canary GRANT ✓"
rm -f var/posts.json
php bin/blog.php --reject | grep -q "still a draft" && echo "canary REJECT ✓"
vendor/bin/phpunit --testsuite ExampleBlog 2>&1 | tail -1
