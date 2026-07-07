<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> · milpa example blog</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0/dist/milpa-tokens.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0/primitives/milpa-primitives.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0/components/milpa-components.css">
  <style>
    body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-display); }
    .blog { max-width: 44rem; margin-inline: auto; padding: var(--space-8) var(--space-4); }
    .blog__footer { margin-top: var(--space-8); color: var(--text-muted); font-size: var(--text-sm); }
    .blog__footer a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
    article + article { margin-top: var(--space-6); }
  </style>
</head>
<body>
  <main class="blog mui-prose">
    <p><span class="mui-badge">milpa</span> <span class="mui-badge mui-badge--accent">example blog</span></p>
    <?php require __DIR__ . '/' . $template . '.php'; ?>
    <p class="blog__footer">
      Posts get here through the loop: <code>tool → verification → event → result</code>.
      Run <code>php bin/blog.php</code> to publish one ·
      <a href="https://github.com/getmilpa/example-agent-ready-blog">source</a>
    </p>
  </main>
</body>
</html>
