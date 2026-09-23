<?php

namespace Laundo\SecondBrain\Graph;

/**
 * The node and edge types this codebase actually produces.
 *
 * Written down as a closed list so the indexer cannot quietly invent a
 * fourteenth kind of edge, and so `doctor` can check the graph against it.
 *
 * Note what is **absent**, because it is a finding about the repository rather
 * than an omission here: there are no Policies, no Events, no Listeners and no
 * API Resources in this project — authorisation is `permission:` middleware and
 * the `canDo()` helper, and API payloads are private `present*()` methods on
 * the controllers. Types for them would describe a Laravel application that
 * this one is not.
 */
final class Vocabulary
{
    public const NODES = [
        'community',      // a sidebar group — the owner's own domain grouping
        'module',         // app/Modules/{Name}
        'feature',        // a capability with an entry point
        'file',
        'class',
        'interface',
        'trait',
        'enum',
        'method',
        'route',
        'table',
        'column',
        'permission',
        'view',
        'command',
        'test',
        'integration',
        'doc',
    ];

    public const EDGES = [
        'contains',       // community→module, module→file, file→class, class→method
        'extends',
        'implements',
        'uses_trait',
        'imports',        // file→class, from a `use` statement
        'depends_on',     // constructor/parameter injection — the layer contract
        'calls',          // method→method, resolved receiver only
        'references',     // class→class, anything weaker than the above
        'route_to',       // route→controller method
        'guarded_by',     // route→permission
        'renders',        // controller method→view
        'includes',       // view→view
        'validates',      // controller method→request class
        'maps_to',        // model→table
        'belongs_to',
        'has_many',
        'has_one',
        'belongs_to_many',
        'has_many_through',
        'has_one_through',
        'morph_to',
        'morph_many',
        'morph_one',
        'morph_to_many',
        'foreign_key',    // table→table
        'writes_table',   // migration file→table
        'defined_by',     // model→the migration that creates its table; carries
                          // `confidence`, which is `declared` when the model
                          // names its own $table and `convention` when the name
                          // came from Laravel's pluralisation rule
        'dispatches',     // class→job
        'scheduled',      // command→cadence, held as meta
        'tested_by',      // class/route→test
        'integrates',     // file→external integration
        'co_changes',     // file→file, from git history
        'entry_point',    // feature→route/command
        'implemented_by', // feature→class/method
        'touches',        // feature→table
    ];

    /** Edge types that mean "A cannot work without B". */
    public const DEPENDENCY_EDGES = ['depends_on', 'calls', 'imports', 'extends', 'implements', 'uses_trait', 'references'];

    /** Edge types walked when asked what is *related* to something. */
    public const RELATION_EDGES = [
        'depends_on', 'calls', 'route_to', 'renders', 'validates', 'maps_to',
        'belongs_to', 'has_many', 'has_one', 'belongs_to_many', 'tested_by',
        'implemented_by', 'entry_point', 'touches', 'contains', 'includes', 'guarded_by',
    ];
}
