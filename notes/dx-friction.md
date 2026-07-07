# DX friction log

Friction found while consuming the published packages (`milpa/core`, `milpa/http`,
`milpa/tool-runtime`) from Packagist, building this example end to end. Reported upstream.

## Routing (`milpa/http`) + read-only view

- `milpa/http`'s routing surface (`Milpa\Http\Routing\{RouteResult,MatchStatus,Route,RouterInterface}`,
  `Milpa\Http\HttpMethod`) matched the task brief's assumptions **exactly** — `RouteResult::$status`/`$route`,
  `MatchStatus::NOT_FOUND`/`METHOD_NOT_ALLOWED`, `Route::allows(HttpMethod)`/`$methods`, all present
  and typed as documented. Zero adaptation needed between the brief's draft test/impl and what
  `vendor/milpa/http/src/Routing/*.php` actually ships. Good contract hygiene — worth naming as the
  positive counter-example to the frictions logged for `milpa/core`/`milpa/tool-runtime` below.
- Not a `milpa/http` issue, but a real gotcha in **this app's own** `StoragePlugin` default
  (`getcwd() . '/var/posts.json'`, see the StoragePlugin/BlogPlugin/CapabilityGraph section
  below) combined with PHP's built-in server: `php -S
  -t public` **chdir()s into the docroot** for every request, so `getcwd()` inside `public/index.php`
  resolves to `public/`, not the project root — `Kernel::boot()` with no argument would silently read
  and write a *different* `posts.json` than `bin/blog.php` (run via CLI from the project root) does.
  Symptom: the home page renders "No published posts yet" even right after `php bin/blog.php
  --auto-approve` succeeded. Fixed by passing `Kernel::boot(__DIR__ . '/../var/posts.json')` explicitly
  in `public/index.php` — cwd-independent by construction. Worth flagging for anyone building a second
  entry point (web + CLI) against a `Kernel::boot(?string $storageFile = null)`-shaped API: the
  "convenient" no-arg default is a footgun the moment two processes with different cwds share storage.

## AgentToolsPlugin + Kernel (full loop)

- `VerificationRequest`'s primary constructor (`vendor/milpa/core/src/ValueObjects/Verification/VerificationRequest.php`)
  leaves `id` as `null` if not passed explicitly — it does **not** autogenerate one. Only the
  separate `VerificationRequest::withGeneratedId()` factory mints one, via the same
  `UuidGenerator` trait other core VOs use. This is documented in the class docblock ("`id` is
  an optional correlation id") but easy to miss: a caller who naively does
  `new VerificationRequest(subject: ...)` expecting a correlation id "for free" (as the task
  brief's first draft did) silently gets `null` and loses the request/resolve correlation.
  `BlogTools::publishPost()` now uses `withGeneratedId()` explicitly.
- `HumanVerifier::grant()`/`reject()`/`verify()` (`vendor/milpa/tool-runtime/src/Verification/HumanVerifier.php`)
  dispatch `verification.requested` / `verification.granted` / `verification.rejected` with
  payload shape `['event' => VerificationRequestedEvent|VerificationGrantedEvent|VerificationRejectedEvent]`
  — an event *object*, not a flat `['subject' => ...]` array. The subject is only reachable via
  `$payload['event']->getRequest()->subject`. The tool-runtime README documents *that* these
  events get dispatched but never documents *what shape the payload array carries* — a
  consumer has to go read `HumanVerifier`'s source to find out. `BlogPlugin`'s handler now
  accepts both the flat shape (used by its own minimal unit test) and the real event-object
  shape, via a `subjectFrom()` helper, and documents why in its docblock.
- `Milpa\ToolRuntime\ToolRegistry::getTools()` (`vendor/milpa/tool-runtime/src/ToolRegistry.php`)
  returns `list<array{name: string, description: string, inputSchema: array}>` — plain
  associative arrays — not a list of `ToolDefinition` objects, even though `ToolDefinition`
  (same package) is itself an object with a public readonly `$name` property and is what's
  stored internally per tool. Code that assumes `getTools()` exposes `->name` (an easy
  assumption given `ToolDefinition` exists and looks like the natural return type) fails
  silently: PHP resolves `$arrayItem->name` to `null` with a warning rather than a fatal
  error, so a naive `array_map(fn ($t) => $t->name, ...)` degrades to a list of nulls instead
  of erroring loudly. Discovered because `KernelLoopTest::testBootRegistersTheFourTools` (as
  originally drafted) did exactly this and the assertion failure showed `[null, null, null,
  null]` instead of a type error — worth a `list<ToolDefinition>` return type or a rename to
  `getToolSummaries()`/similar to make the shape unambiguous at the call site.
- Publishing a post through the registry takes **3 calls end to end**, not the 2 you'd expect
  from a single `confirm: true` tool: `publish_post` is registered with
  `requiresConfirmation: true`, so the `ToolRegistry`'s generic confirm-token gate intercepts
  the first call and returns a `confirm_token`; redeeming it (call 2) invokes
  `BlogTools::publishPost()`, which itself asks the D8 human-verification seam
  (`HumanVerifier::verify()`) and returns `pending_verification`; the human's `grant()`/
  `reject()` closes the loop (call 3). Two independent gates — the registry's mutating-tool
  confirm-token gate, and the D8 verification seam — are stacked, and nothing in the
  tool-runtime docs flags that a `confirm: true` tool which also calls `verify()` internally
  produces this compound choreography. `Demo.php` narrates it explicitly (`INTERCEPTED by the
  registry confirm gate` → `token redeemed … asked the VERIFICATION seam`) precisely because it
  wasn't obvious going in. Learned live building this example — relevant input for
  tool-runtime 0.2's pending double-gate decision (`requiresConfirmation: false` with
  `handle()` owning its own two-phase protocol).

## StoragePlugin / BlogPlugin / CapabilityGraph

- `milpa/core` publishes `CapabilityProvision`/`CapabilityRequirement` (the VOs) and
  `PluginsManagerInterface` (discovery + boot), but ships no capability-graph checker that
  actually consumes those VOs to fail fast when a `requires` has no `provides`. We had to
  hand-roll `CapabilityGraph::check()` in application code — reflecting `#[PluginMetadata]`,
  building the VOs, and diffing `provides` against `requires` ourselves. If this is meant to
  be a reusable "provides/requires must be satisfied before boot" guarantee of the framework
  (it reads like one from the VO docblocks), it belongs in core, not in every consuming app.
