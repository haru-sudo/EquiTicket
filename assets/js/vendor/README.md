# 同梱している外部ライブラリ

## jsqr.min.js — jsQR v1.4.0

- 用途: 受付画面（`assets/js/checkin.js`）でカメラ映像から QR コードを読み取る。
  ブラウザー標準の `BarcodeDetector` API は iOS Safari や Windows の Chrome では使えないため、
  対応していない環境ではこのライブラリで読み取る。
- 配布元: <https://github.com/cozmo/jsQR>（npm パッケージ `jsqr`）
- ライセンス: Apache License 2.0（全文は `jsqr-LICENSE.txt`）
- 取得したファイル: `jsqr@1.4.0` の `dist/jsQR.js`
  （npm tarball の sha1: `8efb8d0a7cc6863cb6d95116b9069123ce9eb2d1`）
- 圧縮: terser 5 で `--compress --mangle`

### 作り直す手順

```bash
npm pack jsqr@1.4.0
tar xzf jsqr-1.4.0.tgz
npx terser package/dist/jsQR.js --compress --mangle --output jsqr.min.js
# 先頭に版・ライセンス・出典のコメントを付け、さらに全体を
#   ( function () { var define, exports, module; …ここに圧縮後のコード… } )();
# で包んで差し替える（理由は下記）
```

### 即時関数で包んでいる理由

元のファイルは UMD ビルドで、読み込み先の環境を見て「AMD モジュール」「CommonJS」
「グローバル変数」のどれかに自分を登録します。WordPress では他のプラグインが
`define.amd`（RequireJS など）や `exports` を定義していることがあり、その場合
jsQR は `window.jsQR` を設定せずに終わってしまい、受付画面が「カメラ読み取りが
できませんでした」となります。そこで `define` / `exports` / `module` を隠した
即時関数で包み、必ずグローバル（`window.jsQR`）に入るようにしています。

プラグイン側では `wp_register_script( 'equiticket-jsqr', ... )` として登録し、
受付画面のスクリプトの依存として読み込みます。加えて、キャッシュ系プラグインの
JavaScript 結合などで読み込みに失敗した場合に備え、`assets/js/checkin.js` から
このファイルを直接読み込み直す経路も持たせています。

なお、このプラグイン本体のライセンスは GPL v2 以降（GPL-2.0-or-later、リポジトリ直下の `LICENSE`）です。
Apache License 2.0 は GPL v3 と両立するため、本体を「v2 以降」としています（「v2 のみ」にはしないでください）。
jsQR は Apache License 2.0 のまま同梱しており、再配布するときは `jsqr-LICENSE.txt` も
一緒に含めてください。
