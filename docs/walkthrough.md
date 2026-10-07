# Walkthrough — fourteen stops, in the order that makes both loops click

This is the guided tour of the repo, in two parts.

**Part one** is the loop in the title — `plugin → capability → tool → verification → event →
result`: how a mutation gets gated. **Part two** is the question the first part leaves open: *who*
is allowed to answer the gate. An agent proposes; a person, with an identity of their own, disposes.

Nothing here is framework magic. Every file is application code you could have written, on
contracts from published packages.

---

## Part one — a mutation is gated

### 1. `bin/blog.php` — the whole app is 18 lines

The entry point. Notice:

- It does exactly two things: `Kernel::boot()` and `Demo::run()`.
- The flags (`--auto-approve`, `--reject`) exist so CI can drive **both** outcomes of the
  human decision.
- There is no routing, no controller, no framework bootstrap file. If this feels too
  small, that's the point.

### 2. `src/App/Kernel.php` — a thin bootstrap, and what it deliberately keeps

Notice:

- The private constructor + `Kernel::boot()` factory: a kernel is not something you `new`
  halfway through a request.
- Almost everything is delegated: the container, the event dispatcher, the capability check and
  the dependency-ordered plugin boot all belong to `milpa/runtime`'s `Kernel::boot()`, which this
  class calls with a plugin list and a small config bag.
