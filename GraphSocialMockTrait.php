<?php

namespace go1\graph_mock;

use go1\util\GraphEdgeTypes;
use GraphAware\Neo4j\Client\Client;
use GraphAware\Neo4j\Client\Stack;

trait GraphSocialMockTrait
{
    private $hasTag;
    private $hasAccount;
    private $hasGroup;
    private $hasGroupOwn;
    private $hasMember;
    private $hasFollowing;
    private $hasFollower;

    public function __construct()
    {
        $this->hasTag = GraphEdgeTypes::HAS_TAG;
        $this->hasAccount = GraphEdgeTypes::HAS_ACCOUNT;
        $this->hasGroup = GraphEdgeTypes::HAS_GROUP;
        $this->hasGroupOwn = GraphEdgeTypes::HAS_GROUP_OWN;
        $this->hasMember = GraphEdgeTypes::HAS_MEMBER;
        $this->hasFollowing = GraphEdgeTypes::HAS_FOLLOWING;
        $this->hasFollower = GraphEdgeTypes::HAS_FOLLOWER;
    }

    protected function addGraphUserTag(Client $client, $userId, $tagNames) {
        $stack = $client->stack();
        $stack->push(
            "MATCH (u:User { id: {$userId} })"
            . " MATCH (u)-[r:{$this->hasTag}]->(:Tag)"
            . " DELETE r"
        );

        $stack->push(
            "MATCH (u:User)-[:{$this->hasAccount}]->(o:User)-[:{$this->hasGroup}]->(p:Group)-[:{$this->hasMember}]->(t:Tag)"
            . " WHERE p.name STARTS WITH 'portal:' AND u.id = {$userId} AND t.name IN {tagNames}"
            . " MERGE (u)-[:{$this->hasTag}]->(t)",
            ['tagNames' => $tagNames]
        );

        $client->runStack($stack);
    }

    protected function followGraph(Client $client, $sourceId, $targetId) {
        $query = "MATCH (A:User { id: {$sourceId} })"
            . " MATCH (B:User { id: {$targetId} })"
            . " MERGE (A)-[r:{$this->hasFollowing}]->(B)"
            . " MERGE (B)-[rr:{$this->hasFollower}]->(A)";

        $client->run($query);
    }

    protected function createGraphGroup(Client $client, $id, $authorId = 0, $title = '', $created = 0, $type = 'public') {
        $title = $title ? $title : uniqid('group');
        $created = $created ? $created : time();
        $authorId = $authorId ? $authorId : 1;

        $stack = $client->stack();
        $stack->push("MERGE (g:Group { id: {$id} }) SET g += {data}",
             [
                'data' => [
                    'name'          => "group:{$id}",
                    'title'         => $title,
                    'created'       => (int)$created,
                    'type'          => $type,
                ]
            ]
        );

        if ($type == 'public') {
            $stack->push(
                    "MATCH (g:Group { id: {$id}})"
                    . " MATCH (p:Group { name: {public}})"
                    . " MERGE (g)-[:{$this->hasGroup}]->(p)"
                    . " MERGE (p)-[:{$this->hasMember}]->(g)",
                ['public' => 'public']
            );
        }

        $stack->push(
                "MATCH (u:User { id: {$authorId}})"
                . " MATCH (g:Group { id: {$id}})"
                . " MERGE (u)-[:{$this->hasGroupOwn}]->(g)"
                . " MERGE (g)-[:{$this->hasMember}]->(u)"
        );

        $client->runStack($stack);
    }

    protected function addGraphUserGroup(Client $client, $userId, $groupId) {
        $query = "MATCH (u:User { id: {$userId} })"
            . " MATCH (g:Group { id: {$groupId} })"
            . " MERGE (u)-[:{$this->hasGroup}]->(g)"
            . " MERGE (g)-[:{$this->hasMember}]->(u)";

        $client->run($query);
    }
}
