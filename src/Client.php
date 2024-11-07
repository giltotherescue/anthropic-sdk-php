<?php

namespace WpAi\Anthropic;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use WpAi\Anthropic\Exceptions\ClientException as AnthropicClientException;
use WpAi\Anthropic\Responses\ErrorResponse;
use WpAi\Anthropic\Responses\StreamResponse;

class Client
{
    private GuzzleClient $client;

    public function __construct(private string $baseUrl, private array $headers = [])
    {
        $stack = \GuzzleHttp\HandlerStack::create();
        if (class_exists('\Illuminate\Support\Facades\Log')) {
            $stack->push($this->createLoggingMiddleware());
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => $headers,
            'handler' => $stack,
        ]);
    }

    private function createLoggingMiddleware(): callable
    {
        return function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $start = microtime(true);
                
                return $handler($request, $options)->then(
                    function ($response) use ($request, $start, $options) {
                        $duration = microtime(true) - $start;
                        
                        $context = [
                            'anthropic' => true,
                            'method' => $request->getMethod(),
                            'uri' => (string) $request->getUri(),
                            'headers' => $request->getHeaders(),
                            'body' => json_decode((string) $request->getBody(), true),
                            'duration' => round($duration * 1000, 2) . 'ms',
                            'response_status' => $response->getStatusCode(),
                            'response_headers' => $response->getHeaders(),
                            'response_body' => !isset($options['stream']) ? 
                                json_decode((string) $response->getBody(), true) : 
                                '[STREAM]'
                        ];

                        \Illuminate\Support\Facades\Log::debug('Anthropic API Request', $context);

                        return $response;
                    },
                    function ($reason) use ($request) {
                        \Illuminate\Support\Facades\Log::error('Anthropic API Error', [
                            'anthropic' => true,
                            'method' => $request->getMethod(),
                            'uri' => (string) $request->getUri(),
                            'error' => $reason->getMessage()
                        ]);
                        
                        throw $reason;
                    }
                );
            };
        };
    }

    public function post(string $endpoint, array $args, array $extraHeaders = []): ResponseInterface|ErrorResponse
    {
        try {
            return $this->client->post($endpoint, [
                'json' => $args,
                'headers' => array_merge($this->client->getConfig('headers'), $extraHeaders),
            ]);
        } catch (RequestException $e) {
            $this->badRequest($e);
        }
    }

    public function stream(string $endpoint, array $args, array $extraHeaders = []): StreamResponse
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $args,
                'stream' => true,
                'headers' => array_merge($this->client->getConfig('headers'), $extraHeaders),
            ]);

            return new StreamResponse($response);
        } catch (RequestException $e) {
            $this->badRequest($e);
        }
    }

    private function badRequest(RequestException $e): void
    {
        $response = $e->getResponse();
        $error = (new ErrorResponse($response))->getError();

        throw new AnthropicClientException($error->getMessage(), $response->getStatusCode(), $e);
    }
}
