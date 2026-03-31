<?php

declare(strict_types=1);

namespace Laravel\Boost\Services;

use App\Models\Category;
use App\Models\Menu;
use App\Models\ModuleManager;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModuleGeneratorService
{
    protected Application $app;

    protected string $moduleName;

    protected string $singularName;

    protected string $studlyName;

    protected string $studlySingular;

    protected array $fields;

    protected string $identifierField;

    protected array $roles;

    protected bool $dryRun;

    /** @var array<int, array{relative_path: string, content: string, type: string}> */
    protected array $generatedFiles = [];

    public function __construct(Application $app, string $moduleName, array $fields, string $identifierField = 'id', array $roles = ['user'], bool $dryRun = false)
    {
        $this->app = $app;
        $this->moduleName = $moduleName;
        $this->singularName = Str::singular($moduleName);
        $this->studlyName = Str::studly($moduleName);
        $this->studlySingular = Str::studly($this->singularName);
        $this->fields = $fields;
        $this->identifierField = $identifierField;
        $this->roles = $roles;
        $this->dryRun = $dryRun;
        $this->generatedFiles = [];
    }

    public function generate(): array
    {
        $results = [];

        try {
            // In dryRun mode, skip infrastructure checks (they depend on local DB)
            if (! $this->dryRun) {
                $this->checkAndCreateInfrastructure();
            }

            $results['model'] = $this->generateModel();
            $results['migration'] = $this->generateMigration();
            $results['factory'] = $this->generateFactory();
            $results['controller'] = $this->generateController();
            $results['resource'] = $this->generateResource();
            $results['collection'] = $this->generateCollection();
            $results['request'] = $this->generateRequest();
            $results['policy'] = $this->generatePolicy();
            $results['seeder'] = $this->generateSeeder();
            $results['route'] = $this->addRoute();

            // In dryRun mode, return files content instead of executing side effects
            if ($this->dryRun) {
                return [
                    'success' => true,
                    'message' => 'Module generated (dry run)',
                    'files' => $this->generatedFiles,
                    'route_append' => $results['route'],
                    'post_actions' => ['migrate', "seed:{$this->studlySingular}Seeder"],
                    'infrastructure' => [
                        'menu' => [
                            'name' => $this->studlyName,
                            'route' => '/'.strtolower($this->moduleName),
                            'icon' => 'extension',
                            'color' => '#10b981',
                            'slug' => Str::slug($this->moduleName),
                            'roles' => $this->roles,
                        ],
                        'module_manager' => [
                            'module_name' => $this->moduleName,
                            'slug' => Str::slug($this->moduleName),
                            'display_name' => $this->studlyName,
                            'display_name_singular' => $this->studlySingular,
                            'resource_type' => $this->moduleName,
                            'identifier_field' => $this->identifierField,
                            'route_path' => $this->moduleName,
                            'fields' => $this->fields,
                            'roles' => $this->roles,
                        ],
                    ],
                ];
            }

            // Normal mode: run migrations, seeders, etc.
            if (config('boost.module_generator.auto_migrate', true)) {
                $migrationResult = $this->runMigration();
                $results['migration_executed'] = $migrationResult;
            }

            if (config('boost.module_generator.auto_seed', true)) {
                $seederResult = $this->runSeeder();
                $results['seeder_executed'] = $seederResult;
            }

            $this->generateMenu($this->roles);
            $results['menu_generated'] = true;

            $this->addToModuleManager();
            $results['module_manager_registered'] = true;

            return [
                'success' => true,
                'message' => 'Module generated successfully',
                'files' => $results,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Module generation failed: '.$e->getMessage(),
                'error' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Write file to disk or collect it for dry run.
     */
    protected function writeOrCollect(string $absolutePath, string $content, string $type): string
    {
        if ($this->dryRun) {
            // Convert absolute path to relative path from project root
            $basePath = $this->app->basePath();
            $relativePath = str_replace($basePath.DIRECTORY_SEPARATOR, '', $absolutePath);
            $relativePath = str_replace('\\', '/', $relativePath);

            $this->generatedFiles[] = [
                'relative_path' => $relativePath,
                'content' => $content,
                'type' => $type,
            ];

            return $relativePath;
        }

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $content);

        return $absolutePath;
    }

    protected function hasFileField(): bool
    {
        foreach ($this->fields as $field) {
            if ($field['type'] === 'File') {
                return true;
            }
        }

        return false;
    }

    protected function getFileFields(): array
    {
        return array_filter($this->fields, fn ($field) => $field['type'] === 'File');
    }

    protected function generateModel(): string
    {
        $fillable = $this->getFillableFields();
        $casts = $this->getCasts();

        $useSlug = '';
        $useMedia = '';
        $implements = '';
        $useTrait = 'HasFactory';
        $slugMethod = '';
        $mediaMethod = '';

        if ($this->identifierField === 'slug') {
            $useSlug = "use Spatie\\Sluggable\\HasSlug;\nuse Spatie\\Sluggable\\SlugOptions;\n";
            $useTrait = 'HasFactory, HasSlug';
            $firstFieldName = $this->fields[0]['name'] ?? 'name';
            $slugMethod = "

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['{$firstFieldName}'])
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }";
        }

        if ($this->hasFileField()) {
            $useMedia = "use Spatie\\MediaLibrary\\HasMedia;\nuse Spatie\\MediaLibrary\\InteractsWithMedia;\n";
            $implements = ' implements HasMedia';
            $useTrait .= ', InteractsWithMedia';

            $diskName = Str::snake($this->moduleName);
            $mediaMethod = "

    /**
     * Register media collections.
     */
    public function registerMediaCollections(): void
    {
        \$this->addMediaCollection('{$diskName}')
            ->acceptsMimeTypes(['image/png', 'image/jpg', 'image/jpeg', 'application/pdf'])
            ->useDisk('{$diskName}');
    }";
        }

        $content = "<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;
{$useSlug}{$useMedia}
class {$this->studlySingular} extends Model{$implements}
{
    use {$useTrait};

    protected \$fillable = [{$fillable}];

    protected function casts(): array
    {
        return [{$casts}
        ];
    }{$slugMethod}{$mediaMethod}
}
";

        $path = $this->app->path("Models/{$this->studlySingular}.php");

        return $this->writeOrCollect($path, $content, 'model');
    }

    protected function generateMigration(): string
    {
        $timestamp = date('Y_m_d_His');
        $tableName = Str::snake($this->moduleName);
        $fields = $this->getMigrationFields();

        $content = "<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();{$fields}
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
";

        $path = $this->app->databasePath("migrations/{$timestamp}_create_{$tableName}_table.php");

        return $this->writeOrCollect($path, $content, 'migration');
    }

    protected function generateFactory(): string
    {
        $factoryFields = $this->getFactoryDefinition();

        $content = "<?php

declare(strict_types=1);

namespace Database\\Factories;

use App\\Models\\{$this->studlySingular};
use Illuminate\\Database\\Eloquent\\Factories\\Factory;

/**
 * @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\{$this->studlySingular}>
 */
class {$this->studlySingular}Factory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
{$factoryFields}
        ];
    }
}
";

        $path = $this->app->databasePath("factories/{$this->studlySingular}Factory.php");

        return $this->writeOrCollect($path, $content, 'factory');
    }

    protected function generateController(): string
    {
        $keyName = $this->identifierField === 'slug' ? 'slug' : 'id';

        // Exclude File fields for searchable and sortable
        $fieldsWithoutFile = array_filter($this->fields, fn ($field) => $field['type'] !== 'File');
        $searchable = implode("', '", array_column(array_slice($fieldsWithoutFile, 0, 3), 'name'));
        $sortable = implode("', '", array_column(array_slice($fieldsWithoutFile, 0, 2), 'name'));

        $keyMethod = $this->identifierField === 'slug' ? "
    public function keyName(): string
    {
        return 'slug';
    }

    " : '';

        $content = "<?php

declare(strict_types=1);

namespace App\\Http\\Controllers;

use App\\Http\\Requests\\{$this->studlySingular}Request;
use App\\Http\\Resources\\{$this->studlySingular}Resource;
use App\\Http\\Resources\\Collections\\{$this->studlySingular}Collection;
use App\\Models\\{$this->studlySingular};
use Orion\\Http\\Controllers\\Controller;
use Orion\\Concerns\\DisableAuthorization;

class {$this->studlySingular}Controller extends Controller
{
    use DisableAuthorization;

    protected \$model = {$this->studlySingular}::class;

    protected \$resource = {$this->studlySingular}Resource::class;

    protected \$collectionResource = {$this->studlySingular}Collection::class;

    protected \$request = {$this->studlySingular}Request::class;
{$keyMethod}
    public function limit(): int
    {
        return config('app.limit_pagination', 15);
    }

    public function maxLimit(): int
    {
        return config('app.max_pagination', 100);
    }

    public function searchableBy(): array
    {
        return ['{$searchable}'];
    }

    public function sortableBy(): array
    {
        return ['{$sortable}', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['{$searchable}'];
    }
}
";

        $path = $this->app->path("Http/Controllers/{$this->studlySingular}Controller.php");

        return $this->writeOrCollect($path, $content, 'controller');
    }

    // Helper methods will be added in the next part...
    protected function getFillableFields(): string
    {
        $fieldsWithoutFiles = array_filter($this->fields, fn ($field) => $field['type'] !== 'File');
        $fillable = array_map(fn ($field) => "'{$field['name']}'", $fieldsWithoutFiles);

        if ($this->identifierField === 'slug') {
            array_unshift($fillable, "'slug'");
        }

        return implode(', ', $fillable);
    }

    protected function getCasts(): string
    {
        $casts = [];

        foreach ($this->fields as $field) {
            if ($field['type'] === 'File') {
                continue;
            }

            if ($field['type'] === 'number') {
                $casts[] = "\n            '{$field['name']}' => 'integer'";
            } elseif ($field['type'] === 'boolean') {
                $casts[] = "\n            '{$field['name']}' => 'boolean'";
            } elseif ($field['type'] === 'Date') {
                $casts[] = "\n            '{$field['name']}' => 'datetime'";
            }
        }

        return implode(',', $casts);
    }

    protected function getMigrationFields(): string
    {
        $fields = '';

        if ($this->identifierField === 'slug') {
            $fields .= "\n            \$table->string('slug')->unique();";
        }

        foreach ($this->fields as $field) {
            if ($field['type'] === 'File') {
                continue;
            }

            $type = $this->getMigrationType($field['type']);
            $nullable = ! $field['required'] ? '->nullable()' : '';
            $fields .= "\n            \$table->{$type}('{$field['name']}'){$nullable};";
        }

        return $fields;
    }

    protected function getMigrationType(string $type): string
    {
        return match ($type) {
            'string', 'email', 'password' => 'string',
            'number' => 'integer',
            'boolean' => 'boolean',
            'Date' => 'timestamp',
            'textarea' => 'text',
            'quill-editor' => 'longText',
            default => 'string',
        };
    }

    protected function getFactoryDefinition(): string
    {
        $definitions = [];

        foreach ($this->fields as $field) {
            if ($field['type'] === 'File') {
                continue;
            }

            $fakerMethod = $this->getFakerMethod($field['type'], $field['name']);
            $definitions[] = "            '{$field['name']}' => {$fakerMethod}";
        }

        return implode(",\n", $definitions);
    }

    protected function getFakerMethod(string $type, string $fieldName): string
    {
        $lower = strtolower($fieldName);

        return match ($type) {
            'string' => match (true) {
                str_contains($lower, 'email') => 'fake()->unique()->safeEmail()',
                str_contains($lower, 'phone') => 'fake()->phoneNumber()',
                str_contains($lower, 'name') => 'fake()->words(3, true)',
                default => 'fake()->sentence()',
            },
            'number' => 'fake()->numberBetween(1, 1000)',
            'boolean' => 'fake()->boolean()',
            'Date' => 'fake()->dateTimeBetween(\'-1 year\', \'now\')',
            'textarea' => 'fake()->paragraph(5)',
            'email' => 'fake()->unique()->safeEmail()',
            'password' => 'bcrypt(\'password\')',
            default => 'fake()->sentence()',
        };
    }

    protected function generateResource(): string
    {
        $fields = $this->getResourceFields();

        $content = "<?php

declare(strict_types=1);

namespace App\\Http\\Resources;

use Illuminate\\Http\\Resources\\Json\\JsonResource;

class {$this->studlySingular}Resource extends JsonResource
{
    public function toArray(\$request): array
    {
        return [
            'id' => \$this->id,{$fields}
            'created_at' => \$this->created_at,
            'updated_at' => \$this->updated_at,
        ];
    }
}
";

        $path = $this->app->path("Http/Resources/{$this->studlySingular}Resource.php");

        return $this->writeOrCollect($path, $content, 'resource');
    }

    protected function getResourceFields(): string
    {
        $fields = '';

        if ($this->identifierField === 'slug') {
            $fields .= "\n            'slug' => \$this->slug,";
        }

        foreach ($this->fields as $field) {
            if ($field['type'] === 'File') {
                continue;
            }
            $fields .= "\n            '{$field['name']}' => \$this->{$field['name']},";
        }

        if ($this->hasFileField()) {
            $collectionName = Str::snake($this->moduleName);
            $fields .= "\n            'media' => \$this->getMedia('{$collectionName}')->map(function (\$media) {
                return [
                    'id' => \$media->id,
                    'name' => \$media->name,
                    'file_name' => \$media->file_name,
                    'url' => \$media->getUrl(),
                ];
            }),";
        }

        return $fields;
    }

    protected function generateCollection(): string
    {
        $content = "<?php

declare(strict_types=1);

namespace App\\Http\\Resources\\Collections;

use App\\Http\\Resources\\{$this->studlySingular}Resource;
use Illuminate\\Http\\Resources\\Json\\ResourceCollection;

class {$this->studlySingular}Collection extends ResourceCollection
{
    public function toArray(\$request): array
    {
        return {$this->studlySingular}Resource::collection(\$this->collection)->toArray(\$request);
    }
}
";

        $path = $this->app->path("Http/Resources/Collections/{$this->studlySingular}Collection.php");

        return $this->writeOrCollect($path, $content, 'collection');
    }

    protected function generateRequest(): string
    {
        $storeRules = $this->getValidationRules(true);
        $updateRules = $this->getValidationRules(false);

        $content = "<?php

declare(strict_types=1);

namespace App\\Http\\Requests;

use Orion\\Http\\Requests\\Request;

class {$this->studlySingular}Request extends Request
{
    public function storeRules(): array
    {
        return [{$storeRules}
        ];
    }

    public function updateRules(): array
    {
        return [{$updateRules}
        ];
    }

    public function commonMessages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute field must be a string.',
            'numeric' => 'The :attribute field must be a number.',
            'boolean' => 'The :attribute field must be true or false.',
            'date' => 'The :attribute field must be a valid date.',
        ];
    }
}
";

        $path = $this->app->path("Http/Requests/{$this->studlySingular}Request.php");

        return $this->writeOrCollect($path, $content, 'request');
    }

    protected function getValidationRules(bool $isStore): string
    {
        $rules = '';

        foreach ($this->fields as $field) {
            $rule = $this->getFieldValidation($field, $isStore);
            $rules .= "\n            '{$field['name']}' => {$rule},";
        }

        return $rules;
    }

    protected function getFieldValidation(array $field, bool $isStore): string
    {
        $rules = [];

        if ($field['required'] && $isStore) {
            $rules[] = "'required'";
        } elseif (! $isStore) {
            $rules[] = "'sometimes'";
        } else {
            $rules[] = "'nullable'";
        }

        $rules[] = match ($field['type']) {
            'string' => "'string', 'max:255'",
            'number' => "'numeric'",
            'boolean' => "'boolean'",
            'Date' => "'date'",
            'File' => "'array'",
            default => "'string'",
        };

        return '['.implode(', ', $rules).']';
    }

    protected function generatePolicy(): string
    {
        $content = "<?php

declare(strict_types=1);

namespace App\\Policies;

use App\\Models\\{$this->studlySingular};
use App\\Models\\User;

class {$this->studlySingular}Policy
{
    public function viewAny(?User \$user): bool
    {
        return true;
    }

    public function view(?User \$user, {$this->studlySingular} \${$this->singularName}): bool
    {
        return true;
    }

    public function create(?User \$user): bool
    {
        return true;
    }

    public function update(?User \$user, {$this->studlySingular} \${$this->singularName}): bool
    {
        return true;
    }

    public function delete(?User \$user, {$this->studlySingular} \${$this->singularName}): bool
    {
        return true;
    }

    public function restore(?User \$user, {$this->studlySingular} \${$this->singularName}): bool
    {
        return true;
    }

    public function forceDelete(?User \$user, {$this->studlySingular} \${$this->singularName}): bool
    {
        return true;
    }
}
";

        $path = $this->app->path("Policies/{$this->studlySingular}Policy.php");

        return $this->writeOrCollect($path, $content, 'policy');
    }

    protected function generateSeeder(): string
    {
        $content = "<?php

declare(strict_types=1);

namespace Database\\Seeders;

use App\\Models\\{$this->studlySingular};
use Illuminate\\Database\\Seeder;

class {$this->studlySingular}Seeder extends Seeder
{
    public function run(): void
    {
        {$this->studlySingular}::factory()->count(10)->create();
    }
}
";

        $path = $this->app->databasePath("seeders/{$this->studlySingular}Seeder.php");

        return $this->writeOrCollect($path, $content, 'seeder');
    }

    protected function addRoute(): array|string
    {
        $routeLine = "Orion::resource('{$this->moduleName}', {$this->studlySingular}Controller::class);";
        $importLine = "use App\\Http\\Controllers\\{$this->studlySingular}Controller;";
        $orionImport = 'use Orion\\Facades\\Orion;';

        // In dryRun mode, return the route instructions instead of modifying the file
        if ($this->dryRun) {
            return [
                'file' => 'routes/api.php',
                'import_lines' => [$orionImport, $importLine],
                'route_line' => $routeLine,
            ];
        }

        $routesPath = $this->app->basePath('routes/api.php');

        if (! File::exists($routesPath)) {
            return 'routes/api.php not found';
        }

        $content = File::get($routesPath);

        if (! str_contains($content, $orionImport)) {
            if (str_contains($content, 'use Illuminate\Support\Facades\Route;')) {
                $content = str_replace(
                    'use Illuminate\Support\Facades\Route;',
                    "use Illuminate\Support\Facades\Route;\n{$orionImport}",
                    $content
                );
            } else {
                if (str_contains($content, 'declare(strict_types=1);')) {
                    $content = str_replace(
                        'declare(strict_types=1);',
                        "declare(strict_types=1);\n\n{$orionImport}",
                        $content
                    );
                } else {
                    $content = preg_replace(
                        '/^<\?php\s*/',
                        "<?php\n\n{$orionImport}\n",
                        $content
                    );
                }
            }
        }

        if (! str_contains($content, $importLine)) {
            $content = preg_replace(
                '/(use\s+App\\\\Http\\\\Controllers\\\\[^;]+;)/',
                "$1\n{$importLine}",
                $content,
                1
            );

            if (! str_contains($content, $importLine)) {
                $content = str_replace(
                    $orionImport,
                    "{$orionImport}\n{$importLine}",
                    $content
                );
            }
        }

        if (! str_contains($content, "Orion::resource('{$this->moduleName}'")) {
            $content = rtrim($content)."\n\n{$routeLine}\n";
        }

        File::put($routesPath, $content);

        return $routesPath;
    }

    protected function runMigration(): array
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            return [
                'success' => true,
                'message' => 'Migration executed successfully',
                'output' => $output,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Migration failed: '.$e->getMessage(),
            ];
        }
    }

    protected function runSeeder(): array
    {
        try {
            Artisan::call('db:seed', [
                '--class' => "Database\\Seeders\\{$this->studlySingular}Seeder",
                '--force' => true,
            ]);
            $output = Artisan::output();

            return [
                'success' => true,
                'message' => 'Seeder executed successfully',
                'output' => $output,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Seeder failed: '.$e->getMessage(),
            ];
        }
    }

    protected function checkAndCreateInfrastructure(): void
    {
        $this->ensureCategoryInfrastructure();
        $this->ensureMenuInfrastructure();
        $this->ensureModuleManagerInfrastructure();
    }

    protected function ensureCategoryInfrastructure(): void
    {
        // 1. Ensure Model exists
        $modelPath = $this->app->path('Models/Category.php');

        if (! File::exists($modelPath)) {
            $content = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Category extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = ['name', 'slug', 'order', 'icon', 'navigation_type', 'position_reference_id', 'position_type'];

    protected $casts = [
        'order' => 'integer',
        'position_reference_id' => 'integer',
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    public function positionReference()
    {
        return $this->belongsTo(Category::class, 'position_reference_id');
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name'])
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }
}
PHP;
            File::ensureDirectoryExists(dirname($modelPath));
            File::put($modelPath, $content);
        }

        // 2. Ensure Migration exists (simplified check by checking if table exists, if not create migration)
        // Note: Creating migration file only if it doesn't exist is harder strictly by filename due to timestamps.
        // We will assume if the Model didn't exist, we probably need the migration, OR if the table doesn't exist.
        // For simplicity in this tool, we check if a file with 'create_categories_table' exists in migrations.

        $migrationExists = ! empty(File::glob($this->app->databasePath('migrations/*_create_categories_table.php')));

        if (! $migrationExists) {
            $timestamp = date('Y_m_d_His');
            $migrationPath = $this->app->databasePath("migrations/{$timestamp}_create_categories_table.php");

            $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('order')->default(0);
            $table->string('icon')->nullable();
            $table->string('navigation_type')->default('side');
            $table->unsignedBigInteger('position_reference_id')->nullable();
            $table->string('position_type')->nullable(); // before, after
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
PHP;
            File::put($migrationPath, $content);
            // Run migrate immediately to ensure table exists for subsequent operations
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function ensureMenuInfrastructure(): void
    {
        // 1. Ensure Model exists
        $modelPath = $this->app->path('Models/Menu.php');

        if (! File::exists($modelPath)) {
            $content = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Menu extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'name',
        'icon',
        'color',
        'route',
        'roles',
        'slug',
        'category_id',
        'disable',
        'description',
    ];

    protected $casts = [
        'roles' => 'array',
        'description' => 'array',
        'disable' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name'])
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }
}
PHP;
            File::ensureDirectoryExists(dirname($modelPath));
            File::put($modelPath, $content);
        }

        // 2. Ensure Migration exists
        $migrationExists = ! empty(File::glob($this->app->databasePath('migrations/*_create_menus_table.php')));

        if (! $migrationExists) {
            $timestamp = date('Y_m_d_His');
            $migrationPath = $this->app->databasePath("migrations/{$timestamp}_create_menus_table.php");

            $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon');
            $table->string('color');
            $table->string('route')->unique();
            $table->json('roles');
            $table->string('slug')->unique()->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->integer('disable')->default(0);
            $table->json('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
PHP;
            File::put($migrationPath, $content);
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function ensureModuleManagerInfrastructure(): void
    {
        // ... (previous implementation) ...
        // 2. Ensure Migration exists
        $migrationExists = ! empty(File::glob($this->app->databasePath('migrations/*_create_module_managers_table.php')));

        if (! $migrationExists) {
            $timestamp = date('Y_m_d_His');
            $migrationPath = $this->app->databasePath("migrations/{$timestamp}_create_module_managers_table.php");

            $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_managers', function (Blueprint $table) {
            $table->id();
            $table->string('module_name')->unique();
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->string('display_name_singular');
            $table->string('resource_type');
            $table->string('identifier_field')->default('id');
            $table->string('identifier_type')->default('number');
            $table->boolean('requires_auth')->default(true);
            $table->string('route_path');
            $table->json('fields');
            $table->boolean('enabled')->default(true);
            $table->boolean('dev_mode')->default(false);
            $table->json('roles')->nullable();
            $table->json('translations')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_managers');
    }
};
PHP;
            File::put($migrationPath, $content);
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function generateMenu(array $roles): void
    {
        // Only proceed if Menu model exists (it should, due to checkAndCreateInfrastructure)
        if (! class_exists('App\Models\Menu')) {
            return;
        }

        $menuName = $this->studlyName; // Use StudlyName for menu name to look good
        $menuRoute = '/'.strtolower($this->moduleName); // e.g. /products
        $menuIcon = 'extension'; // Default icon
        $menuColor = '#10b981'; // Default green color
        $menuSlug = Str::slug($this->moduleName);

        // Get or create default category (Dashboard)
        $category = Category::firstOrCreate(
            ['slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 0, 'icon' => 'dashboard']
        );

        // Check if menu already exists
        $existingMenu = Menu::where('name', $menuName)
            ->orWhere('slug', $menuSlug)
            ->first();

        if (! $existingMenu) {
            Menu::create([
                'name' => $menuName,
                'icon' => $menuIcon,
                'color' => $menuColor,
                'route' => $menuRoute,
                'roles' => $roles,
                'slug' => $menuSlug,
                'category_id' => $category->id,
                'disable' => 0, // 0 = enabled based on migration default
            ]);
        }
    }

    protected function addToModuleManager(): void
    {
        // Only proceed if ModuleManager model exists
        if (! class_exists('App\Models\ModuleManager')) {
            return;
        }

        $moduleName = $this->moduleName;

        $exists = ModuleManager::where('module_name', $moduleName)->exists();

        if (! $exists) {
            ModuleManager::create([
                'module_name' => $moduleName,
                'slug' => Str::slug($moduleName),
                'display_name' => $this->studlyName,
                'display_name_singular' => $this->studlySingular,
                'resource_type' => $moduleName,
                'identifier_field' => $this->identifierField,
                'identifier_type' => 'number', // Defaulting to number for now, could be inferred
                'requires_auth' => true,
                'route_path' => $moduleName,
                'fields' => $this->fields,
                'enabled' => true,
                'dev_mode' => false,
                'roles' => $this->roles,
                'translations' => null,
                'actions' => [
                    'create' => ['enabled' => true],
                    'edit' => ['enabled' => true],
                    'delete' => ['enabled' => true],
                    'show' => ['enabled' => true],
                    'list' => ['enabled' => true],
                ],
            ]);
        }
    }

    public function delete(): array
    {
        $tableName = Str::snake($this->moduleName);
        $pluralName = strtolower($this->moduleName);

        $filesToDelete = [
            $this->app->path("Models/{$this->studlySingular}.php"),
            $this->app->databasePath("factories/{$this->studlySingular}Factory.php"),
            $this->app->path("Http/Controllers/{$this->studlySingular}Controller.php"),
            $this->app->path("Http/Resources/{$this->studlySingular}Resource.php"),
            $this->app->path("Http/Resources/Collections/{$this->studlySingular}Collection.php"),
            $this->app->path("Http/Requests/{$this->studlySingular}Request.php"),
            $this->app->path("Policies/{$this->studlySingular}Policy.php"),
            $this->app->databasePath("seeders/{$this->studlySingular}Seeder.php"),
        ];

        // Migrations are tricky due to timestamps. We return a glob pattern for the client to find it.
        $migrationPattern = "database/migrations/*_create_{$tableName}_table.php";

        $relativeFiles = [];
        $basePath = $this->app->basePath();

        foreach ($filesToDelete as $path) {
            $relative = str_replace($basePath.DIRECTORY_SEPARATOR, '', $path);
            $relativeFiles[] = str_replace('\\', '/', $relative);
        }

        $results = [
            'success' => true,
            'message' => "Module '{$this->moduleName}' deletion instructions generated",
            'files_to_delete' => $relativeFiles,
            'migration_pattern' => $migrationPattern,
            'route_removal' => [
                'file' => 'routes/api.php',
                'import_line' => "use App\\Http\\Controllers\\{$this->studlySingular}Controller;",
                'route_line_pattern' => "Orion::resource('{$pluralName}', {$this->studlySingular}Controller::class);",
            ],
            'infrastructure' => [
                'menu_slug' => Str::slug($this->moduleName),
                'module_manager_slug' => Str::slug($this->moduleName),
            ],
        ];

        if ($this->dryRun) {
            return $results;
        }

        // Real deletion (if running locally on MCP server)
        foreach ($filesToDelete as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        // Delete migrations matching pattern
        $migrations = File::glob($this->app->databasePath('migrations/*_create_'.$tableName.'_table.php'));

        foreach ($migrations as $migration) {
            File::delete($migration);
        }

        return $results;
    }

    public function edit(array $allFields, array $changes = []): array
    {
        $this->fields = $allFields; // Mettre à jour avec l'état complet souhaité

        $results = [];

        try {
            // Régénérer tous les fichiers "managés" avec les nouveaux champs
            $results['model'] = $this->generateModel();
            $results['factory'] = $this->generateFactory();
            $results['controller'] = $this->generateController();
            $results['resource'] = $this->generateResource();
            $results['collection'] = $this->generateCollection();
            $results['request'] = $this->generateRequest();
            $results['policy'] = $this->generatePolicy();
            $results['seeder'] = $this->generateSeeder();

            // Générer la migration d'altération si des changements sont fournis
            if (! empty($changes)) {
                $results['alter_migration'] = $this->generateAlterMigration($changes);
            }

            if ($this->dryRun) {
                return [
                    'success' => true,
                    'message' => "Module '{$this->moduleName}' updated instructions generated (dry run)",
                    'files' => $this->generatedFiles,
                    'post_actions' => ['migrate'],
                    'infrastructure' => [
                        'module_manager' => [
                            'module_name' => $this->moduleName,
                            'fields' => $this->fields,
                        ],
                    ],
                ];
            }

            // Mode normal : mettre à jour le ModuleManager localement
            $this->addToModuleManager();

            return [
                'success' => true,
                'message' => "Module '{$this->moduleName}' updated successfully",
                'files' => $results,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Module update failed: '.$e->getMessage(),
                'error' => $e->getTraceAsString(),
            ];
        }
    }

    protected function generateAlterMigration(array $changes): string
    {
        $timestamp = date('Y_m_d_His');
        $tableName = Str::snake($this->moduleName);
        $migrationName = "update_{$tableName}_table_".time();

        $upContent = '';
        $downContent = '';

        // Ajout de colonnes
        if (isset($changes['added']) && is_array($changes['added'])) {
            foreach ($changes['added'] as $field) {
                $type = $this->getMigrationType($field['type']);
                $nullable = (! isset($field['required']) || ! $field['required']) ? '->nullable()' : '';
                $upContent .= "\n            \$table->{$type}('{$field['name']}'){$nullable};";
                $downContent .= "\n            \$table->dropColumn('{$field['name']}');";
            }
        }

        // Renommage de colonnes
        if (isset($changes['renamed']) && is_array($changes['renamed'])) {
            foreach ($changes['renamed'] as $change) {
                $oldName = $change['old'] ?? '';
                $newName = $change['new'] ?? '';

                if ($oldName && $newName) {
                    $upContent .= "\n            \$table->renameColumn('{$oldName}', '{$newName}');";
                    $downContent .= "\n            \$table->renameColumn('{$newName}', '{$oldName}');";
                }
            }
        }

        // Modification de types
        if (isset($changes['modified']) && is_array($changes['modified'])) {
            foreach ($changes['modified'] as $field) {
                $type = $this->getMigrationType($field['type']);
                $upContent .= "\n            \$table->{$type}('{$field['name']}')->change();";
            }
        }

        $content = "<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
            {$upContent}
        });
    }

    public function down(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
            {$downContent}
        });
    }
};
";

        $path = $this->app->databasePath("migrations/{$timestamp}_{$migrationName}.php");

        return $this->writeOrCollect($path, $content, 'migration');
    }
}
