// Minimal Anthropic API client: model resolution, web search, JSON extraction.
const API = 'https://api.anthropic.com/v1';
const VERSION = '2023-06-01';

function key() {
  const k = process.env.ANTHROPIC_API_KEY;
  if (!k) throw new Error('ANTHROPIC_API_KEY is not set. Add it as a repository secret.');
  return k;
}

/** Pick the newest available model whose id starts with the configured prefix. */
export async function resolveModel() {
  if (process.env.BRIEFING_MODEL) return process.env.BRIEFING_MODEL;
  const prefix = process.env.BRIEFING_MODEL_PREFIX || 'claude-fable';
  const r = await fetch(`${API}/models?limit=100`, {
    headers: { 'x-api-key': key(), 'anthropic-version': VERSION },
  });
  if (!r.ok) throw new Error(`model list failed: ${r.status} ${await r.text()}`);
  const { data } = await r.json();
  const match = data
    .map((m) => m.id)
    .filter((id) => id.startsWith(prefix))
    .sort()
    .reverse();
  if (!match.length) {
    const ids = data.map((m) => m.id).join(', ');
    throw new Error(`no model matching "${prefix}". Available: ${ids}`);
  }
  return match[0];
}

const WEB_SEARCH_TOOL = {
  type: process.env.WEB_SEARCH_TOOL_TYPE || 'web_search_20250305',
  name: 'web_search',
  max_uses: Number(process.env.WEB_SEARCH_MAX_USES || 18),
};

export async function ask({
  model,
  system,
  prompt,
  maxTokens = 16000,
  search = false,
  attempts = 3,
}) {
  const body = {
    model,
    max_tokens: maxTokens,
    system,
    messages: [{ role: 'user', content: prompt }],
  };
  if (search) body.tools = [WEB_SEARCH_TOOL];

  let lastErr;
  for (let i = 1; i <= attempts; i++) {
    try {
      const r = await fetch(`${API}/messages`, {
        method: 'POST',
        headers: {
          'x-api-key': key(),
          'anthropic-version': VERSION,
          'content-type': 'application/json',
        },
        body: JSON.stringify(body),
      });
      if (r.status === 429 || r.status >= 500) {
        throw new Error(`retryable ${r.status}: ${(await r.text()).slice(0, 300)}`);
      }
      if (!r.ok) {
        const text = await r.text();
        // A rejected server-tool type is a config problem: say so precisely.
        if (search && /tool/i.test(text)) {
          throw new Error(
            `web search tool rejected (type "${WEB_SEARCH_TOOL.type}"). ` +
              `Set WEB_SEARCH_TOOL_TYPE to the current server-tool version. API said: ${text.slice(0, 300)}`
          );
        }
        throw new Error(`api ${r.status}: ${text.slice(0, 500)}`);
      }
      const json = await r.json();
      const text = (json.content || [])
        .filter((b) => b.type === 'text')
        .map((b) => b.text)
        .join('');
      if (!text.trim()) throw new Error('empty completion');
      return text;
    } catch (e) {
      lastErr = e;
      if (i === attempts) break;
      const wait = 4000 * i * i;
      console.log(`  api attempt ${i} failed (${e.message.slice(0, 140)}); retrying in ${wait}ms`);
      await new Promise((res) => setTimeout(res, wait));
    }
  }
  throw lastErr;
}

/** Pull the first balanced JSON object out of a completion. */
export function extractJson(text) {
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  const raw = fence ? fence[1] : text;
  const start = raw.indexOf('{');
  if (start < 0) throw new Error(`no JSON object in output: ${text.slice(0, 200)}`);
  let depth = 0,
    inStr = false,
    escaped = false;
  for (let i = start; i < raw.length; i++) {
    const c = raw[i];
    if (inStr) {
      if (escaped) escaped = false;
      else if (c === '\\') escaped = true;
      else if (c === '"') inStr = false;
      continue;
    }
    if (c === '"') inStr = true;
    else if (c === '{') depth++;
    else if (c === '}') {
      depth--;
      if (depth === 0) {
        const slice = raw.slice(start, i + 1);
        try {
          return JSON.parse(slice);
        } catch (e) {
          throw new Error(`JSON parse failed: ${e.message}`);
        }
      }
    }
  }
  throw new Error('unbalanced JSON in output');
}
