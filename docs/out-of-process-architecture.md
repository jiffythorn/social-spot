# Out-of-process architecture: what can leave core's process, and what cannot

The question this answers: **if some part of the organization suite must not be AGPL, what can be
moved behind an API boundary — and what is stuck?**

Short answer: **almost none of what we have built today, and the reason is architectural rather
than legal.** Nine of this addon's ten hooks do something with no out-of-process equivalent: they
change what core renders, decide whether core proceeds, or extend core's schema and boot. To a
large extent, *interception is the product*, and a separate service cannot intercept a request it
never sees.

(Every hook runs inside core's process — that is what a hook is — so the distinction that matters
is not "which run in-process" but "which do something a service could not do instead". The tenth,
the one declaring our own routes, is the only one that merely publishes pages; those pages could
equally be served from outside, which is the whole opportunity described in §5.)

The rest of this document says which is which, on evidence from this repository, and what the
boundary would have to look like for the parts that can move.

**Read the caveat at the end before acting on any of this.** The line between "two programs" and
"one combined work" is a legal question with no settled answer, and the FSF's own FAQ says so.

---

## 1. Summary

| Verdict | Features | Why |
|---|---|---|
| **Cannot move out of process** | Gated registration, sign-in gating, navigation/home replacement, moderation-UI integration, page-asset injection | Each must run inside Friendica's own request to have any effect. See §4. |
| **Could move, with real work** | Dues and payment handling, the document vault, committees and positions *as data*, announcements, audit log, roster | Their own data, their own pages. Nothing about them requires intercepting core — *today*, because they currently piggy-back on core for identity, storage and authorization. See §5. |
| **Cannot move, by definition** | The permission gate itself (`can()` / `requirePermission()`), the schema hook, the boot hook | The authorization decision happens on core's pages, about core's users, using core's session. |

The blunt conclusion: **moving features out does not reduce the AGPL obligation on what remains.**
We would still be serving a modified Friendica, §13 would still require us to offer that
modification's source, and the connector that does the intercepting would still be ours, AGPL,
and published. What a boundary buys is the *possibility* that the features behind it are separate
works. It buys nothing if the features behind it are the ones that cannot leave.

---

## 2. The legal test, in the licence's own words

Three provisions matter. Quoted from AGPLv3.

**§1 needs no permission to *run* anything, and defines what source must travel with a work:**

> "Corresponding Source includes interface definition files associated with source files for the
> work, and the source code for shared libraries and dynamically linked subprograms that the work
> is **specifically designed to require**, such as by **intimate data communication or control
> flow** between those subprograms and other parts of the work."

**§5(c) closes the "package it separately" escape for a combined work:**

> "You must license the entire work, as a whole, under this License to anyone who comes into
> possession of a copy... regardless of how they are packaged."

**§13 creates the network obligation, and names its trigger:**

> "Notwithstanding any other provision of this License, **if you modify the Program**, your
> modified version must prominently offer all users interacting with it remotely through a
> computer network... an opportunity to receive the Corresponding Source of your version..."

Two consequences that shape everything below:

1. **§13's trigger is modifying the Program plus serving it.** Shipping an extra program that
   talks to Friendica over an API does not, by itself, remove or extend that obligation. The
   obligation follows the modified Friendica, not our service.
2. **Process boundaries are not a safe harbour.** The FSF's stated criterion is both the
   *mechanism* of communication and the *semantics* — what information is interchanged. Pipes and
   sockets look like separation; exchanging internal data structures and driving each other's
   control flow does not. The FAQ is explicit that the boundary is "a legal question, which
   ultimately judges will decide."

So "separate process" is evidence, not an answer. The test that actually predicts the outcome is
**how much internal state and control flow crosses the line.**

---

## 3. The evidence: how coupled this addon actually is

Measured from this repository, not estimated.

**Ten hooks, every one an interception point** (`addon/orgsuite/orgsuite.php:73`):

| Hook | What it does |
|---|---|
| `register_post` | Validates the property credential and **aborts account creation** |
| `register_account` | Binds the credential to the account core just created |
| `authenticate` | **Blocks sign-in** for an unapproved member |
| `nav_info` | Rewrites the site navigation |
| `page_content_top` | Injects the credential field into core's sign-up form |
| `head` | Injects our stylesheet into core's page |
| `route_collection` | Registers our routes into core's router |
| `moderation_users_tabs` | Adds a tab to core's moderation UI |
| `dbstructure_definition` | **Adds our 12 tables to core's schema** |
| `init_1` | Runs on boot |

**Core surface reached into:** `BaseModule`, `DBA`, `DBStructure`, `Core\Hook`, `DI`,
`Model\User`, `Model\Register`, `Util\Mimetype`, `Util\DateTimeFormat`, and core's storage
abstraction (`ICanReadFromStorage`) for vault files.

