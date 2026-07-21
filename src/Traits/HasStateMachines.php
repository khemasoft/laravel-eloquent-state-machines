<?php

namespace Asantibanez\LaravelEloquentStateMachines\Traits;

use Asantibanez\LaravelEloquentStateMachines\Models\PendingTransition;
use Asantibanez\LaravelEloquentStateMachines\Models\StateHistory;
use Asantibanez\LaravelEloquentStateMachines\StateMachines\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Javoscript\MacroableModels\Facades\MacroableModels;

trait HasStateMachines
{
    public static function bootHasStateMachines(): void
    {
        /*
         * Laravel 13 does not allow `new static()` while the same model
         * is still in its boot cycle.
         *
         * whenBooted() runs after Laravel marks the model as booted.
         */
        static::whenBooted(function (): void {
            $model = new static();

            collect($model->stateMachines)
                ->each(function ($_, $field): void {
                    MacroableModels::addMacro(
                        static::class,
                        $field,
                        function () use ($field) {
                            $stateMachine = new $this->stateMachines[$field](
                                $field,
                                $this
                            );

                            return new State(
                                $this->{$stateMachine->field},
                                $stateMachine
                            );
                        }
                    );

                    $camelField = Str::of($field)->camel();

                    MacroableModels::addMacro(
                        static::class,
                        $camelField,
                        function () use ($field) {
                            $stateMachine = new $this->stateMachines[$field](
                                $field,
                                $this
                            );

                            return new State(
                                $this->{$stateMachine->field},
                                $stateMachine
                            );
                        }
                    );

                    $studlyField = Str::of($field)->studly();

                    Builder::macro(
                        "whereHas{$studlyField}",
                        function ($callable = null) use ($field) {
                            $model = $this->getModel();

                            if (! method_exists($model, 'stateHistory')) {
                                return $this->newQuery();
                            }

                            return $this->whereHas(
                                'stateHistory',
                                function ($query) use ($field, $callable) {
                                    $query->forField($field);

                                    if ($callable !== null) {
                                        $callable($query);
                                    }

                                    return $query;
                                }
                            );
                        }
                    );
                });
        });

        /*
         * Keep these listeners registered during the normal trait boot
         * process to preserve their ordering relative to other listeners.
         */
        static::creating(function (Model $model): void {
            $model->initStateMachines();
        });

        static::created(function (Model $model): void {
            collect($model->stateMachines)
                ->each(function ($_, $field) use ($model): void {
                    $currentState = $model->{$field};
                    $stateMachine = $model->{$field}()->stateMachine();

                    if ($currentState === null) {
                        return;
                    }

                    if (! $stateMachine->recordHistory()) {
                        return;
                    }

                    $responsible = auth()->user();
                    $changedAttributes = $model->getChangedAttributes();

                    $model->recordState(
                        $field,
                        null,
                        $currentState,
                        [],
                        $responsible,
                        $changedAttributes
                    );
                });
        });
    }

    // Keep the remaining trait methods unchanged.
}
