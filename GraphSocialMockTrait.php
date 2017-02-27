<?php

namespace go1\graph_mock;

use go1\util\GraphEdgeTypes;
use go1\util\GroupStatus;
use GraphAware\Neo4j\Client\Client;

trait GraphSocialMockTrait
{
    private $hasTag = GraphEdgeTypes::HAS_TAG;
    private $hasAccount = GraphEdgeTypes::HAS_ACCOUNT;
    private $hasGroup = GraphEdgeTypes::HAS_GROUP;
    private $hasGroupOwn = GraphEdgeTypes::HAS_GROUP_OWN;
    private $hasMember = GraphEdgeTypes::HAS_MEMBER;
    private $hasFollowing = GraphEdgeTypes::HAS_FOLLOWING;
    private $hasFollower = GraphEdgeTypes::HAS_FOLLOWER;

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

    protected function createGraphGroup(Client $client, array $option) {
        static $autoId = 1;

        $id = isset($option['id']) ? $option['id'] : $autoId++;

        $stack = $client->stack();
        $stack->push("MERGE (g:Group { id: {$id}, name: {name} }) SET g += {data}",
            [
                'name' => "group:{$id}",
                'data' => [
                    'title'         => isset($option['title']) ? $option['title'] : uniqid('group'),
                    'created'       => isset($option['created']) ? $option['created'] : time(),
                    'visibility'    => isset($option['visibility']) ? $option['visibility'] : GroupStatus::PUBLIC
                ]
            ]
        );

        $instanceId = isset($option['instance_id']) ? $option['instance_id'] : 0;
        $instanceId && $stack->push(
            " MATCH (g:Group { id: {$id}, name: {groupName} })"
            . "MERGE (p:Group { name: {portalName} })"
            . " MERGE (g)-[:{$this->hasGroup}]->(p)"
            . " MERGE (p)-[:{$this->hasMember}]->(g)",
            ['portalName' => "portal:{$instanceId}", 'groupName' => "group:{$id}"]
        );

        $subAuthorId = isset($option['account_id']) ? $option['account_id'] : 0;
        $subAuthorId && $stack->push(
            " MATCH (g:Group { id: {$id}, name: {groupName}})"
            . "MERGE (sub:User { id: {$subAuthorId} })"
            . " MERGE (sub)-[:{$this->hasGroupOwn}]->(g)"
            . " MERGE (g)-[:{$this->hasMember}]->(sub)",
            ['groupName' => "group:{$id}"]
        );

        $client->runStack($stack);
    }

    protected function addGraphUserGroup(Client $client, $userId, $groupId) {
        $query = "MATCH (u:User { id: {$userId} })"
            . " MATCH (g:Group { id: {$groupId}, name: {groupName} })"
            . " MERGE (u)-[:{$this->hasGroup}]->(g)"
            . " MERGE (g)-[:{$this->hasMember}]->(u)";

        $client->run($query, ['groupName' => "group:{$groupId}"]);
    }
}
