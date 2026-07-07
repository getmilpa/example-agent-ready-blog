<h1>Milpa example blog</h1>
<?php if (!empty($notFound)): ?>
  <p>Nothing here. <a href="/">Back home</a>.</p>
<?php elseif ($posts === []): ?>
  <p>No published posts yet — run <code>php bin/blog.php</code> and approve the publication.</p>
<?php else: ?>
  <?php foreach ($posts as $post): ?>
    <article>
      <h2><a href="/post/<?= $post->id ?>"><?= htmlspecialchars($post->title) ?></a></h2>
      <p><?= htmlspecialchars(mb_strimwidth($post->body, 0, 140, '…')) ?></p>
      <p><small>published <?= htmlspecialchars((string) $post->publishedAt) ?></small></p>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