**41 call sites** that read the live session (`userSession()`, `localUid()`, `isSiteAdmin()`) to
decide who may see what.

**12 tables** (`orgsuite_member`, `orgsuite_role`, `orgsuite_document`, `orgsuite_charge`, ...)
created *inside core's database and schema*, read via core's `DBA`.

**5 routes** registered into core's router; **4 modules** (`Console`, `Dashboard`, `Directory`,
`Vault`), every one descending from core's `BaseModule` through our own `BaseOrgModule`.

This is not a program that happens to interoperate with Friendica. It is a set of extensions
loaded into Friendica's address space, hooked into its boot, its schema, its router, its page
rendering, and its authentication — sharing its database handle and its session. Under §1's
language about subprograms "specifically designed to require" intimate data communication and
control flow, the current addon is part of the covered work. That was already the conclusion in
[licensing-compliance.md](licensing-compliance.md), and nothing here changes it.

---

## 4. What cannot leave, and why

Each of these fails for a structural reason, not a legal one.

**Gated registration.** The requirement is that no account exists until an officer releases it.
`register_post` runs *inside* core's registration transaction and can abort it; `register_account`
then binds the credential to the row core created. An out-of-process service is never called
during that transaction, so it cannot prevent the account. To gate registration from outside you
must sit in front of core as a reverse proxy and reimplement registration — a shim that duplicates
core's logic and shares its session and schema, which is *worse* on the §1 test than the hook it
replaced. You would also have to intercept `authenticate` the same way to keep unapproved members
out.

**Navigation and home-page replacement** (`nav_info`, `head`). These change what core renders, by
mutating core's own page assembly. The alternatives are a theme or HTML rewriting at a proxy —
both are in-path, both intimate, and a proxy that parses and rewrites core's output is arguably a
derivative work in the strongest sense.

**Moderation-UI integration.** Adds a tab to core's moderation screens. In-process by definition.

**The permission gate.** `can()` and `requirePermission()` decide, on core's own pages and using
core's session, whether the reader may proceed. The *data* about who holds which position can live
in a service (§5); the *enforcement* cannot, because enforcement happens at the moment core renders
a page — and if the service is asked over the network for every page render, it is in the request
path and its control flow is woven through core's.

**Schema and boot hooks.** Registering tables into core's schema and running at boot are, by
construction, inside the process.

**Honest note:** you can technically move *any* of these if you are willing to put a proxy in the
request path and reimplement core behaviour there. The point is that the result is not less
intimate — it is more — so it does not serve the goal of being a separate work.

---

## 5. What could move, and what the boundary needs

These have their own data and their own pages. They could be a standalone service. What stops them
today is that they borrow core for three things: **identity, authorization, and storage.**

### 5.1 The three things that must cross the boundary

| Need | Today | Across a boundary |
|---|---|---|
| Identity | `DI::userSession()->getLocalUserId()` (41 sites) | A **signed assertion** from the connector: user id, display name, membership id, and the position/capabilities snapshot, with a short expiry. Ordinary request/response semantics — this is the difference between a documented contract and intimate sharing. |
| Authorization | Our `RoleRepository`/`Permissions` read our tables | The **service enforces its own authorization**, from the assertion (or better, from its own store keyed by user id). Core must never be trusted to say "this user may see this document" without the service checking. |
| Storage | Core's `ICanReadFromStorage` | The service owns its own storage. File bytes must not be proxied *through* core. |

### 5.2 Candidate by candidate

**Dues and payments — the strongest candidate, and worth doing regardless.** Charges and payments
are our own data with our own arithmetic and no need to intercept anything. More importantly,
taking money means handling a payment provider (deliberately not named here — pick one on its
merits), webhooks, PCI scope and reconciliation. That is a service-shaped problem, and a separate
service removes payment code from the core process entirely. A member reaching a dues page hosted
by the service and linked from the dashboard is a clean, ordinary boundary.

**Document vault — possible but the most delicate.** It looks like simple storage plus
access control, but that is exactly the pattern §1 describes: it currently reads core's session
(41 sites include it), stores bytes through core's storage abstraction, and enforces visibility
against our tables. To move it: the service holds the bytes, decides visibility from its own
records, and accepts only a signed assertion of *who* is asking. Note the failure mode: if the
service trusts a `X-User-Id` header from core, it can be forged unless the assertion is signed and
short-lived, so the signing key and its rotation become part of the design rather than an
afterthought. Large-file streaming and byte-range support move to the service too.

