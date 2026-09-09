<?php

namespace Sprint\Migration\Helpers;

use Bitrix\Main\Entity\DataManager as LegacyDataManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ScalarField;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Sprint\Migration\Exceptions\MigrationException;
use Sprint\Migration\Helper;
use Sprint\Migration\Module;
use Throwable;

class OrmEntityHelper extends Helper
{
    public const PHP_INTERFACE_SOURCE = 'php_interface';

    public function getSources(): array
    {
        $sources = [
            [
                'ID' => self::PHP_INTERFACE_SOURCE,
                'NAME' => self::PHP_INTERFACE_SOURCE,
            ],
        ];

        foreach (array_keys(ModuleManager::getInstalledModules()) as $moduleId) {
            $sources[] = [
                'ID' => $moduleId,
                'NAME' => $moduleId,
            ];
        }

        usort($sources, static fn(array $left, array $right): int => strcmp($left['NAME'], $right['NAME']));

        return $sources;
    }

    public function getEntityClasses(string $source): array
    {
        $directory = $this->getSourceDirectory($source);
        $classes = [];

        foreach ($this->getPhpFiles($directory) as $fileName) {
            foreach ($this->extractClassNames($fileName) as $className) {
                if (!$this->isDataManagerClass($className)) {
                    continue;
                }

                $classes[$className] = [
                    'CLASS' => $className,
                    'TITLE' => $className,
                ];
            }
        }

        ksort($classes);

        return array_values($classes);
    }

    public function getScalarFields(string $entityClass): array
    {
        $entity = $this->getEntity($entityClass);
        $autoIncrement = $entity->getAutoIncrement();
        $fields = [];

        foreach ($entity->getScalarFields() as $field) {
            if (!$field instanceof ScalarField || $field->getName() === $autoIncrement) {
                continue;
            }

            $fields[] = $field->getName();
        }

        return $fields;
    }

    public function getPrimaryFields(string $entityClass): array
    {
        return array_values(array_intersect(
            $this->getEntity($entityClass)->getPrimaryArray(),
            $this->getScalarFields($entityClass),
        ));
    }

    public function hasIdField(string $entityClass): bool
    {
        $entity = $this->getEntity($entityClass);

        return $entity->hasField('ID') && $entity->getField('ID') instanceof ScalarField;
    }

    public function exportRows(string $entityClass, array $ids = []): array
    {
        $entity = $this->getEntity($entityClass);
        $fields = $this->getScalarFields($entityClass);
        if ($fields === []) {
            throw new MigrationException('ORM entity has no exportable fields: ' . $entityClass);
        }

        $ids = array_values(array_unique(array_map('strval', array_filter($ids, static fn($id): bool => $id !== ''))));
        if ($ids !== [] && !$this->hasIdField($entityClass)) {
            throw new MigrationException('ORM entity has no ID field: ' . $entityClass);
        }

        $select = $fields;
        if ($ids !== [] && !in_array('ID', $select, true)) {
            $select[] = 'ID';
        }

        $query = $entityClass::query()->setSelect($select);
        if ($ids !== []) {
            $query->setFilter(['@ID' => $ids]);
        }

        $rows = $query->exec()->fetchAll();
        if ($ids !== []) {
            $foundIds = array_map(static fn(array $row): string => (string)$row['ID'], $rows);
            $missingIds = array_values(array_diff($ids, $foundIds));
            if ($missingIds !== []) {
                throw new MigrationException(sprintf(
                    'ORM records not found in %s: %s',
                    $entityClass,
                    implode(', ', $missingIds),
                ));
            }
        }

        $autoIncrement = $entity->getAutoIncrement();
        foreach ($rows as &$row) {
            if ($autoIncrement !== null) {
                unset($row[$autoIncrement]);
            }

            foreach ($row as &$value) {
                $value = $this->normalizeValue($value);
            }
            unset($value);
        }
        unset($row);

        if ($rows === []) {
            throw new MigrationException('ORM entity has no records to export: ' . $entityClass);
        }

        return $rows;
    }

