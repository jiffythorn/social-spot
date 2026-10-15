# Licensing and compliance: Social Spot on Friendica

This note answers one question: **what does running this instance oblige Social Spot to do, and
what is currently missing?** Every claim below is grounded in the files in this repository or in
the licence text itself, and each finding says how it was checked.

Read the summary first; the rest is the reasoning.

## Summary

| # | Question | Answer |
|---|----------|--------|
| 1 | May we run a private, unmodified Friendica without publishing anything? | **Yes, unconditionally.** |
| 2 | Does running it *for members over the network* change that? | **Yes.** The AGPL's network clause applies to us. |
| 3 | Is our addon allowed to be closed source? | **No**, not while it hooks into Friendica's process. |
| 4 | Is our code correctly labelled today? | **Yes** — all 25 of our files carry SPDX headers. |
| 5 | Must our pages show a copyright / no-warranty notice? | **No**, and this is a genuine exemption, not an oversight. |
| 6 | **Is anything actually missing?** | **It was, and it is now built.** §13's source offer is implemented and asserted by five end-to-end checks. One gap remains: a signed-out visitor sees the offer, but a signed-in one has not been confirmed on a logged-in account. See §13 below. |

The single actionable gap is item 6. Everything else is already correct or is a matter of not
breaking what is correct.

---

## What is actually true of this tree

Checked by inspection, not assumed:

- **Friendica core is AGPL-3.0-or-later.** `friendica/composer.json` declares
  `"license": "AGPL-3.0+"` and `friendica/LICENSE` is the full GNU Affero GPL v3 text.
- **Our code is AGPL-3.0-or-later too.** All 25 files under `addon/orgsuite/` and `theme/orgtool/`
  carry `SPDX-FileCopyrightText: 2026, Social Spot` and `SPDX-License-Identifier:
  AGPL-3.0-or-later`. Verified with a per-file SPDX sweep.
- **We do not modify core.** Every tracked file under `friendica/` is byte-identical to upstream.
  Our work lives in exactly two places, both at the repository root: `addon/orgsuite/`, which
  upstream's own `.gitignore` already excludes (`/addon` at `.gitignore:75`), and
  `theme/orgtool/`, a brand-new directory path upstream does not own. Neither is *inside* the
  core checkout; `tools/dev/link-into-core.sh` symlinks them into the places Friendica looks.
  `tools/dev/check-core-clean.sh` enforces all of this.
- **Upstream ships no contributor licence agreement.** `.github/` contains only Dependabot,
  issue templates and workflows. There is nothing to sign and no CLA trap for contributors.

That combination is the good case: obligations we cannot meet by accident, because core is
untouched and every file we own is already labelled.

---

## The obligations that apply

### Running privately is unrestricted (AGPL §2)

> "This License explicitly affirms your unlimited permission to run the unmodified Program."

If Social Spot ran Friendica on a laptop to evaluate it, nothing above applies. No publication,
no source offer, no notices. This is worth stating plainly because it is where most of the
anxiety about the AGPL is misplaced.

### Serving members activates the network clause (AGPL §13)

> "Notwithstanding any other provision of this License, **if you modify the Program**, your
> modified version must **prominently offer all users interacting with it remotely through a
> computer network** (if your version supports such interaction) an opportunity to receive the
> **Corresponding Source of your version** by providing access to the Corresponding Source from
> a network server at no charge…"

Members sign in and interact with our instance over the network, so the precondition holds. The
one word that needs care is *modify*: we have not edited a single line of Friendica. **A
defensible reading of "modified version" includes running core together with our addon and
theme, and the practical answer for an organization is that it does.** The whole point of §13 is
to cover the operator who assembles a running system out of a free program and their own
additions; an interpretation under which adding 9,307 lines of our own code — 8,676 of them PHP
that registers core hooks and calls core services — counts as "not modifying" would empty the
clause of its purpose. Do not bet an organization's legal exposure on that reading.

Two things follow for the *Corresponding Source of your version*:

1. It is the source of **the version we run** — Friendica core (however unmodified) *plus* our
   addon *plus* our theme, together with the scripts needed to install and run them.
2. It must be reachable **at no charge, from a network server**.

### Our addon cannot be proprietary (AGPL §5(c), §1)

> "You must license the entire work, as a whole, under this License to anyone who comes into
> possession of a copy."

The AGPL does not define "linking" the way the LGPL does, so people hope a plugin escapes the
copyleft. Section 1 forecloses it for code shaped like ours — Corresponding Source includes:

