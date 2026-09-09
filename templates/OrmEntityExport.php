<?php

/**
 * @var $version
 * @var $description
 * @var $source
 * @var $entityClass
 * @var $rows
 * @var $matchFields
 * @var $extendUse
 * @var $extendClass
 * @var $moduleVersion
 * @var $author
 * @formatter:off
 */

use Sprint\Migration\Helpers\OrmEntityHelper;

?><?php echo "<?php\n" ?>

namespace Sprint\Migration;

<?php echo $extendUse ?>
class <?php echo $version ?> extends <?php echo $extendClass ?>

{
    protected $author = "<?php echo $author ?>";

    protected $description = "<?php echo $description ?>";

    protected $moduleVersion = "<?php echo $moduleVersion ?>";

    public function up()
    {
<?php if ($source !== OrmEntityHelper::PHP_INTERFACE_SOURCE) { ?>
        if (!\Bitrix\Main\Loader::includeModule(<?php echo var_export($source, true) ?>)) {
            throw new Exceptions\MigrationException('Unable to include module: <?php echo addslashes($source) ?>');
        }

<?php } ?>
        $this->getHelperManager()->OrmEntity()->saveRows(
            \<?php echo ltrim($entityClass, '\\') ?>::class,
            <?php echo var_export($rows, true) ?>,
            <?php echo var_export($matchFields, true) ?>,
        );
    }

    public function down()
    {
    }
}
