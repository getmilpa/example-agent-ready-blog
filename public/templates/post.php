<article>
  <h1><?= htmlspecialchars($post->title) ?></h1>
  <p><small>published <?= htmlspecialchars((string) $post->publishedAt) ?></small></p>
  <p><?= nl2br(htmlspecialchars($post->body)) ?></p>
  <p><a href="/">← all posts</a></p>
</article>
