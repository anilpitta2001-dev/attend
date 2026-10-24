/**
 * Attendance bot webhook controller.
 * Routes Telegram webhook updates to Render A/B with manual override and failover.
 *
 * Required Cloudflare binding:
 *   BOT_CONFIG -> KV namespace
 *
 * Required Worker secrets/vars:
 *   BACKEND_A_URL, BACKEND_B_URL, BACKEND_PROXY_SECRET,
 *   TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET,
 *   ADMIN_BOT_TOKEN, ADMIN_WEBHOOK_SECRET, ADMIN_TELEGRAM_ID
 */
const ROUTES = {
  "/user": "/telegram_bot.php",
  "/admin": "/admin_bot.php",
};
const ACTIVE_KEY = "active_server";

function responseJson(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" },
  });
}
function backendBase(env, server) {
  return server === "A" ? env.BACKEND_A_URL : env.BACKEND_B_URL;
}
function endpoint(base, path) {
  return new URL(path.replace(/^\/+/, ""), base.replace(/\/+$/, "") + "/");
}
async function probe(env, server) {
  const base = backendBase(env, server);
  if (!base) return { server, healthy: false, reason: "URL not configured" };
  try {
    const r = await fetch(endpoint(base, "/telegram_bot.php"), {
      method: "GET",
      signal: AbortSignal.timeout(4000),
      redirect: "manual",
    });
    return { server, healthy: r.status >= 200 && r.status < 300, status: r.status };
  } catch {
    return { server, healthy: false, reason: "unreachable or timed out" };
  }
}
async function sendAdmin(env, chatId, text) {
  if (!env.ADMIN_BOT_TOKEN) return;
  await fetch(`https://api.telegram.org/bot${env.ADMIN_BOT_TOKEN}/sendMessage`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ chat_id: chatId, text }),
    signal: AbortSignal.timeout(5000),
  });
}
function isAuthorizedAdmin(update, env) {
  const m = update?.message;
  return !!m &&
    m.chat?.type === "private" &&
    String(m.from?.id ?? "") === String(env.ADMIN_TELEGRAM_ID ?? "");
}
async function proxyToBackend(request, env, path) {
  const configured = (await env.BOT_CONFIG.get(ACTIVE_KEY)) || "A";
  const primary = configured === "B" ? "B" : "A";
  const backup = primary === "A" ? "B" : "A";
  const rawBody = await request.arrayBuffer();

  for (const server of [primary, backup]) {
    const base = backendBase(env, server);
    if (!base) continue;
    try {
      const headers = new Headers();
      headers.set("content-type", request.headers.get("content-type") || "application/json");
      headers.set("x-attendance-proxy-secret", env.BACKEND_PROXY_SECRET);
      const telegramSecret = request.headers.get("x-telegram-bot-api-secret-token");
      if (telegramSecret) headers.set("x-telegram-bot-api-secret-token", telegramSecret);

      const response = await fetch(endpoint(base, path), {
        method: "POST",
        headers,
        body: rawBody.slice(0),
        signal: AbortSignal.timeout(10000),
        redirect: "manual",
      });

      // Only retry server-side failures. A 4xx usually means the request is invalid
      // or its secret is wrong, so trying another server won't fix it.
      if (response.status >= 500 && response.status <= 599) continue;

      if (server !== configured) {
        await env.BOT_CONFIG.put(ACTIVE_KEY, server);
      }
      return response;
    } catch {
      // Connection failure/timeout: try the other deployment.
    }
  }
  return new Response("Attendance bot temporarily unavailable. Telegram may retry this update.", {
    status: 503,
    headers: { "content-type": "text/plain; charset=utf-8", "retry-after": "5" },
  });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === "GET" && url.pathname === "/health") {
      return responseJson({ ok: true, service: "attendance-webhook-controller" });
    }

    if (request.method !== "POST" || !ROUTES[url.pathname]) {
      return new Response("Not found", { status: 404 });
    }

    const isAdminBot = url.pathname === "/admin";
    const expectedSecret = isAdminBot
      ? env.ADMIN_WEBHOOK_SECRET
      : env.TELEGRAM_WEBHOOK_SECRET;
    const receivedSecret = request.headers.get("x-telegram-bot-api-secret-token") || "";

    if (!expectedSecret || !receivedSecret || receivedSecret !== expectedSecret) {
      return new Response("Unauthorized", { status: 401 });
    }
    if (!env.BACKEND_PROXY_SECRET || !env.BACKEND_A_URL || !env.BACKEND_B_URL) {
      return new Response("Controller configuration incomplete", { status: 503 });
    }

    let update;
    try {
      update = await request.clone().json();
    } catch {
      return new Response("Invalid JSON", { status: 400 });
    }

    // Only the verified admin bot and the configured private admin ID can switch.
    if (isAdminBot && isAuthorizedAdmin(update, env)) {
      const message = update.message;
      const text = String(message.text || "").trim();
      const chatId = message.chat.id;
      const switchMatch = text.match(/^\/switchserver(?:@\w+)?\s+([AB])$/i);
      const statusMatch = /^\/serverstatus(?:@\w+)?$/i.test(text);

      if (switchMatch) {
        const selected = switchMatch[1].toUpperCase();
        const health = await probe(env, selected);
        if (!health.healthy) {
          await sendAdmin(env, chatId, `Server ${selected} does not appear healthy (HTTP ${health.status ?? "unreachable"}). Selection was not changed.`);
          return new Response("OK");
        }
        await env.BOT_CONFIG.put(ACTIVE_KEY, selected);
        await sendAdmin(env, chatId, `Active server set to ${selected}. Health check passed.`);
        return new Response("OK");
      }

      if (statusMatch) {
        const active = (await env.BOT_CONFIG.get(ACTIVE_KEY)) || "A";
        const [a, b] = await Promise.all([probe(env, "A"), probe(env, "B")]);
        const fmt = x => `${x.server}: ${x.healthy ? "healthy" : "UNAVAILABLE"}${x.status ? ` (HTTP ${x.status})` : ""}`;
        await sendAdmin(env, chatId, `Configured active server: ${active}\n${fmt(a)}\n${fmt(b)}\n\nCommands:\n/switchserver A\n/switchserver B`);
        return new Response("OK");
      }
    }

    return proxyToBackend(request, env, ROUTES[url.pathname]);
  },
};
