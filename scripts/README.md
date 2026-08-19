# The Briefing: the weekly publish

Runs itself. `.github/workflows/briefing.yml` fires every Monday at 12:01 AM Eastern,
writes the next issue, runs the gates, and commits only if every gate passes. Hostinger
deploys from `main`, so the commit is the publish.

**It runs on Chadwick's Claude subscription, not on metered API credit.** The writing step
uses `anthropics/claude-code-action` with an OAuth token. There is no per-issue bill; the
run draws on the plan's usage allowance, the same pool as Claude chat and Cowork.

## What you need set once

**Repository secret** (Settings, Secrets and variables, Actions):

- `CLAUDE_CODE_OAUTH_TOKEN` — required. Generate it by running `claude setup-token` in the
  Claude Code CLI on your own machine, approving in the browser, and pasting the printed
  token here. It lasts one year. When it expires the Monday run fails and opens an issue
  telling you to regenerate it.

That is the only setup. No API key, no billing account.

## Why it is built this way

The Monday job used to run as a Cowork scheduled task that pushed straight to this repo.
That worked until early August 2026, when the sandbox git proxy began refusing pushes to
repositories not attached to the session, with `api.github.com` blocked the same way. The
August 10 run fired twice and could not ship either time. Moving the job here fixes it at
the root: the commit is made by the GitHub runner, which has push rights by definition and
never touches that proxy. It also removes the dependency on anyone's laptop being awake.

## What it does, in order

1. **Guard.** GitHub cron is UTC only, so both DST offsets are scheduled and the run that is
   not actually Monday 00:xx in New York exits immediately.
2. **Research and write** (`scripts/prompts/write-issue.md`, which points at `theme.md` for a
   one-issue theme if one is in force, `research.md` for the selection rule and `topic.md` for
   voice and the tool contract).
   Claude Code searches, verifies, writes five articles with five working instruments, and
   writes one file: `build/topics.json`. It touches nothing else.
3. **Assemble** (`scripts/assemble-issue.mjs`). Deterministic, no network. It validates every
   article first (word counts, source URLs, banned language, tool JS shape) and refuses to
   build if anything fails. Then the previous issue is archived to `briefing/archive/no-00N/`,
   the new issue is built from fixed templates in `scripts/lib/templates.mjs`, the archive
   index gains a group and loses its old "This week" tag, and older back issues get the share
   retrofit if they lack it. No model output ever writes page structure.
4. **Gates** (`scripts/check-briefing.mjs`). Real browser, every page, 390 and 1440:
   em dashes in anything that renders, Interpolar mentions, inline JS parses, horizontal
   overflow, page errors, 404s, all five topics expand, every tool actually computes or
   generates, every share button copies the right deep link, `#t3` opens topic 3, archive
   counts and tag placement. Any failure means no commit and the live site is untouched.
5. **Publish.** Commit and push, then poll goudegroup.com for three minutes to confirm
   Hostinger synced.
6. **On failure**, the workflow opens a GitHub issue, which emails you. Silence is not a
   possible outcome.

A second job checks the result independently: a Cowork scheduled task at 7 AM Monday reads
the live site and the commit list and notifies if the issue is not up. It never builds.

## Running it by hand

Actions tab, The Briefing, Run workflow. Tick **dry run** to write and gate without
publishing; the built issue is attached to the run as an artifact you can download and open.
The clock guard is skipped for manual runs, so it works any day.

## Working on it locally

```bash
npm install --no-save playwright && npx playwright install chromium
node scripts/dry-assemble.mjs      # fixture issue, no model, no spend
node scripts/check-briefing.mjs    # gates against whatever is in the tree
git checkout . && rm -rf build     # throw the fixture away
```

`dry-assemble.mjs` exists so assembly and the gates can be changed and tested without a
model in the loop. If you touch `lib/assemble.mjs` or `lib/templates.mjs`, run it.

`PW_CHROMIUM_PATH` points the gates at an already-installed Chromium if you have one.

## Changing the issue

- **What it covers**: `scripts/prompts/research.md`, the selection rule. The Briefing has no
  assigned subject; it ranks the week by consequence to an operator. Read the two failure
  modes at the top of that file before editing it.
- **The theme for one issue**: `scripts/prompts/theme.md`. It names the single issue number it
  applies to and governs selection for that issue only. Any other issue number and the builder
  ignores it, which is what stops a theme becoming a standing beat. Nothing enforces this in
  code; the scope line at the top of the file is the mechanism, so keep it accurate.
- **How it reads**: `scripts/prompts/topic.md`, the voice rules and the tool contract.
- **How it looks**: `scripts/lib/templates.mjs`. The only place page structure lives.
- **What blocks a publish**: `scripts/check-briefing.mjs` and the validator at the top of
  `scripts/assemble-issue.mjs`.