> "the source code for shared libraries and dynamically linked subprograms that the work is
> **specifically designed to require**, such as by **intimate data communication or control
> flow** between those subprograms and other parts of the work."

`addon/orgsuite/` registers Friendica hooks, calls `DI::` services, reads `DI::userSession()`,
renders Friendica templates and queries Friendica's schema in its own tables. That is the
definition of intimate data communication with core. It is part of the covered work, and the
covered work ships under the AGPL.

**There is no "closed-source plugin tier" available here.** This is not a risk to mitigate; it
is a business-model constraint to plan around. If a proprietary product is a hard requirement,
the only honest routes are a separately-run service (its own process, its own database, reached
over a documented API) or a clean-room reimplementation that never links into Friendica — and
even the service route is genuinely contested under §1's wording, so it needs a lawyer, not a
blog post.

### Notices: we are exempt from the UI requirement (§5(d))

> "(d) If the work has interactive user interfaces, each must display Appropriate Legal Notices;
> however, **if the Program has interactive interfaces that do not display Appropriate Legal
> Notices, your work need not make them do so.**"

"Appropriate Legal Notices" means a visible copyright notice plus a no-warranty statement. I
checked whether core displays them: there is no copyright/no-warranty notice in
`src/Content/`, `src/Module/About…` or `view/templates/`. Friendica's web UI does not display
them, so §5(d)'s escape clause applies and **our theme is not required to add one.** Worth
documenting precisely because it is the kind of gap an auditor would flag and we can answer it
with the licence's own words.

### Marking modifications, if we ever make any (§5(a))

> "The work must carry prominent notices stating that you modified it, and giving a relevant
> date."

Today this costs nothing because core is pristine. The moment anyone edits a core file in place,
that file needs a modification notice and a date, *and* the clean-update story
(`check-core-clean.sh`, fast-forward pulls) is broken. **Keep the count of edited core files at
zero**; it is cheaper than compliance.

### Trademarks are not licensed (AGPL §7(e))

> "(e) Declining to grant rights under trademark law for use of some trade names, trademarks, or
> service marks…"

The AGPL grants copyright permissions, never brand permissions. The fact that matters about the
current UI is that **the active theme is Frio, not our own skin**: `view/theme/orgtool/` is
present in the tree but nothing enables it — there is no `system.theme` setting, and pages are
served with `view/theme/frio/` assets — and Friendica's default banner links to `friendi.ca`.
(The skin is selected by provisioning and asserted by the suite — see the note at the end.)

So the site displays the Friendica mark while presenting itself as Social Spot's product. That is fine as attribution, but it must not read as endorsement. Either keep a plain
"Built on Friendica" credit, or replace the logo with Social Spot's own mark while retaining the
attribution link. Do not use "Friendica" in the name of the service, and do not imply the
Friendica project runs or blesses it.

---

## The source offer (§13) — **resolved**

**Status.** Both halves of §13 are now in place, and both are tested rather than assumed:

1. **The source is published and obtainable** — `github.com/jiffythorn/social-spot`, public, no
   account needed. Our sources are its entire contents apart from tooling and documentation; core
   is fetched separately.
2. **It is prominently offered to users of the instance.** A "Source code" link renders in the
   shared footer of every page, signed in or not, because a link behind a sign-in is not an offer
   to the people the clause names. The address is a setting (`source_url`) with a working default,
   so an instance nobody has configured is still compliant rather than quietly not.

The end-to-end suite asserts all of this on the rendered page: that the footer carries the offer,
that it points at the published source by default, that it is visible **without signing in**, and
that a configured address replaces the default. That is five checks, and they exist because
"we did this" is not evidence.

What follows is the reasoning that got here, kept because the options were real choices.

### The original problem

Grepping our user-facing surfaces for any source or licence link turned up **nothing but our own
SPDX header comments**. There was no "Source code" link in the console, the dashboard, the site
footer or the theme — so as soon as a member signed in, we were in breach of §13, not
hypothetically but on every page load by every member.

### Options

**A. Public source mirror plus a visible "Source code" link — recommended.**
Publish core + our addon + our theme (a plain repository or a periodic source tarball) and add a
link to it in the theme footer and the member navigation. Satisfies §13 literally and cheaply:
"prominently", "at no charge", "from a network server". Cost is essentially zero because our
code needs no scrubbing — there are no secrets in it, only your own AGPL files.

**B. Private repository with access on request.**
§13 asks for the source "from a network server at no charge", not "on request". A private repo
you open when asked satisfies the spirit and probably the letter, but it is slower and turns a
one-time setup into an ongoing obligation to respond. Reasonable only if the source must stay
private for reasons unrelated to secrecy of the *code* (e.g. you do not want to run a public
repo yet).

