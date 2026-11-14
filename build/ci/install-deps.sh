#!/bin/bash
# Clones our forks of the protocol/data libraries and installs Composer dependencies with them.
# Usage: build/ci/install-deps.sh [--no-dev]
#
# Branches come from CI variables so a protocol update only needs the variables changed (or the defaults here).
# The projects are public and cloned anonymously. If they ever become private, set DEPS_USE_JOB_TOKEN=1 and add
# this project to each one's CI/CD job token allowlist.
set -euo pipefail

DEV_FLAG="${1:-}"
DEPS_DIR="$(pwd)/.ci-deps"
GROUP_URL="${DEPS_GROUP_URL:-https://gitlab.com/VanillaPocketMine}"
if [ "${DEPS_USE_JOB_TOKEN:-0}" = "1" ] && [ -n "${CI_JOB_TOKEN:-}" ]; then
	GROUP_URL="${GROUP_URL/https:\/\//https://gitlab-ci-token:${CI_JOB_TOKEN}@}"
fi

declare -A BRANCHES=(
	[BedrockProtocol]="${BEDROCK_PROTOCOL_BRANCH:-bedrock-26.60}"
	[BedrockData]="${BEDROCK_DATA_BRANCH:-bedrock-26.60}"
	[BedrockBlockUpgradeSchema]="${BLOCK_UPGRADE_SCHEMA_BRANCH:-bedrock-26.60}"
	[BedrockItemUpgradeSchema]="${ITEM_UPGRADE_SCHEMA_BRANCH:-master}"
	[RakLib]="${RAKLIB_BRANCH:-bedrock-26.60}"
)

rm -rf "$DEPS_DIR"
mkdir -p "$DEPS_DIR"
for repo in "${!BRANCHES[@]}"; do
	branch="${BRANCHES[$repo]}"
	echo "Cloning $repo ($branch)"
	git clone --quiet --depth 1 --branch "$branch" "$GROUP_URL/$repo.git" "$DEPS_DIR/$repo"
	echo "  -> $(git -C "$DEPS_DIR/$repo" rev-parse --short HEAD)"
done

php build/ci/use-ci-deps.php composer.json "$DEPS_DIR"

export COMPOSER_NO_INTERACTION=1
#a partial update installs everything else exactly as locked
# shellcheck disable=SC2086 # DEV_FLAG is empty or a single flag
composer update --prefer-dist --no-progress $DEV_FLAG \
	pocketmine/bedrock-protocol pocketmine/bedrock-data \
	pocketmine/bedrock-block-upgrade-schema pocketmine/bedrock-item-upgrade-schema pocketmine/raklib

installed="$(composer show --format=json pocketmine/bedrock-protocol | php -r 'echo implode(",", json_decode(stream_get_contents(STDIN), true)["versions"]);')"
echo "bedrock-protocol installed: $installed"
case "$installed" in
	dev-*) ;;
	*) echo "bedrock-protocol does not come from our clone" >&2; exit 1 ;;
esac

#composer.json/lock were rewritten only for this job; keep git diff clean for the codegen check
git checkout -- composer.json composer.lock