- `CapabilityProvision`/`CapabilityRequirement`'s primary constructors do **not** validate
  (e.g. `contractVersion` isn't checked against semver) — only the `fromArray()` factories
  validate. Easy to construct an invalid VO by hand (as `CapabilityGraph` does, passing a
  literal `'1.0.0'`) without any error surfacing. Not a blocker, just surprising given how
  much validation `fromArray()` does.
- `PluginMetadata::$type` (e.g. `'Service'` used here) has no documented enum or list of
  valid values anywhere in `milpa/core` — it reads like a convention, not a contract.

## Container / EventDispatcher contract fidelity

- `DIContainerInterface`'s docblocks PROMISE auto-resolution/autowiring semantics on
  `get()`/`has()` — every implementation is forced to ship an autowirer to be
  contract-honest; consider softening to "MAY auto-resolve" (or extracting an
  `AutowiringContainerInterface`) in core 0.3.
- `MilpaEventDispatcherInterface::dispatch()`'s `$async` parameter is documented only as "If
  true, dispatch via queue for deferred execution" — with no `MAY`/`MUST` qualifier for hosts
  that have no queue at all. Contrast with the same docblock's error-isolation paragraph, which
  is explicit ("one failing listener MUST NOT abort the dispatch … An implementation that needs
  fail-fast semantics must document the deviation"). A queue-less implementation (like this
  example's `App\EventDispatcher`) has no documented fallback to point to: is dispatching
  synchronously a conformant degradation, or a deviation that must be flagged? We treated it as
  the latter and documented it in the class docblock, but the contract itself doesn't say which
  is expected. Worth closing in core: either "MAY dispatch synchronously if no queue is
  configured" or "MUST dispatch via queue, and MUST fail loudly if none is wired."
