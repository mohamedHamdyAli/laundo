<?php

namespace Laundo\SecondBrain\Graph;

/**
 * Nodes and edges, held as arrays.
 *
 * No value objects: the whole graph is written to JSON and read back by an MCP
 * process that has milliseconds to answer, and a hydration step over 4,000
 * objects to then re-encode them would be work done twice for no reader's
 * benefit.
 *
 * The one invariant worth enforcing is that an edge names two nodes that
 * exist. `prune()` drops the rest at the end of a build — a `calls` edge to
 * `Illuminate\Support\Facades\DB` is real but points outside the graph, and
 * keeping it would put framework classes in every dependency answer.
 */
final class Graph
{
    /** @var array<string,array<string,mixed>> */
    private array $nodes = [];

    /** @var array<string,array<string,mixed>> */
    private array $edges = [];

    /**
     * Adjacency, built on first use and thrown away whenever an edge is added.
     *
     * `out()` and `in()` used to scan all 21,000 edges per call. The feature
     * detector calls them thousands of times — once per entry-point walk, per
     * feature, per hop — so a build spent most of its time re-reading the same
     * edge list. With the index, a lookup costs the node's own degree.
     *
     * @var array<string,list<string>>|null
     */
    private ?array $outIndex = null;

    /** @var array<string,list<string>>|null */
    private ?array $inIndex = null;

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function addNode(string $id, string $type, array $attributes = []): void
    {
        if (isset($this->nodes[$id])) {
            // Merge rather than replace: a class node may be created by the
            // file walk and enriched later by the route pass.
            $this->nodes[$id] = array_merge($this->nodes[$id], array_filter(
                $attributes,
                static fn ($value) => $value !== null && $value !== [] && $value !== ''
            ));

            return;
        }

        $this->nodes[$id] = ['id' => $id, 'type' => $type] + $attributes;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function addEdge(string $from, string $to, string $type, array $meta = []): void
    {
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $key = $from."\x00".$to."\x00".$type;

        if (isset($this->edges[$key])) {
            $this->edges[$key]['weight']++;
            if ($meta !== []) {
                $this->edges[$key]['meta'] = array_merge($this->edges[$key]['meta'] ?? [], $meta);
            }

            return;
        }

        $edge = ['from' => $from, 'to' => $to, 'type' => $type, 'weight' => 1];
        if ($meta !== []) {
            $edge['meta'] = $meta;
        }

        $this->edges[$key] = $edge;
        $this->outIndex = null;
        $this->inIndex = null;
    }

    private function buildAdjacency(): void
    {
        $this->outIndex = [];
        $this->inIndex = [];

        foreach ($this->edges as $key => $edge) {
            $this->outIndex[$edge['from']][] = $key;
            $this->inIndex[$edge['to']][] = $key;
        }
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return array<string,mixed>|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array<string,mixed>> */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /** @return list<array<string,mixed>> nodes of one type */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (array $node) => $node['type'] === $type
        ));
    }

    /**
     * Remove a node. Its edges are left to `prune()`, which is the only place
     * that decides what a dangling edge means.
     */
    public function removeNode(string $id): void
    {
        unset($this->nodes[$id]);
    }

    public function setAttribute(string $id, string $key, mixed $value): void
    {
        if (isset($this->nodes[$id])) {
            $this->nodes[$id][$key] = $value;
        }
    }

    /**
     * Drop edges whose endpoints are not both in the graph, and report how many
     * went — the count is written into the manifest so a build that suddenly
     * prunes thousands is visible rather than quiet.
     */
    public function prune(): int
    {
        $before = count($this->edges);

        $this->edges = array_filter(
            $this->edges,
            fn (array $edge) => isset($this->nodes[$edge['from']]) && isset($this->nodes[$edge['to']])
        );

        $this->outIndex = null;
        $this->inIndex = null;

        return $before - count($this->edges);
    }

    /** Deterministic order, so two builds of the same tree produce the same file. */
    public function sort(): void
    {
        ksort($this->nodes, SORT_STRING);
        ksort($this->edges, SORT_STRING);

        $this->outIndex = null;
        $this->inIndex = null;
    }

    /**
     * Outgoing neighbours, optionally filtered by edge type.
     *
     * @param  list<string>|null  $types
     * @return list<array{id:string,type:string,weight:int}>
     */
    public function out(string $id, ?array $types = null): array
    {
        if ($this->outIndex === null) {
            $this->buildAdjacency();
        }

        $out = [];
        foreach ($this->outIndex[$id] ?? [] as $key) {
            $edge = $this->edges[$key] ?? null;
            if ($edge === null) {
                continue;   // pruned since the index was built
            }
            if ($types !== null && ! in_array($edge['type'], $types, true)) {
                continue;
            }
            $out[] = ['id' => $edge['to'], 'type' => $edge['type'], 'weight' => $edge['weight']];
        }

        return $out;
    }

    /**
     * @param  list<string>|null  $types
     * @return list<array{id:string,type:string,weight:int}>
     */
    public function in(string $id, ?array $types = null): array
    {
        if ($this->inIndex === null) {
            $this->buildAdjacency();
        }

        $in = [];
        foreach ($this->inIndex[$id] ?? [] as $key) {
            $edge = $this->edges[$key] ?? null;
            if ($edge === null) {
                continue;
            }
            if ($types !== null && ! in_array($edge['type'], $types, true)) {
                continue;
            }
            $in[] = ['id' => $edge['from'], 'type' => $edge['type'], 'weight' => $edge['weight']];
        }

        return $in;
    }
}
