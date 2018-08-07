<?php
/*
 * Copyright 2018, Google Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
namespace Google\ApiCore\Transport;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\Call;
use Google\ApiCore\ServiceAddressTrait;
use Google\ApiCore\ValidationException;
use Google\ApiCore\ValidationTrait;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Status;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * A transport that sends protobuf over HTTP 1.1 that can be used when full gRPC support
 * is not available.
 */
class GrpcFallbackTransport implements TransportInterface
{
    use ValidationTrait;
    use ServiceAddressTrait;
    use HttpUnaryTransportTrait;

    private $baseUri;

    /**
     * @param string $baseUri
     * @param callable $httpHandler A handler used to deliver PSR-7 requests.
     */
    public function __construct(
        $baseUri,
        callable $httpHandler
    ) {
        $this->baseUri = $baseUri;
        $this->httpHandler = $httpHandler;
        $this->transportName = 'grpc-fallback';
    }

    /**
     * Builds a GrpcFallbackTransport.
     *
     * @param string $serviceAddress
     *        The address of the API remote host, for example "example.googleapis.com".
     * @param array $config {
     *    Config options used to construct the grpc-fallback transport.
     *
     *    @type callable $httpHandler A handler used to deliver PSR-7 requests.
     * }
     * @return GrpcFallbackTransport
     * @throws ValidationException
     */
    public static function build($serviceAddress, array $config = [])
    {
        $config += [
            'httpHandler'  => null,
        ];
        list($baseUri, $port) = self::normalizeServiceAddress($serviceAddress);
        $httpHandler = $config['httpHandler'] ?: self::buildHttpHandlerAsync();
        return new GrpcFallbackTransport($baseUri, $httpHandler);
    }

    /**
     * {@inheritdoc}
     */
    public function startUnaryCall(Call $call, array $options)
    {
        $httpHandler = $this->httpHandler;
        return $httpHandler(
            $this->buildRequest($call, $options),
            $this->getCallOptions($options)
        )->then(
            function (ResponseInterface $response) use ($options) {
                if (isset($options['metadataCallback'])) {
                    $metadataCallback = $options['metadataCallback'];
                    $metadataCallback($response->getHeaders());
                }
                return $response;
            }
        )->then(
            function (ResponseInterface $response) use ($call) {
                return $this->unpackResponse($call, $response);
            },
            function (\Exception $ex) {
                throw $this->transformException($ex);
            }
        );
    }

    /**
     * @param Call $call
     * @param array $options
     * @return Request
     */
    private function buildRequest(Call $call, array $options)
    {
        $headers = self::buildCommonHeaders($options);

        // It is necessary to supply 'grpc-web' in the 'x-goog-api-client' header
        // when using the grpc-fallback protocol.
        if (!isset($headers['x-goog-api-client'])) {
            $headers['x-goog-api-client'] = [];
        }
        $headers['x-goog-api-client'][] = 'grpc-web';

        // Uri format: https://<service>/$rpc/<method>
        $uri = "https://{$this->baseUri}/\$rpc/{$call->getMethod()}";

        return new Request(
            'POST',
            $uri,
            ['Content-Type' => 'application/x-protobuf'] + $headers,
            $call->getMessage()->serializeToString()
        );
    }

    /**
     * @param Call $call
     * @param ResponseInterface $response
     * @return Message
     */
    private function unpackResponse(Call $call, ResponseInterface $response)
    {
        $decodeType = $call->getDecodeType();
        /** @var Message $return */
        $return = new $decodeType;
        $return->mergeFromString((string)$response->getBody());
        return $return;
    }

    /**
     * @param array $options
     * @return array
     */
    private function getCallOptions(array $options)
    {
        $callOptions = isset($options['transportOptions']['grpc-fallbackOptions'])
            ? $options['transportOptions']['grpc-fallbackOptions']
            : [];

        if (isset($options['timeoutMillis'])) {
            $callOptions['timeout'] = $options['timeoutMillis'] / 1000;
        }

        return $callOptions;
    }

    /**
     * @param \Exception $ex
     * @return \Exception
     */
    private function transformException(\Exception $ex)
    {
        if ($ex instanceof RequestException && $ex->hasResponse()) {
            $res = $ex->getResponse();
            $body = (string) $res->getBody();
            $status = new Status();
            try {
                $status->mergeFromString($body);
                return ApiException::createFromRpcStatus($status);
            } catch (\Exception $ex) {
                $code = ApiStatus::rpcCodeFromHttpStatusCode($res->getStatusCode());
                return ApiException::createFromApiResponse($body, $code, null, $ex);
            }
        } else {
            throw $ex;
        }
    }
}