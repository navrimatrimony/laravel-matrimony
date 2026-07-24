<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileApiCachePolicy
{
    public function handle(
        Request $request,
        Closure $next,
        string $visibility = 'private',
        string $maxAge = '60',
        string $staleWhileRevalidate = '0',
        string $tags = 'mobile-api'
    ): Response {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->canCache($request, $response)) {
            return $response;
        }

        $visibility = $visibility === 'public' ? 'public' : 'private';
        $maxAgeSeconds = max(0, (int) $maxAge);
        $staleSeconds = max(0, (int) $staleWhileRevalidate);
        $tagHeader = trim(str_replace('|', ',', $tags));
        $etag = $this->etagFor($request, $response, $visibility, $tagHeader);
        $headers = $this->headers($visibility, $maxAgeSeconds, $staleSeconds, $tagHeader, $etag);

        if ($this->requestHasMatchingEtag($request, $etag)) {
            $notModified = response('', 304);
            foreach ($headers as $name => $value) {
                $notModified->headers->set($name, $value);
            }

            return $notModified;
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function canCache(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains(strtolower($contentType), 'application/json')) {
            return false;
        }

        return $response->getContent() !== false;
    }

    private function etagFor(Request $request, Response $response, string $visibility, string $tags): string
    {
        $scope = [
            'url' => $request->fullUrl(),
            'visibility' => $visibility,
            'tags' => $tags,
            'user_id' => $visibility === 'private' ? $request->user()?->getAuthIdentifier() : null,
            'content' => $response->getContent(),
        ];

        return 'W/"mobile-api-cache-v1-'.substr(hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES)), 0, 32).'"';
    }

    /**
     * @return array<string, string>
     */
    private function headers(
        string $visibility,
        int $maxAge,
        int $staleWhileRevalidate,
        string $tags,
        string $etag
    ): array {
        $cacheControl = sprintf('%s, max-age=%d', $visibility, $maxAge);
        if ($staleWhileRevalidate > 0) {
            $cacheControl .= sprintf(', stale-while-revalidate=%d', $staleWhileRevalidate);
        }

        return [
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
            // Accept-Language belongs here because SetApiLocale can pick the
            // response language from that header alone. Without it a shared
            // cache would hand the first caller's language to everyone behind
            // it for the whole TTL — publicly cached lookups run 12 hours.
            'Vary' => $visibility === 'private'
                ? 'Accept, Accept-Language, Authorization'
                : 'Accept, Accept-Language',
            'X-Mobile-Cache-Policy' => $visibility,
            'X-Mobile-Cache-TTL' => (string) $maxAge,
            'X-Mobile-Cache-Stale-While-Revalidate' => (string) $staleWhileRevalidate,
            'X-Mobile-Cache-Tags' => $tags !== '' ? $tags : 'mobile-api',
        ];
    }

    private function requestHasMatchingEtag(Request $request, string $etag): bool
    {
        $header = trim((string) $request->headers->get('If-None-Match', ''));
        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        return in_array($etag, array_map('trim', explode(',', $header)), true);
    }
}
