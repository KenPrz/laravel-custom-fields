<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\CustomFieldManager;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\FieldValidator;
use KenPrz\CustomFields\Types\SelectType;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:custom-field')]
class MakeCustomFieldCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'make:custom-field
        {key? : The field key, for example tax_bracket}
        {--model= : The model: a class, a short name such as User, or a morph alias}
        {--type= : The field type, for example text, select or date}
        {--label= : The label shown to users (defaults to the key as a headline)}
        {--options= : Choices for select types, as "value:Label,value:Label"}
        {--rules= : Extra validation rules, separated by |}
        {--description= : Help text for the field}
        {--required : Make the field required}
        {--mode= : Where to create the field: migration (default) or db}
        {--scope= : A scope for db mode, as Model:id}
        {--path= : Where to write the migration, relative to the base path}
        {--force : Skip the confirmation in production (db mode)}';

    protected $description = 'Create a custom field, as a migration or straight in the database';

    private CustomFieldManager $manager;

    public function handle(CustomFieldManager $manager, Filesystem $files): int
    {
        try {
            $mode = $this->askMode();
            $model = $this->askModel();
            $this->manager = $manager->forScope($mode === 'db' ? $this->askScope() : $this->rejectScope());
            $attributes = $this->askDefinition($model);

            $this->manager->check($attributes);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        } catch (CustomFieldException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->input->isInteractive()) {
            $this->summarize($attributes, $mode);

            if (! confirm('Create this field?', default: true)) {
                $this->components->warn('Nothing was created.');

                return self::SUCCESS;
            }
        }

        if ($mode === 'db') {
            if (! $this->confirmToProceed()) {
                return self::FAILURE;
            }

            $field = $this->manager->create($attributes);
            $this->components->info("Custom field [{$field->key}] created with id [{$field->getKey()}].");

            return self::SUCCESS;
        }

        $path = $this->writeMigration($files, $attributes);
        $this->components->info("Migration [{$path}] created. Run php artisan migrate to add the field.");

        return self::SUCCESS;
    }

    private function askMode(): string
    {
        $mode = $this->stringOption('mode');

        if ($mode === null) {
            return $this->input->isInteractive()
                ? (string) select(
                    label: 'Where should the field be created?',
                    options: ['migration' => 'In a migration file (recommended)', 'db' => 'In the database, right now'],
                    default: 'migration',
                )
                : 'migration';
        }

        if (! in_array($mode, ['migration', 'db'], true)) {
            throw $this->invalid('mode', "The mode [{$mode}] is not supported. Use migration or db.");
        }

        return $mode;
    }

    /**
     * @return class-string<Model>
     */
    private function askModel(): string
    {
        $model = $this->askOrOption(
            'model',
            fn (Closure $validate) => suggest(
                label: 'Which model gets the field?',
                options: $this->appModels(),
                placeholder: 'User',
                required: true,
                validate: $validate,
            ),
            fn (string $value) => $this->resolveModel($value) === null ? "The model [{$value}] could not be found." : null,
        );

        return ($model === null ? null : $this->resolveModel($model))
            ?? throw $this->invalid('model', 'The --model option is required.');
    }

    private function askScope(): ?Model
    {
        $scope = $this->stringOption('scope');

        if ($scope === null && $this->input->isInteractive()) {
            $scope = text(
                label: 'Scope',
                placeholder: 'Property:5',
                hint: 'Optional. The record this field belongs to. Leave empty for a global field.',
                validate: fn (string $value) => $value === '' ? null : $this->scopeError($value),
            );
        }

        if ($scope === null || $scope === '') {
            return null;
        }

        if (($error = $this->scopeError($scope)) !== null) {
            throw $this->invalid('scope', $error);
        }

        return $this->resolveScope($scope);
    }

    private function rejectScope(): null
    {
        if ($this->stringOption('scope') !== null) {
            throw $this->invalid('scope', 'A scope only works with --mode=db: record ids differ between environments, so a migration cannot point at one.');
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, mixed>
     */
    private function askDefinition(string $model): array
    {
        $key = $this->askOrOption(
            'key',
            fn (Closure $validate) => text(
                label: 'Field key',
                placeholder: 'tax_bracket',
                required: true,
                validate: $validate,
                hint: 'Lowercase letters, numbers and underscores. Checked against existing fields and the model.',
            ),
            fn (string $value) => $this->manager->keyError($model, $value) ?? $this->pendingMigrationError($model, $value),
            argument: true,
        ) ?? throw $this->invalid('key', 'The key argument is required.');

        $label = $this->askOrOption(
            'label',
            fn (Closure $validate) => text(label: 'Label', default: Str::headline($key), required: true, validate: $validate),
            fn (string $value) => mb_strlen($value) > 255 ? 'The label may not be longer than 255 characters.' : null,
        ) ?? Str::headline($key);

        $types = $this->manager->types();
        $type = $this->askOrOption(
            'type',
            fn (Closure $validate) => (string) select(label: 'Field type', options: $types->names(), default: 'text', scroll: 10),
            fn (string $value) => $types->has($value) ? null : "The type [{$value}] is not registered. Available: ".implode(', ', $types->names()).'.',
        ) ?? throw $this->invalid('type', 'The --type option is required.');

        $attributes = ['model' => $model, 'key' => $key, 'label' => $label, 'type' => $type];

        if ($types->get($type) instanceof SelectType) {
            $attributes['settings'] = ['options' => $this->askOptions()];
        }

        $required = $this->option('required') || ($this->input->isInteractive() && confirm('Is this field required?', default: false));

        if ($required) {
            $attributes['is_required'] = true;
        }

        $description = $this->askOrOption(
            'description',
            fn (Closure $validate) => text(label: 'Description', hint: 'Optional. Press Enter to skip.'),
            fn () => null,
        );

        if ($description !== null && $description !== '') {
            $attributes['description'] = $description;
        }

        $rules = $this->askOrOption(
            'rules',
            fn (Closure $validate) => text(
                label: 'Extra validation rules',
                placeholder: 'min:18|max:120',
                hint: 'Optional. Laravel rules separated by |. Press Enter to skip.',
                validate: $validate,
            ),
            fn (string $value) => $this->rulesError($value),
        );

        if ($rules !== null && $rules !== '') {
            $attributes['validation_rules'] = $this->splitRules($rules);
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function askOptions(): array
    {
        $given = $this->stringOption('options');

        if ($given !== null) {
            $options = [];

            foreach (array_filter(array_map(trim(...), explode(',', $given)), fn ($pair) => $pair !== '') as $pair) {
                [$value, $label] = array_pad(array_map(trim(...), explode(':', $pair, 2)), 2, '');

                if (array_key_exists($value, $options)) {
                    throw $this->invalid('options', "The option [{$value}] is listed twice.");
                }

                $options[$value] = $label === '' ? $value : $label;
            }

            return $options;
        }

        if (! $this->input->isInteractive()) {
            throw $this->invalid('options', 'Select types need --options, as "value:Label,value:Label".');
        }

        $options = [];

        while (true) {
            $value = text(
                label: 'Option value',
                placeholder: $options === [] ? 'vip' : '',
                hint: $options === [] ? 'The value that gets stored.' : 'Leave empty to finish.',
                validate: fn (string $value) => match (true) {
                    $value === '' && $options === [] => 'Add at least one option.',
                    array_key_exists($value, $options) => "The option [{$value}] is already added.",
                    default => null,
                },
            );

            if ($value === '') {
                return $options;
            }

            $options[$value] = text(label: "Label for [{$value}]", default: $value, required: true);
        }
    }

    /**
     * Use the option (or argument) when given, validated; otherwise ask
     * interactively with the same validation; otherwise null.
     *
     * @param  Closure(Closure(string): ?string): mixed  $ask
     * @param  Closure(string): ?string  $validate
     */
    private function askOrOption(string $name, Closure $ask, Closure $validate, bool $argument = false): ?string
    {
        $value = $argument ? $this->argument($name) : $this->option($name);

        if (is_string($value)) {
            $error = $validate($value);

            if ($error !== null) {
                throw $this->invalid($name, $error);
            }

            return $value;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $ask($validate);

        return is_scalar($answer) ? (string) $answer : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function summarize(array $attributes, string $mode): void
    {
        $rows = [
            ['Model', $attributes['model']],
            ['Key', $attributes['key']],
            ['Label', $attributes['label']],
            ['Type', $attributes['type']],
        ];

        foreach ($attributes['settings']['options'] ?? [] as $value => $label) {
            $rows[] = ['Option', "{$value} ({$label})"];
        }

        $rows[] = ['Required', ($attributes['is_required'] ?? false) ? 'yes' : 'no'];
        $rows[] = ['Description', $attributes['description'] ?? '-'];
        $rows[] = ['Rules', implode('|', $attributes['validation_rules'] ?? []) ?: '-'];
        $rows[] = ['Scope', ($scope = $this->manager->currentScope()) ? $scope::class.':'.$scope->getKey() : 'global'];
        $rows[] = ['Created in', $mode === 'db' ? 'the database' : 'a migration file'];

        $this->table(['Setting', 'Value'], $rows);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeMigration(Filesystem $files, array $attributes): string
    {
        $directory = $this->migrationDirectory();
        $file = $directory.'/'.date('Y_m_d_His').'_'.$this->migrationName($attributes['model'], $attributes['key']).'.php';

        $model = '\\'.ltrim($attributes['model'], '\\').'::class';

        $files->ensureDirectoryExists($directory);
        $files->put($file, str_replace(
            ['{{ attributes }}', '{{ model }}', '{{ key }}'],
            [$this->export(['model' => $model] + $attributes, 2, raw: ['model']), $model, var_export($attributes['key'], true)],
            $files->get(__DIR__.'/../../resources/stubs/custom-field-migration.stub'),
        ));

        return $file;
    }

    /**
     * A migration for this key that was generated but hasn't run yet isn't in
     * the database, so look for the file too.
     *
     * @param  class-string<Model>  $model
     */
    private function pendingMigrationError(string $model, string $key): ?string
    {
        if ($this->stringOption('mode') === 'db') {
            return null;
        }

        $existing = glob($this->migrationDirectory().'/*_'.$this->migrationName($model, $key).'.php') ?: [];

        return $existing === [] ? null : 'A migration for the key ['.$key.'] already exists: '.basename($existing[0]).'.';
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function migrationName(string $model, string $key): string
    {
        return "add_{$key}_custom_field_to_".Str::snake(Str::pluralStudly(class_basename($model)));
    }

    private function migrationDirectory(): string
    {
        $path = $this->stringOption('path');

        return $path !== null
            ? $this->laravel->basePath(trim($path, '/'))
            : $this->laravel->databasePath('migrations');
    }

    /**
     * Write an array as short-syntax PHP, indented for the stub.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $raw  top-level keys whose values are already PHP code
     */
    private function export(array $value, int $depth, array $raw = []): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $code = match (true) {
                in_array($key, $raw, true) => $item,
                is_array($item) => $this->export($item, $depth + 1),
                default => var_export($item, true),
            };

            $lines[] = $indent.var_export($key, true).' => '.$code.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    /**
     * @return class-string<Model>|null
     */
    private function resolveModel(string $name): ?string
    {
        $name = ltrim($name, '\\');

        foreach ([$name, $this->laravel->getNamespace().'Models\\'.$name, $this->laravel->getNamespace().$name] as $class) {
            if (is_subclass_of($class, Model::class)) {
                return $class;
            }
        }

        $morphed = Relation::getMorphedModel($name);

        return $morphed !== null && is_subclass_of($morphed, Model::class) ? $morphed : null;
    }

    private function scopeError(string $scope): ?string
    {
        [$model, $id] = array_pad(explode(':', $scope, 2), 2, '');

        if ($id === '' || ($class = $this->resolveModel($model)) === null) {
            return "The scope [{$scope}] must look like Model:id, for example Property:5.";
        }

        return $class::query()->whereKey($id)->exists() ? null : "No [{$class}] with id [{$id}] exists.";
    }

    private function resolveScope(string $scope): Model
    {
        [$model, $id] = explode(':', $scope, 2);
        $class = $this->resolveModel($model) ?? throw $this->invalid('scope', "The model [{$model}] could not be found.");

        return $class::query()->findOrFail($id);
    }

    private function rulesError(string $rules): ?string
    {
        if ($rules === '') {
            return null;
        }

        $unknown = $this->laravel->make(FieldValidator::class)->unknownRules($this->splitRules($rules));

        return $unknown === [] ? null : 'Unknown validation rules: '.implode(', ', $unknown).'.';
    }

    /**
     * @return list<string>
     */
    private function splitRules(string $rules): array
    {
        return array_values(array_filter(array_map(trim(...), explode('|', $rules)), fn ($rule) => $rule !== ''));
    }

    /**
     * Short names of the models in app/Models, for suggestions.
     *
     * @return list<string>
     */
    private function appModels(): array
    {
        $directory = $this->laravel->path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_map(
            fn ($file) => str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()),
            (new Filesystem)->allFiles($directory),
        ));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    private function invalid(string $name, string $message): ValidationException
    {
        return ValidationException::withMessages([$name => $message]);
    }
}
