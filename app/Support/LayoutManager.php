<?php

namespace App\Support;

use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use Illuminate\Support\Facades\DB;

/**
 * Layout versioning with publish and revert (Z-3.2). Every draft() call creates a new,
 * unpublished version; publish() makes exactly one version live per module+view;
 * revert() never rewrites history — it drafts a new version from an old one's
 * definition and publishes that.
 */
final class LayoutManager
{
    public function __construct(
        private readonly LayoutValidator $validator,
        private readonly MetadataRepository $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function draft(string $moduleKey, string $view, array $definition): Layout
    {
        $module = $this->findModule($moduleKey);
        $errors = $this->validateDefinition($module, $moduleKey, $view, $definition);

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var Module $module */
        $maxVersion = Layout::query()
            ->where('module_id', $module->id)
            ->where('view', $view)
            ->max('version');
        $nextVersion = (is_numeric($maxVersion) ? (int) $maxVersion : 0) + 1;

        $layout = Layout::create([
            'module_id' => $module->id,
            'view' => $view,
            'definition' => $definition,
            'version' => $nextVersion,
            'is_published' => false,
        ]);

        $this->repository->bump();

        return $layout;
    }

    public function publish(string $layoutId): Layout
    {
        $layout = Layout::query()->findOrFail($layoutId);

        $errors = $this->validator->errors($layout->definition);
        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        DB::transaction(function () use ($layout): void {
            Layout::query()
                ->where('module_id', $layout->module_id)
                ->where('view', $layout->view)
                ->where('id', '!=', $layout->id)
                ->update(['is_published' => false]);

            $layout->update(['is_published' => true]);
        });

        $this->repository->bump();

        return $layout->refresh();
    }

    /**
     * Draft a new version from an old version's definition, then publish it.
     */
    public function revert(string $moduleKey, string $view, int $toVersion): Layout
    {
        $module = $this->findModule($moduleKey);
        if ($module === null) {
            throw new MetadataValidationException(["Module [{$moduleKey}] does not exist."]);
        }

        $old = Layout::query()
            ->where('module_id', $module->id)
            ->where('view', $view)
            ->where('version', $toVersion)
            ->first();

        if ($old === null) {
            throw new MetadataValidationException(["No version [{$toVersion}] of the [{$view}] layout for [{$moduleKey}]."]);
        }

        $draft = $this->draft($moduleKey, $view, $old->definition);

        return $this->publish($draft->id);
    }

    private function findModule(string $moduleKey): ?Module
    {
        return Module::query()->where('key', $moduleKey)->first();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function validateDefinition(?Module $module, string $moduleKey, string $view, array $definition): array
    {
        $errors = [];

        if ($module === null) {
            $errors[] = "Module [{$moduleKey}] does not exist.";
        }

        if (($definition['view'] ?? null) !== $view) {
            $errors[] = "The definition's view must match [{$view}].";
        }

        if (($definition['module'] ?? null) !== $moduleKey) {
            $errors[] = "The definition's module must match [{$moduleKey}].";
        }

        return [...$errors, ...$this->validator->errors($definition)];
    }
}
