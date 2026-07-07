<?php

declare(strict_types=1);

namespace Milpa\ExampleBlog\Tests\App;

use Milpa\ExampleBlog\App\Http\Router;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\MatchStatus;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouterInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        return new Router(
            new Route('/', HttpMethod::GET, name: 'home'),
            new Route('/post/{id}', HttpMethod::GET, name: 'post.show'),
        );
    }

    public function testImplementsThePublishedContract(): void
    {
        $this->assertInstanceOf(RouterInterface::class, $this->router());
    }

    public function testMatchesStaticAndPlaceholderRoutes(): void
    {
        $r = $this->router();
        $home = $r->match(new ServerRequest('GET', '/'));
        $this->assertTrue($home->isMatched());
        $this->assertSame('home', $home->route->name);

        $show = $r->match(new ServerRequest('GET', '/post/42'));
        $this->assertTrue($show->isMatched());
        $this->assertSame('42', $show->parameter('id'));
    }

    public function testNotFoundAndMethodNotAllowedNeverThrow(): void
    {
        $r = $this->router();
        $this->assertSame(MatchStatus::NOT_FOUND, $r->match(new ServerRequest('GET', '/nope'))->status);
        $this->assertSame(MatchStatus::METHOD_NOT_ALLOWED, $r->match(new ServerRequest('POST', '/'))->status);
    }
}
