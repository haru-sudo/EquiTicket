#!/usr/bin/env bash
#
# docs/manual/manual.html から PDF の運用マニュアルを作る。
#
# 使い方:
#   bash bin/build-manual.sh            # PDF だけ作り直す
#   bash bin/build-manual.sh --screens  # 画面例を撮り直してから PDF を作る
#
# 必要なもの:
#   - node と playwright（Chromium）。すでに Chromium がある環境では
#     EQUITICKET_CHROMIUM=/path/to/chrome で実行ファイルを指定できる
#   - --screens のときは php も必要（tools/manual/ でプラグインの描画処理を実際に動かす）
#   - python3 と PyMuPDF があれば、もくじにページ番号を入れ、PDF にしおりを付ける。
#     ない場合は番号としおりを省いて作る
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${root}/docs/manual/EquiTicket-運用マニュアル.pdf"
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

export EQUITICKET_MANUAL_SRC="${root}/docs/manual/manual.html"

if [ "${1:-}" = "--screens" ]; then
	php "${root}/tools/manual/render-screens.php" "${work}/screens"
	node "${root}/tools/manual/capture.js" "${work}/screens" "${root}/docs/manual/assets"
fi

# $1: 出力する PDF、$2: もくじのページ番号（JSON ファイル／placeholder／空）
render() {
	EQUITICKET_MANUAL_OUT="$1" \
	EQUITICKET_TOC_PAGES="$2" \
	EQUITICKET_TOC_ENTRIES_OUT="${work}/entries.json" \
	node -e '
const fs = require( "fs" );
const { chromium } = require( "playwright" );

( async () => {
	const launchOptions = { args: [ "--no-sandbox" ] };
	if ( process.env.EQUITICKET_CHROMIUM ) {
		launchOptions.executablePath = process.env.EQUITICKET_CHROMIUM;
	}

	let pages = null;
	if ( "placeholder" === process.env.EQUITICKET_TOC_PAGES ) {
		pages = "placeholder";
	} else if ( process.env.EQUITICKET_TOC_PAGES ) {
		pages = JSON.parse( fs.readFileSync( process.env.EQUITICKET_TOC_PAGES, "utf8" ) );
	}

	const browser = await chromium.launch( launchOptions );
	const page = await browser.newPage();

	await page.addInitScript( ( value ) => { window.EQUITICKET_TOC_PAGES = value; }, pages );
	await page.goto( "file://" + process.env.EQUITICKET_MANUAL_SRC, { waitUntil: "networkidle" } );

	const entries = await page.evaluate( () => window.EQUITICKET_TOC_ENTRIES || [] );
	fs.writeFileSync( process.env.EQUITICKET_TOC_ENTRIES_OUT, JSON.stringify( entries ) );

	await page.pdf( {
		path: process.env.EQUITICKET_MANUAL_OUT,
		format: "A4",
		printBackground: true,
		displayHeaderFooter: true,
		headerTemplate: "<div style=\"width:100%;font-size:8px;color:#7a838b;padding:0 14mm;font-family:IPAPGothic,sans-serif;\">EquiTicket 運用マニュアル</div>",
		footerTemplate: "<div style=\"width:100%;font-size:8px;color:#7a838b;padding:0 14mm;text-align:right;font-family:IPAPGothic,sans-serif;\"><span class=\"pageNumber\"></span> / <span class=\"totalPages\"></span></div>",
		margin: { top: "18mm", bottom: "16mm", left: "14mm", right: "14mm" }
	} );

	await browser.close();
} )();
'
}

if python3 -c 'import pymupdf' 2>/dev/null; then
	# 1 回目: 番号の桁だけ確保して PDF にし、もくじのリンク先のページを読み取る。
	render "${work}/pass1.pdf" placeholder

	python3 - "${work}/pass1.pdf" "${work}/entries.json" > "${work}/pages.json" <<'PY'
import json
import sys

import pymupdf

doc = pymupdf.open(sys.argv[1])
entries = json.load(open(sys.argv[2], encoding="utf-8"))

# もくじの各行は本文の見出しへのリンクになっている。本文には内部リンクがないので、
# 文書の先頭から順に集めたリンクの行き先が、そのままもくじの順番になる。
targets = []
for page in doc:
    links = [link for link in page.get_links() if "page" in link and link["page"] >= 0]
    links.sort(key=lambda link: (link["from"].y0, link["from"].x0))
    targets.extend(link["page"] + 1 for link in links)

if len(targets) != len(entries):
    sys.exit("もくじのリンク数（%d）と見出しの数（%d）が一致しません。" % (len(targets), len(entries)))

print(json.dumps(targets))
PY

	# 2 回目: 読み取ったページ番号を入れて作り直し、しおりを付ける。
	render "${work}/pass2.pdf" "${work}/pages.json"

	python3 - "${work}/pass1.pdf" "${work}/pass2.pdf" "${work}/entries.json" "${work}/pages.json" "${out}" <<'PY'
import json
import sys

import pymupdf

first, second, entries_path, pages_path, out = sys.argv[1:6]

if pymupdf.open(first).page_count != pymupdf.open(second).page_count:
    sys.exit("1 回目と 2 回目でページ数が変わりました。もくじの行が折り返していないか確認してください。")

entries = json.load(open(entries_path, encoding="utf-8"))
pages = json.load(open(pages_path, encoding="utf-8"))

doc = pymupdf.open(second)
doc.set_toc([[entry["level"], entry["title"], page] for entry, page in zip(entries, pages)])
doc.set_metadata({"title": "EquiTicket 運用マニュアル", "subject": "体験乗馬 整理券システム"})
doc.save(out, garbage=3, deflate=True)
PY
else
	echo "PyMuPDF がないため、もくじのページ番号としおりを省いて作ります。" >&2
	render "${out}" ""
fi

echo "created: ${out}"
