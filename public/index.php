<?php

declare(strict_types=1);

use Milpa\Data\RepositoryInterface;
use Milpa\ExampleBlog\App\Kernel;
use Milpa\ExampleBlog\Blog\Post;
use Milpa\Runtime\Http\Router;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\MatchStatus;
use Milpa\Http\Routing\Route;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

// Explicit, cwd-independent path: PHP's built-in server chdir()s into the
// docroot (-t public) for every request, so Kernel::boot()'s default
// getcwd() . '/var/posts.json' would resolve to public/var/posts.json here
// — a different file from the one bin/blog.php (run from the project root)
// writes to. Passing the path explicitly keeps both entry points reading
// and writing the same storage file regardless of the server's cwd.
$kernel = Kernel::boot(__DIR__ . '/../var/posts.json');
/** @var RepositoryInterface<Post> $storage */
$storage = $kernel->container()->get(RepositoryInterface::class);

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

$router = new Router(
    new Route('/', HttpMethod::GET, name: 'home'),
    new Route('/post/{id}', HttpMethod::GET, name: 'post.show'),
);

$result = $router->match($request);
$render = static function (string $template, array $vars): void {
    extract($vars);
    require __DIR__ . '/templates/layout.php';
};

if ($result->status === MatchStatus::METHOD_NOT_ALLOWED) {
    http_response_code(405);
    header('Allow: ' . implode(', ', array_map(static fn ($m) => $m->value, $result->allowedMethods)));
    $render('home', ['posts' => [], 'title' => 'Not found', 'notFound' => true]);

    return;
}

if (!$result->isMatched()) {
    http_response_code(404);
    $render('home', ['posts' => [], 'title' => 'Not found', 'notFound' => true]);

    return;
}

if ($result->route->name === 'post.show') {
    $post = $storage->find((int) $result->parameter('id'));
    if ($post === null || $post->status !== 'published') {
        http_response_code(404);
        $render('home', ['posts' => [], 'title' => 'Not found', 'notFound' => true]);

        return;
    }
    $render('post', ['post' => $post, 'title' => $post->title]);

    return;
}

$published = array_values(array_filter($storage->all(), static fn ($p) => $p->status === 'published'));
$render('home', ['posts' => $published, 'title' => 'Home', 'notFound' => false]);
