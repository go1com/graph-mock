<?php

namespace go1\graph_mock;

use GraphAware\Neo4j\Client\Client;

trait GraphInstanceMockTrait
{
    public function createGraphInstance(Client $client, array $options = [])
    {
        static $autoInstanceId;

        $portal['id'] = isset($options['id']) ? $options['id'] : ++$autoInstanceId;

        $client->run(
            "MERGE (portal:Group { name: {portalName} })",
            ['portalName' => "portal:{$portal['id']}"]
        );

        return $portal['id'];
    }
}
