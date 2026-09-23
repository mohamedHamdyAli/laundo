<?php

namespace Tests\Feature\SecondBrain;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Parse\FileScanner;
use Laundo\SecondBrain\Parse\GitHistory;
use Laundo\SecondBrain\Support\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The indexer, exercised against a miniature repository built in a temp
 * directory.
 *
 * A fixture rather than the real tree, for one reason: these assertions have to
 * be exact. "The Order module exists" is not a test of module detection when
 * the Order module would be found by any implementation; a fixture lets each
 * detector be given something it must get right and something it must not get
 * wrong — a secret it has to refuse, a `down()` block whose drop it must not
 * apply, a `Request` it has to connect to the action that type-hints it.
 *
 * `SearchTest` covers the other half: the same code against this repository's
 * real modules.
 *
 * Extends PHPUnit's TestCase rather than the project's, deliberately: none of
 * this touches the database, and `RefreshDatabase` would add a migration run to
 * every one of these.
 */
final class IndexerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/.second-brain/autoload.php';

        $this->root = sys_get_temp_dir().'/second-brain-fixture-'.bin2hex(random_bytes(6));
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------- detection

    #[Test]
    public function it_detects_modules_from_the_directory_layout(): void
    {
        $brain = $this->index();

        $modules = array_column($brain->architecture('modules')['modules'], 'name');

        $this->assertContains('Widget', $modules);
        $this->assertContains('Billing', $modules);
    }

    #[Test]
    public function it_detects_routes_with_their_controller_and_permission(): void
    {
        $brain = $this->index();

        $route = $brain->resolve('admin.widget.store');

        $this->assertNotNull($route, 'the route node should exist');
        $this->assertSame('/admin/widget', $route['uri']);
        $this->assertContains('POST', $route['methods']);
        $this->assertStringContainsString('WidgetController@store', $route['action']);
    }

    #[Test]
    public function it_detects_controllers_services_repositories_and_models_as_layers(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $expected = [
            'class:App\\Modules\\Widget\\Controllers\\WidgetController' => 'controller',
            'class:App\\Modules\\Widget\\Services\\widgetCrudService' => 'service',
            'class:App\\Modules\\Widget\\Repositories\\WidgetRepository' => 'repository',
            'class:App\\Modules\\Widget\\Models\\Widget' => 'model',
            'class:App\\Modules\\Widget\\Requests\\WidgetRequest' => 'request',
        ];

        foreach ($expected as $id => $layer) {
            $node = $graph->node($id);
            $this->assertNotNull($node, "missing node {$id}");
            $this->assertSame($layer, $node['layer'], "{$id} should be layer {$layer}");
        }
    }

    #[Test]
    public function it_follows_the_layer_contract_from_controller_to_repository(): void
    {
        $brain = $this->index();

        $dependencies = $brain->dependencies('WidgetController', 'outbound', 2);
        $names = array_column($dependencies['depends_on'], 'name');

        $this->assertContains('widgetCrudService', $names, 'constructor injection should be an edge');
        $this->assertContains('WidgetRepository', $names, 'the service reaching the repository should be an edge at depth 2');
    }

    #[Test]
    public function it_records_which_action_a_form_request_validates(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $validated = $graph->out('method:App\\Modules\\Widget\\Controllers\\WidgetController::store', ['validates']);

        $this->assertSame(
            ['class:App\\Modules\\Widget\\Requests\\WidgetRequest'],
            array_column($validated, 'id')
        );
    }

    #[Test]
    public function it_maps_a_model_to_its_table_and_its_relationships(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $widget = $graph->node('class:App\\Modules\\Widget\\Models\\Widget');

        $this->assertTrue($widget['is_model']);
        $this->assertSame('widgets', $widget['table']);
        $this->assertContains('name', $widget['fillable']);

        $relations = $graph->out('class:App\\Modules\\Widget\\Models\\Widget', ['belongs_to', 'has_many']);
        $targets = array_column($relations, 'id');

        $this->assertContains('class:App\\Modules\\Billing\\Models\\Invoice', $targets);
    }

    #[Test]
    public function it_replays_migrations_and_ignores_the_down_method(): void
    {
        $brain = $this->index();
        $tables = array_column($brain->architecture('database')['tables'], 'name');

        // `create_widgets_table` drops the table in `down()`. Reading the whole
        // file would create it and immediately delete it again.
        $this->assertContains('widgets', $tables);
        $this->assertContains('invoices', $tables);

        // `drop_legacy_table` really does drop it in `up()`.
        $this->assertNotContains('legacy_things', $tables);
    }

    #[Test]
    public function it_records_columns_and_foreign_keys(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $invoices = $graph->node('table:invoices');

        $this->assertContains('widget_id', $invoices['columns']);
        $this->assertContains('total', $invoices['columns']);

        $keys = $graph->out('table:invoices', ['foreign_key']);
        $this->assertContains('table:widgets', array_column($keys, 'id'));
    }

    #[Test]
    public function it_detects_features_with_entry_points_and_evidence(): void
    {
        $brain = $this->index();

        $features = $brain->features();
        $labels = array_column($features, 'label');

        $create = null;
        foreach ($features as $feature) {
            if ($feature['capability'] === 'Create' && str_contains($feature['group'], 'Widget')) {
                $create = $feature;
                break;
            }
        }

        $this->assertNotNull($create, 'a Create capability should exist. Found: '.implode(', ', $labels));
        $this->assertNotEmpty($create['entry_points'], 'a feature must name an entry point');
        $this->assertNotEmpty($create['evidence'], 'a feature must carry its evidence');

        $paths = array_column($create['files'], 'path');
        $this->assertContains('app/Modules/Widget/Services/widgetCrudService.php', $paths);
    }

    #[Test]
    public function it_links_a_test_file_to_the_classes_it_names(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $tested = $graph->out('class:App\\Modules\\Widget\\Services\\widgetCrudService', ['tested_by']);

        $this->assertContains('test:tests/Feature/WidgetTest.php', array_column($tested, 'id'));
    }

    #[Test]
    public function it_connects_a_controller_action_to_the_view_it_renders(): void
    {
        $brain = $this->index();
        $graph = $brain->graph();

        $rendered = $graph->out('method:App\\Modules\\Widget\\Controllers\\WidgetController::index', ['renders']);

        $this->assertContains('view:admin.widget.index', array_column($rendered, 'id'));
    }

    // -------------------------------------------------------------- security

    #[Test]
    public function it_never_indexes_env_files_or_keys(): void
    {
        $brain = $this->index();

        $indexed = array_column($brain->graph()->ofType('file'), 'path');

        $this->assertNotContains('.env', $indexed);
        $this->assertNotContains('.env.production', $indexed);
        $this->assertNotContains('storage/logs/laravel.log', $indexed);
    }

    #[Test]
    public function no_secret_value_reaches_any_generated_file(): void
    {
        $this->index();

        $secrets = [
            'super-secret-database-password',
            'AKIAIOSFODNN7EXAMPLE',
            'sk_live_abcdefghijklmnop0123',
        ];

        foreach ($this->generatedFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $contents,
                    basename($file).' contains a secret from .env'
                );
            }
        }
    }

    #[Test]
    public function the_scanner_refuses_a_denied_path_even_when_handed_one_directly(): void
    {
        $scanner = new FileScanner(new Paths($this->root));

        $this->assertFalse($scanner->accepts('.env'));
        $this->assertFalse($scanner->accepts('storage/logs/laravel.log'));
        $this->assertFalse($scanner->accepts('vendor/anything/File.php'));
        $this->assertTrue($scanner->accepts('app/Modules/Widget/Models/Widget.php'));

        $this->expectException(\RuntimeException::class);
        $scanner->read('.env');
    }

    /**
     * The deny list is a prefix test, so without lexical normalisation
     * `app/../vendor/autoload.php` does not start with `vendor/` and walks
     * straight through the gate the whole security model rests on.
     */
    #[Test]
    public function the_deny_list_cannot_be_walked_around_with_dot_dot(): void
    {
        $scanner = new FileScanner(new Paths($this->root));

        $escapes = [
            'app/../.env',
            'app/../storage/logs/laravel.log',
            'app/../vendor/autoload.php',
            'app/./../../outside.php',
            'app/Modules/../../../etc/passwd.php',
            'app\\..\\storage\\logs\\a.php',
            '../.env',
            "app/Modules/Widget/Models/Widget.php\0.env",
        ];

        foreach ($escapes as $path) {
            $this->assertFalse(
                $scanner->accepts($path),
                'the gate let ['.addcslashes($path, "\0").'] through'
            );
            $this->assertTrue(
                $scanner->isDenied($path),
                '['.addcslashes($path, "\0").'] should be denied'
            );
        }

        // A path with a harmless `..` that resolves back inside is still fine.
        $this->assertTrue($scanner->accepts('app/Modules/Widget/../Widget/Models/Widget.php'));

        $this->expectException(\RuntimeException::class);
        $scanner->read('app/../vendor/autoload.php');
    }

    #[Test]
    public function raw_string_literals_are_never_persisted(): void
    {
        $this->index();

        $cache = (string) file_get_contents($this->root.'/.second-brain/cache/artifacts.json');

        // The fixture's own literals, none of which anything downstream reads.
        $this->assertStringNotContainsString('required|string|max:191', $cache);
        $this->assertStringNotContainsString('required|in:active,inactive', $cache);

        $decoded = json_decode($cache, true);
        foreach ($decoded['files'] as $path => $artifact) {
            foreach ($artifact['classes'] ?? [] as $class) {
                $this->assertArrayNotHasKey('strings', $class, $path.' persisted class string literals');
                foreach ($class['methods'] as $method) {
                    $this->assertArrayNotHasKey('strings', $method, $path.' persisted method string literals');
                }
            }
        }
    }

    #[Test]
    public function a_git_reference_that_is_not_one_is_refused(): void
    {
        $git = new GitHistory(Paths::discover(dirname(__DIR__, 3)));

        $this->expectException(\InvalidArgumentException::class);
        $git->changedSince('--output=/tmp/pwned');
    }

    // ----------------------------------------------------------- incremental

    #[Test]
    public function a_second_index_reuses_the_parse_cache(): void
    {
        $paths = new Paths($this->root);

        $first = Indexer::make($paths)->run();
        $this->assertSame(0, $first['files_reused_from_cache']);
        $this->assertGreaterThan(0, $first['files_parsed']);

        $second = Indexer::make($paths)->run();
        $this->assertSame(0, $second['files_parsed'], 'nothing changed, so nothing should be re-parsed');
        $this->assertSame($first['files_scanned'], $second['files_reused_from_cache']);
    }

    #[Test]
    public function an_incremental_update_re_parses_only_what_changed_and_picks_the_change_up(): void
    {
        $paths = new Paths($this->root);
        Indexer::make($paths)->run();

        file_put_contents(
            $this->root.'/app/Modules/Widget/Services/widgetCrudService.php',
            str_replace(
                'public function addWidget(array $data)',
                "public function archiveWidget(int \$id): void {}\n\n    public function addWidget(array \$data)",
                (string) file_get_contents($this->root.'/app/Modules/Widget/Services/widgetCrudService.php')
            )
        );

        $manifest = Indexer::make($paths)->run(['app/Modules/Widget/Services/widgetCrudService.php']);

        $this->assertSame(1, $manifest['files_parsed'], 'only the changed file should be re-parsed');

        $brain = new Brain($paths);
        $service = $brain->graph()->node('class:App\\Modules\\Widget\\Services\\widgetCrudService');

        $this->assertContains('archiveWidget', $service['methods'], 'the new method should be in the graph');
    }

    /**
     * The parse cache stores `layer`, `kind`, `module` and `integrations`,
     * none of which comes from the file's own bytes — they are all derived
     * from `config.php`. Keyed on the source hash alone, editing a layer rule
     * and re-indexing left every artifact claiming the old layer, and the
     * indexer's promise that an update matches a cold build stopped holding.
     */
    #[Test]
    public function editing_the_config_invalidates_the_parse_cache(): void
    {
        $paths = new Paths($this->root);
        Indexer::make($paths)->run();

        $configPath = $this->root.'/.second-brain/config.php';
        $config = (string) file_get_contents($configPath);

        file_put_contents($configPath, str_replace(
            "'#^app/Modules/[^/]+/Services/#' => 'service',",
            "'#^app/Modules/[^/]+/Services/#' => 'domain-service',",
            $config
        ));

        $manifest = Indexer::make(new Paths($this->root))->run();

        $this->assertGreaterThan(0, $manifest['files_parsed'], 'a config change must force a re-parse');

        $service = (new Brain(new Paths($this->root)))
            ->graph()
            ->node('class:App\\Modules\\Widget\\Services\\widgetCrudService');

        $this->assertSame('domain-service', $service['layer'], 'the new layer rule should be in the graph');
    }

    #[Test]
    public function a_module_that_is_not_a_directory_is_not_given_one(): void
    {
        $brain = $this->index();

        foreach ($brain->architecture('modules')['modules'] as $module) {
            $path = (new Brain(new Paths($this->root)))->module($module['name'])['path'] ?? null;

            if ($path !== null) {
                $this->assertDirectoryExists(
                    $this->root.'/'.$path,
                    $module['name'].' is published with a directory that does not exist'
                );
            }
        }
    }

    #[Test]
    public function rebuilding_is_deterministic(): void
    {
        $paths = new Paths($this->root);

        Indexer::make($paths)->run();
        $first = file_get_contents($paths->data('graph/edges.json'));

        Indexer::make($paths)->run();
        $second = file_get_contents($paths->data('graph/edges.json'));

        $this->assertSame($first, $second, 'two builds of the same tree must produce the same file');
    }

    // --------------------------------------------------------------- helpers

    private function index(): Brain
    {
        $paths = new Paths($this->root);
        Indexer::make($paths)->run();

        return new Brain($paths);
    }

    /** @return list<string> */
    private function generatedFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root.'/.second-brain', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($directory as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * A repository shaped like this one: modules with the five inner
     * directories, a routes file, migrations with a `down()`, a menu config,
     * a Blade view, a test — and a `.env` holding things that must never leave.
     */
    private function buildFixture(): void
    {
        $files = [
            'composer.json' => '{"name":"fixture/app"}',
            'artisan' => "<?php\n// fixture\n",

            // Must never be read. The values are checked for by name in the
            // generated output.
            '.env' => "APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=\nDB_PASSWORD=super-secret-database-password\nAWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE\n",
            '.env.production' => "STRIPE_KEY=sk_live_abcdefghijklmnop0123\n",
            'storage/logs/laravel.log' => 'password=super-secret-database-password',
            'vendor/acme/lib/Thing.php' => "<?php\nnamespace Acme;\nclass Thing {}\n",
            'node_modules/pkg/index.js' => "module.exports = {};\n",

            'config/menu.php' => <<<'PHP'
                <?php

                return [
                    'groups' => [
                        'operations' => [
                            'order' => 1,
                            'title' => 'Operations',
                            'icon' => 'bi bi-gear',
                            'items' => ['widget' => 1, 'invoice' => 2],
                        ],
                    ],
                    'singles' => [],
                    'icons' => ['widget' => 'bi bi-box', 'invoice' => 'bi bi-receipt'],
                    'titles' => ['widget' => 'Widgets', 'invoice' => 'Invoices'],
                    'routes' => ['widget' => 'admin.widget.index', 'invoice' => 'admin.invoice.index'],
                ];
                PHP,

            'config/dashboard.php' => <<<'PHP'
                <?php

                use App\Modules\Billing\Models\Invoice;
                use App\Modules\Widget\Models\Widget;

                return ['models' => [Widget::class, Invoice::class]];
                PHP,

            'routes/web.php' => <<<'PHP'
                <?php

                use App\Modules\Widget\Controllers\WidgetController;
                use Illuminate\Support\Facades\Route;

                Route::prefix('admin')->group(function () {
                    Route::get('/widget', [WidgetController::class, 'index'])->name('admin.widget.index');
                    Route::post('/widget', [WidgetController::class, 'store'])->name('admin.widget.store');
                });
                PHP,

            'app/Modules/Widget/Controllers/WidgetController.php' => <<<'PHP'
                <?php

                namespace App\Modules\Widget\Controllers;

                use App\Modules\Widget\Requests\WidgetRequest;
                use App\Modules\Widget\Services\widgetCrudService;

                /**
                 * HTTP only — the rules live in the service.
                 */
                class WidgetController
                {
                    public function __construct(private readonly widgetCrudService $widgets) {}

                    public function index()
                    {
                        return view('admin.widget.index', $this->widgets->shredData());
                    }

                    public function store(WidgetRequest $request)
                    {
                        $this->widgets->addWidget($request->validated());

                        return redirect()->route('admin.widget.index');
                    }
                }
                PHP,

            'app/Modules/Widget/Services/widgetCrudService.php' => <<<'PHP'
                <?php

                namespace App\Modules\Widget\Services;

                use App\Modules\Widget\Repositories\WidgetRepository;

                /**
                 * Business rules for widgets.
                 */
                class widgetCrudService
                {
                    public function __construct(private readonly WidgetRepository $widgets) {}

                    public function shredData($id = null)
                    {
                        return ['widgets' => $this->widgets->getAll()];
                    }

                    public function addWidget(array $data)
                    {
                        return $this->widgets->create($data);
                    }
                }
                PHP,

            'app/Modules/Widget/Repositories/WidgetRepository.php' => <<<'PHP'
                <?php

                namespace App\Modules\Widget\Repositories;

                use App\Modules\Widget\Models\Widget;

                class WidgetRepository
                {
                    public function getAll()
                    {
                        return Widget::paginate(10);
                    }

                    public function create(array $data)
                    {
                        return Widget::create($data);
                    }
                }
                PHP,

            'app/Modules/Widget/Models/Widget.php' => <<<'PHP'
                <?php

                namespace App\Modules\Widget\Models;

                use App\Modules\Billing\Models\Invoice;
                use Illuminate\Database\Eloquent\Model;
                use Illuminate\Database\Eloquent\Relations\HasMany;

                /**
                 * A widget.
                 */
                class Widget extends Model
                {
                    protected $fillable = ['name', 'status'];

                    public function invoices(): HasMany
                    {
                        return $this->hasMany(Invoice::class, 'widget_id');
                    }
                }
                PHP,

            'app/Modules/Widget/Requests/WidgetRequest.php' => <<<'PHP'
                <?php

                namespace App\Modules\Widget\Requests;

                use Illuminate\Foundation\Http\FormRequest;

                class WidgetRequest extends FormRequest
                {
                    public function rules(): array
                    {
                        return [
                            'name' => 'required|string|max:191',
                            'status' => 'required|in:active,inactive',
                        ];
                    }
                }
                PHP,

            'app/Modules/Billing/Models/Invoice.php' => <<<'PHP'
                <?php

                namespace App\Modules\Billing\Models;

                use App\Modules\Widget\Models\Widget;
                use Illuminate\Database\Eloquent\Model;
                use Illuminate\Database\Eloquent\Relations\BelongsTo;

                /**
                 * One invoice for one widget.
                 */
                class Invoice extends Model
                {
                    protected $fillable = ['widget_id', 'total'];

                    public function widget(): BelongsTo
                    {
                        return $this->belongsTo(Widget::class, 'widget_id');
                    }
                }
                PHP,

            'database/migrations/2026_01_01_000000_create_widgets_table.php' => <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                /**
                 * Widgets.
                 */
                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('widgets', function (Blueprint $table) {
                            $table->id();
                            $table->string('name');
                            $table->enum('status', ['active', 'inactive'])->default('active');
                            $table->timestamps();
                        });
                    }

                    public function down(): void
                    {
                        Schema::dropIfExists('widgets');
                    }
                };
                PHP,

            'database/migrations/2026_01_02_000000_create_invoices_table.php' => <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('invoices', function (Blueprint $table) {
                            $table->id();
                            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();
                            $table->decimal('total', 10, 2);
                            $table->timestamps();
                        });
                    }

                    public function down(): void
                    {
                        Schema::dropIfExists('invoices');
                    }
                };
                PHP,

            'database/migrations/2026_01_03_000000_drop_legacy_table.php' => <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('legacy_things', function (Blueprint $table) {
                            $table->id();
                        });

                        Schema::dropIfExists('legacy_things');
                    }

                    public function down(): void {}
                };
                PHP,

            'resources/views/admin/widget/index.blade.php' => "@extends('layouts.main')\n@section('content')\n<h1>Widgets</h1>\n@if(canDo('widget.create'))<a href=\"{{ route('admin.widget.index') }}\">New</a>@endif\n@endsection\n",

            'tests/Feature/WidgetTest.php' => <<<'PHP'
                <?php

                namespace Tests\Feature;

                use App\Modules\Widget\Models\Widget;
                use App\Modules\Widget\Services\widgetCrudService;
                use PHPUnit\Framework\TestCase;

                /**
                 * Widgets, end to end.
                 */
                class WidgetTest extends TestCase
                {
                    public function a_widget_can_be_created(): void
                    {
                        $this->assertTrue(class_exists(Widget::class));
                        $this->assertTrue(class_exists(widgetCrudService::class));
                    }
                }
                PHP,
        ];

        foreach ($files as $relative => $contents) {
            $path = $this->root.'/'.$relative;
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents);
        }

        // The brain's own config, copied so the fixture uses the real rules.
        mkdir($this->root.'/.second-brain', 0777, true);
        copy(
            dirname(__DIR__, 3).'/.second-brain/config.php',
            $this->root.'/.second-brain/config.php'
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
