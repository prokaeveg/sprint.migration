<?php

namespace Sprint\Migration\Builders;

use Sprint\Migration\Exceptions\HelperException;
use Sprint\Migration\Exceptions\MigrationException;
use Sprint\Migration\Exceptions\RebuildException;
use Sprint\Migration\Locale;
use Sprint\Migration\Module;
use Sprint\Migration\VersionBuilder;

class OrmEntityBuilder extends VersionBuilder
{
    protected function isBuilderEnabled(): bool
    {
        return true;
    }

    protected function initialize(): void
    {
        $this->setGroup(Locale::getMessage('BUILDER_GROUP_Main'));
        $this->setTitle(implode(' ', [
            Locale::getMessage('BUILDER_OrmEntity_Title'),
            Locale::getMessage('DEVELOPER_LABEL'),
        ]));
        $this->setDescription(Locale::getMessage('BUILDER_OrmEntity_Description'));

        $this->addVersionFields();
    }

    /**
     * @throws HelperException
     * @throws MigrationException
     * @throws RebuildException
     */
    protected function execute(): void
    {
        $helper = $this->getHelperManager()->OrmEntity();

        $source = $this->addFieldAndReturn(
            'source',
            [
                'title' => Locale::getMessage('BUILDER_OrmEntity_Source'),
                'width' => 350,
                'select' => $this->createSelect($helper->getSources(), 'ID', 'NAME'),
            ],
        );

        $entityClasses = $helper->getEntityClasses($source);
        if ($entityClasses === []) {
            throw new MigrationException(Locale::getMessage(
                'BUILDER_OrmEntity_NoClasses',
                ['#SOURCE#' => $source],
            ));
        }

        $entityClass = $this->addFieldAndReturn(
            'entity_class',
            [
                'title' => Locale::getMessage('BUILDER_OrmEntity_Class'),
                'width' => 500,
                'select' => $this->createSelect($entityClasses, 'CLASS', 'TITLE'),
            ],
        );

        $ids = $this->getExportIds($entityClass);
        $fields = $helper->getScalarFields($entityClass);
        if ($fields === []) {
            throw new MigrationException(Locale::getMessage(
                'BUILDER_OrmEntity_NoFields',
                ['#CLASS#' => $entityClass],
            ));
        }

        $fieldSelect = array_map(
            static fn(string $fieldName): array => [
                'NAME' => $fieldName,
                'TITLE' => $fieldName,
            ],
            $fields,
        );

        $matchFields = $this->addFieldAndReturn(
            'match_fields',
            [
                'title' => Locale::getMessage('BUILDER_OrmEntity_MatchFields'),
                'width' => 350,
                'multiple' => 1,
                'value' => $helper->getPrimaryFields($entityClass),
                'select' => $this->createSelect($fieldSelect, 'NAME', 'TITLE'),
            ],
        );

        $this->createVersionFile(
            Module::getModuleTemplateFile('OrmEntityExport'),
            [
                'source' => $source,
                'entityClass' => $entityClass,
                'rows' => $helper->exportRows($entityClass, $ids),
                'matchFields' => $matchFields,
            ],
        );
    }

    /**
     * @throws HelperException
     * @throws RebuildException
     */
    private function getExportIds(string $entityClass): array
    {
        $helper = $this->getHelperManager()->OrmEntity();
        $filterOptions = [
            [
                'title' => Locale::getMessage('BUILDER_SelectAll'),
                'value' => 'all',
            ],
        ];

        if ($helper->hasIdField($entityClass)) {
            $filterOptions[] = [
                'title' => Locale::getMessage('BUILDER_OrmEntity_SelectSomeId'),
                'value' => 'list_id',
            ];
        }

        $filterMode = $this->addFieldAndReturn(
            'filter_mode',
            [
                'title' => Locale::getMessage('BUILDER_OrmEntity_Filter'),
                'width' => 350,
                'select' => $filterOptions,
            ],
        );

        if ($filterMode !== 'list_id') {
            return [];
        }

        $ids = $this->addFieldAndReturn(
            'export_filter_list_id',
            [
                'title' => Locale::getMessage('BUILDER_OrmEntity_FilterListId'),
                'width' => 350,
                'height' => 40,
            ],
        );

        return $this->explodeString($ids);
    }
}
