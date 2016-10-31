<?php

namespace go1\graph_mock;

use go1\util\GraphEdgeTypes;
use GraphAware\Neo4j\Client\Client;

trait GraphEnrolmentMockTrait
{
    public function createGraphEnrolment(Client $client, array $options)
    {
        static $autoEnrolmentId;

        $hasGroup = GraphEdgeTypes::HAS_GROUP;
        $hasMember = GraphEdgeTypes::HAS_MEMBER;

        $enrolment = [
            'user_id' => isset($options['user_id']) ? $options['user_id'] : 1,
            'lo_id'   => isset($options['lo_id']) ? $options['lo_id'] : 1,
            'status'  => isset($options['status']) ? $options['status'] : 'in-progress',
            'result'  => isset($options['result']) ? $options['result'] : null,
            'pass'    => isset($options['pass']) ? $options['pass'] : 0,
        ];

        $client
            ->run(
                "MERGE (user:User { id: {$enrolment['user_id']} })"
              . " MERGE (lo:Group { name: {enrolmentName} })"
              . " MERGE (user)-[r:$hasGroup]->(lo) ON CREATE SET r = {data} ON MATCH set r = {data}"
              . " MERGE (lo)-[:$hasMember]->(user)",
                [
                    'enrolmentName' => "lo:{$enrolment['lo_id']}",
                    'data'          => [
                        'id'     => ++$autoEnrolmentId,
                        'status' => $enrolment['status'],
                        'result' => $enrolment['result'],
                        'pass'   => $enrolment['pass'],
                    ],
                ]
            );

        return $autoEnrolmentId;
    }
}
