<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Graphql\SchemaFactory;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphqlController
{
    private Schema $schema;

    public function __construct()
    {
        $this->schema = SchemaFactory::createSchema();
    }

    public function __invoke(Request $request): Response
    {
        try {
            $input = json_decode($request->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new Response(
                json_encode(['errors' => [['message' => 'invalid_json']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                400,
                ['Content-Type' => 'application/json']
            );
        }

        $query = $input['query'] ?? null;
        $variables = $input['variables'] ?? null;
        $operationName = $input['operationName'] ?? null;

        if ($query === null) {
            return new Response(json_encode(['errors' => [['message' => 'Missing query']]]), 400, ['Content-Type' => 'application/json']);
        }

        $result = GraphQL::executeQuery(
            $this->schema,
            $query,
            null,
            ['request' => $request],
            $variables,
            $operationName
        );

        $output = $result->toArray();

        return new Response(json_encode($output), 200, ['Content-Type' => 'application/json']);
    }
}
