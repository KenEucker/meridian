<?php

declare(strict_types=1);

namespace App\Orchid\Filters\Scope;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filter;

/**
 * Base for the Orchid organization/department/team narrowing filters.
 *
 * God Mode list screens span every organization by default, which makes them
 * hard to read once more than one organization exists. Each filter applies a
 * model-declared local scope (for example `scopeInOrganization`), so a model
 * only offers the narrowing levels that make sense for it and expresses its own
 * path to that level. Levels a model does not declare are hidden.
 */
abstract class ScopeFilter extends Filter
{
    /**
     * @param  class-string<Model>|null  $modelClass
     */
    public function __construct(private readonly ?string $modelClass = null)
    {
        parent::__construct();
    }

    /**
     * The local scope method this filter applies, without the `scope` prefix.
     */
    abstract protected function scopeMethod(): string;

    /**
     * The request parameter carrying the selected id.
     */
    abstract protected function parameter(): string;

    public function parameters(): ?array
    {
        return [$this->parameter()];
    }

    public function run(Builder $builder): Builder
    {
        $value = $this->selected();

        if ($value === null || ! $this->supportsScope()) {
            return $builder;
        }

        return $builder->{$this->scopeMethod()}($value);
    }

    public function isDisplay(): bool
    {
        return $this->supportsScope() && parent::isDisplay();
    }

    /**
     * Whether the listed model declares the local scope this filter applies.
     */
    public function supportsScope(): bool
    {
        if ($this->modelClass === null) {
            return false;
        }

        return method_exists($this->modelClass, 'scope'.ucfirst($this->scopeMethod()));
    }

    /**
     * The selected id, or null when the filter is cleared.
     */
    protected function selected(): ?string
    {
        return $this->selectedParameter($this->parameter());
    }

    /**
     * Read any scope parameter so narrower filters can cascade from wider ones.
     */
    protected function selectedParameter(string $parameter): ?string
    {
        $value = $this->request->get($parameter);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
