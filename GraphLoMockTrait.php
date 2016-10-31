<?php

namespace go1\graph_mock;

use go1\util\EdgeTypes;
use go1\util\GraphEdgeTypes;
use GraphAware\Neo4j\Client\Client;
use GraphAware\Neo4j\Client\Stack;

trait GraphLoMockTrait
{
    public function createGraphTag(Client $client, $instanceId, $tag, $parentName = null)
    {
        static $autoTagId;
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;
        $hasItem    = GraphEdgeTypes::HAS_ITEM;

        $q = "MERGE (portal:Group { name: {portalName} })"
           . " MERGE (tag:Tag { name: {tagName} })-[:$hasGroup]->(portal)"
           . " MERGE (portal)-[:$hasMember]->(tag)"
           . "     ON CREATE SET tag.id = {tagId}"
           . "     ON MATCH SET tag.id = {tagId}";
        $p = [
            'portalName' => "portal:$instanceId",
            'tagId'      => ++$autoTagId,
            'tagName'    => $tag,
        ];
        if ($parentName) {
            $q .= " MERGE (parent:Tag { name: {parentName} })-[:$hasGroup]->(portal)"
                . " MERGE (portal)-[:$hasMember]->(parent)"
                . "     ON CREATE SET parent.id = {parentId}"
                . "     ON MATCH SET parent.id = coalesce(parent.id, {parentId})"
                . " MERGE (parent)-[:$hasItem]->(tag)";
            $p += [
                'parentId'   => ++$autoTagId,
                'parentName' => $parentName,
            ];
        }
        $q .= " RETURN tag.id";

        $result = $client->run($q, $p);

        return $result->firstRecord()->get('tag.id');
    }

    public function createGraphLearningPathway(Client $client, array $options = [])
    {
        return $this
            ->createGraphCourse(
                $client,
                ['type' => GraphEdgeTypes::type('learning_pathway')] + $options
            );
    }

    public function createGraphModule(Client $client, array $options = [])
    {
        return $this
            ->createGraphCourse(
                $client,
                ['type' => GraphEdgeTypes::type('module')] + $options
            );
    }

    public function createGraphCourse(Client $client, array $options = [])
    {
        // tags has prop weight
        static $autoCourseId;
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;

        $course = [
            'type'          => isset($options['type']) ? GraphEdgeTypes::type($options['type']) : 'Course',
            'id'            => isset($options['id']) ? $options['id'] : ++$autoCourseId,
            'title'         => isset($options['title']) ? $options['title'] : 'Example course',
            'instance_id'   => $instanceId = isset($options['instance_id']) ? $options['instance_id'] : 0,
            'private'       => isset($options['private']) ? $options['private'] : 0,
            'published'     => isset($options['published']) ? $options['published'] : 1,
            'marketplace'   => isset($options['marketplace']) ? $options['marketplace'] : 0,
            'privacy'       => isset($options['privacy']) ? $options['privacy'] : [],
            'tags'          => isset($options['tags']) ? $options['tags'] : [],
            'groups'        => isset($options['groups']) ? $options['groups'] : [],
            'tutors'        => isset($options['tutors']) ? $options['tutors'] : [],
            'authors'       => isset($options['authors']) ? $options['authors'] : [],
            'roles'         => isset($options['roles']) ? $options['roles'] : [],
            'event'         => isset($options['event']) ? $options['event'] : [],
            'parents'       => isset($options['parents']) ? $options['parents'] : [],
        ];
        if (!empty($options['price'])) {
            $course['pricing'] = [
                'price'     => $options['price']['price'],
                'currency'  => $options['price']['currency'],
                'tax'       => $options['price']['tax'],
            ];
        }

        $stack = $client->stack();
        $stack->push(
            "MERGE (lo:{$course['type']}:Group { id: {$course['id']}, name: {name} }) ON CREATE SET lo += {lo} ON MATCH SET lo += {lo}"
          . " MERGE (lo)-[:$hasGroup]->(lo)"
          . " MERGE (lo)-[:$hasMember]->(lo)",
            [
                'name' => "lo:{$course['id']}",
                'lo' => ['title' => $course['title']],
            ]
        );

        $this
            ->linkCourseAuthors($stack, $course)
            ->linkCourseTutors($stack, $course)
            ->linkCoursePortal($stack, $course)
            ->linkCourseRoles($stack, $course)
            ->linkCoursePublicGroup($stack, $course)
            ->linkCourseMarketplace($stack, $course)
            ->linkCoursePrivacy($stack, $course)
            ->linkCoursePricing($stack, $course)
            ->linkCourseTags($stack, $course)
            ->linkEvent($stack, $course)
            ->linkParent($stack, $course);

        $client->runStack($stack);

        return $course['id'];
    }