**Committees, positions, roles — the data can move; the enforcement cannot.** The tables are ours,
so a service could own them. But `can()` is consulted while core renders pages, so the connector
would either cache a capability snapshot (stale-but-fast) or call the service in-path (correct-but-
intimate). Either way the connector stays AGPL. Moving the data buys governance and audit
independence, not licence separation.

**Announcements, audit log, roster.** All three are ours end to end. An announcements *page* can be
served by the service; the copy that appears in Friendica's navigation is rendered in-process and
stays behind.

### 5.3 Shape of the architecture, if we build it

```
        browser
           |
           v
  +---------------------+          signed assertion (identity + capabilities)
  |  Friendica +        |  -----------------------------------------------+
  |  AGPL connector     |                                                 |
  |  (hooks, nav,       |  <------------------------------------------+   |
  |   routes, gate)     |        ordinary request/response            |   v
  +---------------------+                                             +------------------+
           |                                                          |  OUR SERVICE     |
           | shares nothing with the service                          |  own DB, own      |
           v                                                          |  storage, own UI  |
      core's database                                                 +------------------+
```

Rules that make the separation defensible, and that we would have to actually follow:

1. **No shared code.** The service must not `use Friendica\...`; not even our own `Text` or
   `Permissions` classes unless they are duplicated or extracted into a separately licensed
   library used on both sides (which would pull them back under the copyleft).
2. **No shared schema.** The service has its own database. It must not read core's tables through
   `DBA` as it does now.
3. **No shared control flow.** Core must not call the service to decide whether to render a page.
   This is the constraint that keeps the permission gate in-process.
4. **A documented, versioned API** with ordinary semantics — request in, response out. This is the
   "mechanism" half of the FAQ's criterion, and it is the half we can control by design.
5. **The connector is AGPL and published.** It always will be. Budget for that rather than wishing
   otherwise.

Latency and reliability: an out-of-process vault adds a hop to every document fetch and a new
failure mode — the dashboard must degrade when the service is down rather than error. Dues and
payments tolerate this well (their page can fail on its own); in-path authorization does not, which
is a further reason the gate stays inside.

---

## 6. The decision rule, going forward

Use this before writing the next feature. It is the whole document in four questions:

1. **Does it need to change what core renders, or whether core proceeds?** If yes → it is a hook.
   It is in-process. It is AGPL. Stop here.
2. **Does it read core's session, schema or storage to do its work?** If yes → it is intimate. It
   can only move after those three dependencies are removed.
3. **Does it have its own data, its own pages, and its own enforcement?** If yes → it can be a
   service, and should be built as one from the start, because retrofitting is far more expensive
   than starting there.
4. **Is the wire contract ordinary request/response over a versioned API?** If yes, the separation
   argument is at its strongest. If the service and core are exchanging internal structures or
   driving each other, it is at its weakest.

A feature that answers "yes" to 1 will never be proprietary. The organization's own screens —
console, dashboard, roster, dues, vault — are mostly *not* that, which is the one genuinely
encouraging finding here: the business logic of an HOA is separable. The part that is not separable
is the ability to *insert itself into Friendica*, and that is the part that makes the product work.

---

## 7. The cheaper alternative worth weighing

Before building any of this, note what the boundary is actually for. If the goal is a proprietary
product, the boundary is necessary but expensive, and it will apply to only part of the suite.

If the goal is instead to stop a competitor from standing up the same service, **the AGPL already
does that job**, and it does it better than a boundary would: anyone who runs our modified
Friendica must offer their users our source. The licence is an asset in that scenario, not the
constraint.

The one thing no boundary can fix: **we cannot dual-license this work, because we do not hold the
copyright on Friendica.** Only the Friendica project can do that, and only for their code. Our
options are to comply, to separate, or to license our own addon — within the AGPL it must be.

---

## 8. Caveat

**This is architecture analysis, and it is not legal advice.** The boundary between "two programs"
and "one combined work" is explicitly described as decideable by courts; the FSF FAQ says so in as
many words, and neither §1 nor §5(c) draws a line that a socket cleanly crosses. Everything above
about *our* code — the hooks, the 41 session call sites, the 12 tables in core's schema, core's
storage backend — is measured fact. Everything above about *how a judge would treat it* is a
reasoned position that a lawyer should review before anyone relies on it commercially.

The genuinely settled parts, worth relying on without a lawyer: §13 requires us to offer the source
of our modified Friendica to its users; §5(c) requires the whole covered work to be AGPL; and our
current addon is, on the evidence in §3, part of that work.

Sources for the licence wording and the FSF's stated criterion are cited inline above; the AGPL
text is at <https://www.gnu.org/licenses/agpl-3.0.html>, and the FSF's FAQ — which is the FSF's
view, not a ruling — at <https://www.gnu.org/licenses/gpl-faq.en.html>.