- What it keeps is what the runtime does not own: the `HumanVerifier` and the `ToolRegistry`
  (both `milpa/tool-runtime`, the host's opt-in) — and one collaborator that is this
  application's own, the `SignedCallDesk`. Hold that name; it is stop 12.
- The storage backend is one config line (`'driver' => 'sqlite'`). Stop 4 is why that is enough.

### 3. The three `#[PluginMetadata]` attributes — the "A provides / B requires" edge

Open `src/Plugins/StoragePlugin/StoragePlugin.php`, `BlogPlugin/BlogPlugin.php` and
`AgentToolsPlugin/AgentToolsPlugin.php` and read only the attribute on top of each class. Notice:

- `StoragePlugin` **provides** `RepositoryInterface::class`; `BlogPlugin` and `AgentToolsPlugin`
  **require** it. That is the whole capability graph of this app.
- The runtime resolves that graph *before* any plugin's `boot()` runs: providers boot before
  requirers, and a `requires` with no `provides` stops the boot with a readable message instead of
  failing mid-request. Change one class name in a `requires:` and watch it — try it.

### 4. `src/Plugins/StoragePlugin/StoragePlugin.php` — the humblest plugin is the load-bearing one

Notice:

- It names **no backend**. It hands the app's `storage` config block to `milpa/data`'s
  `RepositoryFactory::fromConfig()` and registers whatever comes back under the
  `RepositoryInterface` capability.
- That is why switching the blog between a JSON file, SQLite, MySQL and memory is the one line in
  `Kernel::boot()` and touches no plugin, tool or test.
- No events, no tools. A plugin can be this small.

### 5. `src/Plugins/AgentToolsPlugin/BlogTools.php` — the agent surface

The three `#[Tool]` methods. Notice:

- `create_post` and `list_posts` are plain tools — no gate, no friction.
- `publish_post` (line 52) declares `confirm: true`. That single argument is what makes the
  runtime ask for consent before the tool runs, and *what consent consists of depends on where
  the call came from*: over MCP the registry returns a `confirm_token` to redeem (the two-call
  choreography in the README's "What an agent sees"); on a terminal it asks for a signature that
  names the call (what `bin/blog.php` shows).
- The tool method itself doesn't publish anything. It *requests verification* and returns
  `pending_verification`. Hold that thought for stop 6.

### 6. `src/Plugins/BlogPlugin/BlogPlugin.php` — the result arrives by event

The most important file for understanding what makes Milpa different. Notice:

- Line 38: the plugin **subscribes** to `verification.granted`. When a human approves,
  the event lands here, and *this handler* — not the tool — flips the post to `published`
  and dispatches `post.published`.
- The state change is a *reaction to a verification event*, not a return value. An agent
  cannot force it by calling harder.
- `subjectFrom()` (line 83) and its docblock: the `verification.granted` payload has two
  shapes in the wild, and the handler honestly supports both. This is what consuming a
  real contract — including its rough edges — looks like.

### 7. `tests/App/KernelLoopTest.php` + `.github/workflows/ci.yml` — test the promise

Notice:

- `KernelLoopTest` runs the loop end to end in-process: boot, create, the refusal without a
  signature, the publish with one, the grant — and asserts the post is published *because the
  event fired*.
- CI runs `bin/blog.php --auto-approve` (must print `PUBLISHED`) **and**
  `bin/blog.php --reject` (must print `still a draft`). It doesn't just test classes —
  it tests the thesis, both branches of it.

### 8. `bin/mcp-server.php` — the same registry, over MCP stdio

About sixty lines. Notice:

- Same two ingredients as `bin/blog.php`: `Kernel::boot()` and the registry off it. Only
  what wraps them changes — `milpa/mcp-server`'s `JsonRpcService`, looping over stdin/stdout
  instead of a prompt.
- STDOUT is protocol-only: one JSON-RPC 2.0 message per line, `fflush()`ed after every
  write. Human-readable status goes to STDERR, so nothing pollutes the wire — the same
  discipline every stdio MCP server needs.
- The protocol layer owns the JSON-RPC contract: envelope errors come back as well-formed error
  arrays, and a notification (no `id` member) returns `null`. The transport's only job is to
  write what is non-null.
- Every call runs under `ToolContext::stdio()`: principal `stdio`. That is not a login — it is
  the honest name for "whoever holds this pipe". Remember it: in part two that name is allowed
  to propose and is not allowed to decide.
- `tests/App/McpStdioTest.php` spawns this exact file with `proc_open` and drives the full grant
  *and* reject choreography through real OS pipes — the transport contract, proven, not asserted.

**Where part one stops.** In this loop the human's approval is a call that carries the approver's
name as an argument (`resolve_verification`'s `principal`) — so whoever can make the call can type
any name. The README's "Security boundary" section says so plainly. Part two is the loop that
closes that door.

---

## Part two — who answers the gate

### 9. `bin/process.php` — session 1: the agent proposes, and is refused

Run it, then read it. Notice:

- It runs under `ToolContext::cli()` — principal `local-shell`, the terminal's name, not a
  person's. With that it creates a post and calls `process_instantiate`; the process parks at
  `review_gate` and the terminal is recorded as the gate's **requester**.
- Then it tries to answer its own gate, twice: once as itself, once passing
  `principal: "human:you"`. Both come back `UNVERIFIED_APPROVER`. The second attempt is the old
  way through — the argument is gone from the tool, so the name is simply not read.
- The script has no flag that decides. It ends by telling you where the decision is made.
- It exits non-zero if the agent ever *does* get through. The demo is also an alarm.

### 10. `src/Orchestrator/Definitions/PublishPostProcess.php` — the process is a declaration

Notice:

- Three states, three transitions, one gate on `review_gate` whose two outcomes (`grant`,
  `reject`) share it. That is all the domain says; the engine — reducer, runner, human gate — is
  `milpa/orchestrator`.
- Nothing here stores a `current_state`. State is a projection of the append-only log
  (`var/events.jsonl`); `PublishCampaignProcess.php` next to it composes this same process as a
  subprocess without changing a line of it.
- `src/Plugins/AgentToolsPlugin/AgentToolsPlugin.php` is where the definitions, the decision
  surface and the `process.terminal` listener are wired onto the package's three tools.

### 11. `bin/enroll.php` + `src/Identity/EditorKey.php` + `ApproverKeyring.php` — becoming somebody

Run `php bin/enroll.php`, then read the three files. Notice:

- An identity here is an OpenPGP key with two halves that live in two different places. The
  **private** half stays in the editor's own keyring, outside the project (`EditorKey`). The
  **public** half is handed to the house (`ApproverKeyring`, under `var/identity/approvers/`).
- So the house can *check* a signature and can never *make* one — an agent that reads every file
  this application owns finds nothing to sign with. `tests/Identity/EditorKeyTest.php` tries.
- It is a **demo** identity and both files say why: the key has no passphrase and is generated on
  your machine when you ask. With a real key the signature is the moment a person is present.

### 12. `src/Identity/SignedCallDesk.php` — the only place a verified identity is made

The file part two exists for; under a hundred lines. Notice:

- `authorizationFor()` builds what a person signs: an `OperationAuthorization` naming the
  operation, its arguments, this house, the time and a nonce. Not "yes" — *this call*.
- `call()` hands the signed bytes and the signature to tool-runtime's `OperationAuthorizer`. The
  four rules are the framework's, not this example's: an enrolled key signed it, it names this
  call, it is fresh, it is unused.
- Only on a grant does it build `ToolContext::authorizedBy($signer, $tool->scopes)` — the
  operation's own scopes, never `*` — and run the tool. The principal is the key's fingerprint.
- `HouseKeyringVerifier.php`, next to it, is ten lines of consequence: it holds tool-runtime's gpg
  verifier to the *house's* keyring, so the keys of whoever happens to run the script do not count
  as approvers of this blog.
- Search the repo for `authorizedBy(`. Outside the first loop's stand-in signer (`Demo` and
  `KernelLoopTest`, which say so), this is the one call. Nobody else gets to say who somebody is.

### 13. `bin/decide.php` — session 2: a person decides

Run it after stop 9. Notice:

- It reads the inbox as `local-shell` — reading is free on a terminal — and shows the gate's
  decision artifact and who requested it, read back from the log.
- It prints the exact bytes it is about to sign before it signs them.
- Nothing in it names an approver. There is no `principal` to pass: who decided is read back by
  the house from the signature.
- After the decision it replays what the log gained: the decision itself, `by` a fingerprint, and
  everything the engine did because of it. Run `php bin/campaign.php` first and you will see the
  child's outcome route up and the parent finish.
- At the prompt, anything that is not a grant is a reject.

### 14. The tests that hold the claim — `tests/Identity/` and `tests/App/McpProcessToolsTest.php`

Notice:

- `SignedCallDeskTest` is a list of ways a signature could look acceptable and not be: an
  un-enrolled key, a different decision, a stale one, a replay, another house, nobody enrolled —
  and the verified editor who opened the gate themselves. Each uses a real key generated for the
  test.
- `McpProcessToolsTest` runs two real processes against one house: an agent on the MCP pipe that
  proposes and is refused, and `bin/decide.php` as a session of its own. The agent's still-open
  pipe then sees the post published.
- `ProcessDemoTest` runs the scripts of stops 9, 11 and 13 the way this document tells you to.
- No test hands the engine an approver's name. A test that needs a human decision makes a person
  (`tests/Support/Sandbox.php`) and has them sign — so the suite would notice if the desk stopped
  checking.
- `OnlyTheDeskMakesSomebodyTest` reads every file under `src/` and `bin/` and fails if any of them
  builds a context that names somebody. The engine cannot tell a verified principal from a typed
  one — only the host knows — so that discipline is the host's, and here it is a test.

## Where to go next

- Change the storage driver in `Kernel::boot()` to `'file'` or `'memory'` and run the suite:
  nothing else moves — that's the capability seam paying rent.
- Add a fourth tool to `BlogTools` with `confirm: true` and watch it inherit the consent
  choreography for free.
- Enroll a second person (`php bin/enroll.php --keyring=/tmp/colleague`), open two gates, and
  decide one as each (`php bin/decide.php --keyring=/tmp/colleague`). Then read
  `var/events.jsonl`: the log tells them apart by fingerprint.
- Make it real: give `EditorKey` a key that has a passphrase, or one that lives on a card, and let
  gpg ask for it instead of passing an empty one in `sign()`. Everything *after* the signature —
  the desk, the authorizer, the gate — stays exactly as it is, and that is the point of the
  example.
