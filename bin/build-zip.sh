#!/usr/bin/env bash
#
# WordPress にアップロードできる ZIP を dist/ に作る。
# 使い方: bash bin/build-zip.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="equiticket"
dist="${root}/dist"
stage="${dist}/${slug}"

rm -rf "${stage}" "${dist}/${slug}.zip"
mkdir -p "${stage}"

# プラグインとして必要なファイルだけを集める（テスト・ドキュメント・Git 管理下のメタは除く）。
cp "${root}/equiticket.php" "${root}/uninstall.php" "${root}/README.md" "${root}/LICENSE" "${stage}/"
cp -R "${root}/includes" "${root}/assets" "${stage}/"

cd "${dist}"
zip -rq "${slug}.zip" "${slug}"
rm -rf "${stage}"

echo "created: ${dist}/${slug}.zip"
