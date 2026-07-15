<?php

namespace App\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * belongsTo customer via customer_num scoped by organization_id.
 * customer_num is only unique within an organization.
 */
class BelongsToCustomerByOrganization extends BelongsTo
{
    public function addConstraints()
    {
        if (static::$constraints) {
            parent::addConstraints();

            $this->query->where(
                $this->related->qualifyColumn('organization_id'),
                '=',
                $this->child->getAttribute('organization_id')
            );
        }
    }

    public function addEagerConstraints(array $models)
    {
        $this->query->where(function (Builder $query) use ($models) {
            $added = false;

            foreach ($models as $model) {
                $customerNum = $this->getForeignKeyFrom($model);
                $organizationId = $model->getAttribute('organization_id');

                if ($customerNum === null || $organizationId === null) {
                    continue;
                }

                $added = true;
                $query->orWhere(function (Builder $inner) use ($customerNum, $organizationId) {
                    $inner->where($this->getQualifiedOwnerKeyName(), '=', $customerNum)
                        ->where($this->related->qualifyColumn('organization_id'), '=', $organizationId);
                });
            }

            if (! $added) {
                $query->whereRaw('0 = 1');
            }
        });

        $this->ensureOrganizationIdSelected();
    }

    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = [];

        foreach ($results as $result) {
            $ownerKey = $this->getDictionaryKey($this->getRelatedKeyFrom($result));
            if ($ownerKey === null) {
                continue;
            }

            $dictionary[$result->getAttribute('organization_id').'|'.$ownerKey] = $result;
        }

        foreach ($models as $model) {
            $foreign = $this->getForeignKeyFrom($model);
            if ($foreign === null) {
                continue;
            }

            $key = $model->getAttribute('organization_id').'|'.$this->getDictionaryKey($foreign);
            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            }
        }

        return $models;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query = parent::getRelationExistenceQuery($query, $parentQuery, $columns);

        $parentTable = $parentQuery->getModel()->getTable();

        return $query->whereColumn(
            $this->related->qualifyColumn('organization_id'),
            '=',
            $parentTable.'.organization_id'
        );
    }

    public function get()
    {
        $this->ensureOrganizationIdSelected();

        return parent::get();
    }

    protected function ensureOrganizationIdSelected(): void
    {
        $columns = $this->query->getQuery()->columns;
        if ($columns === null) {
            return;
        }

        $orgColumn = $this->related->qualifyColumn('organization_id');
        foreach ($columns as $column) {
            if ($column === $orgColumn || $column === 'organization_id') {
                return;
            }
        }

        $this->query->addSelect($orgColumn);
    }
}