    private function linkCourseAuthors(Stack $stack, $course)
    {
        $hasAuthor = GraphEdgeTypes::HAS_AUTHOR;
        $hasLO = GraphEdgeTypes::HAS_LO;

        foreach ($course['authors'] as $authorId) {
            $stack->push(
                "MATCH (lo:{$course['type']} { id: {$course['id']} })"
              . " MERGE (author:User { id: $authorId })"
              . " MERGE (lo)-[r:$hasAuthor]->(author)"
              . " MERGE (author)-[:$hasLO]->(lo)"
            );
        }

        return $this;
    }

    private function linkCourseTutors(Stack $stack, $course)
    {
        $hasTutor = GraphEdgeTypes::HAS_TUTOR;
        $hasLO = GraphEdgeTypes::HAS_LO;

        foreach ($course['tutors'] as $tutorId) {
            $stack->push(
                "MATCH (lo:{$course['type']} { id: {$course['id']} })"
              . " MERGE (tutor:User { id: $tutorId })"
              . " MERGE (lo)-[r:$hasTutor]->(tutor)"
              . " MERGE (tutor)-[:$hasLO]->(lo)"
            );
        }

        return $this;
    }

    private function linkCoursePortal(Stack $stack, $course)
    {
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;

        if ($course['instance_id']) {
            $q = "MATCH (lo:{$course['type']} { id: {$course['id']} })"
               . " MERGE (portal:Group { name: {portal} })"
               . " MERGE (lo)-[:$hasGroup]->(portal)"
               . " MERGE (portal)-[:$hasMember]->(lo)";
            $stack->push($q, ['portal' => "portal:{$course['instance_id']}"]);

            if (!empty($course['roles']) || !empty($course['privacy'] || $course['private'])) {
                $q = "MATCH (portal:Group)-[r:$hasMember]->(lo:{$course['type']})"
                   . " WHERE portal.name = {portal} AND lo.id = {$course['id']}"
                   . " DELETE r";
                $stack->push($q, ['portal' => "portal:{$course['instance_id']}"]);
            }
        }

        return $this;
    }

    private function linkCourseRoles(Stack $stack, $course)
    {
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;

        foreach ($course['roles'] as $role) {
            $q = "MATCH (lo:{$course['type']} {id: {$course['id']} })";
            if ($portalId = $course['instance_id']) {
                $q .= ", (portal:Group { name: {portalName} })"
                    . " MERGE (role:Group { name: {role} })-[:$hasGroup]->(portal)";
                $p = ['role' => "role:$role", 'portalName' => "portal:$portalId"];
            } else {
                $q .= " MERGE (role:Group { name: {role} })";
                $p = ['role' => "role:$role"];
            }
            $q .= " MERGE (lo)-[:$hasGroup]->(role)"
                . " MERGE (role)-[:$hasMember]->(lo)";
            $stack->push($q, $p);
        }

        return $this;
    }

    private function linkCoursePublicGroup(Stack $stack, $course)
    {
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;

        if (!$course['private'] && $course['published'] && empty($course['roles'])) {
            $stack->push(
                "MATCH (lo:{$course['type']} {id: {$course['id']} })"
              . " MERGE (public:Group { name: 'public' })"
              . " MERGE (lo)-[:$hasGroup]->(public)"
              . " MERGE (public)-[:$hasMember]->(lo)"
            );
        }
        else {
            $stack->push(
                "MATCH (lo:{$course['type']} {id: {$course['id']} })"
              . " MATCH (public:Group { name: 'public' })"
              . " MATCH (lo)-[r:$hasGroup]->(public)"
              . " MATCH (public)-[rr:$hasMember]->(lo)"
              . " DELETE r, rr"
            );
        }

        return $this;
    }

    private function linkCourseMarketplace(Stack $stack, $course)
    {
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;

        if (!$course['private'] && $course['published'] && $course['marketplace'] && empty($course['roles'])) {
            $stack->push(
                "MATCH (lo:{$course['type']} {id: {$course['id']} })"
              . " MERGE (marketplace:Group { name: 'marketplace' })"
              . " MERGE (lo)-[:$hasGroup]->(marketplace)"
              . " MERGE (marketplace)-[:$hasMember]->(lo)"
            );
        }
        else {
            $stack->push(
                "MATCH (lo:{$course['type']} {id: {$course['id']} })"
              . " MATCH (marketplace:Group { name: 'marketplace' })"
              . " MATCH (lo)-[r:$hasGroup]->(marketplace)"
              . " MATCH (marketplace)-[rr:$hasMember]->(lo)"
              . " DELETE r, rr"
            );
        }

        return $this;
    }

