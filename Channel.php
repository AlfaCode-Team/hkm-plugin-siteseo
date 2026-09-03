<?php

namespace Plugins\SiteSEO;


/**
 * Per-request page state (shared props + meta tags) and one-shot flash values.
 *
 * COROUTINE SAFETY
 * ----------------
 * $shared and $meta used to be plain process-statics. Under OpenSwoole one
 * worker interleaves many in-flight requests, so request B's shared props and
 * meta overwrote request A's while A was suspended on I/O — and A then rendered
 * B's data. Since these carry page content (titles, descriptions, arbitrary
 * shared props), that is a cross-request and potentially cross-TENANT data leak.
 *
 * State now lives in the current coroutine's context when there is one, which is
 * isolated per coroutine and destroyed when it ends. Outside a coroutine
 * (PHP-FPM, CLI) it falls back to the statics, which is correct there because
 * those SAPIs genuinely serve one request per process.
 */
class Channel
{
    /** Fallback state for non-coroutine SAPIs. */
    protected static array $shared = [];
    protected static array $meta = [];

    /**
     * The current coroutine's context, or null outside one. Mirrors the
     * detection used by I18n\Support\Lang and the HttpClient backoff.
     */
    private static function context(): ?\ArrayObject
    {
        if (\class_exists('\\OpenSwoole\\Coroutine') && \OpenSwoole\Coroutine::getCid() > 0) {
            return \OpenSwoole\Coroutine::getContext();
        }
        if (\class_exists('\\Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            return \Swoole\Coroutine::getContext();
        }

        return null;
    }

    /** Read a request-scoped bag ('shared' | 'meta' | 'flash'). */
    private static function bag(string $name): array
    {
        $context = self::context();

        if ($context !== null) {
            return (array) ($context['hkm.seo.' . $name] ?? []);
        }

        return $name === 'meta' ? static::$meta : static::$shared;
    }

    /** Write a request-scoped bag back. */
    private static function putBag(string $name, array $values): void
    {
        $context = self::context();

        if ($context !== null) {
            $context['hkm.seo.' . $name] = $values;

            return;
        }

        if ($name === 'meta') {
            static::$meta = $values;
        } else {
            static::$shared = $values;
        }
    }

    // === Shared data API ===

    public static function share(string $key, mixed $value): void
    {
        $shared       = self::bag('shared');
        $shared[$key] = $value;
        self::putBag('shared', $shared);
    }

    public static function mergeShared(array $data): void
    {
        self::putBag('shared', array_merge(self::bag('shared'), $data));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::bag('shared')[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::bag('shared');
    }

    public static function forget(string $key): void
    {
        $shared = self::bag('shared');
        unset($shared[$key]);
        self::putBag('shared', $shared);
    }

    // === Meta / SEO ===

    public static function setMeta(string $key, string $value): void
    {
        $meta       = self::bag('meta');
        $meta[$key] = $value;
        self::putBag('meta', $meta);
    }

    public static function setMetaBulk(array $data): void
    {
        self::putBag('meta', array_merge(self::bag('meta'), $data));
    }

    public static function getMeta(string $key, ?string $default = null): ?string
    {
        return static::$meta[$key] ?? $default;
    }

    public static function allMeta(): array
    {
        return static::$meta;
    }

    public static function renderMeta(callable|null $titleCallback = null): void
    {

        // Title
        if (!empty(static::$meta['title'])) {
            $title = static::$meta['title'];
            if ($titleCallback) {
                $title = $titleCallback($title);
            }
            static::$meta['title']  = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        
        do_action("head", self::bag('meta'));
    }

    // === Flash messages ===

    protected static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        // Under a coroutine, $_SESSION is a process global shared by every
        // in-flight request — writing to it leaks one user's flash to another.
        // Keep it request-scoped instead. NOTE: that makes flash same-request
        // only under Swoole; for flash that survives a redirect there, use the
        // Session plugin's SessionPort, which is coroutine-aware.
        if (self::context() !== null) {
            $flash       = self::bag('flash');
            $flash[$key] = $value;
            self::putBag('flash', $flash);

            return;
        }

        static::ensureSession();
        $_SESSION['__channel_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        if (self::context() !== null) {
            $flash = self::bag('flash');

            if (!\array_key_exists($key, $flash)) {
                return $default;
            }

            $value = $flash[$key];
            unset($flash[$key]); // one-time
            self::putBag('flash', $flash);

            return $value;
        }

        static::ensureSession();
        if (isset($_SESSION['__channel_flash'][$key])) {
            $value = $_SESSION['__channel_flash'][$key];
            unset($_SESSION['__channel_flash'][$key]); // one-time
            return $value;
        }
        return $default;
    }

    public static function allFlash(): array
    {
        static::ensureSession();
        $all = $_SESSION['__channel_flash'] ?? [];
        $_SESSION['__channel_flash'] = []; // clear after read
        return $all;
    }

    // === Error bags ===

    public static function errorBag(string $name, array $errors): void
    {
        static::ensureSession();
        $_SESSION['__channel_errors'][$name] = $errors;
    }

    public static function getErrors(string $name): array
    {
        static::ensureSession();
        return $_SESSION['__channel_errors'][$name] ?? [];
    }

    public static function allErrorBags(): array
    {
        static::ensureSession();
        return $_SESSION['__channel_errors'] ?? [];
    }

    public static function clearErrorBag(string $name): void
    {
        static::ensureSession();
        unset($_SESSION['__channel_errors'][$name]);
    }

    // === Bootstrap script for client ===

    public static function renderSharedScript(): string
    {
        $payload = [
            'props' => static::$shared,
            'meta' => static::$meta,
            'flash' => static::allFlash(),
            'errors' => static::allErrorBags(),
        ];

        // JSON_HEX_TAG escapes '<' and '>' so no shared prop, flash message or
        // validation error can close this <script> element and continue as
        // markup. It matters more here than anywhere else in this class:
        // JSON_UNESCAPED_SLASHES leaves the '/' of '</script>' intact, so
        // without the hex escaping the sequence survives the encoder whole.
        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return "<script>window.__BOOTSTRAP_DATA__ = $json;</script>";
    }
}
