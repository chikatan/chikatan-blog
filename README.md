# chikatan-blog

tantei.nagoya のブログ記事を、自動投稿するための「受け渡し箱」です。

## 仕組み
1. Claude が、記事を `posts/` に置く（`posts/index.json` に1行足す）。
2. さくらのサーバーが、毎日1回、`blog-poster/blog_poster.php` を実行する。
3. 公開日が来た記事を、microCMS に投稿する（投稿済みの記事は、二重に投稿しない）。

## 記事のフォルダ
```
posts/<記事ID>/
  post.json   {"title": "...", "mode": "draft" か "publish", "category": 任意, "eyecatch": "eyecatch.jpg"(任意)}
  body.html   本文（HTML）
  eyecatch.jpg  アイキャッチ画像（任意）
posts/index.json   [{"id": "<記事ID>", "publish_date": "2026-10-20"}, ...]
```

## さくらのサーバーでの設定
- `blog-poster` フォルダを、サイトのフォルダ（`chikatan3`）の中にアップロードする。
- コントロールパネルの「cron設定」で、毎日1回、`blog_poster.php` を実行する。
- 試すときは、`--draft`（すべて下書きで投稿）か、`--dry-run`（何も投稿せず確認だけ）を付ける。
- APIキーは、このフォルダには書かない。サイトの `api/blog-list.php` に入っているものを使う。