    private function linkCoursePrivacy(Stack $stack, $course)
    {
        $hasMember = GraphEdgeTypes::HAS_MEMBER;
        $hasSharedLO = GraphEdgeTypes::HAS_SHARED_LO;

        if ($course['privacy']) {
            foreach ($course['privacy'] as $privacy) {
                $weight = isset($privacy['weight']) ? $privacy['weight'] : 0;
                $targetId = isset($privacy['target_id']) ? $privacy['target_id'] : 0;
                $weight == 0 && $stack->push(
                    "MATCH (lo:{$course['type']} {id: {$course['id']} })"
                  . " MERGE (shareUser:User { id: {$targetId} })"
                  . " MERGE (lo)-[:$hasMember]->(shareUser)"
                  . " MERGE (shareUser)-[:$hasSharedLO]->(lo)"
                );
            }
        }
        else {
            $stack->push(
                "MATCH (lo:{$course['type']} {id: {$course['id']}})"
              . " MATCH (shareUser:User)"
              . " MATCH (shareUser)-[r:$hasSharedLO]->(lo)"
              . " MATCH (lo)-[rr:$hasMember]->(shareUser)"
              . " DELETE r, rr"
            );
        }

        return $this;
    }

    private function linkCoursePricing(Stack $stack, $course)
    {
        $hasProduct = GraphEdgeTypes::HAS_PRODUCT;

        if (isset($course['pricing']) && is_array($course['pricing'])) {
            $stack->push(
                "MATCH (lo:{$course['type']} { id: {$course['id']} })"
              . " MERGE (product:Product { id: {$course['id']} }) ON CREATE SET product += {product} ON MATCH SET product += {product}"
              . " MERGE (lo)-[:$hasProduct]->(product)",
                ['product' => $course['pricing']]
            );
        }
        else {
            $stack->push(
                "MATCH (product:Product {id: {$course['id']} }) DELETE product"
            );
        }

        return $this;
    }

    private function linkCourseTags(Stack $stack, $course)
    {
        $hasGroup   = GraphEdgeTypes::HAS_GROUP;
        $hasMember  = GraphEdgeTypes::HAS_MEMBER;
        $hasTag     = GraphEdgeTypes::HAS_TAG;

        if (isset($course['tags']) && is_array($course['tags'])) {
            foreach ($course['tags'] as $tag) {
                $stack->push(
                    "MATCH (lo:{$course['type']} { id: {$course['id']} })"
                  . " MERGE (portal:Group { name: {portal} })"
                  . " MERGE (tag:Tag { name: {name} })-[:$hasGroup]->(portal)"
                  . " MERGE (portal)-[r:$hasMember]->(tag)"
                  . "  ON CREATE SET r.count = 1"
                  . "  ON MATCH SET r.count = coalesce(r.count, 0) + 1"
                  . " MERGE (lo)-[:$hasTag]->(tag)",
                    [
                        'name'      => $tag,
                        'portal'    => "portal:{$course['instance_id']}",
                    ]
                );
            }
        }

        return $this;
    }

    private function linkEvent(Stack $stack, $course)
    {
        $hasEvent = GraphEdgeTypes::HAS_EVENT;

        if ($course['event']) {
            $stack->push(
                "MATCH (lo:{$course['type']} { id: {$course['id']} })"
              . " MERGE (event:Event {id: {$course['id']}}) ON CREATE SET event += {event} ON MATCH SET event += {event}"
              . " MERGE (lo)-[:$hasEvent]->(event)",
                ['event' => (array) $course['event']]
            );
        }
        else {
            $stack->push("MATCH (event:Event {id: {$course['id']} }) DELETE event");
        }

        return $this;
    }

    private function linkParent(Stack $stack, $course)
    {
        $hasItem = GraphEdgeTypes::HAS_ITEM;

        $stack->push("MATCH (lo:{$course['type']} { id: {$course['id']} }) MATCH ()-[r:$hasItem]->(lo) DELETE r");
        foreach ($course['parents'] as $item) {
            $itemType = isset($item['type']) ? $item['type'] : 'Course';
            $itemType = GraphEdgeTypes::type($itemType);
            $stack->push(
                "MATCH (lo:{$course['type']} { id: {$course['id']} })"
              . " MERGE (parent:{$itemType} { id: {$item['id']} })"
              . " MERGE (parent)-[r:$hasItem]->(lo) SET r.elective = {elective}",
                ['elective' => in_array($item['edge_type'], [EdgeTypes::HAS_ELECTIVE_LO, EdgeTypes::HAS_ELECTIVE_LI])]
            );
        }

        return $this;
    }
}
