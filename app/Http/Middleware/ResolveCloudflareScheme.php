<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teach Laravel the scheme the *visitor* used, not the one Cloudflare used to
 * reach us.
 *
 * ## Why `trustProxies()` is not enough on its own
 *
 * This origin sits behind Cloudflare in **Flexible SSL** mode: the browser
 * talks https to Cloudflare, and Cloudflare talks plain **http** to us. So the
 * standard header is not lying and cannot be trusted into saying otherwise —
 * measured on the live origin:
 *
 *     HTTP_CF_VISITOR        = {"scheme":"https"}
 *     HTTP_X_FORWARDED_PROTO = http
 *     REQUEST_SCHEME         = http
 *     SERVER_PORT            = 80
 *
 * `X-Forwarded-Proto: http` is a truthful description of the last hop. The
 * visitor's actual scheme survives only in Cloudflare's own `CF-Visitor`
 * header, so that is what has to be read.
 *
 * ## What it broke
 *
 * Every absolute URL came out `http://`. Each admin list screen wires its
 * search box as `url: "{{ route('admin.x.search') }}"`, so a page served over
 * https was requesting an http sub-resource — mixed content, which browsers
 * refuse outright. Search failed on **every** list screen and the notification
 * bell's poll failed with it, while the server log stayed clean and `curl`
 * returned a healthy 200, because curl has no mixed-content policy.
 *
 * ## Why a middleware, and why prepended
 *
 * Rewriting the header rather than calling `URL::forceScheme('https')` means
 * everything downstream agrees, not just URL generation: `$request->isSecure()`,
 * the `secure` flag on cookies, `Request::getSchemeAndHttpHost()`, redirects.
 * `forceScheme()` fixes the symptom and leaves the request object still
 * believing it is insecure.
 *
 * It is **prepended** to the global stack so it runs before `TrustProxies`,
 * which is what reads `X-Forwarded-Proto` and marks the request secure. Setting
 * the header afterwards would be too late.
 *
 * ## Trust
 *
 * `CF-Visitor` is only meaningful from Cloudflare, and anything that can reach
 * this origin directly could forge it — exactly as it could forge
 * `X-Forwarded-Proto`, which `trustProxies(at: '*')` already accepts. The
 * deployment relies on the origin being reachable only through Cloudflare; this
 * adds no trust that was not already extended. Nothing here can *downgrade* a
 * request: it only ever sets https, never clears it.
 *
 * If Cloudflare is later moved to Full SSL, `X-Forwarded-Proto` becomes `https`
 * on its own and this becomes a no-op rather than something to remove.
 */
class ResolveCloudflareScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->visitorUsedHttps($request)) {
            // Both, deliberately. The header is what TrustProxies reads; `HTTPS`
            // is what Symfony falls back to when no trusted proxy applies, so
            // the request is correct either way.
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }

    /**
     * `CF-Visitor` is a small JSON object — `{"scheme":"https"}`.
     *
     * Decoded rather than string-matched: a substring test would also fire on a
     * value that merely mentioned https somewhere, and this decides whether the
     * whole application considers the connection secure.
     */
    private function visitorUsedHttps(Request $request): bool
    {
        $header = $request->headers->get('CF-Visitor');

        if (! is_string($header) || $header === '') {
            return false;
        }

        $decoded = json_decode($header, true);

        return is_array($decoded)
            && ($decoded['scheme'] ?? null) === 'https';
    }
}
