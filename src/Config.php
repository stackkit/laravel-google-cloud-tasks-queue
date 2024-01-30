<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Closure;
use Error;
use Exception;
use Safe\Exceptions\UrlException;

class Config
{
    /**
     * @param Closure|string $handler
     */
    public static function getHandler($handler): string
    {
        $handler = value($handler);

        try {
            $parse = \Safe\parse_url($handler);

            if (empty($parse['host'])) {
                throw new UrlException();
            }

            // A mistake which can unknowingly be made is that the task handler URL is
            // (still) set to localhost. That will never work because Cloud Tasks
            // should always call a public address / hostname to process tasks.
            if (in_array($parse['host'], ['localhost', '127.0.0.1', '::1'])) {
                throw new Exception(
                    sprintf(
                        'Unable to push task to Cloud Tasks because the handler URL is set to a local host: %s. ' .
                        'This does not work because Google is not able to call the given local URL. ' .
                        'If you are developing on locally, consider using Ngrok or Expose for Laravel to expose your local ' .
                        'application to the internet.',
                        $handler
                    )
                );
            }

            // When the application is running behind a proxy and the TrustedProxy middleware has not been set up yet,
            // an error like [HttpRequest.url must start with 'https'] could be thrown. Since the handler URL must
            // always be https, we will provide a little extra information on how to fix this.
            if ($parse['scheme'] !== 'https') {
                throw new Exception(
                    sprintf(
                        'Unable to push task to Cloud Tasks because the hander URL is not https. Google Cloud Tasks ' .
                        'will only call safe (https) URLs. If you are running Laravel behind a proxy (e.g. Ngrok, Expose), make sure it is ' .
                        'as a trusted proxy. To quickly fix this, add the following to the [app/Http/Middleware/TrustProxies] middleware: ' .
                        'protected $proxies = \'*\';'
                    )
                );
            }

            $trimmedHandlerUrl = rtrim($handler, '/');

            if (!str_ends_with($trimmedHandlerUrl, '/handle-task')) {
                return "$trimmedHandlerUrl/handle-task";
            }

            return $trimmedHandlerUrl;
        } catch (UrlException $e) {
            throw new Exception(
                'Unable to push task to Cloud Tasks because the task handler URL (' . $handler . ') is ' .
                'malformed. Please inspect the URL closely for any mistakes.'
            );
        }
    }

    /**
     * @param array $config
     * @return string|null The audience as an hash or null if not needed
     */
    public static function getAudience(array $config): ?string
    {
        return $config['signed_audience'] ?? true
            ? hash_hmac('sha256', self::getHandler($config['handler']), config('app.key'))
            : null;
    }
}
