<?php

namespace Tests\Feature\SecondBrain;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Support\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The brain answering real questions about *this* repository.
 *
 * `IndexerTest` proves the detectors work on a fixture whose answers are known
 * by construction. This is the other half, and the one that catches the
 * failures that matter: a ranking change that quietly stops `LaundryAssigner`
 * being the answer to "which laundry gets the order" breaks nothing a fixture
 * would notice.
 *
 * Every assertion names a file that exists in this codebase. If one of them is
 * renamed the test fails, which is correct — the brain would be pointing at a
 * file that is not there any more.
 */
final class SearchTest extends TestCase
{
    private static ?Brain $brain = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/.second-brain/autoload.php';

        $paths = Paths::discover(dirname(__DIR__, 3));
        $brain = new Brain($paths);

        // Building is deterministic and idempotent, so a missing or stale brain
        // is repaired rather than skipped. Skipping would let a broken index
        // sit green in CI, which is the failure this whole thing exists to stop.
        if (! $brain->isBuilt()) {
            Indexer::make($paths)->run();
            $brain = new Brain($paths);
        }

        self::$brain = $brain;
    }

    public static function tearDownAfterClass(): void
    {
        self::$brain = null;
    }

    private function brain(): Brain
    {
        return self::$brain ?? throw new \RuntimeException('the brain was not opened');
    }

    /** @return list<string> the paths a search returned, in rank order */
    private function paths(string $query, int $limit = 8): array
    {
        $results = $this->brain()->search($query, $limit)['results'] ?? [];

        return array_values(array_filter(array_column($results, 'path')));
    }

    // ----------------------------------------------------------- exact search

    #[Test]
    public function an_exact_class_name_is_the_first_result(): void
    {
        $paths = $this->paths('CouponService');

        $this->assertSame('app/Modules/Coupon/Services/CouponService.php', $paths[0] ?? null);
    }

    #[Test]
    public function an_exact_table_name_resolves_to_the_table(): void
    {
        $results = $this->brain()->search('order_settlements', 5)['results'];

        $this->assertSame('table', $results[0]['type']);
        $this->assertSame('order_settlements', $results[0]['name']);
    }

    #[Test]
    public function a_route_name_resolves_to_its_route(): void
    {
        $route = $this->brain()->resolve('admin.offer.index');

        $this->assertNotNull($route);
        $this->assertSame('route', $route['type']);
        $this->assertStringContainsString('OfferController@index', $route['action']);
    }

    // --------------------------------------------------------- keyword search

    #[Test]
    public function it_finds_where_the_delivery_fee_is_worked_out(): void
    {
        $paths = $this->paths('how is the delivery fee calculated');

        $this->assertContains(
            'app/Modules/Order/Services/DeliveryFeeCalculator.php',
            array_slice($paths, 0, 3)
        );
    }

    #[Test]
    public function it_finds_the_settlement_split(): void
    {
        $paths = $this->paths('how is the platform commission split at settlement');

        $this->assertContains(
            'app/Modules/Payment/Services/SettlementService.php',
            array_slice($paths, 0, 3)
        );
    }

    #[Test]
    public function it_finds_the_order_state_machine(): void
    {
        $paths = $this->paths('where does an order status change');

        $found = array_intersect(
            [
                'app/Modules/Order/Services/OrderStateMachine.php',
                'app/Modules/Order/Enums/OrderStatus.php',
            ],
            array_slice($paths, 0, 4)
        );

        $this->assertNotEmpty($found, 'expected the state machine or the status enum. Got: '.implode(', ', $paths));
    }

    #[Test]
    public function the_glossary_reaches_code_the_query_does_not_name(): void
    {
        // "courier" appears nowhere in this codebase; "driver" is everywhere.
        $found = $this->brain()->search('courier documents awaiting approval', 8);

        $this->assertContains('driver', $found['expanded_with'], 'the glossary should expand courier → driver');

        $paths = array_values(array_filter(array_column($found['results'], 'path')));
        $driverFiles = array_filter($paths, static fn (string $p) => str_contains($p, '/Driver/'));

        $this->assertNotEmpty($driverFiles, 'expected Driver module files. Got: '.implode(', ', $paths));
    }

    // ------------------------------------------------------- relationship search

    #[Test]
    public function it_answers_what_depends_on_a_service(): void
    {
        $answer = $this->brain()->search('what depends on CouponService');

        $this->assertSame('dependencies (inbound)', $answer['answered_as']);

        $paths = array_values(array_filter(array_column($answer['depended_on_by'] ?? [], 'path')));

        $this->assertContains('app/Http/Controllers/Api/V1/CouponController.php', $paths);
    }

    #[Test]
    public function dependencies_carry_the_edge_that_proves_them(): void
    {
        $answer = $this->brain()->dependencies('OfferController', 'outbound');

        $service = null;
        foreach ($answer['depends_on'] as $dependency) {
            if ($dependency['name'] === 'offerCrudService') {
                $service = $dependency;
                break;
            }
        }

        $this->assertNotNull($service, 'the controller depends on its CRUD service');
        $this->assertNotEmpty($service['via']);
        $this->assertSame('app/Modules/Offer/Services/offerCrudService.php', $service['path']);
    }

    #[Test]
    public function no_framework_class_appears_in_a_dependency_answer(): void
    {
        $answer = $this->brain()->dependencies('SettlementService', 'outbound');

        foreach ($answer['depends_on'] as $dependency) {
            $this->assertStringNotContainsString('Illuminate\\', $dependency['id']);
            $this->assertStringNotContainsString('Carbon\\', $dependency['id']);
        }
    }

    // ------------------------------------------------------------ feature map

    #[Test]
    public function a_feature_group_returns_its_capabilities(): void
    {
        $feature = $this->brain()->feature('Discount Codes');

        $this->assertSame('group', $feature['match']);
        $this->assertContains('Create', $feature['capabilities']);
        $this->assertContains('Update', $feature['capabilities']);
        $this->assertContains('Delete', $feature['capabilities']);
    }

    #[Test]
    public function a_feature_names_its_entry_point_permission_and_files(): void
    {
        $answer = $this->brain()->feature('Discount Codes');

        $create = null;
        foreach ($answer['features'] as $candidate) {
            if ($candidate['capability'] === 'Create') {
                $create = $candidate;
                break;
            }
        }

        $this->assertNotNull($create);
        $this->assertNotEmpty($create['entry_points']);
        $this->assertContains('coupon.create', $create['permissions'] ?? []);

        $paths = array_column($create['files'], 'path');
        $this->assertContains('app/Modules/Coupon/Services/couponCrudService.php', $paths);
    }

    #[Test]
    public function a_feature_that_does_not_exist_suggests_rather_than_dead_ends(): void
    {
        $answer = $this->brain()->feature('coupn');

        $this->assertSame('none', $answer['match']);
        $this->assertNotEmpty($answer['did_you_mean']);
    }

    // ----------------------------------------------------------------- module

    #[Test]
    public function a_module_reports_its_layers_models_and_dependents(): void
    {
        $module = $this->brain()->module('Payment');

        $this->assertSame('money', $module['community']);
        $this->assertArrayHasKey('service', $module['layers']);
        $this->assertArrayHasKey('model', $module['layers']);

        $this->assertContains('App\\Modules\\Payment\\Models\\OrderSettlement', $module['models']);
        $this->assertContains('order_settlements', $module['owns_tables']);
        $this->assertNotEmpty($module['depends_on']);
    }

    #[Test]
    public function the_order_module_is_its_own_community_not_a_part_of_delivery(): void
    {
        // `config/menu.php` lists `order_task` under the delivery dropdown, so
        // a first-come assignment put the entire order lifecycle under Delivery.
        $this->assertSame('order', $this->brain()->module('Order')['community']);
    }

    #[Test]
    public function a_screen_that_is_not_a_module_still_resolves_to_its_owner(): void
    {
        // CLAUDE.md: "a sidebar screen is not a module directory" — driver_earning
        // is a screen of the Payment module.
        $module = $this->brain()->module('DriverEarning');

        $this->assertSame('none', $module['match'], 'there is no DriverEarning module');

        $paths = $this->paths('driver earning');
        $payment = array_filter($paths, static fn (string $p) => str_contains($p, '/Payment/'));

        $this->assertNotEmpty($payment, 'driver earnings live in the Payment module. Got: '.implode(', ', $paths));
    }

    // ------------------------------------------------------------ related files

    #[Test]
    public function related_files_explain_why_they_are_related(): void
    {
        $answer = $this->brain()->related('app/Modules/Offer/Controllers/OfferController.php', 10);

        $this->assertNotEmpty($answer['related']);

        foreach ($answer['related'] as $related) {
            $this->assertNotEmpty($related['why'], $related['path'].' came back with no reason');
            $this->assertArrayHasKey('relevance', $related);
        }

        $paths = array_column($answer['related'], 'path');
        $this->assertContains('app/Modules/Offer/Services/offerCrudService.php', $paths);
    }

    // ----------------------------------------------------------- architecture

    #[Test]
    public function the_architecture_overview_states_what_this_project_does_not_have(): void
    {
        $overview = $this->brain()->architecture();

        $this->assertArrayHasKey('absent_by_design', $overview);
        $this->assertArrayHasKey('policies', $overview['absent_by_design']);
        $this->assertArrayHasKey('events_and_listeners', $overview['absent_by_design']);
        $this->assertArrayHasKey('api_resources', $overview['absent_by_design']);

        $this->assertSame(['Controller', 'Service', 'Repository', 'Model'], $overview['layer_contract']['order']);
    }

    #[Test]
    public function communities_come_from_the_menu_and_say_so(): void
    {
        $communities = $this->brain()->architecture('communities')['communities'];

        $bySlug = [];
        foreach ($communities as $community) {
            $bySlug[$community['slug']] = $community;
        }

        foreach (['money', 'delivery', 'catalog', 'laundries', 'marketing', 'operations', 'locations', 'system'] as $slug) {
            $this->assertArrayHasKey($slug, $bySlug, "the {$slug} community should exist");
            $this->assertSame('menu', $bySlug[$slug]['source']);
            $this->assertStringContainsString('config/menu.php', $bySlug[$slug]['evidence']);
        }

        $this->assertSame('derived', $bySlug['api']['source'] ?? null);
    }

    // -------------------------------------------------------------- responses

    #[Test]
    public function a_search_response_stays_small_and_returns_no_source_code(): void
    {
        $json = (string) json_encode($this->brain()->search('order settlement commission', 8));

        $this->assertLessThan(
            8000,
            strlen($json),
            'a search response must stay small — the brain exists to spend fewer tokens, not more'
        );

        // Nothing that looks like source code.
        $this->assertStringNotContainsString('<?php', $json);
        $this->assertStringNotContainsString('public function', $json);
        $this->assertStringNotContainsString('namespace App', $json);
    }

    /**
     * The brain must not index its own tests.
     *
     * This file quotes benchmark queries verbatim — "how is the delivery fee
     * calculated" appears in it three times — so while it was indexed it
     * matched those queries better than most real code and surfaced at rank 3
     * for one of them. A search index containing its own fixtures is grading
     * its own homework.
     */
    #[Test]
    public function the_brains_own_tests_can_never_appear_in_a_result(): void
    {
        $queries = [
            'how is the delivery fee calculated',
            'which laundry gets the order',
            'where is the coupon discount calculated',
            'second brain search test',
            'SearchTest',
        ];

        foreach ($queries as $query) {
            foreach ($this->brain()->search($query, 10)['results'] as $result) {
                $this->assertStringNotContainsString(
                    'tests/Feature/SecondBrain',
                    (string) ($result['path'] ?? ''),
                    "[{$query}] returned one of the brain's own test files"
                );
            }
        }

        foreach ($this->brain()->graph()->ofType('test') as $node) {
            $this->assertStringNotContainsString(
                'tests/Feature/SecondBrain',
                (string) ($node['path'] ?? ''),
                'a Second Brain test reached the graph'
            );
        }
    }

    #[Test]
    public function every_result_names_a_file_that_exists(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['coupon discount', 'driver bonus', 'laundry capacity', 'tenant scope'] as $query) {
            foreach ($this->paths($query) as $path) {
                $this->assertFileExists($root.'/'.$path, "search for [{$query}] returned a path that is not there");
            }
        }
    }
}
