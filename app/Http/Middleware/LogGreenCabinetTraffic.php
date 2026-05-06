<?php

namespace App\Http\Middleware;

use App\GameProtocol\Green\Support\FormPayloads;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogGreenCabinetTraffic
{
    private const BODY_CAPTURE_BYTES = 65536;

    public function __construct(private readonly FormPayloads $forms) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('taiko_green.traffic_log_enabled')) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $rawRequestBody = $this->rawRequestBody($request);

        try {
            $response = $next($request);
        } catch (Throwable $throwable) {
            $this->write([
                'ts' => now()->toIso8601String(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'request' => $this->requestData($request, $rawRequestBody),
                'exception' => [
                    'class' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ],
            ]);

            throw $throwable;
        }

        $this->write([
            'ts' => now()->toIso8601String(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
            'request' => $this->requestData($request, $rawRequestBody),
            'response' => $this->responseData($response),
        ]);

        return $response;
    }

    private function rawRequestBody(Request $request): string
    {
        $body = $request->getContent();

        if ($body !== '') {
            return $body;
        }

        if ($request->request->count() === 1) {
            return (string) array_key_first($request->request->all());
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function requestData(Request $request, string $rawBody): array
    {
        return [
            'method' => $request->method(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'path' => '/'.$request->path(),
            'query' => $request->query->all(),
            'form' => $request->request->all(),
            'allnet_decoded_form' => $this->forms->decodeAllNetRequest($rawBody),
            'client_ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'body' => $this->bodyData($rawBody),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        $content = $response->getContent();

        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'body' => $this->bodyData(is_string($content) ? $content : ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyData(string $body): array
    {
        $captured = substr($body, 0, self::BODY_CAPTURE_BYTES);

        return [
            'bytes' => strlen($body),
            'sha256' => hash('sha256', $body),
            'truncated' => strlen($body) > self::BODY_CAPTURE_BYTES,
            'text' => $this->textPreview($captured),
            'base64' => base64_encode($captured),
            'hex_prefix' => bin2hex(substr($body, 0, 128)),
        ];
    }

    private function textPreview(string $body): ?string
    {
        if ($body === '') {
            return '';
        }

        return preg_match('/^[\P{C}\r\n\t]+$/u', $body) === 1 ? $body : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function write(array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($line)) {
            return;
        }

        try {
            file_put_contents(storage_path('logs/taiko-green-traffic.jsonl'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            //
        }
    }
}
