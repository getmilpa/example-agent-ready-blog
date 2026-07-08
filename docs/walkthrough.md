# Walkthrough — eight files, in the order that makes the loop click

This is the guided tour of the repo. Read the files in this order, with these questions in
mind, and the loop — `plugin → capability → tool → verification → event → result` — stops
being a diagram and becomes something you could rebuild from memory.

Total reading: ~1000 lines. Nothing here is framework magic; every file is application code
you could have written, implementing contracts from four published packages.

## 1. `bin/blog.php` — the whole app is 18 lines

The entry point. Notice:

- It does exactly two things: `Kernel::boot()` and `Demo::run()`.
- The flags (`--auto-approve`, `--reject`) exist so CI can drive **both** outcomes of the
  human decision — the repo's own CI is an agent running the loop.
- There is no routing, no controller, no framework bootstrap file. If this feels too
  small, that's the point.

## 2. `src/App/Kernel.php` — boot order is the architecture

89 lines that orchestrate everything. Notice:

- The private constructor + `Kernel::boot()` factory: a kernel is not something you `new`
  halfway through a request.
- The order inside `boot()`: container → event dispatcher → **capability check** →
  ordered plugin boot → tool registry wiring. The capability check runs *before* any
  plugin's `boot()` — a missing dependency fails fast, not mid-request.
- Boot order is explicit (`StoragePlugin` → `BlogPlugin` → `AgentToolsPlugin`): providers
  boot before requirers.

## 3. `src/App/CapabilityGraph.php` — the "A provides / B requires" edge

52 lines. Notice:

- It reads each plugin's `#[PluginMetadata]` attribute via reflection and builds
  `CapabilityProvision` / `CapabilityRequirement` value objects from `milpa/core` — the
  published contract, not a local invention.
- Look at the three plugin classes' attributes: `StoragePlugin` **provides**
  `PostStorageInterface::class`; `BlogPlugin` and `AgentToolsPlugin` **require** it. Change
  one string and boot fails with a readable message — try it.
- Since `milpa/core` 0.3 this checker also exists in core itself
  (`Milpa\Services\CapabilityGraphChecker`) — it graduated from this example.

## 4. `src/Plugins/StoragePlugin/` — the humblest plugin is the load-bearing one

Notice:

- `JsonPostStorage` is a deliberately boring persistence class (a JSON file under `var/`).
- The plugin's only real job is `provides:` — registering the implementation under the
  `PostStorageInterface` capability so other plugins can require it without knowing it's
  JSON.
- No events, no tools. A plugin can be this small.

## 5. `src/Plugins/AgentToolsPlugin/BlogTools.php` — the agent surface

The three `#[Tool]` methods. Notice:

- `create_post` and `list_posts` are plain tools — no gate, no friction.
- `publish_post` (line 46) declares `confirm: true`. That single argument is what makes
  the registry intercept the first call and demand a `confirm_token` — the two-call
  choreography in the README's "What an agent sees" section falls out of this one flag.
- The tool method itself doesn't publish anything. It *requests verification* and returns
  `pending_verification`. Hold that thought for stop 6.

## 6. `src/Plugins/BlogPlugin/BlogPlugin.php` — the result arrives by event

The most important file for understanding what makes Milpa different. Notice:

- Line ~37: the plugin **subscribes** to `verification.granted`. When a human approves,
  the event lands here, and *this handler* — not the tool — flips the post to `published`
  and dispatches `post.published`.
- The state change is a *reaction to a verification event*, not a return value. An agent
  cannot force it by calling harder.
- `subjectFrom()` (line ~82) and its docblock: the `verification.granted` payload has two
  shapes in the wild, and the handler honestly supports both. This is what consuming a
  real contract — including its rough edges — looks like. (The payload shape got
  documented in `milpa/core` 0.3 because of exactly this friction.)

## 7. `tests/App/KernelLoopTest.php` + `.github/workflows/ci.yml` — test the promise

Notice:

- `KernelLoopTest` runs the loop end to end in-process: boot, create, publish-with-
  confirmation, grant, and asserts the post is published *because the event fired*.
- CI runs `bin/blog.php --auto-approve` (must print `PUBLISHED`) **and**
  `bin/blog.php --reject` (must print `still a draft`). It doesn't just test classes —
  it tests the thesis, both branches of it.

## 8. `bin/mcp-server.php` — the same registry, over MCP stdio

~80 lines. Notice:

- Same two ingredients as `bin/blog.php`: `Kernel::boot()` and the registry off it. Only
  what wraps them changes — instead of `Demo` narrating to a terminal, it's
  `milpa/mcp-server`'s `JsonRpcService`, looping over stdin/stdout instead of a prompt.
- STDOUT is protocol-only: one JSON-RPC 2.0 message per line, `fflush()`ed after every
  write. Human-readable status goes to STDERR, so nothing pollutes the wire — the same
  discipline every stdio MCP server needs.
- Notification handling lives in the transport, not the protocol layer: a request with no
  `id` member (e.g. `notifications/initialized`) gets no response line at all —
  `JsonRpcService` itself doesn't decide this, `array_key_exists('id', $request)` here does.
- `milpa/tool-runtime`'s `mcp` channel policy requires a non-empty `principal`
  (`require_auth: true`) even with zero real auth wired — miss that and every `tools/call`
  comes back silently `FORBIDDEN`. This transport passes a fixed `'stdio'` principal to mean
  exactly what it says: process-level trust, nothing more.
- `tests/App/McpStdioTest.php` is the one test in this repo that spawns a real subprocess:
  `proc_open` against this exact file, driving the full grant *and* reject choreography
  through real OS pipes — the transport contract, proven, not asserted.

## Where to go next

- Swap `JsonPostStorage` for your own storage: implement `PostStorageInterface`, change
  one `provides:`, and nothing else moves — that's the capability seam paying rent.
- Add a fourth tool to `BlogTools` with `confirm: true` and watch it inherit the whole
  verification choreography for free.
- Point a second MCP host at `bin/mcp-server.php` alongside the first — the registry, the
  confirm gate, and the verification seam don't know or care how many transports are
  watching; that's what "transport-agnostic" bought you at stop 5.
