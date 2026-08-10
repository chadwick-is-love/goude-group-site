# The Briefing: the weekly publish

Runs itself. `.github/workflows/briefing.yml` fires every Monday at 12:01 AM Eastern,
builds the next issue, runs the gates, and commits only if every gate passes. Hostinger
deploys from `main`, so the commit is the publish.

## What you need set once

**Repository secret** (Settings, Secrets and variables, Actions, Secrets):

- `ANTHROPIC_API_KEY` — required. Nothing runs without it.

**Repository variables** (same page, Variables tab). All optional, all for when something upstream changes:

- `BRIEFING_MODEL` — pin an exact API model id. Leave unset and the build picks the newest
  model whose id starts with the prefix below.
- `BRIEFING_MODEL_PREFIX` — defaults to `claude-fable`.
- `WEB_SEARCH_TOOL_TYPE` — defaults to `web_search_20250305`. If a run fails saying the web
  search tool was rejected, set this to the current server-tool version.

## What it does, in order

1. **Guard.** GitHub cron is UTC only, so both DST offsets are scheduled and the run that is
   not actually Monday 00:xx in New York exits immediately.
2. **Research** (`scripts/prompts/research.md`). One call with web search. Returns five ranked
   topics with verified facts and source URLs. The selection rule lives in that prompt file:
   edit it there, not in code, when you want to change what the Briefing looks for.
3. **Write** (`scripts/prompts/topic.md`). One call per article. Each returns content plus one
   working tool. Output is validated against `validateTopic()` and retried up to three times
   with the failures fed back; the run dies rather than shipping an article that will not pass.
4. **Assemble** (`scripts/lib/`). Deterministic. The previous issue is the canon: it gets
   archived to `briefing/archive/no-00N/`, the new issue is built from fixed templates, the
   archive index gains a group and loses its old "This week" tag, and older back issues get
   the share retrofit if they lack it. No model output ever writes page structure.
5. **Gates** (`scripts/check-briefing.mjs`). Real browser, every page, 390 and 1440:
   em dashes in anything that renders, Interpolar mentions, inline JS parses, horizontal
   overflow, page errors, 404s, all five topics expand, every tool actually computes or
   generates, every share button copies the right deep link, `#t3` opens topic 3, archive
   counts and tag placement. Any failure means no commit and the live site is untouched.
6. **Publish.** Commit and push. Then poll goudegroup.com for three minutes to confirm
   Hostinger synced.
7. **On failure**, the workflow opens a GitHub issue, which emails you. Silence is not a
   possible outcome.

## Running it by hand

Actions tab, The Briefing, Run workflow. Tick **dry run** to build and gate without
publishing. The guard is skipped for manual runs, so it works any day.

## Working on it locally

```bash
npm install --no-save playwright && npx playwright install chromium
node scripts/dry-assemble.mjs      # assemble a fixture issue, no API calls, no spend
node scripts/check-briefing.mjs    # run the gates against whatever is in the tree
git checkout .                     # throw the fixture away
```

`dry-assemble.mjs` exists so the assembly and the gates can be changed and tested without
spending an API call. If you touch `lib/assemble.mjs` or `lib/templates.mjs`, run it.

`PW_CHROMIUM_PATH` points the gates at an already-installed Chromium if you have one.

## Changing the issue

- **What it covers**: `scripts/prompts/research.md`, the selection rule.
- **How it reads**: `scripts/prompts/topic.md`, the voice rules and the tool contract.
- **How it looks**: `scripts/lib/templates.mjs`. This is the only place page structure lives.
- **What blocks a publish**: `scripts/check-briefing.mjs`.