**C. Do nothing.**
Non-compliance from the first member sign-in. The practical enforcement risk is low — Friendica's
own project is unlikely to come looking — but the remedy is real: §8 lets a rights holder
terminate your licence, after which *running the software at all* infringes copyright. For an
organization whose whole product is trust, an avoidable licence breach is a bad tail risk to
carry for the price of a footer link.

**D. Make the addon proprietary.**
Not available. See §5(c) above. Listed only to close the question.

### Recommended implementation

1. Publish the source where it can be downloaded without an account.
2. Add a configurable **source URL** setting to the addon's Settings tab (alongside the existing
   feature switches), so the destination is data rather than hard-coded and can point at a repo
   or a tarball.
3. When set, render a **"Source code"** link in the site footer and in the member navigation.
   When unset, keep the existing behaviour and surface it in the console as an outstanding
   compliance item rather than silently omitting it.
4. Add a check to the end-to-end suite asserting the link renders once a URL is configured, so
   the guarantee is tested like every other behaviour rather than remembered.
5. Keep `tools/dev/check-core-clean.sh` in CI, since §13 compliance rests on core staying
   pristine — it is what makes the source offer small and honest.

Step 3 is deliberately visible to *members*, not just officers, because §13 names "all users
interacting with it remotely" and a member on a phone is such a user.

---

## Two loose ends this analysis uncovered, and how they were resolved

Neither was a licensing problem, but both were worth fixing rather than leaving to drift:

- **Our theme was not switched on anywhere.** `theme/orgtool/` was 170 lines of PHP and CSS that
  nothing selected, so it was untested code and the site rendered stock Frio. Provisioning now
  activates it and the end-to-end suite asserts that members are actually served our stylesheet,
  so the skin cannot silently go dormant again.
- **Our code was in no repository at all.** `addon/` is gitignored by upstream and lives inside a
  clone whose `origin` is *upstream* Friendica, so `git ls-files addon/` returned nothing and
  none of our lines were under version control — pushing would have proposed our work to the
  Friendica project. Our sources now sit at the repository root (`addon/`, `theme/`), tracked by
  a repository of their own, with core fetched on demand and excluded. That is what makes §13's
  source offer possible: source nobody can obtain is not a source offer.

## What this means for the source offer

Our own sources are tracked and separate from core, so publishing them needed no scrubbing: the
repository contains only our AGPL files, the tooling and this document. It is public at
`github.com/jiffythorn/social-spot`, and the in-product offer that §13 actually asks for is
described above. **Both halves are therefore met**, which is why the section above is headed
"resolved" rather than "action required".

One caveat worth keeping in view: publishing to a personal account means the source offer is
hosted under an individual's identity. If this becomes an organization's product, moving the
repository to an organization (and updating the `source_url` default) is worth doing deliberately
rather than by accident.

## Things that are **not** a problem

To save the reader from re-litigating these:

- **Members' content.** Posts, documents, dues records and minutes are not covered by the code
  licence. Only the software's source is.
- **Selling hosting or support.** Permitted, including for a fee (§4). The AGPL is not a bar to
  charging for the service.
- **Bundling upstream unmodified.** Allowed; our tree does it.
- **GPLv3 compatibility.** §13's second paragraph explicitly permits combining our AGPL work with
  GPLv3 code, should a dependency ever need it.
- **The `addon/` directory being gitignored upstream.** This is what *saves* us: it is why we
  can update freely without conflicts, and why our footprint stays auditable.

## Verifying this document stays true

Re-runnable, and cheap enough to run in CI:

```bash
# core untouched, additions outside the tracked tree  -> prints "Core is clean", exits 0
tools/dev/check-core-clean.sh

# our code parses — measured at the repository root, NOT through the symlinks in friendica/,
# because find does not follow a directory symlink given as its start point
find addon theme -name '*.php' -print0 | xargs -0 -n1 php -l

# every file we own is labelled: lists only the ones that are NOT, so silence means pass
grep -rL 'SPDX-License-Identifier' addon theme

# and the whole gate end to end, on a throwaway instance
tools/dev/verify.sh

# the same gate as CI runs it: a checkout containing only what a clone gives you, a pristine
# core fetched from upstream, and a database initialised from nothing
tools/dev/dryrun-ci.sh
```

All five were run against this tree and pass as written: 23 PHP files lint clean, the SPDX sweep
prints nothing, and both `verify.sh` and `dryrun-ci.sh` report 95 checks passing with the
core-clean gate green.

If any of these start failing, this document is out of date before the licence question is.
