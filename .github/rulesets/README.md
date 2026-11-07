# main を勝手にマージさせないための GitHub の設定

ファイルを置いただけでは、GitHub はマージを止めてくれません。
次の設定を、リポジトリの管理者が **1 回だけ** GitHub の画面で行ってください。

## 1. ルールセットを読み込む

1. このフォルダの [`protect-main.json`](protect-main.json) をダウンロードする
   （ファイルを開いて右上の「Download raw file」ボタン）。
2. リポジトリの **Settings → Rules → Rulesets** を開く。
3. **New ruleset → Import a ruleset** を押し、ダウンロードした JSON を選ぶ。
4. 内容を確認して **Create** を押す。

読み込むと、`main`（既定のブランチ）に次のルールがかかります。

| ルール | 意味 |
| :-- | :-- |
| PR が必須 | `main` に直接プッシュできない |
| 承認 1 件・CODEOWNERS（@haru-sudo）のレビューが必須 | メンテナーが見ていない PR はマージできない |
| 最後のプッシュのあとに承認が必要 / 新しいプッシュで承認を取り消す | 承認後にこっそり変更を足せない |
| レビューのコメントをすべて解決 | 未解決の指摘があるとマージできない |
| CI の `test` が通ることが必須 | テストが落ちている PR はマージできない |
| 強制プッシュ・ブランチ削除を禁止 | 履歴を書き換えられない |
| 例外: 管理者（Repository admin）は PR 上でのみルールを迂回できる | 1 人で開発していて承認者がいないとき用（下を参照） |

### 1 人で開発している場合

GitHub では、自分が作った PR を自分で承認できません。
承認者がいないときは、PR のマージボタンの下にある
**「Merge without waiting for requirements to be met (bypass rules)」** にチェックを入れてマージします。
これは管理者が画面で明示的に選んだときだけできる操作で、`main` への直接プッシュは管理者でもできません。

一緒にレビューしてくれる人がいるなら、`protect-main.json` の `bypass_actors` を空（`[]`）にすると、
管理者も含めて必ず承認が必要になります。

## 2. 自動マージを切る

**Settings → General → Pull Requests** で、次のようにします。

- [ ] **Allow auto-merge** … チェックを外す（条件がそろったら勝手にマージされる機能を使えなくする）
- [ ] **Allow rebase merging** … チェックを外す（マージ方法を squash と merge だけにする）

## 3. 外部の人の PR で CI を勝手に動かさない

**Settings → Actions → General → Fork pull request workflows from outside collaborators** で
**Require approval for all external contributors** を選びます。
初めての人の PR でワークフローが動く前に、メンテナーの承認が必要になります。

## AI（Claude など）に作業を頼むとき

- AI には `main` 以外のブランチで作業させ、PR の作成までにとどめます（[`CLAUDE.md`](../../CLAUDE.md) にも書いてあります）。
- AI が使う GitHub の権限が管理者と同じ場合、上の「管理者は迂回できる」設定は AI にも効きます。
  AI が絶対にマージできないようにしたい場合は、`bypass_actors` を空にして、承認できる人を別に用意してください。
