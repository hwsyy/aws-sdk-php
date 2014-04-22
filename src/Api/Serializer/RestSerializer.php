<?php
namespace Aws\Api\Serializer;

use Aws\Api\MapShape;
use Aws\Api\Service;
use Aws\Api\Operation;
use Aws\Api\Shape;
use Aws\Api\StructureShape;
use GuzzleHttp\Command\Event\PrepareEvent;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Url;
use GuzzleHttp\Stream;

/**
 * Serializes HTTP locations like header, uri, payload, etc...
 * @internal
 */
abstract class RestSerializer implements SubscriberInterface
{
    /** @var Service */
    private $api;

    /** @var Url */
    private $endpoint;

    /**
     * @param string $endpoint Endpoint to connect to
     * @param Service  $api      Service API description
     */
    public function __construct($endpoint, Service $api)
    {
        $this->api = $api;
        $this->endpoint = Url::fromString($endpoint);
    }

    public function getEvents()
    {
        return ['prepare' => ['onPrepare']];
    }

    public function onPrepare(PrepareEvent $event)
    {
        /** @var \Aws\AwsCommandInterface $command */
        $command = $event->getCommand();
        $api = $command->getApi();
        $operation = $api->getOperation($command->getName());
        $args = $command->toArray();

        $request = $event->getClient()->getHttpClient()->createRequest(
            $operation['http']['method'],
            $this->buildEndpoint($operation, $args),
            ['config' => ['command' => $command]]
        );

        $event->setRequest($this->serialize($request, $operation, $args));
    }

    /**
     * Applies a payload body to a request.
     *
     * @param RequestInterface $request Request to apply.
     * @param StructureShape   $member  Member to serialize
     * @param array            $value   Value to serialize
     *
     * @return \GuzzleHttp\Stream\StreamInterface
     */
    abstract protected function payload(
        RequestInterface $request,
        StructureShape $member,
        array $value
    );

    private function serialize(
        RequestInterface $request,
        Operation $operation,
        array $args
    ) {
        $input = $operation->getInput();

        // Apply the payload trait if present
        if ($payload = $input['payload']) {
            $this->applyPayload($request, $input, $payload, $args);
        }

        foreach ($args as $name => $value) {
            if ($member = $input->getMember($name)) {
                $location = $member['location'];
                if (!$payload && !$location) {
                    $bodyMembers[$name] = $value;
                } elseif ($location == 'header') {
                    $this->applyHeader($request, $name, $member, $value);
                } elseif ($location == 'querystring') {
                    $this->applyQuery($request, $name, $member, $value);
                }
            }
        }

        if (isset($bodyMembers)) {
            $this->payload($request, $operation->getInput(), $bodyMembers);
        }

        return $request;
    }

    private function applyPayload(
        RequestInterface $request,
        StructureShape $input,
        $name,
        array $args
    ) {
        if (!isset($args[$name])) {
            return;
        }

        $m = $input->getMember($name);

        if ($m['streaming'] ||
           ($m['type'] == 'string' || $m['type'] == 'blob')
        ) {
            // Streaming bodies or payloads that are strings are
            // always just a stream of data.
            $request->setBody(Stream\create($args[$name]));
        } else {
            $this->payload($request, $m, $args[$name]);
        }
    }

    private function applyHeader(
        RequestInterface $request,
        $name,
        Shape $member,
        $value
    ) {
        if ($member instanceof MapShape) {
            $this->applyHeaderMap($request, $name, $member, $value);
        } else {
            $request->setHeader($member['locationName'] ?: $name, $value);
        }
    }

    /**
     * Note: This is currently only present in the Amazon S3 model.
     */
    private function applyHeaderMap(
        RequestInterface $request,
        $name,
        Shape $member,
        $value
    ) {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("$name must be an array.");
        }

        $prefix = $member['locationName'];
        foreach ($value as $k => $v) {
            $request->setHeader($prefix . $k, $v);
        }
    }

    private function applyQuery(
        RequestInterface $request,
        $name,
        Shape $member,
        $value
    ) {
        $request->getQuery()->set($member['locationName'] ?: $name, $value);
    }

    /**
     * Builds the URI template for a REST based request.
     *
     * @param Operation $operation
     * @param array     $args
     *
     * @return array
     */
    private function buildEndpoint(Operation $operation, array $args)
    {
        $uri = $this->endpoint->combine($operation['http']['requestUri']);
        $varspecs = [];

        foreach ($operation->getInput()->getMembers() as $name => $member) {
            if ($member['location'] == 'uri') {
                $varspecs[$member['locationName'] ?: $name] =
                    isset($args[$name]) ? $args[$name] : null;
            }
        }

        return [$uri, $varspecs];
    }
}
