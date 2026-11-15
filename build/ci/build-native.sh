#!/usr/bin/env bash
# RyzeMP consumes pinned OFFICIAL RyzeVanillaGen binaries, not Go source from a PR.
# The Egg downloads the exact same release and checks its immutable SHA-256.
set -euo pipefail
cd "$(dirname "$0")/../.."

GO_TAG='v0.1.0'
TARGETS="${NATIVE_TARGETS:-linux/amd64}"
BASE="https://github.com/RyzeCompany/RyzeVanillaGen/releases/download/${GO_TAG}"
mkdir -p native/vanillagen/bin
for target in $TARGETS; do
  name="vanillagen-${target//\//-}"
  case "$target" in
    linux/amd64) sha='3cddcb4be9bd17eaa3225be5d167fb63328566c252a8c4fe7b1e3b9e7d716dc7' ;;
    linux/arm64) sha='481786abfb8d80a3fa4ba895dd128181599e78e17c141e8c3b10e06fd998f349' ;;
    windows/amd64) name="${name}.exe"; sha='bc9788f8565b9b40707d83d257dee681e6f48f418e47fa431141f59a113a2eeb' ;;
    darwin/amd64) sha='f502f2ecfca03006b90c6283fbf3de0f0d82e8125d63e964d6f5f92d6f3bfbd5' ;;
    darwin/arm64) sha='b049afdaa16d635bcfb057bf7ca2f47247ee161ff61b52b26cbe8165da8a7bdd' ;;
    *) echo "Unsupported RyzeVanillaGen platform: $target" >&2; exit 1 ;;
  esac
  dest="native/vanillagen/bin/${name}"
  echo "Downloading official RyzeVanillaGen ${GO_TAG}: ${name}"
  curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
    "${BASE}/${name}" --output "${dest}"
  echo "${sha}  ${dest}" | sha256sum -c -
  chmod 755 "${dest}"
  if [[ "$target" == linux/amd64 ]] && [[ "$(uname -m)" == x86_64 ]] && [[ "$(uname -s)" == Linux ]]; then
    [[ "$("./${dest}" -version)" == "vanillagen ${GO_TAG} protocol 7 algorithm 4" ]] || {
      echo "RyzeVanillaGen protocol or algorithm mismatch" >&2; exit 1;
    }
  fi
done