    public function saveRows(string $entityClass, array $rows, array $matchFields): void
    {
        $entity = $this->getEntity($entityClass);
        $availableFields = $this->getScalarFields($entityClass);
        $matchFields = array_values(array_unique($matchFields));

        if ($matchFields === []) {
            throw new MigrationException('ORM match fields are empty: ' . $entityClass);
        }

        $unknownFields = array_values(array_diff($matchFields, $availableFields));
        if ($unknownFields !== []) {
            throw new MigrationException(sprintf(
                'Unknown ORM match fields for %s: %s',
                $entityClass,
                implode(', ', $unknownFields),
            ));
        }

        if ($rows === []) {
            throw new MigrationException('ORM rows are empty: ' . $entityClass);
        }

        $primaryFields = $entity->getPrimaryArray();
        if ($primaryFields === []) {
            throw new MigrationException('ORM entity has no primary fields: ' . $entityClass);
        }

        $connection = $entity->getConnection();
        $connection->startTransaction();

        try {
            foreach ($rows as $row) {
                $missingFields = array_values(array_diff($matchFields, array_keys($row)));
                if ($missingFields !== []) {
                    throw new MigrationException(sprintf(
                        'ORM row has no match fields for %s: %s',
                        $entityClass,
                        implode(', ', $missingFields),
                    ));
                }

                $row = $this->restoreDateValues($entity, $row);

                $filter = [];
                foreach ($matchFields as $fieldName) {
                    $filter['=' . $fieldName] = $row[$fieldName];
                }

                $existingRows = $entityClass::query()
                    ->setSelect($primaryFields)
                    ->setFilter($filter)
                    ->setLimit(2)
                    ->exec()
                    ->fetchAll();

                if (count($existingRows) > 1) {
                    throw new MigrationException('Several ORM records match the selected fields: ' . $entityClass);
                }

                if ($existingRows === []) {
                    $result = $entityClass::add($row);
                } else {
                    $primary = array_intersect_key($existingRows[0], array_flip($primaryFields));
                    $result = $entityClass::update(count($primary) === 1 ? reset($primary) : $primary, $row);
                }

                if (!$result->isSuccess()) {
                    throw new MigrationException(sprintf(
                        'Unable to save ORM record in %s by [%s]: %s',
                        $entityClass,
                        implode(', ', $matchFields),
                        implode('; ', $result->getErrorMessages()),
                    ));
                }
            }

            $connection->commitTransaction();
        } catch (Throwable $exception) {
            $connection->rollbackTransaction();

            throw $exception;
        }
    }

    private function getSourceDirectory(string $source): string
    {
        if ($source === self::PHP_INTERFACE_SOURCE) {
            return Module::getPhpInterfaceDir();
        }

        if (!Loader::includeModule($source)) {
            throw new MigrationException('Unable to include module: ' . $source);
        }

        $relativePath = getLocalPath('modules/' . $source);
        if ($relativePath === false) {
            throw new MigrationException('Module directory not found: ' . $source);
        }

        return Module::getDocRoot() . $relativePath;
    }

    private function getPhpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new MigrationException('ORM source directory not found: ' . $directory);
        }

        $iterator = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function ($current): bool {
                if ($current->isDir()) {
                    return !in_array(strtolower($current->getFilename()), ['vendor', 'migrations'], true);
                }

                return strtolower($current->getExtension()) === 'php';
            },
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function extractClassNames(string $fileName): array
    {
        $contents = file_get_contents($fileName);
        if ($contents === false) {
            return [];
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $classes = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $index + 1);
                continue;
            }

            if ($token[0] !== T_CLASS || !$this->isClassDeclaration($tokens, $index)) {
                continue;
            }

            $className = $this->readClassName($tokens, $index + 1);
            if ($className !== '') {
                $classes[] = ltrim($namespace . '\\' . $className, '\\');
            }
        }

        return $classes;
    }

    private function readNamespace(array $tokens, int $offset): string
    {
        $namespace = '';
        $nameTokens = [T_STRING, T_NS_SEPARATOR];
        if (defined('T_NAME_QUALIFIED')) {
            $nameTokens[] = T_NAME_QUALIFIED;
        }

        for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], $nameTokens, true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace, '\\');
    }

    private function readClassName(array $tokens, int $offset): string
    {
        for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            if ($token === '{') {
                break;
            }
        }

        return '';
    }

    private function isClassDeclaration(array $tokens, int $classIndex): bool
    {
        for ($index = $classIndex - 1; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_NEW, T_DOUBLE_COLON], true)) {
                return false;
            }

            return true;
        }

        return true;
    }

    private function isDataManagerClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            return !$reflection->isAbstract()
                && ($reflection->isSubclassOf(DataManager::class)
                    || $reflection->isSubclassOf(LegacyDataManager::class));
        } catch (Throwable) {
            return false;
        }
    }

    private function getEntity(string $entityClass): \Bitrix\Main\ORM\Entity
    {
        if (!$this->isDataManagerClass($entityClass)) {
            throw new MigrationException('ORM DataManager class is unavailable: ' . $entityClass);
        }

        return $entityClass::getEntity();
    }

    private function restoreDateValues(\Bitrix\Main\ORM\Entity $entity, array $row): array
    {
        foreach ($row as $fieldName => $value) {
            if ($value === null || $value instanceof Date) {
                continue;
            }

            $field = $entity->getField($fieldName);
            if ($field instanceof DatetimeField) {
                $row[$fieldName] = new DateTime((string)$value, 'Y-m-d H:i:s');
            } elseif ($field instanceof DateField) {
                $row[$fieldName] = new Date((string)$value, 'Y-m-d');
            }
        }

        return $row;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Date) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        if (is_object($value) || is_resource($value)) {
            throw new MigrationException('Unsupported ORM field value type: ' . get_debug_type($value));
        }

        return $value;
    }
}
