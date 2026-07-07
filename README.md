<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Example: Agent-Ready Blog

> The Milpa loop, live: `plugin → capability → tool → verification → event → result` —
> as a tiny agent-ready blog you can run in two commands.

[![CI](https://github.com/getmilpa/example-agent-ready-blog/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/example-agent-ready-blog/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/example-agent-ready-blog.svg)](https://packagist.org/packages/milpa/example-agent-ready-blog)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

This isn't a tutorial about the loop — it's the loop, wired end to end, in ~940 lines of
application code — of which ~440 implement every framework seam (container, events,
capability graph, router, kernel); the seams table below is accurate to the line. Three
plugins (provides, requires, exposes), three agent-callable tools, one human-verification
gate, and a read-only blog that only ever changes because the loop ran.

## Quickstart

```bash
composer create-project milpa/example-agent-ready-blog blog
cd blog
php bin/blog.php
```

You'll be asked to approve or reject a publish request interactively. Here's a real run
(`a` typed at the prompt):

```
milpa · example-agent-ready-blog — the loop, live
plugin → capability → tool → verification → event → result

✔ Capability graph: StoragePlugin provides PostStorage → BlogPlugin requires it
✔ 3 plugins booted · tools: create_post, human_verify, list_posts, publish_post

→ create_post("Hello Milpa") … draft #1 created (not mutating-gated: no friction)
→ publish_post(#1) … INTERCEPTED by the registry confirm gate → confirm_token 867c9ca7…
  ⚡ verification.requested
→ token redeemed … the tool ran and asked the VERIFICATION seam (status: pending_verification)
? An agent wants to publish post #1 — [a]pprove / [r]eject: a
  ⚡ verification.granted
  ⚡ post.published (id 1)
✔ post #1 is now PUBLISHED — the result arrived via event, handled by BlogPlugin

See it: php -S localhost:8080 -t public   →   http://localhost:8080
```

Now look at it:

```bash
php -S localhost:8080 -t public
```

Prefer non-interactive? `php bin/blog.php --auto-approve` and `php bin/blog.php --reject`
drive both paths without a prompt — that's exactly what this repo's own CI runs as its
smoke test.

## The loop, stage by stage

Every stage below is a real contract from a published package, not an abstraction invented
for this example.

| Stage | What runs | Published contract |
|---|---|---|
| **plugin** | `Kernel::boot()` instantiates `StoragePlugin`, `BlogPlugin`, `AgentToolsPlugin` | `Milpa\Interfaces\Plugin\PluginInterface` + `#[Milpa\Attributes\PluginMetadata]` (`milpa/core`) |
| **capability** | `CapabilityGraph::check()` reads each plugin's `#[PluginMetadata]`, builds the VOs, and fails *before* boot if a `requires` has no `provides` | `Milpa\ValueObjects\Capability\{CapabilityProvision,CapabilityRequirement}` (`milpa/core`) |
| **tool** | `AgentToolsPlugin::registerTools()` scans `BlogTools`'s three `#[Tool]` methods into the registry | `Milpa\ToolRuntime\{Attributes\Tool,Attributes\Param,ToolScanner,ToolRegistry}` + `Milpa\Interfaces\Tooling\ToolProviderInterface` (`milpa/tool-runtime` on `milpa/core`) |
| **verification** | `publish_post` asks `HumanVerifier::verify()`; a human approves or rejects at the terminal prompt | `Milpa\Interfaces\Verification\VerifierInterface` (`milpa/core`) + `Milpa\ToolRuntime\Verification\HumanVerifier` (`milpa/tool-runtime`) |
| **event** | Every step above fires through one shared dispatcher — `verification.requested` / `verification.granted` / `verification.rejected` / `post.published` — printed live by name | `Milpa\Interfaces\Event\MilpaEventDispatcherInterface` (`milpa/core`), implemented here by `App\EventDispatcher` |
| **result** | `BlogPlugin`'s `verification.granted` handler flips the post to `published` and dispatches `post.published` — the result arrives *via event*, not a return value | `src/Plugins/BlogPlugin/BlogPlugin.php` (this repo) |

## What implements what

The three published packages define the seams; this repo implements the smallest possible
host around them — on purpose, so you can read every line:

| Unit | Lines | Implements | Notes |
|---|---|---|---|
| `App\Container` | 146 | `Milpa\Interfaces\Di\DIContainerInterface` (`milpa/core`) | Explicit `registerService()` plus honest constructor autowiring — exactly what the published docblocks promise, no more. |
| `App\EventDispatcher` | 82 | `Milpa\Interfaces\Event\MilpaEventDispatcherInterface` (`milpa/core`) | Priority ordering, the documented wildcard grammar (`*` matches exactly one dot-segment), and handler error isolation. |
| `App\CapabilityGraph` | 52 | — (consumes core's `CapabilityProvision`/`CapabilityRequirement` VOs) | The "A provides / B requires" edge of the loop, checked before any plugin boots. |
| `App\Http\Router` | 71 | `Milpa\Http\Routing\RouterInterface` (`milpa/http`) | Exact segments plus single-segment `{placeholder}`s; never throws, never returns null — `RouteResult` carries the outcome. |
| `App\Kernel` | 89 | — (orchestrates the four above) | Container → dispatcher → capability check → ordered plugin boot → tool registry wiring. A miniature of a real Milpa host. |

You can implement the seams in an afternoon — this repo is the proof.

## What this example is NOT

- **Not production.** Storage is a plain JSON file (`var/posts.json`), there's no auth, and
  `App\Container` has no compiled/cached resolution — it's a from-scratch DI container that
  happens to satisfy the published interface.
- **Not a template to fork for a real blog.** It's a template for understanding the loop.
- **Mutations enter via tools, not HTTP** — that's the point. The web view
  (`php -S localhost:8080 -t public`) is read-only by design: publishing a post always goes
  through `create_post` → `publish_post` → human verification, whether the caller is a human
  running `bin/blog.php` or an agent calling the same tools — e.g. over MCP; not wired in
  this example, the tools are transport-agnostic.

## The family

This example consumes three published Milpa packages, unmodified, from Packagist:

- [`milpa/core`](https://packagist.org/packages/milpa/core) — the contracts core ·
  [API reference](https://getmilpa.github.io/core/)
- [`milpa/http`](https://packagist.org/packages/milpa/http) — PSR-15-native routing
  contracts · [API reference](https://getmilpa.github.io/http/)
- [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) — the
  agent-tool-execution engine · [API reference](https://getmilpa.github.io/tool-runtime/)

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=example-agent-ready-blog)**.
